<?php
/**
 * Uninstall — remove plugin tables, options, and the protected attachment
 * storage directory.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = [
	$wpdb->prefix . 'WPISTIC_CF_submissions',
	$wpdb->prefix . 'WPISTIC_CF_replies',
	$wpdb->prefix . 'WPISTIC_CF_attachments',
];
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
}

$options = [
	// Schema + general.
	'WPISTIC_CF_db_version',
	'WPISTIC_CF_notify_admin',
	'WPISTIC_CF_notify_email',
	'WPISTIC_CF_reply_from_name',
	'WPISTIC_CF_reply_from_email',
	'WPISTIC_CF_reply_signature',
	// Captures.
	'WPISTIC_CF_capture_cf7',
	'WPISTIC_CF_capture_wpforms',
	'WPISTIC_CF_capture_gform',
	'WPISTIC_CF_capture_fluent',
	'WPISTIC_CF_capture_g2a',
	'WPISTIC_CF_capture_wpmail',
	// Spam.
	'WPISTIC_CF_spam_recaptcha_enabled',
	'WPISTIC_CF_spam_recaptcha_site_key',
	'WPISTIC_CF_spam_recaptcha_secret_key',
	'WPISTIC_CF_spam_recaptcha_threshold',
	'WPISTIC_CF_spam_turnstile_enabled',
	'WPISTIC_CF_spam_turnstile_site_key',
	'WPISTIC_CF_spam_turnstile_secret_key',
	'WPISTIC_CF_spam_akismet_enabled',
	'WPISTIC_CF_spam_ip_blocklist',
	'WPISTIC_CF_spam_rate_limit_enabled',
	'WPISTIC_CF_spam_rate_limit_max',
	'WPISTIC_CF_spam_rate_limit_window',
	// Auto-responder.
	'WPISTIC_CF_ar_enabled',
	'WPISTIC_CF_ar_subject',
	'WPISTIC_CF_ar_body',
	// Attachments.
	'WPISTIC_CF_att_enabled',
	'WPISTIC_CF_att_max_size_mb',
	'WPISTIC_CF_att_allowed_types',
	// GDPR.
	'WPISTIC_CF_gdpr_consent_enabled',
	'WPISTIC_CF_gdpr_required',
	'WPISTIC_CF_gdpr_consent_text',
	'WPISTIC_CF_gdpr_autopurge_enabled',
	'WPISTIC_CF_gdpr_autopurge_days',
	// Webhooks.
	'WPISTIC_CF_webhook_enabled',
	'WPISTIC_CF_webhook_urls',
	'WPISTIC_CF_webhook_secret',
	// Reply templates (v1.3).
	'WPISTIC_CF_reply_templates',
];
foreach ( $options as $option ) {
	delete_option( $option );
}

// Delete all custom forms (WPISTIC_CF_form CPT) created by the builder.
$form_ids = $wpdb->get_col(
	$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'WPISTIC_CF_form' )
);
foreach ( (array) $form_ids as $fid ) {
	wp_delete_post( (int) $fid, true );
}

// Clear the auto-purge cron event.
$ts = wp_next_scheduled( 'WPISTIC_CF_daily_cleanup' );
if ( $ts ) {
	wp_unschedule_event( $ts, 'WPISTIC_CF_daily_cleanup' );
}

// Wipe the protected storage directory.
$uploads = wp_get_upload_dir();
$dir     = trailingslashit( $uploads['basedir'] ) . 'wpistic-contact-form';
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
