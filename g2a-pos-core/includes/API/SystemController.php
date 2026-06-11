<?php

namespace G2A\POS\API;

use WP_REST_Request;

final class SystemController {

	public static function health( WP_REST_Request $request ) {
		global $wpdb;

		$tables = array(
			'g2a_pos_orders',
			'g2a_pos_order_items',
			'g2a_inventory_logs',
			'g2a_serial_registry',
			'g2a_register_sessions',
			'g2a_cash_movements',
			'g2a_compliance_logs',
			'g2a_audit_logs',
			'g2a_sync_queue',
			'g2a_barcodes',
		);

		$checks = array();
		foreach ( $tables as $table ) {
			$name             = $wpdb->prefix . $table;
			$exists           = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			$checks[ $table ] = $exists;
		}

		return array(
			'ok'         => ! in_array( false, $checks, true ),
			'db_version' => get_option( 'g2a_pos_core_db_version' ),
			'tables'     => $checks,
			'time'       => current_time( 'mysql' ),
		);
	}
}
