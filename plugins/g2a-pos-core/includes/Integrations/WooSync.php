<?php

namespace G2A\POS\Integrations;

use G2A\POS\Database\InventoryRepository;

defined( 'ABSPATH' ) || exit;

final class WooSync {

	public static function boot(): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		add_action( 'woocommerce_order_status_processing', array( self::class, 'handle_sale' ), 20 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'handle_sale' ), 20 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'handle_restock' ), 20 );
		add_action( 'woocommerce_order_status_refunded', array( self::class, 'handle_restock' ), 20 );
	}

	public static function handle_sale( int $order_id ): void {
		self::sync_order( $order_id, 'online_sale', -1 );
	}

	public static function handle_restock( int $order_id ): void {
		self::sync_order( $order_id, 'online_restock', 1 );
	}

	private static function sync_order( int $order_id, string $event_type, int $direction ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// POS-created Woo orders are already represented in POS flows.
		if ( $order->get_created_via() === 'g2a_pos' ) {
			return;
		}

		$repo        = new InventoryRepository();
		$location_id = (int) get_option( 'g2a_pos_default_location_id', 1 );
		$source_ref  = 'wc_order:' . $order_id;

		if ( $repo->has_event_source( $source_ref, $event_type ) ) {
			return;
		}

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			if ( ! $product->managing_stock() ) {
				continue;
			}

			$product_id = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
			$qty        = (float) $item->get_quantity();
			if ( $qty <= 0 ) {
				continue;
			}

			$delta      = $qty * $direction;
			$after_qty  = (float) ( $product->get_stock_quantity() ?? 0 );
			$before_qty = $after_qty - $delta;

			$repo->log(
				array(
					'product_id'     => $product_id,
					'variation_id'   => $item->get_variation_id() ?: null,
					'location_id'    => $location_id,
					'event_type'     => $event_type,
					'quantity_delta' => $delta,
					'before_qty'     => $before_qty,
					'after_qty'      => $after_qty,
					'source_ref'     => $source_ref,
					'metadata'       => array(
						'channel'      => 'online',
						'order_status' => $order->get_status(),
					),
				)
			);
		}
	}
}
