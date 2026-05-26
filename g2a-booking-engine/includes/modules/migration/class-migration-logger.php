<?php
/**
 * Migration Logger — writes structured events into g2ab_logs.
 *
 * Static helper. Designed to never throw; logging failures are silent
 * (we log to error_log as a last resort).
 *
 * @package G2AB\Modules\Migration
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Migration_Logger {

	/**
	 * Log a structured migration event.
	 *
	 * @param string $event_type  e.g. 'migration_started', 'record_imported', 'adapter_detect_failed'.
	 * @param array  $context     Arbitrary context — JSON-encoded into logs table.
	 * @param string $severity    info | warning | error
	 * @param int    $booking_id  Optional FK if the log relates to a specific booking.
	 */
	public static function log( $event_type, $context = array(), $severity = 'info', $booking_id = null ) {
		global $wpdb;

		$table = $wpdb->prefix . 'g2ab_logs';

		// Log table must exist; if not, fall back to error_log.
		try {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( ! $exists ) {
				error_log( sprintf( '[G2AB Migration] %s: %s', $event_type, wp_json_encode( $context ) ) );
				return;
			}

			$wpdb->insert(
				$table,
				array(
					'booking_id' => $booking_id ? (int) $booking_id : null,
					'user_id'    => get_current_user_id() ?: null,
					'event_type' => 'migration_' . sanitize_key( $event_type ),
					'severity'   => in_array( $severity, array( 'info', 'warning', 'error' ), true ) ? $severity : 'info',
					'message'    => isset( $context['message'] ) ? (string) $context['message'] : ucfirst( str_replace( '_', ' ', $event_type ) ),
					'context'    => wp_json_encode( $context ),
					'ip_address' => self::get_ip(),
					'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : null,
					'created_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		} catch ( \Throwable $e ) {
			error_log( sprintf( '[G2AB Migration] LOG FAIL %s: %s', $event_type, $e->getMessage() ) );
		}
	}

	private static function get_ip() {
		if ( function_exists( 'g2ab_get_client_ip' ) ) return g2ab_get_client_ip();
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null;
	}

	/**
	 * Append to a migration_runs row's log column (running tail).
	 */
	public static function append_to_run( $run_id, $line ) {
		global $wpdb;
		$run_id = (int) $run_id;
		if ( ! $run_id ) return;

		$table = $wpdb->prefix . 'g2ab_migration_runs';
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT log FROM {$table} WHERE id = %d", $run_id ) );
		$ts = current_time( 'mysql' );
		$new = (string) $current . sprintf( "[%s] %s\n", $ts, $line );

		// Cap at ~256KB to avoid unbounded growth on huge migrations.
		if ( strlen( $new ) > 262144 ) {
			$new = "...truncated...\n" . substr( $new, -200000 );
		}

		$wpdb->update( $table, array( 'log' => $new ), array( 'id' => $run_id ), array( '%s' ), array( '%d' ) );
	}
}
