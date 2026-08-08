<?php
/**
 * Uninstall handler.
 *
 * @package Memberistic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings    = get_option( 'memberistic_settings', array() );
$delete_data = is_array( $settings ) && isset( $settings['delete_data_on_uninstall'] ) && 'yes' === $settings['delete_data_on_uninstall'];

if ( ! $delete_data ) {
	return;
}

$allowed_tables = array(
	'memberistic_plans',
	'memberistic_memberships',
	'memberistic_people',
	'memberistic_payments',
	'memberistic_activity',
	'memberistic_checkins',
	'memberistic_notes',
	'memberistic_logs',
);

foreach ( $allowed_tables as $table ) {
	if ( ! preg_match( '/^memberistic_[a-z_]+$/', $table ) ) {
		continue;
	}

	$table_name = esc_sql( $wpdb->prefix . $table );
	$wpdb->query( "DROP TABLE IF EXISTS `{$table_name}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'memberistic_settings' );
delete_option( 'memberistic_db_version' );
delete_option( 'memberistic_version' );
