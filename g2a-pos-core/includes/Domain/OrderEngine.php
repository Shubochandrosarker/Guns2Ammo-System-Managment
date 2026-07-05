<?php

namespace G2A\POS\Domain;

use G2A\POS\Compliance\ATF\BoundBook;
use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\OrderRepository;
use G2A\POS\Database\QueueRepository;
use G2A\POS\Database\RegisterSessionRepository;

final class OrderEngine {

	public static function create( array $payload ): array {
		global $wpdb;

		$items = $payload['items'] ?? array();
		if ( ! $items ) {
			return array( 'error' => 'Order items are required.' );
		}

		$repo      = new OrderRepository();
		$queue     = new QueueRepository();
		$audit     = new AuditLogRepository();
		$registers = new RegisterSessionRepository();

		$register_code = sanitize_text_field( (string) ( $payload['register_code'] ?? '' ) );
		$location_id   = (int) ( $payload['location_id'] ?? 1 );
		$actor_id      = get_current_user_id();
		if ( $register_code === '' ) {
			return array( 'error' => 'register_code is required.' );
		}

		$open_session = $registers->get_open_session( $register_code, $location_id );
		if ( ! $open_session ) {
			return array( 'error' => 'No open register session for this register/location.' );
		}
		if ( (int) $open_session['opened_by'] !== $actor_id && ! current_user_can( 'g2a_pos_manage_settings' ) && ! current_user_can( 'manage_options' ) ) {
			return array( 'error' => 'Current user is not assigned to this open register session.' );
		}

		// Compliance state is never accepted from the request payload — new
		// orders always start server-side at pending_review; the dedicated
		// compliance endpoints (g2a_pos_manage_compliance) move it forward.
		$compliance_state  = 'pending_review';
		$payment_status    = sanitize_key( (string) ( $payload['payment_status'] ?? 'pending' ) );
		$finalizing_states = array( 'paid', 'completed' );
		$has_firearm       = false;
		$subtotal          = 0.0;
		foreach ( $items as $idx => $item ) {
			$is_firearm = ( ( $item['item_type'] ?? '' ) === 'firearm' );
			if ( $is_firearm ) {
				$has_firearm = true;
			}
			$serial = sanitize_text_field( (string) ( $item['serial_number'] ?? '' ) );
			if ( $is_firearm && $serial === '' ) {
				return array( 'error' => 'serial_number is required for firearm items.' );
			}
			if ( $is_firearm && $repo->serial_already_sold( $serial ) ) {
				return array( 'error' => 'serial_number has already been sold in another POS order.' );
			}
			// Recompute line totals server-side; clerks may override unit_price
			// but the extension (unit_price × qty) is never trusted from the client.
			$unit_price                  = (float) ( $item['unit_price'] ?? 0 );
			$qty                         = (float) ( $item['quantity'] ?? 1 );
			$items[ $idx ]['line_total'] = round( $unit_price * $qty, 2 );
			$subtotal                   += $items[ $idx ]['line_total'];
		}
		$subtotal = round( $subtotal, 2 );
		// Discounts are legitimately clerk-entered but never exceed the
		// subtotal; tax is always computed server-side (client value ignored).
		$discount_total = min( $subtotal, max( 0.0, (float) ( $payload['discount_total'] ?? 0 ) ) );
		$tax_total      = TaxService::tax_for_lines( $items, $discount_total );
		$grand_total    = round( $subtotal + $tax_total - $discount_total, 2 );

		if ( $has_firearm && ! current_user_can( 'g2a_pos_process_firearm_sale' ) ) {
			return array( 'error' => 'User lacks capability to process firearm sales.' );
		}

		if ( $has_firearm && in_array( $payment_status, $finalizing_states, true ) && ! in_array( $compliance_state, array( 'approved', 'cleared' ), true ) ) {
			return array( 'error' => 'Firearm sale cannot be finalized until compliance_state is approved.' );
		}

		$wc_order_id = null;
		$order_id    = 0;
		$saved       = null;
		try {
			$wpdb->query( 'START TRANSACTION' );

			$wc_order_id = WooBridge::create_order(
				array(
					'customer_id'    => $payload['customer_id'] ?? null,
					'items'          => $items,
					'payment_method' => $payload['payment_method'] ?? '',
					'discount_total' => $discount_total,
					'grand_total'    => $grand_total,
				)
			);

			if ( ! $wc_order_id ) {
				WooBridge::reduce_stock( $items );
			}

			$order_id = $repo->create(
				array(
					'wc_order_id'         => $wc_order_id ?: ( $payload['wc_order_id'] ?? null ),
					'register_session_id' => (int) $open_session['id'],
					'customer_id'         => $payload['customer_id'] ?? null,
					'employee_id'         => $actor_id,
					'location_id'         => $location_id,
					'status'              => $payload['status'] ?? 'open',
					'subtotal'            => $subtotal,
					'tax_total'           => $tax_total,
					'discount_total'      => $discount_total,
					'grand_total'         => $grand_total,
					'payment_status'      => $payment_status,
					'payment_method'      => $payload['payment_method'] ?? '',
					'compliance_state'    => $compliance_state,
				),
				$items
			);

			if ( $order_id <= 0 ) {
				throw new \RuntimeException( 'Unable to create POS order.' );
			}

			$queue->enqueue( 'crm_sync', array( 'pos_order_id' => $order_id ) );
			$queue->enqueue( 'compliance_screening', array( 'pos_order_id' => $order_id ) );

			$audit->add( 'order.create', 'pos_order', (string) $order_id, null, $payload );
			$saved = $repo->find_with_items( $order_id );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			if ( ! empty( $wc_order_id ) && function_exists( 'wc_get_order' ) ) {
				$wc_order = wc_get_order( (int) $wc_order_id );
				if ( $wc_order ) {
					$wc_order->update_status( 'cancelled', 'POS order create failed; auto-cancelled for consistency.' );
				}
			}
			return array( 'error' => $e->getMessage() );
		}

		// Counter sales that arrive already paid must not leave the WC order
		// sitting in 'pending' — payment_complete() moves it forward and lets
		// WooCommerce decrement stock (guarded internally against re-running).
		if ( $wc_order_id && in_array( $payment_status, $finalizing_states, true ) ) {
			WooBridge::complete_payment( (int) $wc_order_id );
		}

		return array(
			'pos_order_id'        => $order_id,
			'wc_order_id'         => $wc_order_id,
			'register_session_id' => (int) $open_session['id'],
			'compliance_state'    => $saved['compliance_state'] ?? $compliance_state,
			'order'               => $saved,
		);
	}

	public static function record_firearm_disposition( int $pos_order_id, ?int $form_4473_id = null ): array {
		$repo  = new OrderRepository();
		$order = $repo->find_with_items( $pos_order_id );
		if ( ! $order ) {
			return array( 'error' => 'POS order not found.' );
		}
		$created = array();
		foreach ( ( $order['items'] ?? array() ) as $item ) {
			if ( ( $item['item_type'] ?? '' ) !== 'firearm' || empty( $item['serial_number'] ) ) {
				continue;
			}
			$r = BoundBook::record_disposition(
				array(
					'serial_number'     => $item['serial_number'],
					'disp_date'         => current_time( 'Y-m-d' ),
					'disp_form4473_id'  => $form_4473_id,
					'disp_pos_order_id' => $pos_order_id,
					'disp_method'       => 'over_counter',
				)
			);
			if ( ! isset( $r['error'] ) ) {
				$created[] = $r['id'];
			}
		}
		return array( 'disposition_ids' => $created );
	}
}
