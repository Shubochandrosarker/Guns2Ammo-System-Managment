<?php
/**
 * Deactivation handler.
 *
 * Runs when the plugin is deactivated (NOT deleted). Should be reversible.
 * DO NOT drop tables, delete options, or remove user data here — that's
 * uninstall.php's job.
 *
 * Responsibilities:
 *   - Unschedule cron events.
 *   - Clear plugin transients (cached resources, availability calendars).
 *   - Flush rewrite rules.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class G2AB_Deactivator
 */
final class G2AB_Deactivator {

	/**
	 * Run deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {

		// 1. Unschedule cron events.
		$crons = array(
			'g2ab_cleanup_expired_reservations',
			'g2ab_send_booking_reminders',
			'g2ab_send_notification',
			'g2ab_daily_reports',
			// Email-automation module reminder tick — registered in
			// includes/modules/email-automation/class-email-cron.php
			// but was previously missing from the deactivation sweep.
			'g2ab_email_reminder_tick',
		);

		foreach ( $crons as $cron ) {
			$timestamp = wp_next_scheduled( $cron );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $cron );
			}
			wp_clear_scheduled_hook( $cron );
		}

		// 2. Clear plugin transients (only g2ab_* — leave site-wide cache alone).
		self::clear_plugin_transients();

		// 3. Flush rewrite rules so REST routes deregister cleanly.
		flush_rewrite_rules();

		/**
		 * Fires after deactivation finishes.
		 */
		do_action( 'g2ab_deactivated' );
	}

	/**
	 * Delete all transients with the g2ab_ prefix.
	 *
	 * @return void
	 */
	private static function clear_plugin_transients() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_g2ab\\_%' OR option_name LIKE '\\_transient\\_timeout\\_g2ab\\_%'" );

		// Clear any object-cached entries.
		wp_cache_flush_group( 'g2ab' );
	}
}
