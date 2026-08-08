<?php
/**
 * Canonical booking visibility policy for operational staff screens.
 *
 * "Operational" means staff can act on it: the customer is expected on the
 * range. That requires payment evidence appropriate to the booking's kind:
 *
 *   Operational:
 *     - confirmed with $0 total (eligible member lane, free event)
 *     - paid / partially_refunded (verified money on the ledger)
 *     - completed historical bookings, no_show records
 *     - reserved pay-at-store bookings created by STAFF (front desk, manual,
 *       POS) — never public web checkouts
 *
 *   Never operational:
 *     - pending checkout holds (open Stripe sessions)
 *     - failed / expired / abandoned attempts
 *     - cancelled and refunded bookings (historical, shown via filters only)
 *     - public web pay-at-store attempts (legacy rows from the retired flow)
 *
 * Payment holds and abandoned checkout attempts belong in the Checkout
 * Attempts diagnostics screen, not on rosters, calendars, KPIs or exports.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Booking_Visibility {

	/**
	 * Statuses that are always operational, whatever the payment mode.
	 *
	 * @return string[]
	 */
	public static function operational_statuses() {
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters(
			'g2ab_operational_booking_statuses',
			array( 'confirmed', 'paid', 'completed', 'partially_refunded', 'no_show' )
		) ) ) );
	}

	/**
	 * Booking sources whose reserved/pay-at-store rows staff intentionally
	 * created (walk-ins recorded at the desk, phone bookings, POS). A public
	 * 'web' booking can never enter operational state through pay-at-store.
	 *
	 * @return string[]
	 */
	public static function staff_sources() {
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters(
			'g2ab_staff_booking_sources',
			array( 'admin', 'manual', 'frontdesk', 'front_desk', 'pos', 'phone', 'staff', 'walk_in' )
		) ) ) );
	}

	/**
	 * SQL WHERE fragment for operational bookings. No user input is accepted.
	 *
	 * @param string $alias Bookings table alias.
	 * @return string
	 */
	public static function operational_sql( $alias = 'b' ) {
		$alias    = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $alias );
		$statuses = array_filter( self::operational_statuses() );
		$quoted   = array_map( static function ( $status ) {
			return "'" . esc_sql( $status ) . "'";
		}, $statuses );
		$status_sql = $quoted ? "{$alias}.status IN (" . implode( ',', $quoted ) . ')' : '1=0';

		$sources = array_map( static function ( $source ) {
			return "'" . esc_sql( $source ) . "'";
		}, array_filter( self::staff_sources() ) );
		$staff_reserved_sql = $sources
			? "( {$alias}.status = 'reserved' AND {$alias}.payment_mode = 'in_store' AND {$alias}.source IN (" . implode( ',', $sources ) . ') )'
			: '1=0';

		$sql = "( {$status_sql} OR {$staff_reserved_sql} )";
		return (string) apply_filters( 'g2ab_operational_booking_where', $sql, $alias, $statuses );
	}

	/**
	 * Runtime check for a booking array/object.
	 *
	 * @param array|object $booking Booking row.
	 * @return bool
	 */
	public static function is_operational( $booking ) {
		$row    = is_object( $booking ) ? get_object_vars( $booking ) : (array) $booking;
		$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		if ( in_array( $status, self::operational_statuses(), true ) ) {
			return true;
		}
		return 'reserved' === $status
			&& 'in_store' === sanitize_key( (string) ( $row['payment_mode'] ?? '' ) )
			&& in_array( sanitize_key( (string) ( $row['source'] ?? '' ) ), self::staff_sources(), true );
	}

	/**
	 * Non-operational statuses that belong on the Checkout Attempts /
	 * diagnostics screen (payment funnel visibility, never rosters).
	 *
	 * @return string[]
	 */
	public static function diagnostic_statuses() {
		return array( 'pending', 'payment_failed', 'expired', 'cancelled' );
	}

	/**
	 * Staff-facing status label.
	 *
	 * @param array|object $booking Booking row.
	 * @return string
	 */
	public static function status_label( $booking ) {
		$row    = is_object( $booking ) ? get_object_vars( $booking ) : (array) $booking;
		$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
		$mode   = sanitize_key( (string) ( $row['payment_mode'] ?? '' ) );

		if ( 'paid' === $status ) {
			return __( 'Payment Confirmed', 'g2a-booking' );
		}
		if ( 'confirmed' === $status ) {
			return __( 'Confirmed', 'g2a-booking' );
		}
		if ( 'completed' === $status ) {
			return __( 'Completed', 'g2a-booking' );
		}
		if ( 'partially_refunded' === $status ) {
			return __( 'Partially Refunded', 'g2a-booking' );
		}
		if ( 'pending' === $status ) {
			return __( 'Checkout Hold', 'g2a-booking' );
		}
		if ( 'reserved' === $status && 'in_store' === $mode ) {
			return __( 'Pay at Store', 'g2a-booking' );
		}
		return ucwords( str_replace( '_', ' ', $status ) );
	}
}
