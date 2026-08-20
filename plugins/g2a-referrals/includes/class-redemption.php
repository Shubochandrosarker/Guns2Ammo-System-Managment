<?php
/**
 * Guest Pass redemption on lane bookings.
 *
 * Hooked at priority 12 on both pricing filters. Memberistic sits at 11 and
 * only overwrites when its own discount is larger, so running after it means
 * we see the member's real, final price before deciding anything.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Events_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Redemption {

	/**
	 * Whether this request asked to spend a pass.
	 *
	 * @var bool
	 */
	private static $opted_in = false;

	/**
	 * What the pricing filter actually applied this request, so the
	 * post-commit hook consumes exactly what the customer was charged for.
	 *
	 * @var array|null
	 */
	private static $applied = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! Settings::is_on( 'redemption_enabled' ) ) {
			return;
		}

		// Priority 12: after Memberistic at 11.
		add_filter( 'g2ab_booking_pricing', array( self::class, 'filter_booking_pricing' ), 12, 5 );
		add_filter( 'g2ab_booking_display_pricing', array( self::class, 'filter_display_pricing' ), 12, 4 );

		// Capture the per-booking opt-in off the REST request.
		add_filter( 'rest_pre_dispatch', array( self::class, 'capture_opt_in' ), 10, 3 );

		// Consume on confirmation, return on cancellation.
		add_action( 'g2ab_booking_created', array( self::class, 'on_booking_created' ), 10, 2 );
		add_action( 'g2ab_booking_cancelled', array( self::class, 'on_booking_cancelled' ), 10, 2 );
	}

	/**
	 * Read use_guest_pass off an inbound booking request.
	 *
	 * @param mixed            $result  Short-circuit result.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed Untouched $result.
	 */
	public static function capture_opt_in( $result, $server, $request ) {
		unset( $server );

		if ( ! $request instanceof \WP_REST_Request ) {
			return $result;
		}

		$namespace = defined( 'G2AB_REST_NAMESPACE' ) ? (string) G2AB_REST_NAMESPACE : 'g2a-booking/v1';

		if ( false === strpos( (string) $request->get_route(), '/' . $namespace . '/' ) ) {
			return $result;
		}

		$param = $request->get_param( 'use_guest_pass' );

		if ( null !== $param ) {
			self::$opted_in = in_array( (string) $param, array( '1', 'true', 'yes', 'on' ), true ) || true === $param;
		}

		return $result;
	}

	/**
	 * Set the opt-in directly. Used by tests and by any UI that wants to
	 * drive redemption without going through REST.
	 *
	 * @param bool $value Opt-in.
	 * @return void
	 */
	public static function set_opt_in( $value ) {
		self::$opted_in = (bool) $value;
	}

	/**
	 * Reset per-request state. Used by tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$opted_in = false;
		self::$applied  = null;
	}

	/**
	 * Real pricing filter — the number the customer is charged.
	 *
	 * @param array  $pricing        Pricing array.
	 * @param object $booking_type   Booking type row.
	 * @param int    $party_size     Party size.
	 * @param int    $user_id        WP user id.
	 * @param string $customer_email Typed email (ignored for entitlement).
	 * @return array
	 */
	public static function filter_booking_pricing( $pricing, $booking_type, $party_size, $user_id, $customer_email = '' ) {
		unset( $customer_email );

		return self::apply( (array) $pricing, $booking_type, (int) $party_size, (int) $user_id, true );
	}

	/**
	 * Display pricing filter — the preview shown on the booking form.
	 *
	 * @param array  $pricing      Pricing array.
	 * @param object $booking_type Booking type row.
	 * @param int    $party_size   Party size.
	 * @param int    $user_id      WP user id.
	 * @return array
	 */
	public static function filter_display_pricing( $pricing, $booking_type, $party_size, $user_id ) {
		return self::apply( (array) $pricing, $booking_type, (int) $party_size, (int) $user_id, false );
	}

	/**
	 * Decide and apply.
	 *
	 * @param array  $pricing      Pricing array.
	 * @param object $booking_type Booking type row.
	 * @param int    $party_size   Party size.
	 * @param int    $user_id      WP user id.
	 * @param bool   $is_real      True for the charged price, false for a preview.
	 * @return array
	 */
	private static function apply( array $pricing, $booking_type, $party_size, $user_id, $is_real ) {
		if ( $is_real ) {
			self::$applied = null;
		}

		if ( $user_id <= 0 ) {
			return $pricing;
		}

		if ( ! self::$opted_in ) {
			// Opt-in per booking, always. A pass is never spent because the
			// member happened to have one.
			return $pricing;
		}

		if ( ! self::booking_type_eligible( $booking_type ) ) {
			return $pricing;
		}

		$subtotal = round( (float) ( $pricing['subtotal'] ?? 0 ), 2 );
		$discount = round( (float) ( $pricing['discount_amount'] ?? 0 ), 2 );
		$total    = isset( $pricing['total'] )
			? round( (float) $pricing['total'], 2 )
			: round( $subtotal - $discount, 2 );

		/*
		 * ─────────────────────────────────────────────────────────────────
		 * THE RULE. Members hold member_discount = 100.00 on lane bookings,
		 * so their lane time is already free. If redemption ran blind here,
		 * a member would burn a hard-earned Guest Pass on a booking that
		 * cost nothing — the angriest conversation this feature can produce
		 * at the front desk. Total already $0 → silently do nothing.
		 * ─────────────────────────────────────────────────────────────────
		 */
		if ( $total <= 0 ) {
			$pricing['guest_pass_skipped'] = 'already_free';

			return $pricing;
		}

		$balance = Rewards_Repository::balance( $user_id, Rewards_Repository::TYPE_GUEST_PASS );

		if ( $balance < 1 ) {
			return $pricing;
		}

		// The pass buys one extra seat at no charge. Seat price is the
		// booking's own per-head rate, not a hard-coded $20.
		$party_size = max( 1, $party_size );
		$seat_price = round( $subtotal / $party_size, 2 );

		if ( $seat_price <= 0 ) {
			return $pricing;
		}

		// Never discount more than is actually owed.
		$credit = min( $seat_price, $total );

		if ( $credit <= 0 ) {
			return $pricing;
		}

		$pricing['discount_amount']  = round( $discount + $credit, 2 );
		// (float) because max( 0, 0.0 ) hands back int 0 in PHP, and a
		// money field that silently changes type is a bug waiting to be
		// found by whatever compares it with ===.
		$pricing['total']            = (float) max( 0, round( $total - $credit, 2 ) );
		$pricing['guest_pass_applied'] = 1;
		$pricing['guest_pass_credit']  = $credit;
		$pricing['discount_label']     = trim(
			( isset( $pricing['discount_label'] ) ? (string) $pricing['discount_label'] . ' + ' : '' )
			. __( '1 Guest Pass (brings a friend free)', 'g2a-referrals' )
		);

		if ( $is_real ) {
			self::$applied = array(
				'user_id'    => $user_id,
				'credit'     => $credit,
				'pre_total'  => $total,
				'party_size' => $party_size,
			);
		}

		return $pricing;
	}

	/**
	 * Is this booking type one a Guest Pass may be spent on?
	 *
	 * @param object $booking_type Booking type row.
	 * @return bool
	 */
	private static function booking_type_eligible( $booking_type ) {
		$allowed = array_map( 'absint', (array) Settings::get( 'redemption_booking_type_ids', array( 1 ) ) );

		if ( ! $allowed ) {
			return false;
		}

		$id = 0;

		if ( is_object( $booking_type ) && isset( $booking_type->id ) ) {
			$id = (int) $booking_type->id;
		} elseif ( is_array( $booking_type ) && isset( $booking_type['id'] ) ) {
			$id = (int) $booking_type['id'];
		}

		return in_array( $id, $allowed, true );
	}

	/**
	 * Consume the pass once the booking is committed.
	 *
	 * Re-checks the balance rather than trusting the pricing pass: two tabs
	 * submitted together would otherwise spend one pass twice.
	 *
	 * @param int   $booking_id Booking row id.
	 * @param array $context    Hook context.
	 * @return void
	 */
	public static function on_booking_created( $booking_id, $context = array() ) {
		unset( $context );

		if ( ! is_array( self::$applied ) ) {
			return;
		}

		$applied = self::$applied;
		self::$applied = null;

		$user_id = (int) $applied['user_id'];

		if ( $user_id <= 0 || (int) $booking_id <= 0 ) {
			return;
		}

		// Same rule again at the point of consumption: a pass never comes off
		// a booking that was already free.
		if ( (float) $applied['pre_total'] <= 0 ) {
			return;
		}

		if ( Rewards_Repository::balance( $user_id, Rewards_Repository::TYPE_GUEST_PASS ) < 1 ) {
			Events_Repository::log(
				'redemption_declined',
				array(
					'object_type' => 'booking',
					'object_id'   => (int) $booking_id,
					'actor_id'    => 0,
					'payload'     => array(
						'user_id' => $user_id,
						'reason'  => 'no_balance_at_commit',
					),
				)
			);

			return;
		}

		// One redeem row per booking, whatever the retry pattern.
		if ( self::already_redeemed_for_booking( $booking_id ) ) {
			return;
		}

		$ledger_id = Rewards_Repository::add(
			array(
				'user_id'     => $user_id,
				'reward_type' => Rewards_Repository::TYPE_GUEST_PASS,
				'amount'      => 1,
				'direction'   => 'redeem',
				'source'      => 'referral',
				'booking_id'  => (int) $booking_id,
				'note'        => __( 'Guest Pass redeemed on a lane booking', 'g2a-referrals' ),
				'actor_id'    => 0,
			)
		);

		Events_Repository::log(
			'guest_pass_redeemed',
			array(
				'object_type' => 'booking',
				'object_id'   => (int) $booking_id,
				'actor_id'    => 0,
				'payload'     => array(
					'user_id'   => $user_id,
					'credit'    => (float) $applied['credit'],
					'ledger_id' => $ledger_id,
				),
			)
		);
	}

	/**
	 * Give the pass back when the booking is cancelled.
	 *
	 * @param int   $booking_id Booking row id.
	 * @param array $context    Hook context.
	 * @return void
	 */
	public static function on_booking_cancelled( $booking_id, $context = array() ) {
		unset( $context );

		$redeem = self::redeem_row_for_booking( $booking_id );

		if ( ! $redeem ) {
			return;
		}

		// Only return it once.
		if ( self::already_returned_for_booking( $booking_id ) ) {
			return;
		}

		Rewards_Repository::add(
			array(
				'user_id'     => (int) $redeem['user_id'],
				'reward_type' => Rewards_Repository::TYPE_GUEST_PASS,
				'amount'      => abs( (float) $redeem['amount'] ),
				'direction'   => 'grant',
				'source'      => 'referral',
				'booking_id'  => (int) $booking_id,
				// Returned passes keep the original expiry rather than
				// resetting the clock — a cancel-and-rebook loop must not be
				// a way to extend a pass indefinitely.
				'expires_at'  => ! empty( $redeem['expires_at'] ) ? (string) $redeem['expires_at'] : Rewards_Service::expiry_for( Rewards_Repository::TYPE_GUEST_PASS ),
				'note'        => __( 'Guest Pass returned — booking cancelled', 'g2a-referrals' ),
				'actor_id'    => get_current_user_id(),
			)
		);

		Events_Repository::log(
			'guest_pass_returned',
			array(
				'object_type' => 'booking',
				'object_id'   => (int) $booking_id,
				'payload'     => array( 'user_id' => (int) $redeem['user_id'] ),
			)
		);
	}

	/**
	 * The redeem row for a booking, if there is one.
	 *
	 * @param int $booking_id Booking row id.
	 * @return array|null
	 */
	private static function redeem_row_for_booking( $booking_id ) {
		global $wpdb;

		$table = Rewards_Repository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE booking_id = %d AND direction = 'redeem' ORDER BY id ASC LIMIT 1",
				(int) $booking_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int $booking_id Booking row id.
	 * @return bool
	 */
	private static function already_redeemed_for_booking( $booking_id ) {
		return null !== self::redeem_row_for_booking( $booking_id );
	}

	/**
	 * @param int $booking_id Booking row id.
	 * @return bool
	 */
	private static function already_returned_for_booking( $booking_id ) {
		global $wpdb;

		$table = Rewards_Repository::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE booking_id = %d AND direction = 'grant' LIMIT 1",
				(int) $booking_id
			)
		);
	}
}
