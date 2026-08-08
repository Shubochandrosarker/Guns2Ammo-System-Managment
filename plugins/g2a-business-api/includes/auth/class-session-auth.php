<?php
/**
 * Cookie-session authentication middleware + central CSRF enforcement.
 *
 * Three hooks, all registered from Plugin::run():
 *
 *  1. `determine_current_user` (priority 30, after core's application
 *     password auth at 20): when no other mechanism authenticated the
 *     request and a `g2aba_session` cookie resolves to a LIVE session row,
 *     the row's user becomes the current user. The row is remembered so
 *     downstream code can distinguish "cookie session" from "Basic auth".
 *
 *  2. `rest_authentication_errors`: WP core's cookie-auth check would
 *     otherwise report a nonce error / null for our session user; when the
 *     request was authenticated by a live g2aba session we return `true`
 *     so REST treats it as authenticated.
 *
 *  3. `rest_pre_dispatch`: central CSRF gate for the whole g2a/v1
 *     namespace. Any unsafe-method request (not GET/HEAD/OPTIONS) that was
 *     authenticated VIA THE SESSION COOKIE must carry an `X-G2A-CSRF`
 *     header that `hash_equals()` the session row's csrf_token, or the
 *     request is rejected with the stable code `g2aba_csrf_failed` (403).
 *     Basic-auth and anonymous requests are exempt (a cross-site attacker
 *     cannot attach a Basic Authorization header without XHR+CORS, and the
 *     credential-carrying login routes authenticate their own body).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Auth;

use WordPressistic\G2ABA\Cors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session_Auth {
	public const CSRF_HEADER = 'X-G2A-CSRF';

	/**
	 * The live session row when the current request authenticated via the
	 * g2aba_session cookie; null otherwise (Basic auth, anonymous, cron).
	 */
	private static ?array $session = null;

	public static function register(): void {
		// Priority 30: core resolves Basic/application-password auth at 20;
		// the cookie only fills the gap when nothing else authenticated.
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_current_user' ), 30 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_authentication_errors' ), 20 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'enforce_csrf' ), 10, 3 );
	}

	/**
	 * `determine_current_user` callback.
	 *
	 * @param int|false $user_id Whatever earlier callbacks resolved.
	 * @return int|false
	 */
	public static function determine_current_user( $user_id ) {
		if ( ! empty( $user_id ) ) {
			return $user_id; // Basic auth (or another mechanism) already won.
		}

		$token = Session_Cookie::read();
		if ( '' === $token ) {
			return $user_id;
		}

		$store = new Session_Store();
		$row   = $store->find_live_by_token( $token );
		if ( null === $row ) {
			// Expired/revoked/unknown cookie: fall through unauthenticated so
			// the route's permission callback answers with the standard 401.
			return $user_id;
		}

		self::$session = $row;
		$store->touch( $row );

		return (int) $row['user_id'];
	}

	/**
	 * `rest_authentication_errors` callback — vouch for the session user.
	 *
	 * @param \WP_Error|true|null $result Result from earlier callbacks.
	 * @return \WP_Error|true|null
	 */
	public static function rest_authentication_errors( $result ) {
		if ( null !== $result ) {
			return $result; // Another auth mechanism already ruled.
		}
		if ( self::is_cookie_authenticated() ) {
			return true;
		}
		return $result;
	}

	/**
	 * The live session row for this request, or null when the request was
	 * not authenticated via the g2aba_session cookie.
	 */
	public static function current_session(): ?array {
		return self::$session;
	}

	public static function is_cookie_authenticated(): bool {
		return null !== self::$session;
	}

	/**
	 * Forget the current request's session binding (used after logout /
	 * revoke-all so nothing downstream trusts a revoked row; also lets
	 * tests reset static state).
	 */
	public static function forget(): void {
		self::$session = null;
	}

	/**
	 * `rest_pre_dispatch` callback — the namespace-wide CSRF gate.
	 *
	 * @param mixed            $result  Null unless something already short-circuited.
	 * @param \WP_REST_Server  $server  Server (unused).
	 * @param \WP_REST_Request $request The request.
	 * @return mixed
	 */
	public static function enforce_csrf( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}
		if ( ! $request instanceof \WP_REST_Request || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}

		$route = (string) $request->get_route();
		if ( ! Cors::is_namespace_route( $route ) ) {
			return $result;
		}
		if ( ! self::requires_csrf( strtoupper( (string) $request->get_method() ), $route, self::is_cookie_authenticated() ) ) {
			return $result;
		}

		$header = (string) $request->get_header( self::CSRF_HEADER );
		if ( self::verify_csrf( $header, (string) ( self::$session['csrf_token'] ?? '' ) ) ) {
			return $result;
		}

		return new \WP_Error(
			'g2aba_csrf_failed',
			__( 'Missing or invalid CSRF token.', 'g2a-business-api' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Pure decision: does this request need a CSRF check?
	 *
	 * Safe methods never do. Requests not authenticated by the cookie never
	 * do (Basic auth is not CSRF-forgeable). The two credential-carrying
	 * login routes are exempt even when a stale cookie rides along —
	 * they re-authenticate from the request body, and blocking them would
	 * wedge a user whose SPA lost its in-memory CSRF token.
	 */
	public static function requires_csrf( string $method, string $route, bool $cookie_authenticated ): bool {
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return false;
		}
		if ( ! $cookie_authenticated ) {
			return false;
		}

		$namespace = '/' . G2ABA_REST_NAMESPACE;
		if ( in_array( $route, array( $namespace . '/auth/session/login', $namespace . '/auth/login' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Constant-time CSRF comparison. Empty values never match.
	 */
	public static function verify_csrf( string $header, string $expected ): bool {
		if ( '' === $header || '' === $expected ) {
			return false;
		}
		return hash_equals( $expected, $header );
	}
}
