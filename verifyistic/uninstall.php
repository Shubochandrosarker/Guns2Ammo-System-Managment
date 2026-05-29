<?php
/**
 * Verifyistic Uninstall Script
 * Runs when plugin is deleted from WP admin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom table
$table = $wpdb->prefix . 'verifyistic_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore

// Delete all plugin options
$options = array(
    'verifyistic_enabled',
    'verifyistic_min_age',
    'verifyistic_mode',
    'verifyistic_id_verification',
    'verifyistic_logo',
    'verifyistic_logo_max_width',
    'verifyistic_heading_text',
    'verifyistic_message_text',
    'verifyistic_btn_yes_text',
    'verifyistic_btn_no_text',
    'verifyistic_btn_yes_color',
    'verifyistic_btn_no_color',
    'verifyistic_btn_yes_text_color',
    'verifyistic_btn_no_text_color',
    'verifyistic_btn_style',
    'verifyistic_font_color',
    'verifyistic_popup_bg_color',
    'verifyistic_overlay_color',
    'verifyistic_overlay_opacity',
    'verifyistic_popup_width',
    'verifyistic_accent_color',
    'verifyistic_remember_me',
    'verifyistic_cookie_days',
    'verifyistic_webhook_url',
    'verifyistic_webhook_enabled',
    'verifyistic_webhook_secret',
    'verifyistic_exclude_pages',
    'verifyistic_redirect_url',
    'verifyistic_custom_css',
    'verifyistic_privacy_text',
    'verifyistic_id_upload_label',
    'verifyistic_selfie_label',
    'verifyistic_db_version',
);

foreach ( $options as $option ) {
    delete_option( $option );
}
