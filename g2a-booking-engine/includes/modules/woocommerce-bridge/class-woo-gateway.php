<?php
/**
 * WooCommerce gateway adapter for G2A Booking.
 *
 * Implements the same gateway interface (id/label/is_available/create_intent)
 * but instead of charging directly, it creates a WooCommerce order with the
 * booking as a virtual product and redirects the customer to WC checkout.
 *
 * @package G2AB\Modules\WooBridge
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Gateway_Woocommerce {

	const META_BOOKING_ID  = '_g2ab_booking_id';
	const META_BOOKING_UUID = '_g2ab_booking_uuid';
	const OPT_PRODUCT_ID   = 'g2ab_woo_product_id';

	public function id() { return 'woocommerce'; }
	public function label() { return 'WooCommerce Checkout'; }
	public function supports() { return array( 'cards', 'wallets', 'manual', 'multi_gateway' ); }

	public function is_available() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Create a WooCommerce order for the booking and return a redirect URL
	 * to WC checkout (pay-for-order page).
	 *
	 * @param object $booking Booking row (with uuid, id, amount, etc.)
	 * @param float  $amount  Amount due.
	 * @return array|WP_Error { redirect_url, order_id }
	 */
	public function create_intent( $booking, $amount ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'wc_not_active', 'WooCommerce is not active.' );
		}

		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return new WP_Error( 'invalid_amount', 'Booking amount must be positive.' );
		}

		// Reuse existing order if present (idempotent).
		$existing = $this->find_order_for_booking( $booking->id );
		if ( $existing ) {
			return array(
				'redirect_url' => $existing->get_checkout_payment_url( true ),
				'order_id'     => $existing->get_id(),
			);
		}

		try {
			$order = wc_create_order();
			if ( is_wp_error( $order ) ) return $order;

			// Customer info from booking form_data. The bookings table column is
			// `form_data` (LONGTEXT JSON) — there is no `fields` column. We also
			// prefer the canonical customer_* columns when present, since
			// form_data carries arbitrary form-builder keys that vary per form.
			$form = array();
			if ( ! empty( $booking->form_data ) ) {
				$decoded = is_string( $booking->form_data )
					? json_decode( $booking->form_data, true )
					: ( is_array( $booking->form_data ) ? $booking->form_data : (array) $booking->form_data );
				if ( is_array( $decoded ) ) $form = $decoded;
			}

			$first_name = $form['first_name'] ?? '';
			$last_name  = $form['last_name'] ?? '';
			if ( ! $first_name && ! empty( $form['name'] ) ) {
				$parts = explode( ' ', trim( $form['name'] ), 2 );
				$first_name = $parts[0];
				$last_name  = $parts[1] ?? '';
			}
			if ( ! $first_name && ! empty( $booking->customer_name ) ) {
				$parts      = explode( ' ', trim( (string) $booking->customer_name ), 2 );
				$first_name = $parts[0];
				$last_name  = $last_name ?: ( $parts[1] ?? '' );
			}
			$email = $booking->customer_email ?? ( $form['email'] ?? '' );
			$phone = $booking->customer_phone ?? ( $form['phone'] ?? '' );

			$address = array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'email'      => $email,
				'phone'      => $phone,
			);
			$order->set_address( $address, 'billing' );

			// Booking line item (uses placeholder product OR ad-hoc fee).
			$product_id = (int) get_option( self::OPT_PRODUCT_ID, 0 );
			$product = $product_id ? wc_get_product( $product_id ) : null;

			if ( $product ) {
				$item_id = $order->add_product( $product, 1, array(
					'subtotal' => $amount,
					'total'    => $amount,
				) );
				$item = $order->get_item( $item_id );
				$item->set_name( sprintf( '%s — %s', $booking->resource_name ?? 'Booking', $this->format_dt( $booking->start_at ?? '' ) ) );
				$item->save();
			} else {
				// Fallback: add as fee.
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( sprintf( '%s — %s', $booking->resource_name ?? 'Booking', $this->format_dt( $booking->start_at ?? '' ) ) );
				$fee->set_amount( $amount );
				$fee->set_total( $amount );
				$fee->set_tax_status( 'taxable' );
				$order->add_item( $fee );
			}

			// Link order to booking.
			$order->update_meta_data( self::META_BOOKING_ID, (int) $booking->id );
			$order->update_meta_data( self::META_BOOKING_UUID, $booking->uuid );
			$order->set_customer_note( sprintf( 'Booking confirmation: %s', $booking->uuid ) );

			$order->calculate_totals();
			$order->update_status( 'pending', 'G2A Booking awaiting payment.' );

			// Save order id to booking row for back-reference.
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'g2ab_bookings',
				array( 'gateway_intent_id' => 'wc_order:' . $order->get_id() ),
				array( 'id' => (int) $booking->id ),
				array( '%s' ), array( '%d' )
			);

			return array(
				'redirect_url' => $order->get_checkout_payment_url( true ),
				'order_id'     => $order->get_id(),
			);

		} catch ( \Throwable $e ) {
			error_log( '[G2AB Woo] order create failed: ' . $e->getMessage() );
			return new WP_Error( 'wc_order_failed', 'Could not create WooCommerce order: ' . $e->getMessage() );
		}
	}

	private function find_order_for_booking( $booking_id ) {
		$orders = wc_get_orders( array(
			'limit'      => 1,
			'meta_key'   => self::META_BOOKING_ID,
			'meta_value' => (int) $booking_id,
			'status'     => array( 'pending', 'on-hold' ),
		) );
		return ! empty( $orders ) ? $orders[0] : null;
	}

	private function format_dt( $iso ) {
		if ( empty( $iso ) ) return '';
		$ts = strtotime( $iso );
		return $ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $iso;
	}
}
