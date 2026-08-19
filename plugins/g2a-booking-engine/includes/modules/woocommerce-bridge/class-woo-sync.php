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
		//
		// Status mapping (see docs/BOOKING-PAYMENT-POLICY.md):
		//   processing | completed  → booking paid/confirmed
		//   pending    | on-hold    → booking stays a pending_payment hold
		//   failed                  → booking payment_failed (retryable)
		//   cancelled               → booking cancelled, slot released
		//   refunded                → booking refunded (ledger-backed)
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed',  array( $this, 'on_paid' ), 10, 2 );
		add_action( 'woocommerce_order_status_on-hold',    array( $this, 'on_awaiting_payment' ), 10, 2 );
		add_action( 'woocommerce_order_status_pending',    array( $this, 'on_awaiting_payment' ), 10, 2 );
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

		// Only proceed when WooCommerce itself considers the order paid.
		if ( ! $order->is_paid() ) return;

		// Amount cross-check — mirror the native gateways: refuse to mark paid
		// when the order total doesn't match the booking total or deposit.
		$amount = (float) $order->get_total();
		if ( function_exists( 'g2ab_validate_payment_amount' ) && ! g2ab_validate_payment_amount( $booking, $amount ) ) {
			$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
				'booking_id' => (int) $booking_id,
				'event_type' => 'payment_amount_mismatch',
				'severity'   => 'warning',
				'message'    => sprintf( 'WooCommerce amount mismatch: paid=%s expected=%s', number_format( $amount, 2 ), number_format( (float) $booking->total_amount, 2 ) ),
				'context'    => wp_json_encode( array( 'wc_order_id' => $order_id ) ),
				'created_at' => current_time( 'mysql' ),
			) );
			$order->add_order_note( sprintf( 'G2A booking #%d NOT marked paid: order total %s does not match booking expected total %s — needs review.', $booking_id, number_format( $amount, 2 ), number_format( (float) $booking->total_amount, 2 ) ) );
			return;
		}

		// Ledger row FIRST: it is the payment evidence the transition service
		// requires before it will move a booking to `paid`. Idempotent on
		// (gateway, transaction_id), so a processing→completed sequence or a
		// replayed webhook updates one row instead of inserting duplicates.
		$payments_table = $wpdb->prefix . 'g2ab_payments';
		$payment_row    = array(
			'booking_id'     => $booking_id,
			'gateway'        => 'woocommerce',
			'transaction_id' => 'wc_' . $order_id,
			'amount'         => $amount,
			'currency'       => strtoupper( (string) $order->get_currency() ),
			'status'         => 'succeeded',
			'payment_method' => (string) $order->get_payment_method(),
			'metadata'       => wp_json_encode( array( 'wc_order_id' => (int) $order_id ) ),
			'processed_at'   => current_time( 'mysql' ),
			'created_at'     => current_time( 'mysql' ),
		);
		$payment_formats = array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' );
		$payment_id      = function_exists( 'g2ab_insert_or_update_payment' )
			? (int) g2ab_insert_or_update_payment( $payments_table, $payment_row, $payment_formats )
			: 0;
		if ( ! $payment_id ) {
			$wpdb->insert( $payments_table, $payment_row, $payment_formats );
			$payment_id = (int) $wpdb->insert_id;
		}

		$previous_status = (string) $booking->status;

		// The transition service enforces the invariants (a successful ledger
		// entry with matching amount + currency), writes the audit record with
		// the previous status, and fires g2ab_booking_status_changed.
		if ( class_exists( 'G2AB_Booking_Transitions' ) ) {
			$result = G2AB_Booking_Transitions::transition( $booking_id, 'paid', array(
				'source'      => 'woocommerce',
				'reason'      => 'wc_order_paid_' . (int) $order_id,
				'payment_id'  => $payment_id,
				'paid_amount' => $amount,
			) );
			if ( is_wp_error( $result ) ) {
				$order->add_order_note( sprintf(
					'G2A booking #%d could not be marked paid: %s',
					$booking_id,
					$result->get_error_message()
				) );
				return;
			}
		} else {
			// No transition service means the plugin is not fully loaded. The
			// old fallback wrote `paid` straight to the row, bypassing the
			// payment-evidence invariant — the one guard that makes a paid
			// booking mean money was collected. Refuse instead: an order that
			// cannot be recorded safely is a support ticket, never a silent
			// state change.
			$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
				'booking_id' => (int) $booking_id,
				'event_type' => 'payment_transition_unavailable',
				'severity'   => 'error',
				'message'    => 'G2AB_Booking_Transitions missing; refused to mark booking paid from WooCommerce order ' . (int) $order_id,
				'context'    => wp_json_encode( array( 'wc_order_id' => (int) $order_id, 'payment_id' => $payment_id ) ),
				'created_at' => current_time( 'mysql' ),
			) );
			$order->add_order_note( sprintf(
				'G2A booking #%d was NOT marked paid: the booking engine\'s transition service is unavailable, so payment state could not be verified. The payment is recorded on the ledger — settle the booking from Front Desk once the plugin is healthy.',
				$booking_id
			) );
			return;
		}

		// Full paid side-effect sequence (account provisioning, Range Guest
		// classification, g2ab_booking_paid, confirmation email) — deduped by
		// gateway+booking+transaction so repeated order-status hooks are safe.
		if ( function_exists( 'g2ab_queue_booking_paid_side_effects' ) ) {
			g2ab_queue_booking_paid_side_effects( $booking_id, 'woocommerce', $previous_status, array( 'id' => 'wc_' . $order_id ) );
		} else {
			$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bk} WHERE id = %d", $booking_id ) ); // phpcs:ignore
			do_action( 'g2ab_booking_paid', $booking, array( 'wc_order_id' => $order_id ) );
		}
	}

	/**
	 * Order is awaiting payment (pending / on-hold): the booking must stay a
	 * non-operational checkout hold. Never confirm from these statuses.
	 */
	public function on_awaiting_payment( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;

		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", (int) $booking_id ) ); // phpcs:ignore
		// Only revive a previously failed attempt; never downgrade a paid or
		// confirmed booking because WooCommerce re-emitted an early status.
		if ( $booking && 'payment_failed' === (string) $booking->status ) {
			$this->transition( $booking_id, 'pending', 'wc_order_awaiting_payment_' . (int) $order_id );
		}
	}

	public function on_cancelled( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;
		$this->transition( $booking_id, 'cancelled', 'wc_order_cancelled_' . (int) $order_id );
		global $wpdb;
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d", $booking_id ) ); // phpcs:ignore
		do_action( 'g2ab_booking_cancelled', $booking, array( 'wc_order_id' => $order_id, 'reason' => 'wc_order_cancelled' ) );
	}

	/**
	 * WooCommerce recorded a refund. Mirror it onto the payment ledger first so
	 * the transition service has the refund evidence its invariant requires,
	 * then move the booking (partial refunds keep the customer attending).
	 */
	public function on_refunded( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;

		global $wpdb;
		$pt      = $wpdb->prefix . 'g2ab_payments';
		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$pt} WHERE booking_id = %d AND gateway = 'woocommerce' ORDER BY id DESC LIMIT 1", // phpcs:ignore
			(int) $booking_id
		) );

		$refunded = abs( (float) $order->get_total_refunded() );
		$paid     = $payment ? (float) $payment->amount : (float) $order->get_total();
		$is_full  = $refunded >= round( $paid, 2 ) - 0.01;

		if ( $payment ) {
			$wpdb->update(
				$pt,
				array( 'status' => $is_full ? 'refunded' : 'partial_refund', 'refund_amount' => $refunded ),
				array( 'id' => (int) $payment->id ),
				array( '%s', '%f' ),
				array( '%d' )
			);
		}

		$this->transition(
			$booking_id,
			$is_full ? 'refunded' : 'partially_refunded',
			'wc_order_refunded_' . (int) $order_id,
			$payment ? (int) $payment->id : 0
		);
	}

	/**
	 * Payment was attempted and declined. The booking becomes an explicit
	 * `payment_failed` record — never a silent pending row that looks like a
	 * live hold — and the slot is released. The customer can retry through the
	 * resume-payment flow, which re-checks availability.
	 */
	public function on_failed( $order_id, $order ) {
		$booking_id = $this->get_booking_id_from_order( $order );
		if ( ! $booking_id ) return;
		$this->transition( $booking_id, 'payment_failed', 'wc_order_failed_' . (int) $order_id );
	}

	/**
	 * Route a booking status change through the central transition service so
	 * invariants, audit logging and status hooks all apply. Falls back to a
	 * direct write only when the service is unavailable.
	 */
	private function transition( $booking_id, $status, $reason, $payment_id = 0 ) {
		if ( class_exists( 'G2AB_Booking_Transitions' ) ) {
			return G2AB_Booking_Transitions::transition( (int) $booking_id, $status, array(
				'source'     => 'woocommerce',
				'reason'     => $reason,
				'payment_id' => (int) $payment_id,
			) );
		}
		global $wpdb;
		return $wpdb->update(
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
