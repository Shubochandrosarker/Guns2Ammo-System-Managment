<?php

namespace G2A\POS\Database;

final class VendorPriceHistoryRepository extends Repository {

	public function capture( int $wholesaler_id ): int {
		global $wpdb;
		$now   = $this->now();
		$today = substr( $now, 0, 10 );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vendor_sku, wholesale_price, current_price, msrp, map_price, stock_qty
             FROM {$this->table('g2a_wholesaler_products')} WHERE wholesaler_id = %d",
				$wholesaler_id
			),
			ARRAY_A
		) ?: array();
		$count = 0;
		foreach ( $rows as $r ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$this->table('g2a_vendor_price_history')}
                 (wholesaler_id, vendor_sku, captured_on, wholesale_price, current_price, msrp, map_price, stock_qty, created_at)
                 VALUES (%d, %s, %s, %s, %s, %s, %s, %d, %s)
                 ON DUPLICATE KEY UPDATE wholesale_price=VALUES(wholesale_price), current_price=VALUES(current_price),
                 msrp=VALUES(msrp), map_price=VALUES(map_price), stock_qty=VALUES(stock_qty)",
					$wholesaler_id,
					$r['vendor_sku'],
					$today,
					$r['wholesale_price'],
					$r['current_price'],
					$r['msrp'],
					$r['map_price'],
					(int) ( $r['stock_qty'] ?? 0 ),
					$now
				)
			);
			++$count;
		}
		return $count;
	}

	public function drift_report( int $wholesaler_id, int $days = 90 ): array {
		global $wpdb;
		$from = date( 'Y-m-d', strtotime( "-{$days} days" ) );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT vendor_sku,
                    MIN(wholesale_price) AS min_cost,
                    MAX(wholesale_price) AS max_cost,
                    AVG(wholesale_price) AS avg_cost,
                    (MAX(wholesale_price) - MIN(wholesale_price)) /
                        NULLIF(MIN(wholesale_price), 0) * 100 AS drift_pct,
                    COUNT(*) AS sample_count
             FROM {$this->table('g2a_vendor_price_history')}
             WHERE wholesaler_id = %d AND captured_on >= %s
             GROUP BY vendor_sku
             HAVING drift_pct >= 5
             ORDER BY drift_pct DESC LIMIT 100",
				$wholesaler_id,
				$from
			),
			ARRAY_A
		) ?: array();
	}
}
