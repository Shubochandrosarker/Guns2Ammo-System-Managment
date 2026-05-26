<?php
/**
 * General helpers.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get a plugin setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Default value.
 */
function memberistic_get_setting( $key, $default = null ) {
	// Constant overrides for sensitive secrets. Defining any of these in
	// wp-config.php (recommended for live keys) takes precedence over the
	// stored option, and refuse-overwrite logic in the settings save +
	// REST update paths refuses to clobber the option while a constant
	// is set. Pattern: define( 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY', '...' );
	$constant_map = array(
		'stripe_test_secret_key'  => 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY',
		'stripe_live_secret_key'  => 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY',
		'stripe_webhook_secret'   => 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET',
	);
	if ( isset( $constant_map[ $key ] ) && defined( $constant_map[ $key ] ) ) {
		$const_val = constant( $constant_map[ $key ] );
		if ( is_string( $const_val ) && '' !== $const_val ) {
			return $const_val;
		}
	}

	$settings = get_option( 'memberistic_settings', array() );

	if ( ! is_array( $settings ) ) {
		return $default;
	}

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

/**
 * The list of secret-setting keys that should never be returned in
 * plain text via the REST settings endpoint, and whose option value
 * should be refused on save if a wp-config.php constant is in force.
 *
 * Filter via memberistic_secret_setting_keys.
 */
function memberistic_secret_setting_keys() {
	return apply_filters(
		'memberistic_secret_setting_keys',
		array(
			'stripe_test_secret_key',
			'stripe_live_secret_key',
			'stripe_webhook_secret',
		)
	);
}

/**
 * Whether a given setting key is currently locked by a wp-config.php constant.
 */
function memberistic_setting_is_locked_by_constant( $key ) {
	$constant_map = array(
		'stripe_test_secret_key' => 'MEMBERISTIC_STRIPE_TEST_SECRET_KEY',
		'stripe_live_secret_key' => 'MEMBERISTIC_STRIPE_LIVE_SECRET_KEY',
		'stripe_webhook_secret'  => 'MEMBERISTIC_STRIPE_WEBHOOK_SECRET',
	);
	if ( ! isset( $constant_map[ $key ] ) ) {
		return false;
	}
	$name = $constant_map[ $key ];
	if ( ! defined( $name ) ) {
		return false;
	}
	$val = constant( $name );
	return is_string( $val ) && '' !== $val;
}

/**
 * Mask a secret for display ("sk_live_***1234").
 */
function memberistic_mask_secret( $value ) {
	if ( ! is_string( $value ) || '' === $value ) {
		return '';
	}
	$len = strlen( $value );
	if ( $len <= 8 ) {
		return str_repeat( '*', $len );
	}
	$prefix_len = $len > 12 ? 7 : 3;
	return substr( $value, 0, $prefix_len ) . str_repeat( '*', 4 ) . substr( $value, -4 );
}

/**
 * Get filtered brand label.
 */
function memberistic_get_brand_label() {
	$label = (string) memberistic_get_setting( 'brand_label', 'Memberistic' );
	return apply_filters( 'memberistic_brand_label', $label );
}

/**
 * Resolve a mapped frontend page URL with an optional branded slug fallback.
 *
 * @param string $setting_key Page ID setting key.
 * @param string $fallback_slug Optional page slug to find if the setting is empty.
 * @param string $fallback_url Final fallback URL.
 */
function memberistic_get_page_url( $setting_key, $fallback_slug = '', $fallback_url = '' ) {
	$page_id = absint( memberistic_get_setting( $setting_key, 0 ) );
	$url     = $page_id ? get_permalink( $page_id ) : '';

	if ( $url && $fallback_slug ) {
		$page = get_post( $page_id );

		if ( $page && in_array( $page->post_name, memberistic_legacy_page_slugs(), true ) ) {
			$url = '';
		}
	}

	if ( ! $url && $fallback_slug ) {
		$page = get_page_by_path( sanitize_title( $fallback_slug ) );
		$url  = $page && 'trash' !== get_post_status( $page ) ? get_permalink( $page ) : '';
	}

	return $url ? $url : $fallback_url;
}

/**
 * Common old membership slugs that should not win over branded Memberistic pages.
 *
 * @return string[]
 */
function memberistic_legacy_page_slugs() {
	return array(
		'memberships',
		'memberships-2',
		'membership-checkout',
		'membership-checkout-2',
		'my-membership',
		'my-membership-2',
		'membership-renewal',
		'membership-renewal-2',
		'membership-login',
		'membership-login-2',
		'membership-thank-you',
		'membership-thank-you-2',
		'membership-payment-failed',
		'membership-payment-failed-2',
	);
}

/**
 * Build an admin URL for plugin screens.
 *
 * @param string               $page Page slug.
 * @param array<string, mixed> $args Query args.
 */
function memberistic_admin_url( $page, $args = array() ) {
	$args = array_merge( array( 'page' => $page ), $args );
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Get mapped admin notice messages.
 */
function memberistic_admin_notices() {
	if ( empty( $_GET['memberistic_notice'] ) ) {
		return;
	}

	$notice = sanitize_key( wp_unslash( $_GET['memberistic_notice'] ) );
	$type   = isset( $_GET['memberistic_notice_type'] ) ? sanitize_key( wp_unslash( $_GET['memberistic_notice_type'] ) ) : 'success';
	$type   = in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'success';

	$messages = array(
		'plan_saved'          => __( 'Plan saved successfully.', 'memberistic' ),
		'plan_deleted'        => __( 'Plan deleted successfully.', 'memberistic' ),
		'plan_deactivated'    => __( 'Plan deactivated successfully.', 'memberistic' ),
		'plan_delete_blocked' => __( 'This plan has active memberships and cannot be deleted.', 'memberistic' ),
		'plan_not_found'      => __( 'The requested plan was not found.', 'memberistic' ),
		'invalid_request'     => __( 'The request could not be verified.', 'memberistic' ),
		'settings_saved'      => __( 'Settings saved successfully.', 'memberistic' ),
		'member_saved'        => __( 'Membership saved successfully.', 'memberistic' ),
		'member_not_found'    => __( 'The requested membership was not found.', 'memberistic' ),
		'member_save_failed'  => __( 'Membership could not be saved.', 'memberistic' ),
		'person_added'        => __( 'Linked member added successfully.', 'memberistic' ),
		'person_limit_reached'=> __( 'This membership plan has reached its included people limit.', 'memberistic' ),
		'payment_added'       => __( 'Payment record added successfully.', 'memberistic' ),
		'checkin_added'       => __( 'Check-in recorded successfully.', 'memberistic' ),
		'note_added'          => __( 'Staff note added successfully.', 'memberistic' ),
		'pages_created'       => __( 'Required Memberistic pages were created or mapped successfully.', 'memberistic' ),
		'pages_remapped'      => __( 'Branded Memberistic pages were created and mapped successfully.', 'memberistic' ),
	);

	$message = $messages[ $notice ] ?? __( 'Memberistic notice.', 'memberistic' );

	printf(
		'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
		esc_attr( $type ),
		esc_html( $message )
	);
}

/**
 * Convert newline benefits to JSON.
 *
 * @param string $raw Raw textarea content.
 */
function memberistic_benefits_to_json( $raw ) {
	$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ?: array() ) );
	return wp_json_encode( array_values( $lines ) ) ?: '[]';
}

/**
 * Decode benefits JSON to lines.
 *
 * @param mixed $raw Raw JSON.
 */
function memberistic_json_to_lines( $raw ) {
	$decoded = json_decode( (string) $raw, true );

	if ( ! is_array( $decoded ) ) {
		return '';
	}

	return implode( "\n", array_map( 'strval', $decoded ) );
}

/**
 * Return allowed membership statuses.
 */
function memberistic_membership_statuses() {
	return array( 'pending', 'active', 'past_due', 'expired', 'cancelled', 'paused', 'comped', 'trial', 'suspended', 'needs_review' );
}

/**
 * Return allowed waiver statuses.
 */
function memberistic_waiver_statuses() {
	return array( 'missing', 'signed', 'expired', 'needs_review', 'rejected' );
}
