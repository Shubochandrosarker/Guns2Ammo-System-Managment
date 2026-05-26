<?php
/**
 * Uninstall handler — runs when the user deletes the plugin from the WP admin.
 *
 * Honors the `g2ab_remove_data_on_uninstall` setting. If that setting is off
 * (default), only the plugin code is removed; bookings, payments, forms and
 * settings stay intact so the data survives accidental deletes.
 *
 * Set the option to `1` (admin → G2A Booking → Settings → "Remove all data on
 * uninstall") to wipe everything cleanly.
 *
 * @package G2AB
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Per-site cleanup callback.
$g2ab_cleanup = static function () use ( $wpdb ) {

	$remove_data = (int) get_option( 'g2ab_remove_data_on_uninstall', 0 );

	if ( 1 !== $remove_data ) {
		return;
	}

	// Drop custom tables.
	$tables = array(
		$wpdb->prefix . 'g2ab_logs',
		$wpdb->prefix . 'g2ab_migration_runs',
		$wpdb->prefix . 'g2ab_payments',
		$wpdb->prefix . 'g2ab_availability_rules',
		$wpdb->prefix . 'g2ab_form_fields',
		$wpdb->prefix . 'g2ab_forms',
		$wpdb->prefix . 'g2ab_booking_types',
		$wpdb->prefix . 'g2ab_resources',
		$wpdb->prefix . 'g2ab_bookings',
	);

	foreach ( $tables as $table ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// Delete all g2ab_* options.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'g2ab\_%' ESCAPE '\\\\'" );

	// Delete all g2ab_* user meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'g2ab\_%' ESCAPE '\\\\'" );

	// Clear scheduled events.
	$crons = array(
		'g2ab_send_notification',
		'g2ab_cleanup_expired_reservations',
		'g2ab_send_booking_reminders',
		'g2ab_daily_reports',
	);
	foreach ( $crons as $cron ) {
		$timestamp = wp_next_scheduled( $cron );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $cron );
		}
		wp_clear_scheduled_hook( $cron );
	}

	// Remove custom roles.
	$roles = array( 'g2ab_manager', 'g2ab_staff', 'g2ab_instructor' );
	foreach ( $roles as $role ) {
		remove_role( $role );
	}

	// Remove custom caps from administrator.
	$admin_caps = array(
		'manage_g2ab_bookings',
		'delete_g2ab_bookings',
		'manage_g2ab_resources',
		'manage_g2ab_forms',
		'manage_g2ab_payments',
		'manage_g2ab_settings',
		'view_g2ab_reports',
	);
	$admin = get_role( 'administrator' );
	if ( $admin ) {
		foreach ( $admin_caps as $cap ) {
			$admin->remove_cap( $cap );
		}
	}
};

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		$g2ab_cleanup();
		restore_current_blog();
	}
} else {
	$g2ab_cleanup();
}
