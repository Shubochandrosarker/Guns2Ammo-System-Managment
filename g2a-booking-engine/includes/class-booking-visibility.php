<?php
/**
 * Canonical booking visibility policy for operational staff screens.
 *
 * Payment holds and abandoned checkout attempts belong in payment/attempt
 * tooling. The normal bookings roster should only show reservations that staff
 * can act on operationally.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Booking_Visibility {

	/**
	 * Statuses that are always operational.
	 *
	 * @return string[]
	 */
	public static function operational_statuses() {
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters(
			'g2ab_operational_booking_statuses',
			array( 'confirmed', 'paid', 'completed' )
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

		$sql = "( {$status_sql} OR ( {$alias}.status = 'reserved' AND {$alias}.payment_mode = 'in_store' ) )";
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
		return 'reserved' === $status && 'in_store' === sanitize_key( (string) ( $row['payment_mode'] ?? '' ) );
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
		if ( 'reserved' === $status && 'in_store' === $mode ) {
			return __( 'Pay at Store', 'g2a-booking' );
		}
		return ucwords( str_replace( '_', ' ', $status ) );
	}
}
