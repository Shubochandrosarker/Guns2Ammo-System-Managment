<?php
/**
 * Bidirectional sync: WC order status ↔ booking status.
 *
 * @package G2AB\Modules\WooBridge
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Woo_Sync {

	public function __construct() {
		// WC order status changes → update booking.
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed',  array( $this, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_cancelled',  array( $this, 'on_cancelled' ), 10, 2 );
		add_action( 'woocommerce_order_status_refunded',   array( $this, 'on_refunded' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed',     array( $this, 'on_failed' ), 10, 2 );

		// Booking status changes → reflect in WC order (admin manual updates).
		add_action( 'g2ab_booking_cancelled', array( $this, 'on_booking_cancelled' ), 10, 2 );
		add_action( 'g2ab_booking_completed', array( $this, 'on_booking_completed' ), 10, 2 );

		// Show booking link in WC order admin.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_booking_meta' ), 10, 1 );
	}

	private function get_booking_id_from_order( $order ) {
		if ( ! $order instanceof WC_Order ) return 0;
		$id = (int) $order->get_meta( G2AB_Gateway_Woocommerce::META_BOOKING_ID );
		return $id;
	}

	public function on_paid( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;

		global $wpdb;
		$bk = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bk} WHERE id = %d", $booking_id ) ); // phpcs:ignore
		if ( ! $booking || in_array( $booking->status, array( 'paid', 'completed' ), true ) ) return;

		$wpdb->update(
			$bk,
			array( 'status' => 'paid', 'amount' => (float) $order->get_total(), 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $booking_id ),
			array( '%s', '%f', '%s' ), array( '%d' )
		);

		// Record payment row.
		$wpdb->insert(
			$wpdb->prefix . 'g2ab_payments',
			array(
				'booking_id'   => $booking_id,
				'gateway'      => 'woocommerce',
				'transaction_id' => 'wc_' . $order_id,
				'amount'       => (float) $order->get_total(),
				'currency'     => $order->get_currency(),
				'status'       => 'paid',
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%f', '%s', '%s', '%s' )
		);

		// Re-fetch + fire booking_paid event.
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bk} WHERE id = %d", $booking_id ) ); // phpcs:ignore
		do_action( 'g2ab_booking_paid', $booking, array( 'wc_order_id' => $order_id ) );
	}

	public function on_cancelled( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;
		$this->set_status( $booking_id, 'cancelled' );
		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", $booking_id ) ); // phpcs:ignore
		do_action( 'g2ab_booking_cancelled', $booking, array( 'wc_order_id' => $order_id, 'reason' => 'wc_order_cancelled' ) );
	}

	public function on_refunded( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;
		$this->set_status( $booking_id, 'refunded' );
	}

	public function on_failed( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;
		// Keep booking as 'pending' — customer may retry.
	}

	private function set_status( $booking_id, $status ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $booking_id ),
			array( '%s', '%s' ), array( '%d' )
		);
	}

	public function on_booking_cancelled( $booking, $context = array() ) {
		// Avoid loop if cancellation came from WC.
		if ( ! empty( $context['wc_order_id'] ) ) return;
		$order = $this->find_order_by_booking( is_object( $booking ) ? $booking->id : ( $booking['id'] ?? 0 ) );
		if ( $order && ! in_array( $order->get_status(), array( 'cancelled', 'refunded' ), true ) ) {
			$order->update_status( 'cancelled', 'Booking cancelled in G2A.' );
		}
	}

	public function on_booking_completed( $booking, $context = array() ) {
		$order = $this->find_order_by_booking( is_object( $booking ) ? $booking->id : ( $booking['id'] ?? 0 ) );
		if ( $order && 'completed' !== $order->get_status() ) {
			$order->update_status( 'completed', 'Booking marked completed in G2A.' );
		}
	}

	private function find_order_by_booking( $booking_id ) {
		if ( ! $booking_id ) return null;
		$orders = wc_get_orders( array(
			'limit'      => 1,
			'meta_key'   => G2AB_Gateway_Woocommerce::META_BOOKING_ID,
			'meta_value' => (int) $booking_id,
		) );
		return ! empty( $orders ) ? $orders[0] : null;
	}

	public function render_booking_meta( $order ) {
		$booking_uuid = $order->get_meta( G2AB_Gateway_Woocommerce::META_BOOKING_UUID );
		$booking_id   = (int) $order->get_meta( G2AB_Gateway_Woocommerce::META_BOOKING_ID );
		if ( ! $booking_uuid && ! $booking_id ) return;
		$edit_url = admin_url( 'admin.php?page=g2ab-bookings&action=edit&id=' . $booking_id );
		echo '<p style="margin-top:20px;border-top:1px solid #eee;padding-top:14px;"><strong>G2A Booking</strong><br>';
		echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $booking_uuid ) . '</a></p>';
	}
}
