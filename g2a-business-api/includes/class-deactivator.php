<?php
/**
 * Deactivation: intentionally minimal — preserves data by design.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {
	public static function deactivate(): void {
		// Unschedule any cron hooks the plugin owns so WP-Cron doesn't try to
		// fire them against a deactivated plugin. Data lives on — full cleanup
		// happens in uninstall.php only.
		$hooks = array(
			'g2aba_generate_insights',
			'g2aba_run_agent',
		);
		foreach ( $hooks as $hook ) {
			$ts = wp_next_scheduled( $hook );
			while ( $ts ) {
				wp_unschedule_event( $ts, $hook );
				$ts = wp_next_scheduled( $hook );
			}
		}
	}
}
