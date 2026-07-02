<?php

namespace G2A\POS\Domain;

final class WooBridge {

	public static function create_order( array $payload ): ?int {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return null;
		}

		$order = wc_create_order(
			array(
				'customer_id' => (int) ( $payload['customer_id'] ?? 0 ),
				'created_via' => 'g2a_pos',
			)
		);

		if ( is_wp_error( $order ) ) {
			return null;
		}

		foreach ( ( $payload['items'] ?? array() ) as $item ) {
			$product_id = (int) ( $item['variation_id'] ?? $item['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			$qty = (float) ( $item['quantity'] ?? 1 );
			$order->add_product( $product, $qty );
		}

		$method = sanitize_text_field( (string) ( $payload['payment_method'] ?? '' ) );
		if ( $method !== '' ) {
			$order->set_payment_method( $method );
		}

		$order->calculate_totals();
		// Keep draft-like lifecycle until POS payment/compliance completion confirms final state.
		$order->set_status( 'pending', 'Created by G2A POS' );
		$order->save();

		return (int) $order->get_id();
	}

	public static function reduce_stock( array $items ): void {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_update_product_stock' ) ) {
			// WooCommerce inactive — nothing to decrement; POS order creation must not hard-fail.
			return;
		}
		foreach ( $items as $item ) {
			$product_id = (int) ( $item['variation_id'] ?? $item['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
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

			// Clamp the decrement so managed stock never goes negative; log the shortfall.
			$available = (float) ( $product->get_stock_quantity() ?? 0 );
			if ( $qty > $available ) {
				\G2A\POS\Support\Logger::warning(
					'POS stock decrement clamped to available quantity',
					array(
						'product_id' => $product_id,
						'requested'  => $qty,
						'available'  => $available,
					)
				);
				$qty = max( 0.0, $available );
				if ( $qty <= 0 ) {
					continue;
				}
			}

			wc_update_product_stock( $product, $qty, 'decrease' );
		}
	}
}
