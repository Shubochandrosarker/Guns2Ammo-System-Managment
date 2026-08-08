<?php
/**
 * Uninstall — remove plugin tables, options, and the protected attachment
 * storage directory.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = [
	$wpdb->prefix . 'wpistic_formistic_submissions',
	$wpdb->prefix . 'wpistic_formistic_replies',
	$wpdb->prefix . 'wpistic_formistic_attachments',
	$wpdb->prefix . 'wpistic_formistic_notes',
	$wpdb->prefix . 'wpistic_formistic_impressions',
	$wpdb->prefix . 'wpistic_formistic_ai_meta',
	$wpdb->prefix . 'wpistic_formistic_subscribers',
];
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

$options = [
	// Schema + general.
	'wpistic_formistic_db_version',
	'wpistic_formistic_notify_admin',
	'wpistic_formistic_notify_email',
	'wpistic_formistic_reply_from_name',
	'wpistic_formistic_reply_from_email',
	'wpistic_formistic_reply_signature',
	'wpistic_formistic_emails_disabled',
	'wpistic_formistic_trusted_proxies',
	// Captures.
	'wpistic_formistic_capture_cf7',
	'wpistic_formistic_capture_wpforms',
	'wpistic_formistic_capture_gform',
	'wpistic_formistic_capture_fluent',
	'wpistic_formistic_capture_g2a',
	'wpistic_formistic_capture_wpmail',
	// Spam.
	'wpistic_formistic_spam_recaptcha_enabled',
	'wpistic_formistic_spam_recaptcha_site_key',
	'wpistic_formistic_spam_recaptcha_secret_key',
	'wpistic_formistic_spam_recaptcha_threshold',
	'wpistic_formistic_spam_turnstile_enabled',
	'wpistic_formistic_spam_turnstile_site_key',
	'wpistic_formistic_spam_turnstile_secret_key',
	'wpistic_formistic_spam_akismet_enabled',
	'wpistic_formistic_spam_ip_blocklist',
	'wpistic_formistic_spam_rate_limit_enabled',
	'wpistic_formistic_spam_rate_limit_max',
	'wpistic_formistic_spam_rate_limit_window',
	// Auto-responder.
	'wpistic_formistic_ar_enabled',
	'wpistic_formistic_ar_subject',
	'wpistic_formistic_ar_body',
	// Attachments.
	'wpistic_formistic_att_enabled',
	'wpistic_formistic_att_max_size_mb',
	'wpistic_formistic_att_allowed_types',
	// GDPR.
	'wpistic_formistic_gdpr_consent_enabled',
	'wpistic_formistic_gdpr_required',
	'wpistic_formistic_gdpr_consent_text',
	'wpistic_formistic_gdpr_autopurge_enabled',
	'wpistic_formistic_gdpr_autopurge_days',
	// Webhooks.
	'wpistic_formistic_webhook_enabled',
	'wpistic_formistic_webhook_urls',
	'wpistic_formistic_webhook_secret',
	// Reply templates (v1.3).
	'wpistic_formistic_reply_templates',
	// AI & Automation.
	'wpistic_formistic_ai_provider',
	'wpistic_formistic_ai_endpoint',
	'wpistic_formistic_ai_model',
	'wpistic_formistic_ai_api_key',
	'wpistic_formistic_ai_smart_reply_enabled',
	'wpistic_formistic_ai_auto_reply_enabled',
	'wpistic_formistic_ai_auto_reply_subject',
	'wpistic_formistic_ai_auto_reply_rules',
	'wpistic_formistic_ai_faq_text',
	'wpistic_formistic_ai_kb_text',
	'wpistic_formistic_ai_google_sheets_urls',
	'wpistic_formistic_ai_text_sources',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete all custom forms (formistic_form CPT) created by the builder.
$form_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'formistic_form' )
);
foreach ( (array) $form_ids as $fid ) {
	wp_delete_post( (int) $fid, true );
}

// Clear the auto-purge cron event.
$ts = wp_next_scheduled( 'wpistic_formistic_daily_cleanup' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'wpistic_formistic_daily_cleanup' );
}

// Wipe the protected storage directory.
$uploads = wp_get_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'formistic-form';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $it as $path ) {
		if ( $path->isDir() ) {
			rmdir( $path->getPathname() );
		} else {
			unlink( $path->getPathname() );
		}
	}
	rmdir( $dir );
}
