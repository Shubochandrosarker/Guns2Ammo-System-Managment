<?php
/**
 * Plugin Name: G2A Output Guard (must-use)
 * Description: Prevents stray PHP output (notices, warnings, whitespace after a
 *              "?>" in any plugin/theme) from breaking redirects and REST JSON.
 *              Fixes "white screen after saving settings / after login" (caused
 *              by 'headers already sent') and "Could not load times (HTTP 200 ·
 *              non-JSON)" in the booking widget — regardless of which other
 *              plugin is leaking the output.
 * Author: WordPressistic
 * Version: 1.0.0
 *
 * INSTALL: place this file in  wp-content/mu-plugins/g2a-output-guard.php
 * (create the mu-plugins folder if it doesn't exist). Must-use plugins load
 * automatically, before all normal plugins — which is exactly why this works.
 *
 * It is intentionally dependency-free and self-contained.
 *
 * @package G2A
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Why this is needed
 * ------------------
 * If anything echoes output early in the request (a PHP notice with display on,
 * a stray echo, or whitespace after a closing "?>" in some plugin/theme file),
 * PHP sends it to the browser immediately. Then when WordPress later calls
 * wp_redirect() — after saving Settings (options.php), after login
 * (wp-login.php), after checkout, etc. — PHP can't send the redirect header
 * because output already started ("headers already sent"). The redirect fails
 * and you get a blank white screen. The same early output, prepended to a REST
 * response, makes the JSON unparseable.
 *
 * The fix: start an output buffer at the very start of the request (mu-plugins
 * run first) for the request types that rely on redirects/JSON. Buffering means
 * nothing is actually sent until the end, so headers (including redirects) can
 * always be set. We also stop PHP from DISPLAYING errors on these requests
 * (they still go to the debug log) so notices can't leak into the output.
 */

$g2a_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';

$g2a_is_admin = ( false !== strpos( $g2a_uri, '/wp-admin/' ) );
$g2a_is_login = ( false !== strpos( $g2a_uri, 'wp-login.php' ) );
$g2a_is_rest  = ( false !== strpos( $g2a_uri, '/wp-json/' ) )
	|| ( false !== strpos( $g2a_uri, 'rest_route=' ) );

if ( $g2a_is_admin || $g2a_is_login || $g2a_is_rest ) {
	// Never let PHP notices/warnings render into these responses (they still
	// log if WP_DEBUG_LOG is on). This alone fixes the common case.
	@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.IniSet.display_errors_Disallowed

	if ( $g2a_is_rest ) {
		// REST: buffer and, at flush, strip any junk before the real JSON so the
		// body is always valid JSON. Only rewrites when a clean JSON document is
		// recovered; non-JSON responses are left untouched.
		ob_start(
			static function ( $buffer ) {
				if ( ! is_string( $buffer ) || '' === $buffer ) {
					return $buffer;
				}
				$trimmed = ltrim( $buffer );
				if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
					json_decode( trim( $buffer ) );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						return $buffer; // Already clean.
					}
				}
				$len      = strlen( $buffer );
				$attempts = 0;
				for ( $i = 0; $i < $len && $attempts < 40; $i++ ) {
					$ch = $buffer[ $i ];
					if ( '{' !== $ch && '[' !== $ch ) {
						continue;
					}
					$attempts++;
					$candidate = trim( substr( $buffer, $i ) );
					json_decode( $candidate );
					if ( JSON_ERROR_NONE === json_last_error() ) {
						return $candidate;
					}
				}
				return $buffer;
			}
		);
	} else {
		// Admin / login: a plain buffer is enough — it keeps headers unsent
		// until the end so wp_redirect() always works. Flushed normally at
		// request shutdown.
		ob_start();
	}
}
