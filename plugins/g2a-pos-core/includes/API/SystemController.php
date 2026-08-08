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

		try {
			$integrity_issues = \G2A\POS\Wholesalers\WholesalerIntegrityChecker::issues();
		} catch ( \Throwable $e ) {
			$integrity_issues = array(
				array(
					'type'          => 'check_failed',
					'provider_code' => '',
					'message'       => $e->getMessage(),
				),
			);
		}

		// plugin_version is the code that is running; db_version is the schema
		// the migrator last finished. They are normally equal — when they are
		// not, the plugin files were updated but the migration has not run yet,
		// and a client that shows only one of them reports a half-truth.
		$db_version = (string) get_option( 'g2a_pos_core_db_version' );

		return array(
			'ok'                   => ! in_array( false, $checks, true ),
			'plugin_version'       => G2A_POS_CORE_VERSION,
			'db_version'           => $db_version,
			'migration_pending'    => $db_version !== G2A_POS_CORE_VERSION,
			'tables'               => $checks,
			'wholesaler_integrity' => array(
				'ok'     => empty( $integrity_issues ),
				'issues' => $integrity_issues,
			),
			'time'                 => current_time( 'mysql' ),
		);
	}
}
