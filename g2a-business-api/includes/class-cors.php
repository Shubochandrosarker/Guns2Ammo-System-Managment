<?php
/**
 * CORS handling for the `g2a/v1` REST namespace.
 *
 * The dashboard is hosted off-site (app.guns2ammo.com) and authenticates
 * with `Authorization: Basic <application-password>` + `credentials:
 * 'include'`, so the browser demands an exact-origin CORS handshake:
 *
 *   - `Access-Control-Allow-Origin` must echo the *matched* origin — never
 *     `*`, which is invalid in credentials mode.
 *   - `Access-Control-Allow-Credentials: true`.
 *   - OPTIONS preflights must succeed *before* authentication runs (the
 *     browser never sends the Authorization header on a preflight).
 *
 * The allow-list lives in the dashboard settings (`allowedOrigins` inside
 * the `g2aba_dashboard_settings` option, editable from the dashboard
 * Settings page) and can be extended via the `g2aba_allowed_origins`
 * filter. Only the `g2a/v1` namespace is opened — the rest of wp-json
 * keeps WordPress' default CORS behaviour.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

use WordPressistic\G2ABA\Settings\Settings_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cors {
	/**
	 * Origins that are always sensible defaults for this install.
	 */
	public const DEFAULT_ORIGINS = array( 'https://app.guns2ammo.com' );

	public static function register(): void {
		// Let core's own CORS plumbing (rest_send_cors_headers) recognise the
		// dashboard origins too, then layer the credential-mode headers on top.
		add_filter( 'allowed_http_origins', array( __CLASS__, 'extend_allowed_http_origins' ) );
		// Priority 15: after core's rest_send_cors_headers (10) so our exact
		// origin + credentials headers win for the g2a/v1 namespace.
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_headers' ), 15, 4 );
	}

	/**
	 * Merge the dashboard origins into WordPress' allowed-origin list.
	 *
	 * @param mixed $origins Core's origin list.
	 * @return array<int, string>
	 */
	public static function extend_allowed_http_origins( $origins ): array {
		$origins = is_array( $origins ) ? $origins : array();
		return array_values( array_unique( array_merge( $origins, self::allowed_origins() ) ) );
	}

	/**
	 * The configured allow-list, defaults applied, filterable.
	 *
	 * @return array<int, string>
	 */
	public static function allowed_origins(): array {
		$settings = ( new Settings_Store() )->all();
		$list     = isset( $settings['allowedOrigins'] ) && is_array( $settings['allowedOrigins'] )
			? $settings['allowedOrigins']
			: array();
		if ( empty( $list ) ) {
			$list = self::DEFAULT_ORIGINS;
		}

		/**
		 * Filter the origins allowed to call the g2a/v1 REST namespace.
		 *
		 * @param array<int, string> $list Normalised `scheme://host[:port]` origins.
		 */
		$list = apply_filters( 'g2aba_allowed_origins', $list );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/**
	 * `rest_pre_serve_request` callback. Emits CORS headers for g2a/v1
	 * requests from an allowed origin and answers OPTIONS preflights with an
	 * empty 200 before authentication/dispatch output is served.
	 *
	 * @param bool              $served  Whether the request has already been served.
	 * @param mixed             $result  Result to send (unused).
	 * @param \WP_REST_Request  $request The request.
	 * @param \WP_REST_Server   $server  Server instance (unused).
	 * @return bool
	 */
	public static function send_headers( $served, $result, $request, $server ) {
		unset( $result, $server );

		if ( ! $request instanceof \WP_REST_Request || ! method_exists( $request, 'get_route' ) ) {
			return $served;
		}
		if ( ! self::is_namespace_route( (string) $request->get_route() ) ) {
			return $served;
		}

		$origin  = function_exists( 'get_http_origin' ) ? (string) get_http_origin() : '';
		$matched = self::match_origin( $origin, self::allowed_origins() );
		if ( null === $matched ) {
			return $served;
		}

		// Exact origin echo — never `*` — because the dashboard sends credentials.
		header( 'Access-Control-Allow-Origin: ' . $matched );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce' );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
		header( 'Access-Control-Max-Age: 600' );
		header( 'Vary: Origin', false );

		if ( 'OPTIONS' === strtoupper( (string) $request->get_method() ) ) {
			// Preflights carry no Authorization header, so answer them here
			// with a bare 200 instead of letting auth/dispatch interfere.
			status_header( 200 );
			return true;
		}

		return $served;
	}

	/**
	 * Whether a REST route belongs to the g2a/v1 namespace.
	 */
	public static function is_namespace_route( string $route ): bool {
		$prefix = '/' . G2ABA_REST_NAMESPACE;
		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	/**
	 * Pure origin matcher: returns the normalised matched origin, or null.
	 *
	 * @param string             $origin  The request's Origin header.
	 * @param array<int, string> $allowed Configured allow-list.
	 */
	public static function match_origin( string $origin, array $allowed ): ?string {
		$norm = self::normalize_origin( $origin );
		if ( null === $norm ) {
			return null;
		}
		foreach ( $allowed as $candidate ) {
			if ( self::normalize_origin( (string) $candidate ) === $norm ) {
				return $norm;
			}
		}
		return null;
	}

	/**
	 * Normalise an origin to lowercase `scheme://host[:port]`. Anything that
	 * is not a plain http(s) origin (paths, wildcards, garbage) → null.
	 */
	public static function normalize_origin( string $origin ): ?string {
		$origin = strtolower( trim( $origin ) );
		$origin = rtrim( $origin, '/' );
		if ( ! preg_match( '#^https?://[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$#', $origin ) ) {
			return null;
		}
		return $origin;
	}
}
