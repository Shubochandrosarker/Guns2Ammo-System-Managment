<?php
/**
 * Fraud controls.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Events_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fraud {

	/**
	 * Reject a conversion outright when the "friend" is really the referrer.
	 *
	 * Checked on four independent vectors, because any one of them alone is
	 * trivially defeated: a second email address beats a user_id check, a
	 * fresh account beats an email check, and a phone hotspot beats a device
	 * check. All four have to pass.
	 *
	 * @param array  $referrer      Referrer row.
	 * @param int    $friend_user_id Friend's WP user id.
	 * @param string $friend_email  Friend's email.
	 * @param string $visitor_hash  Friend's visitor fingerprint.
	 * @param string $payment_fingerprint Stripe fingerprint / last4+brand, when known.
	 * @return string Rejection reason, or '' when the conversion is clean.
	 */
	public static function self_referral_reason( array $referrer, $friend_user_id, $friend_email = '', $visitor_hash = '', $payment_fingerprint = '' ) {
		$referrer_user_id = (int) ( $referrer['user_id'] ?? 0 );
		$friend_user_id   = (int) $friend_user_id;

		// 1. Same WP user.
		if ( $referrer_user_id > 0 && $referrer_user_id === $friend_user_id ) {
			return 'self_referral_user';
		}

		// 2. Same email address.
		$referrer_user = $referrer_user_id ? get_userdata( $referrer_user_id ) : null;
		if ( $referrer_user && $friend_email && 0 === strcasecmp( trim( $friend_email ), trim( $referrer_user->user_email ) ) ) {
			return 'self_referral_email';
		}

		// 3. Same device fingerprint as the referrer's own recent session.
		if ( $visitor_hash && self::hash_belongs_to_user( $visitor_hash, $referrer_user_id ) ) {
			return 'self_referral_device';
		}

		// 4. Same payment instrument.
		if ( $payment_fingerprint && self::payment_belongs_to_user( $payment_fingerprint, $referrer_user_id ) ) {
			return 'self_referral_payment';
		}

		return '';
	}

	/**
	 * Should this conversion be held for a human to look at?
	 *
	 * Flagging is not rejecting: the reward still lands, but the admin
	 * overview surfaces it. Auto-rejecting on a volume heuristic would punish
	 * the one member who genuinely brings their whole shooting club.
	 *
	 * @param array  $referrer     Referrer row.
	 * @param string $visitor_hash Visitor fingerprint.
	 * @return string Flag reason, or ''.
	 */
	public static function review_reason( array $referrer, $visitor_hash = '' ) {
		$max_per_hash = Settings::get_int( 'max_conversions_per_hash' );
		$max_per_day  = Settings::get_int( 'max_conversions_per_day' );

		if ( $visitor_hash && $max_per_hash > 0 ) {
			if ( Visits_Repository::conversions_for_visitor( $visitor_hash ) > $max_per_hash ) {
				return 'many_conversions_one_device';
			}
		}

		if ( $max_per_day > 0 ) {
			$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			if ( Conversions_Repository::count_since( (int) $referrer['id'], $since ) > $max_per_day ) {
				return 'referrer_daily_volume';
			}
		}

		return '';
	}

	/**
	 * Has this referrer already hit the monthly cap?
	 *
	 * The cap bounds two different things at once: lane capacity (every Guest
	 * Pass is an hour someone has to staff) and abuse. 0 disables it.
	 *
	 * @param int $referrer_id Referrer row id.
	 * @return bool
	 */
	public static function monthly_cap_reached( $referrer_id ) {
		$cap = Settings::get_int( 'referral_cap_per_month' );

		if ( $cap <= 0 ) {
			return false;
		}

		return Conversions_Repository::count_rewarded_in_month( (int) $referrer_id ) >= $cap;
	}

	/**
	 * Rate-limit code validation, reusing Memberistic's rate-limit table so
	 * there is one bucket store on the site rather than two.
	 *
	 * Fails open when the table is missing: a referral link must not 500
	 * because an unrelated plugin was deactivated.
	 *
	 * @param string $bucket Logical bucket name.
	 * @return bool True when the caller may proceed.
	 */
	public static function allow_code_lookup( $bucket = 'code_lookup' ) {
		global $wpdb;

		$max    = Settings::get_int( 'rate_limit_attempts' );
		$window = Settings::get_int( 'rate_limit_window_minutes' );

		if ( $max <= 0 || $window <= 0 ) {
			return true;
		}

		$table = $wpdb->prefix . 'memberistic_rate_limits';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return true;
		}

		$key  = hash( 'sha256', 'g2ar|' . $bucket . '|' . Fingerprint::ip_hash() );
		$now  = current_time( 'mysql', true );
		$ends = gmdate( 'Y-m-d H:i:s', time() + ( $window * MINUTE_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE rate_key_hash = %s", $key ),
			ARRAY_A
		);

		if ( ! $row || ( $row['expires_at'] ?? '' ) < $now ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
					"INSERT INTO {$table} ( rate_key_hash, attempt_count, window_started_at, expires_at, updated_at )
					 VALUES ( %s, 1, %s, %s, %s )
					 ON DUPLICATE KEY UPDATE attempt_count = 1, window_started_at = VALUES(window_started_at), expires_at = VALUES(expires_at), updated_at = VALUES(updated_at)",
					$key,
					$now,
					$ends,
					$now
				)
			);

			return true;
		}

		if ( (int) $row['attempt_count'] >= $max ) {
			return false;
		}

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"UPDATE {$table} SET attempt_count = attempt_count + 1, updated_at = %s WHERE rate_key_hash = %s",
				$now,
				$key
			)
		);

		return true;
	}

	/**
	 * Record that a conversion was flagged, for the admin queue.
	 *
	 * @param int    $conversion_id Conversion row id.
	 * @param int    $referrer_id   Referrer row id.
	 * @param string $reason        Machine reason.
	 * @return void
	 */
	public static function flag( $conversion_id, $referrer_id, $reason ) {
		Events_Repository::log(
			'conversion_flagged',
			array(
				'referrer_id' => (int) $referrer_id,
				'object_type' => 'conversion',
				'object_id'   => (int) $conversion_id,
				'actor_id'    => 0,
				'payload'     => array( 'reason' => $reason ),
			)
		);
	}

	/**
	 * Does a visitor fingerprint match one of this user's own recent visits?
	 *
	 * @param string $visitor_hash Fingerprint.
	 * @param int    $user_id      Referrer's user id.
	 * @return bool
	 */
	private static function hash_belongs_to_user( $visitor_hash, $user_id ) {
		if ( (int) $user_id <= 0 ) {
			return false;
		}

		$known = get_user_meta( (int) $user_id, 'g2ar_visitor_hashes', true );

		return is_array( $known ) && in_array( (string) $visitor_hash, $known, true );
	}

	/**
	 * Does a payment fingerprint match one already recorded against this user?
	 *
	 * @param string $fingerprint Payment instrument fingerprint.
	 * @param int    $user_id     Referrer's user id.
	 * @return bool
	 */
	private static function payment_belongs_to_user( $fingerprint, $user_id ) {
		if ( (int) $user_id <= 0 || '' === (string) $fingerprint ) {
			return false;
		}

		$known = get_user_meta( (int) $user_id, 'g2ar_payment_fingerprints', true );

		return is_array( $known ) && in_array( (string) $fingerprint, $known, true );
	}

	/**
	 * Remember the fingerprints a logged-in member browses and pays with, so
	 * the self-referral check has something to compare against later.
	 *
	 * Capped at 10 entries per user — enough to recognise a repeat device,
	 * short enough that user meta never becomes a tracking log.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $meta_key Meta key to append to.
	 * @param string $value    Value to remember.
	 * @return void
	 */
	public static function remember( $user_id, $meta_key, $value ) {
		$user_id = (int) $user_id;
		$value   = (string) $value;

		if ( $user_id <= 0 || '' === $value ) {
			return;
		}

		$known = get_user_meta( $user_id, $meta_key, true );
		$known = is_array( $known ) ? $known : array();

		if ( in_array( $value, $known, true ) ) {
			return;
		}

		$known[] = $value;
		$known    = array_slice( $known, -10 );

		update_user_meta( $user_id, $meta_key, $known );
	}
}
