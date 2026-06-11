<?php

namespace G2A\POS\Support;

final class SecurityHeaders {

	public static function boot(): void {
		add_filter( 'rest_pre_serve_request', array( self::class, 'rest_headers' ), 10, 4 );
		// Priority 20 so this runs after WordPress core's own CORS handler
		// (default priority 10) and can override/strip its permissive origin echo.
		add_filter( 'rest_pre_serve_request', array( self::class, 'cors_headers' ), 20, 4 );
	}

	public static function rest_headers( $served, $result, $request, $server ) {
		if ( ! self::is_pos_route( $request ) ) {
			return $served;
		}
		if ( ! headers_sent() ) {
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: DENY' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			header( 'Permissions-Policy: geolocation=(), camera=(), microphone=()' );
			header( 'Cache-Control: no-store' );
			header( 'Strict-Transport-Security: max-age=63072000; includeSubDomains; preload' );
		}
		return $served;
	}

	/**
	 * Emit cross-origin headers for allow-listed origins only.
	 *
	 * WordPress core echoes any Origin by default; we tighten that to the
	 * configured allowlist so only known clients (e.g. the standalone POS app
	 * at pos.guns2ammo.com) can call the API from another origin. Bearer-token
	 * auth means no cookies cross the boundary, so credentials are not allowed.
	 */
	public static function cors_headers( $served, $result, $request, $server ) {
		if ( ! self::is_pos_route( $request ) || headers_sent() ) {
			return $served;
		}

		$origin = function_exists( 'get_http_origin' ) ? (string) get_http_origin() : (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' );
		if ( $origin === '' ) {
			return $served; // Same-origin request; nothing to negotiate.
		}

		if ( ! self::is_origin_allowed( $origin, self::allowed_origins() ) ) {
			// Strip any permissive header core may have set for a disallowed origin.
			header_remove( 'Access-Control-Allow-Origin' );
			header_remove( 'Access-Control-Allow-Credentials' );
			return $served;
		}

		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Vary: Origin', false );
		header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With' );
		header( 'Access-Control-Max-Age: 600' );

		return $served;
	}

	/**
	 * @param mixed $request WP_REST_Request for the current call.
	 */
	private static function is_pos_route( $request ): bool {
		if ( is_object( $request ) && method_exists( $request, 'get_route' ) ) {
			$route = $request->get_route();
			if ( is_string( $route ) && strpos( $route, '/g2a-pos/' ) !== false ) {
				return true;
			}
		}
		// Fallback for preflight OPTIONS, where the route may not resolve.
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		return strpos( $uri, '/g2a-pos/' ) !== false;
	}

	/**
	 * Configured cross-origin allowlist: the `g2a_pos_cors_origins` option
	 * (array, or a comma/newline/space-separated string) plus the
	 * `g2a_pos_allowed_cors_origins` filter, all normalized.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_origins(): array {
		$raw = get_option( 'g2a_pos_cors_origins', array() );
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[\s,]+/', $raw ) ?: array();
		}
		$raw = apply_filters( 'g2a_pos_allowed_cors_origins', (array) $raw );

		$origins = array();
		foreach ( $raw as $origin ) {
			$normalized = self::normalize_origin( (string) $origin );
			if ( $normalized !== '' ) {
				$origins[ $normalized ] = $normalized;
			}
		}
		return array_values( $origins );
	}

	/**
	 * @param array<int,string> $allowlist Already-normalized origins.
	 */
	public static function is_origin_allowed( string $origin, array $allowlist ): bool {
		$origin = self::normalize_origin( $origin );
		return $origin !== '' && in_array( $origin, $allowlist, true );
	}

	/**
	 * Normalize an origin for comparison: scheme + host (+ optional port) are
	 * case-insensitive and carry no path, so lower-case and drop trailing slash.
	 */
	public static function normalize_origin( string $origin ): string {
		return rtrim( strtolower( trim( $origin ) ), '/' );
	}
}
