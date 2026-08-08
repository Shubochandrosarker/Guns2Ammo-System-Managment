<?php
/**
 * Messageistic uninstall handler.
 *
 * Removes plugin data only when the admin has explicitly opted in via the
 * `messageistic_remove_data_on_uninstall` option. Otherwise settings, tables,
 * and message history are preserved.
 *
 * @package Messageistic
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'messageistic_remove_data_on_uninstall' ) ) {
    return;
}

global $wpdb;

$tables = [
    $wpdb->prefix . 'messageistic_contacts',
    $wpdb->prefix . 'messageistic_messages',
    $wpdb->prefix . 'messageistic_conversations',
    $wpdb->prefix . 'messageistic_campaigns',
    $wpdb->prefix . 'messageistic_campaign_recipients',
    $wpdb->prefix . 'messageistic_templates',
    $wpdb->prefix . 'messageistic_automations',
    $wpdb->prefix . 'messageistic_logs',
    $wpdb->prefix . 'messageistic_optouts',
    $wpdb->prefix . 'messageistic_provider_meta',
    $wpdb->prefix . 'messageistic_consent_events',
    $wpdb->prefix . 'messageistic_locations',
    $wpdb->prefix . 'messageistic_automation_steps',
    $wpdb->prefix . 'messageistic_automation_runs',
    $wpdb->prefix . 'messageistic_conversation_notes',
    $wpdb->prefix . 'messageistic_conversation_events',
    $wpdb->prefix . 'messageistic_conversion_events',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}

$options = [
    'messageistic_db_version',
    'messageistic_general_settings',
    'messageistic_active_provider',
    'messageistic_provider_settings',
    'messageistic_provider_health',
    'messageistic_remove_data_on_uninstall',
    'messageistic_cron_last_run',
    'messageistic_consent_migrated',
    'messageistic_pilot_monitor_snapshot',
    'messageistic_pilot_monitor_reviewed_at',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

$roles = [ 'administrator', 'shop_manager' ];
$caps  = [
    'manage_messageistic',
    'view_messageistic',
    'send_messageistic_sms',
    'manage_messageistic_campaigns',
    'manage_messageistic_settings',
    'manage_messageistic_providers',
    'manage_messageistic_inbox',
];

foreach ( $roles as $role_name ) {
    $role = get_role( $role_name );
    if ( ! $role ) {
        continue;
    }
    foreach ( $caps as $cap ) {
        $role->remove_cap( $cap );
    }
}
