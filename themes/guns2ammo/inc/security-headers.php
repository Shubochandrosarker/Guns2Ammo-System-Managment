<?php
/**
 * Conservative browser security headers that are safe for WordPress,
 * WooCommerce checkout and third-party payment/booking integrations.
 *
 * A Content-Security-Policy is intentionally not guessed here; it requires a
 * report-only staging pass over every checkout, booking and embedded service.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'wp_headers', function ( $headers ) {
	$headers['X-Content-Type-Options'] = 'nosniff';
	$headers['X-Frame-Options']        = 'SAMEORIGIN';
	$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
	$headers['Permissions-Policy']     = 'camera=(), microphone=(), geolocation=()';
	if ( is_ssl() ) {
		$headers['Strict-Transport-Security'] = 'max-age=31536000';
	}
	return $headers;
}, 20 );

add_action( 'send_headers', function () {
	header_remove( 'X-Powered-By' );
}, 20 );
