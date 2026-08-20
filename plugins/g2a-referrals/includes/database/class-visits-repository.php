<?php
/**
 * Referral link visits.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Visits_Repository {

	/**
	 * @return string
	 */
	public static function table() {
		return Schema::table( 'visits' );
	}

	/**
	 * Record a visit against a referrer.
	 *
	 * @param array $data referrer_id, visitor_hash, landing_url, referrer_url, device.
	 * @return int Inserted row id, 0 on failure.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$referrer_id  = (int) ( $data['referrer_id'] ?? 0 );
		$visitor_hash = (string) ( $data['visitor_hash'] ?? '' );

		if ( $referrer_id <= 0 || 64 !== strlen( $visitor_hash ) ) {
			return 0;
		}

		$ok = $wpdb->insert(
			self::table(),
			array(
				'referrer_id'  => $referrer_id,
				'visitor_hash' => $visitor_hash,
				'landing_url'  => mb_substr( (string) ( $data['landing_url'] ?? '' ), 0, 255 ),
				'referrer_url' => mb_substr( (string) ( $data['referrer_url'] ?? '' ), 0, 255 ),
				'device'       => mb_substr( (string) ( $data['device'] ?? '' ), 0, 20 ),
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * The most recent unconverted visit for a visitor fingerprint.
	 *
	 * @param string $visitor_hash sha256 fingerprint.
	 * @param int    $referrer_id  Referrer to match.
	 * @return array|null
	 */
	public static function latest_for_visitor( $visitor_hash, $referrer_id = 0 ) {
		global $wpdb;

		$table = self::table();

		if ( $referrer_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE visitor_hash = %s AND referrer_id = %d ORDER BY id DESC LIMIT 1",
					(string) $visitor_hash,
					(int) $referrer_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE visitor_hash = %s ORDER BY id DESC LIMIT 1",
					(string) $visitor_hash
				),
				ARRAY_A
			);
		}

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Mark a visit as converted.
	 *
	 * @param int $visit_id      Visit row id.
	 * @param int $conversion_id Conversion row id.
	 * @return void
	 */
	public static function mark_converted( $visit_id, $conversion_id ) {
		global $wpdb;

		if ( (int) $visit_id <= 0 ) {
			return;
		}

		$wpdb->update(
			self::table(),
			array(
				'converted_at'  => current_time( 'mysql', true ),
				'conversion_id' => (int) $conversion_id,
			),
			array( 'id' => (int) $visit_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Click count for a referrer, for the dashboard funnel.
	 *
	 * @param int $referrer_id Referrer row id.
	 * @return int
	 */
	public static function count_for_referrer( $referrer_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE referrer_id = %d", (int) $referrer_id ) );
	}

	/**
	 * How many conversions this visitor fingerprint has already produced.
	 * Feeds the fraud review threshold.
	 *
	 * @param string $visitor_hash sha256 fingerprint.
	 * @return int
	 */
	public static function conversions_for_visitor( $visitor_hash ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE visitor_hash = %s AND conversion_id IS NOT NULL",
				(string) $visitor_hash
			)
		);
	}
}
