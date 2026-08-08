<?php
/**
 * The `g2aba_session` HttpOnly cookie.
 *
 * REST callbacks don't emit output themselves — WP_REST_Server echoes the
 * body after `rest_pre_serve_request` — so PHP headers are still open at
 * that point (Cors::send_headers relies on the same fact). Login/logout
 * handlers therefore *queue* the cookie mutation here, and the queued
 * `Set-Cookie` header is emitted by our `rest_pre_serve_request` callback
 * (priority 5, before Cors at 15) right before the body is served.
 *
 * Attributes are fixed by the dashboard contract:
 *   HttpOnly; Secure; Path=/wp-json/g2a/v1; SameSite=<filterable>
 * SameSite defaults to `None` because app.guns2ammo.com is cross-origin
 * today; once the same-origin proxy lands, a site owner flips it to `Lax`
 * via the `g2aba_session_samesite` filter without a plugin release.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session_Cookie {
	public const NAME = 'g2aba_session';

	/**
	 * Queued header value, or null when no mutation is pending.
	 */
	private static ?string $pending = null;

	public static function register(): void {
		// Priority 5: before Cors::send_headers (15). Both only add headers,
		// so order is cosmetic, but the cookie should not depend on CORS
		// short-circuiting OPTIONS preflights.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'emit' ), 5 );
	}

	/**
	 * Queue a Set-Cookie establishing the session.
	 */
	public static function queue_set( string $token, ?int $lifetime = null ): void {
		self::$pending = self::build_header_value( $token, $lifetime ?? Session_Store::lifetime() );
	}

	/**
	 * Queue a Set-Cookie deleting the session cookie (logout).
	 */
	public static function queue_clear(): void {
		self::$pending = self::build_header_value( '', -1 );
	}

	/**
	 * The raw token from the request cookie, or '' when absent/malformed.
	 * Only a 64-hex value is ever accepted.
	 */
	public static function read( ?array $cookies = null ): string {
		$cookies = $cookies ?? $_COOKIE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- validated by the regex below.
		$raw     = isset( $cookies[ self::NAME ] ) ? (string) $cookies[ self::NAME ] : '';
		return preg_match( '/^[0-9a-f]{64}$/', $raw ) ? $raw : '';
	}

	/**
	 * Cookie path — the REST namespace root, `/wp-json/g2a/v1` on a stock
	 * install (honours a customised REST prefix).
	 */
	public static function path(): string {
		$prefix = function_exists( 'rest_get_url_prefix' ) ? rest_get_url_prefix() : 'wp-json';
		return '/' . trim( $prefix, '/' ) . '/' . G2ABA_REST_NAMESPACE;
	}

	/**
	 * SameSite attribute. Default `None` (dashboard is cross-origin today);
	 * filterable via `g2aba_session_samesite`. Anything outside the valid
	 * set falls back to the default rather than emitting a broken cookie.
	 */
	public static function samesite(): string {
		$value = (string) apply_filters( 'g2aba_session_samesite', 'None' );
		$value = ucfirst( strtolower( trim( $value ) ) );
		return in_array( $value, array( 'None', 'Lax', 'Strict' ), true ) ? $value : 'None';
	}

	/**
	 * Pure builder for the Set-Cookie header value so tests can pin every
	 * attribute. `$lifetime < 0` produces a deletion cookie.
	 */
	public static function build_header_value( string $token, int $lifetime, ?string $samesite = null, ?string $path = null ): string {
		$samesite = $samesite ?? self::samesite();
		$path     = $path ?? self::path();

		if ( $lifetime < 0 ) {
			$expires = 'Thu, 01 Jan 1970 00:00:00 GMT';
			$max_age = 0;
		} else {
			$expires = gmdate( 'D, d M Y H:i:s', time() + $lifetime ) . ' GMT';
			$max_age = $lifetime;
		}

		return sprintf(
			'%s=%s; Expires=%s; Max-Age=%d; Path=%s; Secure; HttpOnly; SameSite=%s',
			self::NAME,
			$token,
			$expires,
			$max_age,
			$path,
			$samesite
		);
	}

	/**
	 * `rest_pre_serve_request` callback: send the queued Set-Cookie before
	 * the response body goes out. Passes `$served` straight through — this
	 * hook only adds a header.
	 *
	 * @param bool $served Whether the request has already been served.
	 * @return bool
	 */
	public static function emit( $served ) {
		if ( null !== self::$pending && ! headers_sent() ) {
			header( 'Set-Cookie: ' . self::$pending, false );
			self::$pending = null;
		}
		return $served;
	}
}
