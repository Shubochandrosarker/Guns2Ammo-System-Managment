<?php
/**
 * Procedural helper functions.
 *
 * Loaded eagerly from the bootstrap. Keep this file thin — anything bigger
 * belongs in a dedicated class. Use these helpers from templates and external
 * code that doesn't want to instantiate models directly.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the absolute path to a plugin file.
 *
 * @param string $relative Relative path inside the plugin folder.
 * @return string
 */
function g2ab_path( $relative = '' ) {
	return G2AB_PATH . ltrim( $relative, '/' );
}

/**
 * Get the URL to a plugin asset.
 *
 * @param string $relative Relative path inside the plugin folder.
 * @return string
 */
function g2ab_url( $relative = '' ) {
	return G2AB_URL . ltrim( $relative, '/' );
}

/**
 * Generate a v4 UUID.
 *
 * Used for public-facing booking identifiers so we don't expose auto-increment
 * IDs in URLs or emails.
 *
 * @return string
 */
function g2ab_generate_uuid() {
	return wp_generate_uuid4();
}

/**
 * Get the full table name (with WP prefix) for a g2ab table.
 *
 * @param string $name Short table name (e.g., 'bookings').
 * @return string
 */
function g2ab_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'g2ab_' . $name;
}

/**
 * Get a plugin option with a default fallback.
 *
 * @param string $key     Option key (without prefix — auto-prefixed with g2ab_).
 * @param mixed  $default Default value if missing.
 * @return mixed
 */
function g2ab_get_option( $key, $default = false ) {
	$key = 0 === strpos( $key, 'g2ab_' ) ? $key : 'g2ab_' . $key;
	return get_option( $key, $default );
}

/**
 * Update a plugin option.
 *
 * @param string $key   Option key (auto-prefixed).
 * @param mixed  $value Value.
 * @return bool
 */
function g2ab_update_option( $key, $value ) {
	$key = 0 === strpos( $key, 'g2ab_' ) ? $key : 'g2ab_' . $key;
	return update_option( $key, $value );
}

/**
 * Check if the current user can perform a g2ab capability.
 *
 * Wrapper to keep capability strings consistent across the codebase.
 *
 * @param string $cap Capability without prefix (e.g., 'manage_bookings').
 * @return bool
 */
function g2ab_current_user_can( $cap ) {
	$cap = 0 === strpos( $cap, 'g2ab_' ) || 0 === strpos( $cap, 'manage_g2ab' ) || 0 === strpos( $cap, 'view_g2ab' ) || 0 === strpos( $cap, 'delete_g2ab' )
		? $cap
		: 'manage_g2ab_' . $cap;
	return current_user_can( $cap );
}

/**
 * Get the IP of the current request, respecting common proxy headers.
 *
 * @return string
 */
function g2ab_get_client_ip() {
	$candidates = array(
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'REMOTE_ADDR',
	);

	foreach ( $candidates as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			// X-Forwarded-For can be a comma-separated list. Take the first.
			if ( false !== strpos( $ip, ',' ) ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}

	return '';
}

/**
 * Idempotency check for webhook events.
 *
 * Returns true the first time the (gateway, event_id) pair is seen and false on
 * every subsequent call. Stored in a transient keyed by hash; cleaned up
 * automatically after 24h. Webhook processors must call this BEFORE applying
 * any side effects.
 *
 * @param string $gateway  Gateway id (e.g. 'stripe', 'paypal', 'authnet', 'fortis').
 * @param string $event_id Provider-supplied event identifier.
 * @return bool True if event is new (process it), false if duplicate (skip).
 */
function g2ab_webhook_event_is_new( $gateway, $event_id ) {
	$gateway  = sanitize_key( (string) $gateway );
	$event_id = sanitize_text_field( (string) $event_id );
	if ( '' === $gateway || '' === $event_id ) {
		// Defensive: if either is missing, treat as new and let downstream code decide.
		return true;
	}
	$key = 'g2ab_wh_' . md5( $gateway . '|' . $event_id );
	if ( get_transient( $key ) ) {
		return false;
	}
	set_transient( $key, 1, DAY_IN_SECONDS );
	return true;
}

/**
 * Cross-check a gateway-reported amount against the booking total.
 *
 * Returns true when the paid amount is within 1¢ tolerance of either the full
 * booking total or the deposit amount stored in metadata. Webhook processors
 * call this before flipping a booking to "paid" to reject undercharge or
 * misconfigured-price scenarios.
 *
 * @param object $booking      Booking row (must include total_amount, metadata).
 * @param float  $amount_paid  Amount reported by the gateway.
 * @return bool
 */
function g2ab_validate_payment_amount( $booking, $amount_paid ) {
	if ( ! is_object( $booking ) ) {
		return false;
	}
	$amount_paid = round( (float) $amount_paid, 2 );
	$total       = round( (float) ( $booking->total_amount ?? 0 ), 2 );
	$tolerance   = 0.01;

	if ( abs( $amount_paid - $total ) <= $tolerance ) {
		return true;
	}

	// Accept the configured deposit amount as a valid partial payment.
	$meta = json_decode( (string) ( $booking->metadata ?? '' ), true );
	if ( is_array( $meta ) && isset( $meta['due_now'] ) ) {
		$due_now = round( (float) $meta['due_now'], 2 );
		if ( $due_now > 0 && abs( $amount_paid - $due_now ) <= $tolerance ) {
			return true;
		}
	}

	return false;
}

/**
 * Build a signed access token for an invoice.
 *
 * Signature is HMAC-SHA256( uuid + '|' + exp, secret ). Used so invoice URLs
 * can be shared by customers without exposing PII to anyone who guesses a UUID.
 *
 * @param string $uuid       Booking UUID.
 * @param int    $expires_in Seconds until expiry (default 30 days).
 * @return string `<exp>.<sig>` token.
 */
function g2ab_invoice_sign_token( $uuid, $expires_in = null ) {
	$expires_in = (int) ( $expires_in ?? 30 * DAY_IN_SECONDS );
	$exp        = time() + max( 60, $expires_in );
	$secret     = g2ab_invoice_signing_secret();
	$sig        = hash_hmac( 'sha256', $uuid . '|' . $exp, $secret );
	return $exp . '.' . $sig;
}

/**
 * Verify a signed invoice token. Returns true if the token matches and isn't expired.
 *
 * @param string $uuid  Booking UUID claimed by the URL.
 * @param string $token Token from the URL (format: `<exp>.<sig>`).
 * @return bool
 */
function g2ab_invoice_verify_token( $uuid, $token ) {
	if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
		return false;
	}
	[ $exp, $sig ] = explode( '.', $token, 2 );
	$exp = (int) $exp;
	if ( $exp < time() ) {
		return false;
	}
	$secret   = g2ab_invoice_signing_secret();
	$expected = hash_hmac( 'sha256', $uuid . '|' . $exp, $secret );
	return hash_equals( $expected, (string) $sig );
}

/**
 * Get (or lazily create) the secret used to sign invoice tokens.
 * Stored in wp_options, autoload no, so it's never sent on every page load.
 *
 * @return string
 */
function g2ab_invoice_signing_secret() {
	$secret = get_option( 'g2ab_invoice_signing_secret', '' );
	if ( ! $secret ) {
		$secret = wp_generate_password( 64, true, true );
		add_option( 'g2ab_invoice_signing_secret', $secret, '', 'no' );
	}
	return $secret;
}
