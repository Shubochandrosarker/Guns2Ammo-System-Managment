<?php

namespace G2A\POS\Catalog;

use G2A\POS\Database\ItemIdentityRepository;

/**
 * Resolve-or-create + cross-source aggregation over the canonical item
 * identity graph. The entry point every catalog-touching surface
 * (wholesaler sync, used-firearm intake, consignment, trade-in, manual
 * Woo product create) should call.
 *
 * Strategy:
 *   1. Try by exact source link first (source_type + source_ref). If we've
 *      seen this UPC / vendor SKU / etc. before, return its identity.
 *   2. Otherwise score every candidate that shares the manufacturer,
 *      pick the highest-scoring one above THRESHOLD.
 *   3. Below threshold, create a fresh canonical identity (flagged
 *      review_required = 1 if the best score was in the 0.50–0.85 grey zone).
 */
final class IdentityService {

	/**
	 * Resolve or create. Returns `{ identity_id, identity, score, decision }`.
	 * decision = exact_link | matched | created.
	 */
	public static function resolve_or_create( array $candidate, array $link = array() ): array {
		$repo = new ItemIdentityRepository();

		// 1. Direct source-link hit.
		if ( ! empty( $link['source_type'] ) && ! empty( $link['source_ref'] ) ) {
			$hit = $repo->find_by_source(
				(string) $link['source_type'],
				(string) $link['source_ref'],
				isset( $link['wholesaler_id'] ) ? (int) $link['wholesaler_id'] : null
			);
			if ( $hit ) {
				return array(
					'identity_id' => (int) $hit['id'],
					'identity'    => $hit,
					'score'       => 1.0,
					'decision'    => 'exact_link',
				);
			}
		}

		// 2. Fuzzy match across same-manufacturer candidates.
		$best       = null;
		$best_score = 0.0;
		foreach ( $repo->candidates( $candidate, 100 ) as $existing ) {
			$s = IdentityMatcher::score( $candidate, $existing );
			if ( $s > $best_score ) {
				$best_score = $s;
				$best       = $existing;
			}
		}

		if ( $best && $best_score >= IdentityMatcher::THRESHOLD ) {
			if ( ! empty( $link['source_type'] ) && ! empty( $link['source_ref'] ) ) {
				$repo->link(
					(int) $best['id'],
					(string) $link['source_type'],
					(string) $link['source_ref'],
					array(
						'wholesaler_id' => $link['wholesaler_id'] ?? null,
						'confidence'    => $best_score,
						'matched_by'    => 'auto_matcher',
						'metadata'      => array( 'candidate' => $candidate ),
					)
				);
			}
			return array(
				'identity_id' => (int) $best['id'],
				'identity'    => $best,
				'score'       => $best_score,
				'decision'    => 'matched',
			);
		}

		// 3. Create. If we had a "warm" near-miss, flag for review.
		$needs_review = $best_score > 0.50 && $best_score < IdentityMatcher::THRESHOLD;
		$new_id       = $repo->create(
			array(
				'item_type'         => $candidate['item_type'] ?? 'firearm',
				'firearm_type'      => $candidate['firearm_type'] ?? '',
				'manufacturer'      => $candidate['manufacturer'] ?? '',
				'model'             => $candidate['model'] ?? '',
				'family'            => $candidate['family'] ?? '',
				'caliber'           => $candidate['caliber'] ?? '',
				'barrel_length_in'  => $candidate['barrel_length_in'] ?? null,
				'capacity_rounds'   => $candidate['capacity_rounds'] ?? null,
				'country_of_origin' => $candidate['country_of_origin'] ?? '',
				'msrp'              => $candidate['msrp'] ?? null,
				'default_image_url' => $candidate['default_image_url'] ?? '',
				'description'       => $candidate['description'] ?? '',
				'tags'              => $candidate['tags'] ?? '',
				'attributes'        => $candidate['attributes'] ?? null,
				'review_required'   => $needs_review ? 1 : 0,
			)
		);
		if ( ! empty( $link['source_type'] ) && ! empty( $link['source_ref'] ) ) {
			$repo->link(
				$new_id,
				(string) $link['source_type'],
				(string) $link['source_ref'],
				array(
					'wholesaler_id' => $link['wholesaler_id'] ?? null,
					'confidence'    => 1.0,
					'matched_by'    => 'created_from',
					'metadata'      => array( 'candidate' => $candidate ),
				)
			);
		}
		// Also link the UPC and mfg_part if we have them — saves a lookup next time.
		if ( ! empty( $candidate['upc'] ) ) {
			$repo->link(
				$new_id,
				'upc',
				(string) $candidate['upc'],
				array(
					'confidence' => 1.0,
					'matched_by' => 'created_from',
				)
			);
		}
		if ( ! empty( $candidate['mfg_part'] ) ) {
			$repo->link(
				$new_id,
				'mfg_part',
				(string) $candidate['mfg_part'],
				array(
					'confidence' => 1.0,
					'matched_by' => 'created_from',
				)
			);
		}

		return array(
			'identity_id'     => $new_id,
			'identity'        => $repo->find( $new_id ),
			'score'           => $best_score,
			'decision'        => 'created',
			'review_required' => $needs_review,
		);
	}

	/**
	 * Cross-source price+stock matrix for one canonical item — the
	 * foundation under the future order-sheet sourcing panel (#6).
	 */
	public static function sources( int $identity_id ): array {
		global $wpdb;
		$repo     = new ItemIdentityRepository();
		$identity = $repo->find( $identity_id );
		if ( ! $identity ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		$links = $repo->links_for( $identity_id );

		$p = $wpdb->prefix;

		// Shelf inventory (Woo) — sum stock across linked Woo product IDs.
		$woo_refs    = array_filter(
			array_map(
				static fn( $l ) => $l['source_type'] === 'woo_product' ? (int) $l['source_ref'] : null,
				$links
			)
		);
		$shelf_qty   = 0;
		$shelf_price = null;
		if ( $woo_refs && function_exists( 'wc_get_product' ) ) {
			foreach ( $woo_refs as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}
				$shelf_qty += (int) $product->get_stock_quantity();
				if ( $shelf_price === null ) {
					$shelf_price = (float) $product->get_price();
				}
			}
		}

		// Wholesaler matrix — every wholesaler that carries this canonical item.
		$vendor_refs = array();
		foreach ( $links as $l ) {
			if ( $l['source_type'] === 'wholesaler_product' && $l['wholesaler_id'] ) {
				$vendor_refs[] = array(
					'wholesaler_id' => (int) $l['wholesaler_id'],
					'vendor_sku'    => $l['source_ref'],
				);
			}
		}
		$matrix = array();
		foreach ( $vendor_refs as $vref ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT wp.vendor_sku, wp.upc, wp.current_price, wp.msrp, wp.map_price,
                        wp.stock_qty, wp.can_dropship, w.display_name AS vendor_name, w.provider_code
                 FROM {$p}g2a_wholesaler_products wp
                 JOIN {$p}g2a_wholesalers w ON w.id = wp.wholesaler_id
                 WHERE wp.wholesaler_id = %d AND wp.vendor_sku = %s",
					$vref['wholesaler_id'],
					$vref['vendor_sku']
				),
				ARRAY_A
			);
			if ( $row ) {
				$matrix[] = $row;
			}
		}

		// Sort by stock desc, then price asc — best source first.
		usort(
			$matrix,
			static function ( $a, $b ) {
				$sa = (int) ( $a['stock_qty'] ?? 0 );
				$sb = (int) ( $b['stock_qty'] ?? 0 );
				if ( $sa !== $sb ) {
					return $sb <=> $sa;
				}
				$pa = (float) ( $a['current_price'] ?? PHP_INT_MAX );
				$pb = (float) ( $b['current_price'] ?? PHP_INT_MAX );
				return $pa <=> $pb;
			}
		);

		return array(
			'ok'             => true,
			'identity'       => $identity,
			'shelf'          => array(
				'on_hand'         => $shelf_qty,
				'price'           => $shelf_price,
				'woo_product_ids' => array_values( $woo_refs ),
			),
			'vendors'        => $matrix,
			'recommendation' => self::recommend( $shelf_qty, $matrix ),
			'links'          => $links,
		);
	}

	private static function recommend( int $shelf_qty, array $matrix ): array {
		if ( $shelf_qty > 0 ) {
			return array(
				'source' => 'shelf',
				'reason' => sprintf( '%d on shelf — pull and ring out.', $shelf_qty ),
			);
		}
		if ( $matrix ) {
			$top = $matrix[0];
			return array(
				'source'       => 'wholesaler',
				'vendor_name'  => $top['vendor_name'],
				'vendor_sku'   => $top['vendor_sku'],
				'price'        => (float) ( $top['current_price'] ?? 0 ),
				'in_stock'     => (int) ( $top['stock_qty'] ?? 0 ),
				'can_dropship' => (int) ( $top['can_dropship'] ?? 0 ) === 1,
				'reason'       => sprintf(
					'Best vendor: %s — %d in stock at $%.2f.',
					$top['vendor_name'],
					(int) ( $top['stock_qty'] ?? 0 ),
					(float) ( $top['current_price'] ?? 0 )
				),
			);
		}
		return array(
			'source' => 'none',
			'reason' => 'No shelf stock and no linked wholesaler with stock.',
		);
	}

	/**
	 * Resolve an order/cart line back to its canonical identity. Tries the
	 * cheapest paths first (direct source links) before falling back to a
	 * fuzzy name+manufacturer match.
	 *
	 * `hint` is the order item row — supplies sku, product_id, optional name +
	 * manufacturer + model + caliber.
	 */
	public static function resolve_for_order_item( array $hint ): ?array {
		$repo = new ItemIdentityRepository();

		$product_id = (int) ( $hint['product_id'] ?? 0 );
		$sku        = trim( (string) ( $hint['sku'] ?? '' ) );
		$upc        = trim( (string) ( $hint['upc'] ?? '' ) );

		// 1. Direct woo_product link.
		if ( $product_id > 0 ) {
			$hit = $repo->find_by_source( 'woo_product', (string) $product_id );
			if ( $hit ) {
				return $hit;
			}
		}

		// 2. Direct UPC link (also covers when SKU happens to be a UPC).
		if ( $upc !== '' ) {
			$hit = $repo->find_by_source( 'upc', IdentityMatcher::clean_upc( $upc ) );
			if ( $hit ) {
				return $hit;
			}
		}
		if ( $sku !== '' && ctype_digit( $sku ) && ( strlen( $sku ) === 12 || strlen( $sku ) === 13 || strlen( $sku ) === 14 ) ) {
			$hit = $repo->find_by_source( 'upc', $sku );
			if ( $hit ) {
				return $hit;
			}
		}

		// 3. Vendor SKU across any wholesaler.
		if ( $sku !== '' ) {
			global $wpdb;
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT i.* FROM {$wpdb->prefix}g2a_item_identities i
                 JOIN {$wpdb->prefix}g2a_item_identity_links l ON l.identity_id = i.id
                 WHERE l.source_type = %s AND l.source_ref = %s LIMIT 1",
					'wholesaler_product',
					$sku
				),
				ARRAY_A
			);
			if ( $row ) {
				return $row;
			}
		}

		// 4. Fuzzy match against same-manufacturer candidates.
		$candidate = array(
			'manufacturer' => $hint['manufacturer'] ?? '',
			'model'        => $hint['model'] ?? '',
			'caliber'      => $hint['caliber'] ?? '',
			'upc'          => $upc,
			'mfg_part'     => $hint['mfg_part'] ?? '',
		);
		if ( ! empty( $candidate['manufacturer'] ) ) {
			$best       = null;
			$best_score = 0.0;
			foreach ( $repo->candidates( $candidate, 50 ) as $cand ) {
				$s = IdentityMatcher::score( $candidate, $cand );
				if ( $s > $best_score ) {
					$best_score = $s;
					$best       = $cand;
				}
			}
			if ( $best && $best_score >= IdentityMatcher::THRESHOLD ) {
				return $best;
			}
		}
		return null;
	}
}
