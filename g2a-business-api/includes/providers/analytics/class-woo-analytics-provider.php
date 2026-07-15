<?php
/**
 * WooCommerce order aggregates via the Woo APIs (HPOS-safe).
 *
 * REVENUE RECOGNITION — order statuses included: `completed` +
 * `processing` (paid, awaiting fulfilment) — the same pair Woo's own
 * revenue reports treat as paid. Everything else (pending, on-hold,
 * cancelled, refunded, failed, draft) is excluded. Refunds are measured
 * per included order via WC_Order::get_total_refunded(), so a partially
 * refunded processing/completed order contributes its refund to
 * refundCents while keeping its full total in grossCents;
 * net = gross - refunds.
 *
 * Orders are fetched through wc_get_orders() (never raw SQL) with a
 * bounded date window + status filter, paged at PAGE_SIZE and hard-capped
 * at MAX_ORDERS rows; if the cap trips, `truncated: true` is set so the
 * dashboard can say "showing first N orders" instead of lying.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Woo_Analytics_Provider extends Analytics_Provider_Base {
	public const INCLUDED_STATUSES = array( 'wc-completed', 'wc-processing' );

	public const PAGE_SIZE  = 200;
	public const MAX_ORDERS = 5000;

	public function source_slug(): string {
		return 'woocommerce';
	}

	public function is_available(): bool {
		return class_exists( '\WooCommerce' ) && function_exists( 'wc_get_orders' );
	}

	protected function empty_summary(): array {
		return array(
			// Frontend contract keys (dashboard-app types/api.ts).
			'orders'           => 0,
			'revenueCents'     => 0,
			// Richer detail.
			'statusesIncluded' => array( 'completed', 'processing' ),
			'orderCount'       => 0,
			'revenue'          => array(
				'grossCents'  => 0,
				'refundCents' => 0,
				'netCents'    => 0,
			),
			'aovCents'         => 0,
			'truncated'        => false,
		);
	}

	protected function build_summary( Range $range ): array {
		$scan = $this->scan_orders( $range, false );
		return $this->summary_from_scan( $scan );
	}

	protected function empty_detail(): array {
		return array_merge(
			$this->empty_summary(),
			array(
				'topProducts'          => array(),
				'topProductsTruncated' => false,
				'byCategory'           => array(),
				'series'               => array(),
				'trends'               => array(
					'previous' => array(
						'count'        => 0,
						'revenueCents' => 0,
						'netCents'     => 0,
					),
					'deltas'   => array(
						'countPct'   => null,
						'revenuePct' => null,
					),
				),
			)
		);
	}

	/**
	 * Detail payload for GET /analytics/woocommerce: everything the overview
	 * module emits PLUS topProducts (top 10 by net line revenue), byCategory
	 * (top 10 product_cat terms), a zero-filled daily series, and trends vs
	 * the previous period. All of it is derived from the SAME capped order
	 * scan the summary uses: if the 5000-order cap trips, `truncated` (and
	 * `topProductsTruncated`) say so instead of pretending completeness.
	 */
	protected function build_detail( Range $range ): array {
		$scan = $this->scan_orders( $range, true );
		$out  = $this->summary_from_scan( $scan );

		$out['topProducts']          = self::top_by_revenue( $scan['products'], array( 'productId', 'name', 'qty', 'revenueCents' ) );
		$out['topProductsTruncated'] = $scan['truncated'];
		$out['byCategory']           = self::top_by_revenue( $scan['categories'], array( 'term', 'name', 'revenueCents', 'qty' ) );
		$out['series']               = self::fill_daily_series(
			$range,
			$scan['daily'],
			array(
				'count'        => 0,
				'revenueCents' => 0,
			)
		);

		// Trends — previous period scanned with the same statuses + cap
		// (totals only; no per-item detail needed).
		$prev = $this->scan_orders( $range->previous(), false );

		$out['trends'] = self::build_trends(
			$scan['count'],
			$scan['gross'] - $scan['refunds'],
			array(
				'count'        => $prev['count'],
				'revenueCents' => $prev['gross'] - $prev['refunds'],
				'netCents'     => $prev['gross'] - $prev['refunds'],
			)
		);

		return $out;
	}

	/**
	 * Net order revenue per day for the dashboard series, from the same
	 * capped scan.
	 *
	 * @return array<string, int> 'YYYY-MM-DD' => net cents.
	 */
	protected function build_daily_revenue( Range $range ): array {
		$scan = $this->scan_orders( $range, false );
		$out  = array();
		foreach ( $scan['daily'] as $date => $row ) {
			$out[ $date ] = (int) ( $row['revenueCents'] ?? 0 );
		}
		return $out;
	}

	/**
	 * One paged pass over wc_get_orders() (statuses per the header mapping,
	 * PAGE_SIZE pages, MAX_ORDERS hard cap). Always accumulates totals and
	 * the per-day breakdown; per-product/per-category accumulation only when
	 * $collect_items is true (it walks order line items).
	 *
	 * @return array{gross:int, refunds:int, count:int, truncated:bool,
	 *         last_created:?int, daily:array<string, array{count:int, revenueCents:int}>,
	 *         products:array<string, array>, categories:array<string, array>}
	 */
	private function scan_orders( Range $range, bool $collect_items ): array {
		$scan = array(
			'gross'        => 0,
			'refunds'      => 0,
			'count'        => 0,
			'truncated'    => false,
			'last_created' => null,
			'daily'        => array(),
			'products'     => array(),
			'categories'   => array(),
		);

		$page = 1;
		while ( true ) {
			$orders = wc_get_orders(
				array(
					'limit'        => self::PAGE_SIZE,
					'page'         => $page,
					'status'       => self::INCLUDED_STATUSES,
					'date_created' => $range->from . '...' . $range->to,
					'return'       => 'objects',
				)
			);
			if ( ! is_array( $orders ) || empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! is_object( $order ) || ! method_exists( $order, 'get_total' ) ) {
					continue;
				}
				$scan['count']++;
				$order_gross   = Money::to_cents_from_string( (string) $order->get_total() );
				$order_refunds = Money::to_cents_from_string( (string) $order->get_total_refunded() );
				$scan['gross']   += $order_gross;
				$scan['refunds'] += $order_refunds;

				$created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
				if ( $created && method_exists( $created, 'getTimestamp' ) ) {
					$ts = (int) $created->getTimestamp();
					if ( null === $scan['last_created'] || $ts > $scan['last_created'] ) {
						$scan['last_created'] = $ts;
					}
					$day = method_exists( $created, 'date' ) ? (string) $created->date( 'Y-m-d' ) : gmdate( 'Y-m-d', $ts );
					if ( ! isset( $scan['daily'][ $day ] ) ) {
						$scan['daily'][ $day ] = array(
							'count'        => 0,
							'revenueCents' => 0,
						);
					}
					$scan['daily'][ $day ]['count']++;
					$scan['daily'][ $day ]['revenueCents'] += $order_gross - $order_refunds;
				}

				if ( $collect_items && method_exists( $order, 'get_items' ) ) {
					$this->accumulate_items( $order, $scan['products'], $scan['categories'] );
				}
			}

			if ( $scan['count'] >= self::MAX_ORDERS ) {
				$scan['truncated'] = true;
				break;
			}
			if ( count( $orders ) < self::PAGE_SIZE ) {
				break;
			}
			$page++;
		}

		return $scan;
	}

	/**
	 * Overview payload from a scan — the pre-0.4.0 summary shape, unchanged.
	 *
	 * @param array<string, mixed> $scan
	 * @return array<string, mixed>
	 */
	private function summary_from_scan( array $scan ): array {
		$gross   = (int) $scan['gross'];
		$refunds = (int) $scan['refunds'];

		return array(
			'orders'           => (int) $scan['count'],
			'revenueCents'     => $gross - $refunds,
			'statusesIncluded' => array( 'completed', 'processing' ),
			'orderCount'       => (int) $scan['count'],
			'revenue'          => array(
				'grossCents'  => $gross,
				'refundCents' => $refunds,
				'netCents'    => $gross - $refunds,
			),
			'aovCents'         => self::aov_cents( $gross, (int) $scan['count'] ),
			'truncated'        => (bool) $scan['truncated'],
			'lastUpdatedAt'    => null !== $scan['last_created'] ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $scan['last_created'] ) : null,
		);
	}

	/**
	 * Fold one order's line items into the product + category accumulators.
	 * Category terms are looked up once per distinct product (memoised),
	 * never per line item.
	 *
	 * @param object                       $order      WC_Order.
	 * @param array<string, array>         $products   productId => accumulator.
	 * @param array<string, array>         $categories term slug => accumulator.
	 */
	private function accumulate_items( $order, array &$products, array &$categories ): void {
		static $term_cache = array();

		$items = $order->get_items();
		if ( ! is_iterable( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! method_exists( $item, 'get_total' ) ) {
				continue;
			}
			$product_id = method_exists( $item, 'get_product_id' ) ? (int) $item->get_product_id() : 0;
			$name       = method_exists( $item, 'get_name' ) ? (string) $item->get_name() : '';
			$qty        = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 0;
			$cents      = Money::to_cents_from_string( (string) $item->get_total() );

			$pkey = (string) $product_id;
			if ( ! isset( $products[ $pkey ] ) ) {
				$products[ $pkey ] = array(
					'productId'    => $product_id,
					'name'         => $name,
					'qty'          => 0,
					'revenueCents' => 0,
				);
			}
			$products[ $pkey ]['qty']          += $qty;
			$products[ $pkey ]['revenueCents'] += $cents;

			// product_cat terms, memoised per product id.
			if ( $product_id > 0 && function_exists( 'get_the_terms' ) ) {
				if ( ! array_key_exists( $pkey, $term_cache ) ) {
					$terms               = get_the_terms( $product_id, 'product_cat' );
					$term_cache[ $pkey ] = is_array( $terms ) ? $terms : array();
				}
				foreach ( $term_cache[ $pkey ] as $term ) {
					if ( ! is_object( $term ) || ! isset( $term->slug ) ) {
						continue;
					}
					$tkey = (string) $term->slug;
					if ( ! isset( $categories[ $tkey ] ) ) {
						$categories[ $tkey ] = array(
							'term'         => $tkey,
							'name'         => (string) ( $term->name ?? $tkey ),
							'revenueCents' => 0,
							'qty'          => 0,
						);
					}
					$categories[ $tkey ]['revenueCents'] += $cents;
					$categories[ $tkey ]['qty']          += $qty;
				}
			}
		}
	}

	/**
	 * Top 10 rows by revenueCents (ties broken by the first column for
	 * determinism), columns emitted in the given order.
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @param array<string, array<string, mixed>> $rows
	 * @param array<int, string>                  $columns
	 * @return array<int, array<string, mixed>>
	 */
	public static function top_by_revenue( array $rows, array $columns ): array {
		$rows = array_values( $rows );
		usort(
			$rows,
			static function ( $a, $b ) use ( $columns ) {
				$cmp = ( $b['revenueCents'] ?? 0 ) <=> ( $a['revenueCents'] ?? 0 );
				if ( 0 !== $cmp ) {
					return $cmp;
				}
				$first = $columns[0];
				return ( $a[ $first ] ?? '' ) <=> ( $b[ $first ] ?? '' );
			}
		);

		$out = array();
		foreach ( array_slice( $rows, 0, 10 ) as $row ) {
			$ordered = array();
			foreach ( $columns as $col ) {
				$ordered[ $col ] = $row[ $col ] ?? null;
			}
			$out[] = $ordered;
		}
		return $out;
	}

	/**
	 * @internal Exposed (pure) for tests.
	 */
	public static function aov_cents( int $gross_cents, int $order_count ): int {
		return $order_count > 0 ? (int) round( $gross_cents / $order_count ) : 0;
	}
}
