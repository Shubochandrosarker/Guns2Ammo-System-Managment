<?php

namespace G2A\POS\Pricing;

/**
 * Pure-logic price suggestion over a sourcing matrix row + optional MAP rule.
 *
 * For each vendor candidate we calculate a "suggested sell price":
 *
 *   base_markup = wholesale_price × (1 + markup_pct / 100)
 *   floor       = MAP if a MAP rule applies
 *   ceiling     = MSRP × (1 + max_overage_pct / 100)   — soft cap
 *   suggested   = clamp(base_markup, floor, ceiling)
 *
 * The "best landed cost" winner is the cheapest wholesale row that is also
 * in stock (stock > 0); drop-ship is allowed but penalised by `dropship_fee`
 * (configurable; defaults to $0). Returns the recommendation alongside the
 * scored matrix.
 */
final class SourcingSuggester {

	public const DEFAULT_MARKUP_PCT      = 25.0;
	public const DEFAULT_MAX_OVERAGE_PCT = 50.0;
	public const DEFAULT_DROPSHIP_FEE    = 0.0;

	public static function suggest( array $shelf, array $vendors, ?array $map_rule = null, array $opts = array() ): array {
		$markup_pct      = (float) ( $opts['markup_pct'] ?? self::DEFAULT_MARKUP_PCT );
		$max_overage_pct = (float) ( $opts['max_overage_pct'] ?? self::DEFAULT_MAX_OVERAGE_PCT );
		$dropship_fee    = (float) ( $opts['dropship_fee'] ?? self::DEFAULT_DROPSHIP_FEE );

		$map_price = null;
		if ( is_array( $map_rule ) && ! empty( $map_rule['map_price'] ) && (float) $map_rule['map_price'] > 0 ) {
			$map_price = (float) $map_rule['map_price'];
		}

		$scored = array();
		foreach ( $vendors as $v ) {
			$cost = (float) ( $v['current_price'] ?? $v['wholesale_price'] ?? 0 );
			if ( $cost <= 0 ) {
				continue;
			}
			$stock        = (int) ( $v['stock_qty'] ?? 0 );
			$can_dropship = (int) ( $v['can_dropship'] ?? 0 ) === 1;
			$msrp         = (float) ( $v['msrp'] ?? 0 );

			$effective_cost = $cost + ( $stock <= 0 && $can_dropship ? $dropship_fee : 0.0 );
			$suggested      = self::clamp_price( $cost * ( 1 + $markup_pct / 100 ), $map_price, $msrp, $max_overage_pct );
			$margin_dollars = round( $suggested - $cost, 2 );
			$margin_pct     = $cost > 0 ? round( ( $suggested - $cost ) / $cost * 100, 2 ) : 0.0;

			$scored[] = array(
				'vendor_name'          => $v['vendor_name'] ?? '',
				'vendor_sku'           => $v['vendor_sku'] ?? '',
				'upc'                  => $v['upc'] ?? null,
				'wholesale_price'      => round( $cost, 2 ),
				'effective_cost'       => round( $effective_cost, 2 ),
				'msrp'                 => $msrp > 0 ? round( $msrp, 2 ) : null,
				'map_price'            => $map_price,
				'stock_qty'            => $stock,
				'can_dropship'         => $can_dropship,
				'suggested_sell_price' => round( $suggested, 2 ),
				'margin_dollars'       => $margin_dollars,
				'margin_pct'           => $margin_pct,
				'sellable'             => $stock > 0 || $can_dropship,
			);
		}

		// Sort: in-stock first by effective_cost asc, then drop-ship only,
		// then nothing-available rows last.
		usort(
			$scored,
			static function ( $a, $b ) {
				$a_avail = $a['stock_qty'] > 0 ? 0 : ( $a['can_dropship'] ? 1 : 2 );
				$b_avail = $b['stock_qty'] > 0 ? 0 : ( $b['can_dropship'] ? 1 : 2 );
				if ( $a_avail !== $b_avail ) {
					return $a_avail <=> $b_avail;
				}
				return $a['effective_cost'] <=> $b['effective_cost'];
			}
		);

		$shelf_qty   = (int) ( $shelf['on_hand'] ?? 0 );
		$shelf_price = isset( $shelf['price'] ) && $shelf['price'] !== null ? (float) $shelf['price'] : null;

		$recommendation = self::recommend( $shelf_qty, $shelf_price, $scored, $map_price );

		return array(
			'shelf'           => array(
				'on_hand'              => $shelf_qty,
				'price'                => $shelf_price,
				'suggested_sell_price' => $shelf_price !== null
					? round( self::clamp_price( $shelf_price, $map_price, null, $max_overage_pct ), 2 )
					: null,
			),
			'vendors'         => $scored,
			'map_price'       => $map_price,
			'recommendation'  => $recommendation,
			'markup_pct_used' => $markup_pct,
		);
	}

	public static function clamp_price( float $proposed, ?float $map_price, ?float $msrp, float $max_overage_pct ): float {
		$price = $proposed;
		if ( $map_price !== null && $price < $map_price ) {
			$price = $map_price;
		}
		if ( $msrp !== null && $msrp > 0 ) {
			$ceiling = $msrp * ( 1 + $max_overage_pct / 100 );
			if ( $price > $ceiling ) {
				$price = $ceiling;
			}
		}
		return $price;
	}

	private static function recommend( int $shelf_qty, ?float $shelf_price, array $scored, ?float $map_price ): array {
		if ( $shelf_qty > 0 ) {
			return array(
				'source'     => 'shelf',
				'reason'     => sprintf( '%d on shelf — pull and ring out.', $shelf_qty ),
				'sell_price' => $shelf_price !== null
					? round( self::clamp_price( $shelf_price, $map_price, null, self::DEFAULT_MAX_OVERAGE_PCT ), 2 )
					: null,
			);
		}
		foreach ( $scored as $row ) {
			if ( $row['stock_qty'] > 0 ) {
				return array(
					'source'          => 'vendor_stock',
					'vendor_name'     => $row['vendor_name'],
					'vendor_sku'      => $row['vendor_sku'],
					'wholesale_price' => $row['wholesale_price'],
					'sell_price'      => $row['suggested_sell_price'],
					'margin_pct'      => $row['margin_pct'],
					'reason'          => sprintf( '%s: %d in stock at $%.2f wholesale.', $row['vendor_name'], $row['stock_qty'], $row['wholesale_price'] ),
				);
			}
		}
		foreach ( $scored as $row ) {
			if ( $row['can_dropship'] ) {
				return array(
					'source'          => 'dropship',
					'vendor_name'     => $row['vendor_name'],
					'vendor_sku'      => $row['vendor_sku'],
					'wholesale_price' => $row['wholesale_price'],
					'sell_price'      => $row['suggested_sell_price'],
					'margin_pct'      => $row['margin_pct'],
					'reason'          => sprintf( '%s drop-ship: $%.2f wholesale.', $row['vendor_name'], $row['wholesale_price'] ),
				);
			}
		}
		return array(
			'source'     => 'none',
			'reason'     => 'No shelf stock, no vendor stock, no drop-ship available.',
			'sell_price' => null,
		);
	}
}
