<?php
/**
 * Booking ↔ waiver bridge.
 *
 * Answers the G2A Booking Engine's `g2ab_waiver_satisfied` filter: when a
 * booking type requires a waiver and the customer didn't tick the form
 * checkbox, we satisfy it automatically if they already have a signed waiver
 * on file (matched by email, then name) — so returning customers never
 * re-sign at booking time.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Waivers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Waiver_Booking_Bridge {

	public static function register() {
		add_filter( 'g2ab_waiver_satisfied', array( __CLASS__, 'satisfy' ), 10, 3 );
	}

	/**
	 * @param bool  $ok           Whether the booking form's waiver checkbox was ticked.
	 * @param array $fields       Submitted booking fields.
	 * @param mixed $booking_type Booking type row (unused).
	 * @return bool
	 */
	public static function satisfy( $ok, $fields, $booking_type ) {
		if ( $ok ) {
			return true;
		}
		$fields = is_array( $fields ) ? $fields : array();
		$email  = isset( $fields['customer_email'] ) ? (string) $fields['customer_email'] : '';
		$name   = isset( $fields['customer_name'] ) ? (string) $fields['customer_name'] : '';
		if ( '' === $email && '' === $name ) {
			return $ok;
		}
		return Waivers_Archive::has_on_file( $email, $name ) ? true : (bool) $ok;
	}
}
