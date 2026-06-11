<?php

namespace G2A\POS\Reporting;

use G2A\POS\Database\KpiSnapshotRepository;

/**
 * Pull a live KPI block for the dashboard. Also snapshots key metrics each
 * night via cron into g2a_kpi_snapshots so historical trends survive even
 * when the operational tables are pruned.
 */
final class KpiService {

	public function snapshot(): array {
		global $wpdb;
		$p             = $wpdb->prefix;
		$now           = current_time( 'mysql' );
		$today         = substr( $now, 0, 10 );
		$last_30_start = date( 'Y-m-d', strtotime( '-30 days' ) );

		// Top-line totals
		$today_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(grand_total),0) FROM {$p}g2a_pos_orders
             WHERE DATE(created_at) = %s AND status NOT IN ('void','cancelled','refunded')",
				$today
			)
		);
		$today_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}g2a_pos_orders
             WHERE DATE(created_at) = %s AND status NOT IN ('void','cancelled','refunded')",
				$today
			)
		);
		$month_total = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(grand_total),0) FROM {$p}g2a_pos_orders
             WHERE created_at >= %s AND status NOT IN ('void','cancelled','refunded')",
				$last_30_start
			)
		);

		// Margin: requires order items + product cost. Best-effort: use vendor
		// catalog wholesale_price keyed on SKU as the cost proxy.
		$margin_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(i.line_total),0) AS rev,
                    COALESCE(SUM(i.quantity * COALESCE(wp.wholesale_price, 0)),0) AS cogs
             FROM {$p}g2a_pos_order_items i
             JOIN {$p}g2a_pos_orders o ON o.id = i.pos_order_id
             LEFT JOIN {$p}g2a_wholesaler_products wp ON wp.vendor_sku = i.sku
             WHERE o.created_at >= %s AND o.status NOT IN ('void','cancelled','refunded')",
				$last_30_start
			),
			ARRAY_A
		);
		$rev        = (float) ( $margin_row['rev'] ?? 0 );
		$cogs       = (float) ( $margin_row['cogs'] ?? 0 );
		$margin_pct = $rev > 0 ? round( ( ( $rev - $cogs ) / $rev ) * 100, 2 ) : 0.0;

		// Attach rate: % of firearm orders that also include accessory/ammo.
		$attach     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
                COUNT(DISTINCT o.id) AS firearm_orders,
                COUNT(DISTINCT CASE WHEN o.id IN (
                    SELECT pos_order_id FROM {$p}g2a_pos_order_items
                    WHERE item_type IN ('accessory','ammo','optic','holster')
                ) THEN o.id END) AS attached_orders
             FROM {$p}g2a_pos_orders o
             JOIN {$p}g2a_pos_order_items i ON i.pos_order_id = o.id
             WHERE o.created_at >= %s
               AND i.item_type = 'firearm'
               AND o.status NOT IN ('void','cancelled','refunded')",
				$last_30_start
			),
			ARRAY_A
		);
		$f_orders   = (int) ( $attach['firearm_orders'] ?? 0 );
		$attach_pct = $f_orders > 0 ? round( ( (int) ( $attach['attached_orders'] ?? 0 ) / $f_orders ) * 100, 2 ) : 0.0;

		// Compliance pipeline.
		$nics_open = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}g2a_nics_transactions WHERE response_status IN ('pending','delayed')"
		);
		$open_4473 = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}g2a_form_4473 WHERE transaction_status NOT IN ('transferred','voided')"
		);

		// Inventory health.
		$serials_in_stock = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}g2a_serial_registry WHERE status = 'in_stock'"
		);
		$dead_stock       = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}g2a_serial_registry
             WHERE status = 'in_stock' AND acquisition_date IS NOT NULL AND acquisition_date < %s",
				date( 'Y-m-d', strtotime( '-90 days' ) )
			)
		);

		// Open registers.
		$open_registers = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$p}g2a_register_sessions WHERE status = 'open'"
		);

		// Layaway exposure.
		$layaway_balance = (float) $wpdb->get_var(
			"SELECT COALESCE(SUM(balance_due),0) FROM {$p}g2a_layaways WHERE status = 'active'"
		);

		return array(
			'as_of'        => $now,
			'today'        => array(
				'orders'  => $today_count,
				'revenue' => $today_total,
			),
			'last_30_days' => array(
				'revenue'          => $month_total,
				'cogs'             => $cogs,
				'gross_margin_pct' => $margin_pct,
				'attach_rate_pct'  => $attach_pct,
			),
			'compliance'   => array(
				'nics_open' => $nics_open,
				'open_4473' => $open_4473,
			),
			'inventory'    => array(
				'serials_in_stock' => $serials_in_stock,
				'dead_stock_90d'   => $dead_stock,
			),
			'operations'   => array(
				'open_registers'  => $open_registers,
				'layaway_balance' => $layaway_balance,
			),
		);
	}

	public function vendor_scorecard( int $days = 90 ): array {
		global $wpdb;
		$p     = $wpdb->prefix;
		$since = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT w.id, w.display_name, w.provider_code,
                    COUNT(o.id) AS order_count,
                    SUM(CASE WHEN o.status IN ('confirmed','shipped','complete') THEN 1 ELSE 0 END) AS fulfilled,
                    SUM(CASE WHEN o.shipped_at IS NOT NULL THEN 1 ELSE 0 END) AS shipped_count,
                    AVG(CASE WHEN o.shipped_at IS NOT NULL AND o.submitted_at IS NOT NULL
                            THEN TIMESTAMPDIFF(HOUR, o.submitted_at, o.shipped_at) END) AS avg_ship_hours
             FROM {$p}g2a_wholesalers w
             LEFT JOIN {$p}g2a_wholesaler_orders o ON o.wholesaler_id = w.id AND o.created_at >= %s
             GROUP BY w.id
             ORDER BY order_count DESC",
				$since
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as &$r ) {
			$oc                  = max( 1, (int) $r['order_count'] );
			$r['fill_rate_pct']  = round( ( (int) $r['fulfilled'] / $oc ) * 100, 2 );
			$r['avg_ship_hours'] = $r['avg_ship_hours'] !== null ? round( (float) $r['avg_ship_hours'], 1 ) : null;
		}
		return $rows;
	}

	public function persist_snapshot( ?string $date = null ): void {
		$date = $date ?? date( 'Y-m-d' );
		$repo = new KpiSnapshotRepository();
		$snap = $this->snapshot();
		$repo->put( $date, 0, 'today_revenue', (float) $snap['today']['revenue'] );
		$repo->put( $date, 0, 'today_orders', (float) $snap['today']['orders'] );
		$repo->put( $date, 0, 'last_30_revenue', (float) $snap['last_30_days']['revenue'] );
		$repo->put( $date, 0, 'last_30_margin_pct', (float) $snap['last_30_days']['gross_margin_pct'] );
		$repo->put( $date, 0, 'last_30_attach_pct', (float) $snap['last_30_days']['attach_rate_pct'] );
		$repo->put( $date, 0, 'nics_open', (float) $snap['compliance']['nics_open'] );
		$repo->put( $date, 0, 'serials_in_stock', (float) $snap['inventory']['serials_in_stock'] );
		$repo->put( $date, 0, 'dead_stock_90d', (float) $snap['inventory']['dead_stock_90d'] );
		$repo->put( $date, 0, 'layaway_balance', (float) $snap['operations']['layaway_balance'] );
	}
}
