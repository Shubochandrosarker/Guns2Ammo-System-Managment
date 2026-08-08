<?php

namespace G2A\POS\Reporting;

use G2A\POS\Database\Repository;

final class ReportingService extends Repository {

	public function register_x_report( int $register_session_id ): array {
		global $wpdb;
		$orders    = $this->table( 'g2a_pos_orders' );
		$sessions  = $this->table( 'g2a_register_sessions' );
		$movements = $this->table( 'g2a_cash_movements' );

		$session = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sessions} WHERE id = %d LIMIT 1", $register_session_id ), ARRAY_A );
		if ( ! $session ) {
			return array( 'error' => 'Register session not found.' );
		}

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(1) AS orders, COALESCE(SUM(grand_total),0) AS grand, COALESCE(SUM(tax_total),0) AS tax, COALESCE(SUM(discount_total),0) AS discount
             FROM {$orders} WHERE register_session_id = %d AND status NOT IN ('void','cancelled')",
				$register_session_id
			),
			ARRAY_A
		) ?: array();

		$by_method = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT payment_method, COUNT(1) AS n, COALESCE(SUM(grand_total),0) AS total
             FROM {$orders} WHERE register_session_id = %d AND status NOT IN ('void','cancelled')
             GROUP BY payment_method",
				$register_session_id
			),
			ARRAY_A
		) ?: array();

		$cash_in  = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$movements} WHERE register_session_id = %d AND movement_type = 'cash_in'",
				$register_session_id
			)
		);
		$cash_out = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount),0) FROM {$movements} WHERE register_session_id = %d AND movement_type = 'cash_out'",
				$register_session_id
			)
		);

		$cash_sales = 0.0;
		foreach ( $by_method as $m ) {
			if ( in_array( strtolower( (string) $m['payment_method'] ), array( 'cash' ), true ) ) {
				$cash_sales = (float) $m['total'];
				break;
			}
		}

		return array(
			'session'           => $session,
			'totals'            => $totals,
			'by_payment_method' => $by_method,
			'expected_cash'     => (float) $session['opening_cash'] + $cash_sales + $cash_in - $cash_out,
			'cash_in'           => $cash_in,
			'cash_out'          => $cash_out,
			'report_type'       => $session['status'] === 'closed' ? 'Z' : 'X',
		);
	}

	public function sales_by_employee( string $from, string $to ): array {
		global $wpdb;
		$orders = $this->table( 'g2a_pos_orders' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT employee_id, COUNT(1) AS orders, COALESCE(SUM(grand_total),0) AS total
             FROM {$orders}
             WHERE created_at BETWEEN %s AND %s
               AND status NOT IN ('void','cancelled')
             GROUP BY employee_id
             ORDER BY total DESC",
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
		return $rows;
	}

	public function attach_rate( string $from, string $to ): array {
		global $wpdb;
		$orders                 = $this->table( 'g2a_pos_orders' );
		$items                  = $this->table( 'g2a_pos_order_items' );
		$firearm_orders         = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT o.id) FROM {$orders} o
             INNER JOIN {$items} i ON i.pos_order_id = o.id
             WHERE i.item_type = 'firearm' AND o.created_at BETWEEN %s AND %s
               AND o.status NOT IN ('void','cancelled')",
				$from,
				$to
			)
		);
		$firearm_with_ammo      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT o.id) FROM {$orders} o
             INNER JOIN {$items} i ON i.pos_order_id = o.id
             INNER JOIN {$items} j ON j.pos_order_id = o.id AND j.item_type = 'ammunition'
             WHERE i.item_type = 'firearm' AND o.created_at BETWEEN %s AND %s
               AND o.status NOT IN ('void','cancelled')",
				$from,
				$to
			)
		);
		$firearm_with_accessory = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT o.id) FROM {$orders} o
             INNER JOIN {$items} i ON i.pos_order_id = o.id
             INNER JOIN {$items} j ON j.pos_order_id = o.id AND j.item_type = 'accessory'
             WHERE i.item_type = 'firearm' AND o.created_at BETWEEN %s AND %s
               AND o.status NOT IN ('void','cancelled')",
				$from,
				$to
			)
		);
		return array(
			'firearm_orders'                => $firearm_orders,
			'firearm_orders_with_ammo'      => $firearm_with_ammo,
			'firearm_orders_with_accessory' => $firearm_with_accessory,
			'attach_rate_ammo'              => $firearm_orders > 0 ? round( $firearm_with_ammo / $firearm_orders, 3 ) : 0,
			'attach_rate_accessory'         => $firearm_orders > 0 ? round( $firearm_with_accessory / $firearm_orders, 3 ) : 0,
		);
	}

	public function tax_summary( string $from, string $to ): array {
		global $wpdb;
		$orders = $this->table( 'g2a_pos_orders' );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(1) AS orders, COALESCE(SUM(subtotal),0) AS taxable_subtotal, COALESCE(SUM(tax_total),0) AS tax_collected, COALESCE(SUM(grand_total),0) AS grand
             FROM {$orders}
             WHERE created_at BETWEEN %s AND %s
               AND status NOT IN ('void','cancelled')",
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
		return $row;
	}
}
