<?php

namespace G2A\POS\Domain;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\InventoryRepository;
use G2A\POS\Database\OrderRepository;
use G2A\POS\Database\QueueRepository;

defined( 'ABSPATH' ) || exit;

final class PaymentService {

	public static function collect( int $order_id, array $payload ): array {
		$repo  = new OrderRepository();
		$audit = new AuditLogRepository();
		$order = $repo->find_with_items( $order_id );
		if ( ! $order ) {
			return array( 'error' => 'Order not found.' );
		}

		$payment_status = sanitize_key( (string) ( $payload['payment_status'] ?? 'paid' ) );
		$method         = sanitize_key( (string) ( $payload['payment_method'] ?? 'cash' ) );
		$status         = sanitize_key( (string) ( $payload['status'] ?? $order['status'] ) );

		$has_firearm = false;
		foreach ( ( $order['items'] ?? array() ) as $item ) {
			if ( ( $item['item_type'] ?? '' ) === 'firearm' ) {
				$has_firearm = true;
				break;
			}
		}
		$compliance = sanitize_key( (string) ( $payload['compliance_state'] ?? $order['compliance_state'] ) );
		if ( $has_firearm && in_array( $payment_status, array( 'paid', 'completed' ), true ) && ! in_array( $compliance, array( 'approved', 'cleared' ), true ) ) {
			return array( 'error' => 'Firearm sale cannot be finalized until compliance is approved.' );
		}

		$repo->update_states(
			$order_id,
			array(
				'status'           => $status,
				'payment_status'   => $payment_status,
				'compliance_state' => $compliance,
				'payment_method'   => $method,
			)
		);

		if ( ! empty( $order['wc_order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( (int) $order['wc_order_id'] );
			if ( $wc ) {
				if ( $payment_status === 'paid' ) {
					$wc->payment_complete();
				}
				if ( $method !== '' ) {
					$wc->set_payment_method( $method );
					$wc->save();
				}
			}
		}

		$updated = $repo->find_with_items( $order_id );
		$audit->add(
			'payment.collect',
			'pos_order',
			(string) $order_id,
			$order,
			array(
				'payment_status'   => $payment_status,
				'payment_method'   => $method,
				'status'           => $status,
				'compliance_state' => $compliance,
			)
		);

		return array( 'order' => $updated );
	}

	public static function refund( int $order_id, array $payload ): array {
		$repo      = new OrderRepository();
		$audit     = new AuditLogRepository();
		$inventory = new InventoryRepository();
		$queue     = new QueueRepository();

		$order = $repo->find_with_items( $order_id );
		if ( ! $order ) {
			return array( 'error' => 'Order not found.' );
		}

		if ( ( $order['payment_status'] ?? '' ) === 'refunded' ) {
			return array( 'error' => 'Order is already refunded.' );
		}

		$restore_stock = isset( $payload['restore_stock'] )
			? (bool) $payload['restore_stock']
			: (bool) get_option( 'g2a_pos_refund_restore_stock', 1 );
		$source_ref    = 'pos_refund:' . $order_id;
		$location_id   = (int) ( $order['location_id'] ?? 1 );
		$notes         = sanitize_text_field( (string) ( $payload['notes'] ?? '' ) );

		if ( $restore_stock && ! $inventory->has_event_source( $source_ref, 'pos_refund_restock' ) ) {
			foreach ( ( $order['items'] ?? array() ) as $item ) {
				$product_id = (int) ( $item['variation_id'] ?: $item['product_id'] );
				if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
					continue;
				}

				$product = wc_get_product( $product_id );
				if ( ! $product || ! $product->managing_stock() ) {
					continue;
				}

				$qty = (float) ( $item['quantity'] ?? 0 );
				if ( $qty <= 0 ) {
					continue;
				}

				$before_qty = (float) ( $product->get_stock_quantity() ?? 0 );
				wc_update_product_stock( $product, $qty, 'increase' );
				$product   = wc_get_product( $product_id );
				$after_qty = (float) ( ( $product && $product->get_stock_quantity() !== null ) ? $product->get_stock_quantity() : ( $before_qty + $qty ) );

				$log_id = $inventory->log(
					array(
						'product_id'     => (int) ( $item['product_id'] ?? 0 ),
						'variation_id'   => isset( $item['variation_id'] ) ? (int) $item['variation_id'] : null,
						'serial_number'  => $item['serial_number'] ?? null,
						'location_id'    => $location_id,
						'event_type'     => 'pos_refund_restock',
						'quantity_delta' => $qty,
						'before_qty'     => $before_qty,
						'after_qty'      => $after_qty,
						'source_ref'     => $source_ref,
						'metadata'       => array(
							'pos_order_id' => $order_id,
							'note'         => $notes,
						),
					)
				);

				$queue->enqueue(
					'inventory_sync',
					array(
						'inventory_log_id' => $log_id,
						'product_id'       => (int) ( $item['product_id'] ?? 0 ),
						'location_id'      => $location_id,
						'after_qty'        => $after_qty,
					)
				);
			}
		}

		$repo->update_states(
			$order_id,
			array(
				'status'         => 'refunded',
				'payment_status' => 'refunded',
			)
		);

		if ( ! empty( $order['wc_order_id'] ) && function_exists( 'wc_get_order' ) ) {
			$wc = wc_get_order( (int) $order['wc_order_id'] );
			if ( $wc ) {
				if ( function_exists( 'wc_create_refund' ) ) {
					$existing = $wc->get_refunds();
					if ( ! $existing ) {
						wc_create_refund(
							array(
								'order_id'       => (int) $wc->get_id(),
								'amount'         => (float) ( $wc->get_total() ?: 0 ),
								'reason'         => $notes !== '' ? $notes : 'POS refund',
								'refund_payment' => false,
								'restock_items'  => false,
							)
						);
					}
				}
				$wc->update_status( 'refunded', 'Refunded by G2A POS.' );
			}
		}

		$updated = $repo->find_with_items( $order_id );
		$audit->add(
			'payment.refund',
			'pos_order',
			(string) $order_id,
			$order,
			array(
				'restore_stock'  => $restore_stock,
				'notes'          => $notes,
				'status'         => 'refunded',
				'payment_status' => 'refunded',
			)
		);

		return array( 'order' => $updated );
	}
}
