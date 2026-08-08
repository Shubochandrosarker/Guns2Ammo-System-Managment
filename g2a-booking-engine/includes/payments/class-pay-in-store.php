<?php
/**
 * Pay-In-Store gateway — customer reserves now, pays at the front desk.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Gateway_Pay_In_Store {

	public function id() { return 'pay_in_store'; }
	public function label() { return __( 'Pay In Store', 'g2a-booking' ); }
	public function supports() { return array( 'reservation' ); }
	public function is_available() { return 1 === (int) get_option( 'g2ab_pay_in_store_enabled', 1 ); }

	/**
	 * No external intent — just mark booking as 'reserved' and return a no-op response.
	 *
	 * STAFF ONLY: pay-at-store is a back-office workflow (front desk, phone,
	 * POS). A public web checkout can never settle here — the checkout policy
	 * rejects the gateway upstream, and this guard makes the invariant hold
	 * even for a crafted call path.
	 *
	 * @param object $booking Booking row.
	 * @param float  $amount  Amount.
	 * @return array|WP_Error
	 */
	public function create_intent( $booking, $amount ) {
		global $wpdb;

		$staff_context = current_user_can( 'manage_g2ab_bookings' )
			|| ( class_exists( 'G2AB_Booking_Visibility' )
				&& in_array( sanitize_key( (string) ( $booking->source ?? '' ) ), G2AB_Booking_Visibility::staff_sources(), true ) );
		if ( ! $staff_context ) {
			return new WP_Error(
				'g2ab_gateway_not_allowed',
				__( 'Pay-at-store is available for staff-created bookings only.', 'g2a-booking' ),
				array( 'status' => 403 )
			);
		}
		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'status' => 'reserved', 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $booking->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// Insert payment row tracking the reservation.
		$wpdb->insert(
			$wpdb->prefix . 'g2ab_payments',
			array(
				'booking_id' => (int) $booking->id,
				'gateway' => 'pay_in_store',
				'amount' => (float) $amount,
				'currency' => $booking->currency ?? 'USD',
				'status' => 'pending',
				'payment_method' => 'pay_in_store',
				'metadata' => wp_json_encode( array( 'note' => 'Reserved — pay at front desk' ) ),
				'created_at' => current_time( 'mysql' ),
			)
		);
		do_action( 'g2ab_payment_intent_created', 'pay_in_store', $booking->id, $amount );
		return array(
			'gateway' => 'pay_in_store',
			'gateway_ref' => '',
			'redirect_url' => '',
			'message' => get_option( 'g2ab_pay_in_store_message', __( 'Your spot is held. Pay at the front desk on arrival.', 'g2a-booking' ) ),
		);
	}

	public function confirm_payment( $gateway_ref, $context = array() ) { return false; /* manual confirm by staff */ }
	public function refund( $transaction_id, $amount ) { return array( 'success' => true, 'refunded' => $amount ); }
	public function verify_webhook( $body, $headers ) { return new WP_Error( 'g2ab_no_webhook', 'Pay In Store has no webhook.' ); }
}
