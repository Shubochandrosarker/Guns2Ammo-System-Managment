<?php

namespace G2A\POS\Wholesalers\Routing;

use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerRepository;

/**
 * Picks the best wholesaler to fulfill a UPC: in-stock first, cheapest cost next.
 *
 * Returns a ranked list and the recommended pick. Used both:
 *  - directly by the cart/POS to advise on drop-ship routing, and
 *  - implicitly by submitDropShipOrder when the caller omits wholesaler_id but
 *    provides a UPC.
 */
final class MultiVendorRouter {

	public static function routeByUpc( string $upc, int $quantity = 1, array $opts = array() ): array {
		$upc = trim( $upc );
		if ( $upc === '' ) {
			return array(
				'ok'    => false,
				'error' => 'no_upc',
			);
		}

		global $wpdb;
		$t = ( new WholesalerProductRepository() )->productsTable();
		// Pull every wholesaler row for this UPC.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE upc=%s",
				$upc
			),
			ARRAY_A
		) ?: array();

		if ( ! $rows ) {
			return array(
				'ok'         => false,
				'error'      => 'no_wholesaler_carries_this_upc',
				'candidates' => array(),
			);
		}

		$wholesalers  = self::indexWholesalers();
		$dropshipOnly = (bool) ( $opts['dropship_only'] ?? false );
		$minQty       = (int) ( $opts['min_qty'] ?? $quantity );

		$candidates = array();
		foreach ( $rows as $row ) {
			$w = $wholesalers[ (int) $row['wholesaler_id'] ] ?? null;
			if ( ! $w || ( $w['status'] ?? '' ) !== 'active' ) {
				continue;
			}
			if ( $dropshipOnly && empty( $row['can_dropship'] ) ) {
				continue;
			}
			$stock = (int) ( $row['stock_qty'] ?? 0 );
			if ( $stock < $minQty ) {
				continue;
			}
			$price        = isset( $row['current_price'] ) && $row['current_price'] !== null && $row['current_price'] !== ''
				? (float) $row['current_price']
				: ( isset( $row['wholesale_price'] ) ? (float) $row['wholesale_price'] : INF );
			$candidates[] = array(
				'wholesaler_id' => (int) $row['wholesaler_id'],
				'provider_code' => (string) $w['provider_code'],
				'display_name'  => (string) $w['display_name'],
				'vendor_sku'    => (string) $row['vendor_sku'],
				'stock_qty'     => $stock,
				'price'         => is_finite( $price ) ? round( $price, 2 ) : null,
				'map_price'     => isset( $row['map_price'] ) && $row['map_price'] !== null ? (float) $row['map_price'] : null,
				'can_dropship'  => (bool) ( $row['can_dropship'] ?? false ),
			);
		}

		if ( ! $candidates ) {
			return array(
				'ok'                 => false,
				'error'              => 'no_qualifying_candidate',
				'candidates'         => array(),
				'total_known_offers' => count( $rows ),
			);
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				// Drop-ship eligible first.
				if ( $a['can_dropship'] !== $b['can_dropship'] ) {
					return $a['can_dropship'] ? -1 : 1;
				}
				// Cheapest price wins (null sinks to the end).
				$pa = $a['price'] ?? INF;
				$pb = $b['price'] ?? INF;
				if ( $pa !== $pb ) {
					return $pa <=> $pb;
				}
				// Tie-break on stock depth.
				return $b['stock_qty'] <=> $a['stock_qty'];
			}
		);

		return array(
			'ok'         => true,
			'best'       => $candidates[0],
			'candidates' => $candidates,
		);
	}

	/** @return array<int,array> wholesaler_id => row */
	private static function indexWholesalers(): array {
		$repo = new WholesalerRepository();
		$out  = array();
		foreach ( $repo->all() as $w ) {
			$out[ (int) $w['id'] ] = $w;
		}
		return $out;
	}
}
