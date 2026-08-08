<?php

namespace G2A\POS\Domain;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\InventoryRepository;
use G2A\POS\Database\QueueRepository;

final class InventoryEngine {

	/**
	 * Apply a stock adjustment. The current on-hand quantity is read
	 * server-side inside a transaction (SELECT ... FOR UPDATE on the newest
	 * log row); any client-supplied before_qty/after_qty is ignored so the
	 * ledger can never be rebased from the request payload.
	 */
	public static function adjust( array $payload ): array {
		global $wpdb;

		$repo  = new InventoryRepository();
		$queue = new QueueRepository();
		$audit = new AuditLogRepository();

		$product_id  = (int) $payload['product_id'];
		$location_id = (int) $payload['location_id'];
		$delta       = (float) ( $payload['quantity_delta'] ?? 0 );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$before = $repo->latest_qty_locked( $product_id, $location_id );
			if ( $before === null ) {
				// First movement for this product/location — seed from the
				// authoritative WooCommerce stock count when available.
				$before = self::woo_stock_qty( $payload );
			}
			$after = $before + $delta;

			$log_id = $repo->log(
				array(
					'product_id'     => $product_id,
					'variation_id'   => $payload['variation_id'] ?? null,
					'serial_number'  => $payload['serial_number'] ?? null,
					'location_id'    => $location_id,
					'event_type'     => $payload['event_type'] ?? 'manual_adjustment',
					'quantity_delta' => $delta,
					'before_qty'     => $before,
					'after_qty'      => $after,
					'source_ref'     => $payload['source_ref'] ?? null,
					'metadata'       => $payload['metadata'] ?? array(),
				)
			);

			$queue->enqueue(
				'inventory_sync',
				array(
					'inventory_log_id' => $log_id,
					'product_id'       => $product_id,
					'location_id'      => $location_id,
					'after_qty'        => $after,
				)
			);

			$audit->add( 'inventory.adjust', 'inventory_log', (string) $log_id, array( 'before_qty' => $before ), array( 'after_qty' => $after ) );

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'error' => $e->getMessage() );
		}

		return array(
			'inventory_log_id' => $log_id,
			'before_qty'       => $before,
			'after_qty'        => $after,
		);
	}

	/** Current WooCommerce stock quantity for the adjusted product, or 0. */
	private static function woo_stock_qty( array $payload ): float {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return 0.0;
		}
		$product_id = (int) ( $payload['variation_id'] ?? 0 ) ?: (int) ( $payload['product_id'] ?? 0 );
		if ( $product_id <= 0 ) {
			return 0.0;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return 0.0;
		}

		return (float) ( $product->get_stock_quantity() ?? 0 );
	}
}
