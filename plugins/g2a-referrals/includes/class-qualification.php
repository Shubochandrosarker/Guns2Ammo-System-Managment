<?php
/**
 * Turning a referred signup into a paid reward.
 *
 * Rewards fire on the friend's CONFIRMED membership payment, never on
 * signup. A signup costs nothing to fake; a confirmed payment does not.
 *
 *   pending → qualified (payment confirmed) → rewarded (ledger rows written)
 *           ↘ rejected (self-referral / fraud)
 *   rewarded → reversed (refund or cancellation inside the hold window)
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Events_Repository;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Qualification {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		// Memberistic fires this from the WooCommerce bridge and from both
		// Stripe activation paths, so it is the one place every confirmed
		// membership payment converges.
		add_action( 'memberistic_membership_activated', array( self::class, 'on_membership_activated' ), 20, 1 );
		add_action( 'memberistic_membership_payment_recorded', array( self::class, 'on_payment_recorded' ), 20, 3 );

		// Remember the logged-in member's own device so a later self-referral
		// attempt from the same browser is recognisable.
		add_action( 'wp_login', array( self::class, 'remember_login_device' ), 10, 2 );

		// Ensure a member always has a code once they are active.
		add_action( 'memberistic_membership_activated', array( self::class, 'ensure_referrer_code' ), 30, 1 );
	}

	/**
	 * Give the new member their own referral code.
	 *
	 * @param int $membership_id Membership row id.
	 * @return void
	 */
	public static function ensure_referrer_code( $membership_id ) {
		$membership = self::membership( (int) $membership_id );

		if ( ! $membership || empty( $membership['primary_user_id'] ) ) {
			return;
		}

		Referrers_Repository::ensure_for_user( (int) $membership['primary_user_id'], (int) $membership_id );
	}

	/**
	 * Record the device a member logs in from.
	 *
	 * @param string   $user_login Login name.
	 * @param \WP_User $user       User object.
	 * @return void
	 */
	public static function remember_login_device( $user_login, $user ) {
		if ( $user instanceof \WP_User ) {
			Fraud::remember( (int) $user->ID, 'g2ar_visitor_hashes', Fingerprint::visitor_hash() );
		}
	}

	/**
	 * A membership payment has been recorded.
	 *
	 * @param int    $membership_id Membership row id.
	 * @param int    $payment_id    Payment row id.
	 * @param string $source        Payment source.
	 * @return void
	 */
	public static function on_payment_recorded( $membership_id, $payment_id = 0, $source = '' ) {
		unset( $payment_id, $source );
		self::on_membership_activated( $membership_id );
	}

	/**
	 * The membership is live and paid: qualify and reward any referral
	 * attached to it.
	 *
	 * @param int $membership_id Membership row id.
	 * @return void
	 */
	public static function on_membership_activated( $membership_id ) {
		$membership_id = (int) $membership_id;

		if ( $membership_id <= 0 ) {
			return;
		}

		$membership = self::membership( $membership_id );

		if ( ! $membership ) {
			return;
		}

		// Only a confirmed, paid membership qualifies.
		if ( ! in_array( strtolower( (string) $membership['status'] ), array( 'active', 'comped' ), true ) ) {
			return;
		}

		$existing = Conversions_Repository::get_by_membership( $membership_id );

		// Already handled. UNIQUE (friend_membership_id) means a webhook
		// retry lands here and stops, which is exactly the point.
		if ( $existing && in_array( (string) $existing['status'], array( 'rewarded', 'rejected', 'reversed' ), true ) ) {
			return;
		}

		$friend_user_id = (int) ( $membership['primary_user_id'] ?? 0 );
		$friend_email   = self::membership_email( $membership );

		$resolved = $existing
			? array(
				'referrer' => Referrers_Repository::get( (int) $existing['referrer_id'] ),
				'visit_id' => (int) $existing['visit_id'],
				'source'   => 'existing',
			)
			: Attribution::resolve( self::stored_code_for_membership( $membership ) );

		$referrer = $resolved['referrer'] ?? null;

		if ( ! is_array( $referrer ) ) {
			return;
		}

		$visitor_hash = Fingerprint::visitor_hash();

		$conversion = $existing;

		if ( ! $conversion ) {
			$created = Conversions_Repository::create(
				array(
					'referrer_id'          => (int) $referrer['id'],
					'visit_id'             => (int) $resolved['visit_id'],
					'friend_user_id'       => $friend_user_id,
					'friend_membership_id' => $membership_id,
					'plan_id'              => (int) ( $membership['plan_id'] ?? 0 ),
					'billing_cycle'        => (string) ( $membership['billing_cycle'] ?? '' ),
					'amount_paid'          => (float) ( $membership['billing_amount'] ?? 0 ),
					'status'               => 'pending',
				)
			);

			$conversion = $created['row'];

			if ( ! $conversion ) {
				return;
			}

			if ( ! $created['created'] ) {
				// Someone else won the race and may already have rewarded it.
				if ( in_array( (string) $conversion['status'], array( 'rewarded', 'rejected', 'reversed' ), true ) ) {
					return;
				}
			}
		}

		// ---- Self-referral, on all four vectors --------------------------
		$reject = Fraud::self_referral_reason(
			$referrer,
			$friend_user_id,
			$friend_email,
			$visitor_hash,
			self::payment_fingerprint( $membership )
		);

		if ( '' !== $reject ) {
			Conversions_Repository::set_status( (int) $conversion['id'], 'rejected', array( 'reject_reason' => $reject ) );
			Events_Repository::log(
				'conversion_rejected',
				array(
					'referrer_id' => (int) $referrer['id'],
					'object_type' => 'conversion',
					'object_id'   => (int) $conversion['id'],
					'actor_id'    => 0,
					'payload'     => array( 'reason' => $reject ),
				)
			);

			return;
		}

		// ---- Suspended referrers earn nothing ----------------------------
		if ( 'active' !== (string) $referrer['status'] ) {
			Conversions_Repository::set_status( (int) $conversion['id'], 'rejected', array( 'reject_reason' => 'referrer_suspended' ) );

			return;
		}

		// ---- Monthly cap --------------------------------------------------
		// The cap bounds lane capacity as much as abuse. A capped conversion
		// is not rejected — it stays qualified and visible, so the front desk
		// can see the member hit their limit rather than wondering why a
		// genuine referral vanished.
		Conversions_Repository::set_status( (int) $conversion['id'], 'qualified' );
		$conversion = Conversions_Repository::get( (int) $conversion['id'] );

		if ( Fraud::monthly_cap_reached( (int) $referrer['id'] ) ) {
			Events_Repository::log(
				'conversion_capped',
				array(
					'referrer_id' => (int) $referrer['id'],
					'object_type' => 'conversion',
					'object_id'   => (int) $conversion['id'],
					'actor_id'    => 0,
					'payload'     => array( 'cap' => Settings::get_int( 'referral_cap_per_month' ) ),
				)
			);

			return;
		}

		// ---- Reward both sides ---------------------------------------------
		$referrer_ledger = Rewards_Service::grant_referrer_reward( $conversion, $referrer );
		$friend_ledger   = Rewards_Service::grant_friend_reward( $conversion );

		if ( ! $referrer_ledger && ! $friend_ledger ) {
			return;
		}

		Conversions_Repository::set_status( (int) $conversion['id'], 'rewarded' );
		Referrers_Repository::refresh_counters( (int) $referrer['id'] );

		if ( (int) $conversion['visit_id'] > 0 ) {
			Visits_Repository::mark_converted( (int) $conversion['visit_id'], (int) $conversion['id'] );
		}

		// ---- Review flags ---------------------------------------------------
		$flag = Fraud::review_reason( $referrer, $visitor_hash );
		if ( '' !== $flag ) {
			Fraud::flag( (int) $conversion['id'], (int) $referrer['id'], $flag );
		}

		Events_Repository::log(
			'conversion_rewarded',
			array(
				'referrer_id' => (int) $referrer['id'],
				'object_type' => 'conversion',
				'object_id'   => (int) $conversion['id'],
				'actor_id'    => 0,
				'payload'     => array(
					'referrer_ledger_id' => $referrer_ledger,
					'friend_ledger_id'   => $friend_ledger,
					'plan_id'            => (int) ( $membership['plan_id'] ?? 0 ),
					'billing_cycle'      => (string) ( $membership['billing_cycle'] ?? '' ),
				),
			)
		);

		/**
		 * Fires once both reward rows are written.
		 *
		 * @param array $conversion Conversion row.
		 * @param array $referrer   Referrer row.
		 */
		do_action( 'g2ar_referral_rewarded', $conversion, $referrer );
	}

	/**
	 * Read a membership row.
	 *
	 * @param int $membership_id Row id.
	 * @return array|null
	 */
	private static function membership( $membership_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'memberistic_memberships';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $membership_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * The membership's primary email, for the self-referral email check.
	 *
	 * @param array $membership Membership row.
	 * @return string
	 */
	private static function membership_email( array $membership ) {
		$user_id = (int) ( $membership['primary_user_id'] ?? 0 );

		if ( $user_id > 0 ) {
			$user = get_userdata( $user_id );
			if ( $user ) {
				return (string) $user->user_email;
			}
		}

		global $wpdb;

		$people = $wpdb->prefix . 'memberistic_people';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$email = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT email FROM {$people} WHERE membership_id = %d AND email IS NOT NULL ORDER BY id ASC LIMIT 1",
				(int) $membership['id']
			)
		);

		return (string) $email;
	}

	/**
	 * A referral code captured at checkout and stored against the membership
	 * or the user, when one was typed in rather than clicked through.
	 *
	 * @param array $membership Membership row.
	 * @return string
	 */
	private static function stored_code_for_membership( array $membership ) {
		$user_id = (int) ( $membership['primary_user_id'] ?? 0 );

		if ( $user_id > 0 ) {
			$code = get_user_meta( $user_id, 'g2ar_signup_code', true );
			if ( $code ) {
				return (string) $code;
			}
		}

		return '';
	}

	/**
	 * Payment instrument fingerprint for the fourth self-referral vector.
	 *
	 * @param array $membership Membership row.
	 * @return string
	 */
	private static function payment_fingerprint( array $membership ) {
		$customer = (string) ( $membership['stripe_customer_id'] ?? '' );

		/**
		 * Allow the Stripe integration to supply a real instrument
		 * fingerprint (pm_/card fingerprint) rather than the customer id.
		 *
		 * @param string $fingerprint Default: the Stripe customer id.
		 * @param array  $membership  Membership row.
		 */
		return (string) apply_filters( 'g2ar_payment_fingerprint', $customer, $membership );
	}
}
