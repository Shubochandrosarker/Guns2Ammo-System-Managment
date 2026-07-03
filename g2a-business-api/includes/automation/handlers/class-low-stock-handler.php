<?php
/**
 * Low-stock alert handler.
 *
 * Walks WooCommerce products that are managing stock and drafts an email
 * listing SKUs at or under threshold. Uses the same Woo helper Woo's own
 * admin low-stock report uses.
 *
 * When Woo is absent the handler no-ops with a diagnostic result string —
 * it does NOT throw.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Low_Stock_Handler extends Handler_Base {
	public static function slug(): string {
		return 'low-stock-alert';
	}

	public static function run(): string {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 'Skipped — WooCommerce is not active.';
		}

		$products = wc_get_products(
			array(
				'limit'          => 100,
				'status'         => 'publish',
				'manage_stock'   => true,
				'stock_status'   => array( 'instock', 'lowstock' ),
			)
		);
		if ( ! is_array( $products ) ) {
			return 'No product data returned.';
		}

		$hits = array();
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$threshold = self::threshold_for( $product );
			$qty       = self::qty_for( $product );
			if ( null === $qty || $qty > $threshold ) {
				continue;
			}
			$hits[] = array(
				'name'      => (string) $product->get_name(),
				'sku'       => (string) $product->get_sku(),
				'qty'       => $qty,
				'threshold' => $threshold,
			);
			if ( count( $hits ) >= 20 ) {
				break;
			}
		}

		if ( empty( $hits ) ) {
			return 'No products below threshold this run.';
		}

		( new Email_Draft_Store() )->enqueue(
			'Low-stock alert',
			'',
			sprintf( 'Low stock: %d SKU%s below threshold', count( $hits ), 1 === count( $hits ) ? '' : 's' ),
			self::compose_body( $hits )
		);

		return sprintf( 'Drafted low-stock alert for %d SKU%s.', count( $hits ), 1 === count( $hits ) ? '' : 's' );
	}

	/**
	 * @internal Exposed for tests.
	 * @param array<int, array{name:string,sku:string,qty:int,threshold:int}> $hits
	 */
	public static function compose_body( array $hits ): string {
		$lines = array( 'The following products are at or under their low-stock threshold:', '' );
		foreach ( $hits as $h ) {
			$lines[] = sprintf(
				'- %s (SKU %s): %d in stock (threshold %d)',
				$h['name'],
				'' === $h['sku'] ? 'n/a' : $h['sku'],
				$h['qty'],
				$h['threshold']
			);
		}
		$lines[] = '';
		$lines[] = 'Full inventory: https://app.guns2ammo.com/woo-store-analytics';
		return implode( "\n", $lines );
	}

	private static function threshold_for( \WC_Product $product ): int {
		// Product-level override wins; site-wide fallback second; 5 as last resort.
		if ( method_exists( $product, 'get_low_stock_amount' ) ) {
			$raw = $product->get_low_stock_amount();
			if ( '' !== $raw && null !== $raw ) {
				return max( 0, (int) $raw );
			}
		}
		$site = get_option( 'woocommerce_notify_low_stock_amount', 2 );
		return max( 0, (int) $site );
	}

	private static function qty_for( \WC_Product $product ): ?int {
		if ( ! $product->managing_stock() ) {
			return null;
		}
		$qty = $product->get_stock_quantity();
		return null === $qty ? null : (int) $qty;
	}
}
