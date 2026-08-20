<?php
/**
 * Uninstall.
 *
 * The reward ledger is money. Dropping it on plugin delete would destroy the
 * only record of what members are owed, so tables are kept unless someone
 * has explicitly opted in by setting the g2ar_drop_data_on_uninstall option.
 *
 * @package G2AR
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$g2ar_drop = get_option( 'g2ar_drop_data_on_uninstall', 'no' );

delete_option( 'g2ar_daily_salt' );

$timestamp = wp_next_scheduled( 'g2ar_daily_maintenance' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'g2ar_daily_maintenance' );
}

foreach ( array( 'administrator', 'shop_manager' ) as $g2ar_role_name ) {
	$g2ar_role = get_role( $g2ar_role_name );
	if ( $g2ar_role ) {
		$g2ar_role->remove_cap( 'g2ar_manage_referrals' );
	}
}

if ( 'yes' !== $g2ar_drop ) {
	return;
}

global $wpdb;

foreach ( array( 'events', 'balances', 'rewards', 'conversions', 'visits', 'referrers' ) as $g2ar_table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- table name built from $wpdb->prefix during an explicit opt-in uninstall.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'g2ar_' . $g2ar_table );
}

delete_option( 'g2ar_settings' );
delete_option( 'g2ar_db_version' );
delete_option( 'g2ar_drop_data_on_uninstall' );
