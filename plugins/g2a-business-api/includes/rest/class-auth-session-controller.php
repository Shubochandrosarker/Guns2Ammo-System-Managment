<?php
/**
 * Cookie-session auth routes (Phase 2 of the dashboard auth migration).
 *
 *   POST /auth/session/login     public + rate-limited; sets the HttpOnly
 *                                `g2aba_session` cookie, returns the user
 *                                envelope + per-session CSRF token.
 *   GET  /auth/session           envelope for the current cookie session
 *                                (SPA boot / CSRF re-sync after reload).
 *   POST /auth/session/logout    revokes the current session server-side,
 *                                clears the cookie. CSRF-gated centrally.
 *   POST /auth/session/revoke-all revokes EVERY session of the current
 *                                user ("sign out everywhere"). CSRF-gated.
 *
 * The CSRF requirement for the two POSTs is enforced namespace-wide in
 * Session_Auth::enforce_csrf() (rest_pre_dispatch) — not per-route here.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Auth\Session_Auth;
use WordPressistic\G2ABA\Auth\Session_Cookie;
use WordPressistic\G2ABA\Auth\Session_Store;
use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auth_Session_Controller extends REST_Controller {
	/**
	 * Rate-limit buckets, shared with the legacy /auth/login route so an
	 * attacker can't double their budget by alternating endpoints.
	 */
	public const IP_LIMIT       = 10;
	public const USER_LIMIT     = 5;
	public const LIMIT_WINDOW   = 900; // 15 minutes.
	public const IP_SCOPE       = 'auth.login.ip';
	public const USERNAME_SCOPE = 'auth.login.user';

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/auth/session/login',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'login' ),
				// Public by design: this IS the front door. Brute force is
				// contained by the per-IP + per-username rate limits below.
				'permission_callback' => '__return_true',
				'args'                => array(
					'username' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'password' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/auth/session',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'session' ),
				'permission_callback' => array( $this, 'session_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/auth/session/logout',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => array( $this, 'session_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/auth/session/revoke-all',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'revoke_all' ),
				'permission_callback' => array( $this, 'session_permissions_check' ),
			)
		);
	}

	/**
	 * These routes are cookie-session-only: a Basic-auth request has no
	 * session row to read a CSRF token from or to revoke, so it gets the
	 * same 401 an anonymous caller gets.
	 *
	 * @return bool|\WP_Error
	 */
	public function session_permissions_check() {
		if ( Session_Auth::is_cookie_authenticated() ) {
			return true;
		}
		return new \WP_Error(
			'g2aba_auth_no_session',
			__( 'No active dashboard session.', 'g2a-business-api' ),
			array( 'status' => 401 )
		);
	}

	public function login( \WP_REST_Request $req ) {
		$ip       = Rate_Limiter::client_ip();
		$username = (string) $req->get_param( 'username' );
		$password = (string) $req->get_param( 'password' );

		// Rate limit BEFORE touching credentials: per-IP first (10/15min),
		// then per-username (5/15min) so a distributed attacker can't focus
		// one account. Both buckets are charged even for requests that later
		// fail validation — that is the point.
		$ip_check = ( new Rate_Limiter( self::IP_SCOPE, self::IP_LIMIT, self::LIMIT_WINDOW ) )->hit( $ip );
		if ( ! $ip_check['allowed'] ) {
			return $this->too_many_requests( $ip_check['retryAfter'] );
		}
		if ( '' !== $username ) {
			$user_check = ( new Rate_Limiter( self::USERNAME_SCOPE, self::USER_LIMIT, self::LIMIT_WINDOW ) )->hit_key( strtolower( $username ) );
			if ( ! $user_check['allowed'] ) {
				return $this->too_many_requests( $user_check['retryAfter'] );
			}
		}

		if ( '' === $username || '' === $password ) {
			return new \WP_Error(
				'g2aba_auth_missing',
				__( 'Username and password are required.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		// wp_authenticate() runs the whole core `authenticate` filter stack,
		// so it accepts the real WP password, email-as-username, AND WP
		// application passwords (core treats REST as an API request).
		$user = wp_authenticate( $username, $password );
		if ( ! ( $user instanceof \WP_User ) ) {
			( new Audit_Log() )->record(
				'session_login_failed',
				sprintf( 'Failed dashboard session login for "%s"', $username ),
				array( 'ip' => $ip, 'username' => $username )
			);
			// Deliberately generic — same body whether the user exists or not.
			return new \WP_Error(
				'g2aba_auth_invalid',
				__( 'Invalid credentials.', 'g2a-business-api' ),
				array( 'status' => 401 )
			);
		}

		// Same dashboard gate every read route uses (g2a_dashboard, with the
		// manage_options bypass Capabilities already grants).
		if ( ! user_can( $user, Capabilities::READ ) && ! user_can( $user, 'manage_options' ) ) {
			( new Audit_Log() )->record(
				'session_login_failed',
				sprintf( 'Dashboard session login refused for "%s" (no capability)', $user->user_login ),
				array( 'ip' => $ip, 'user' => (int) $user->ID, 'reason' => 'no_capability' )
			);
			return new \WP_Error(
				'g2aba_auth_no_capability',
				__( 'This account cannot access the dashboard.', 'g2a-business-api' ),
				array( 'status' => 403 )
			);
		}

		// Always mint a brand-new token (rotation-on-login). The raw token
		// only ever exists in the Set-Cookie header — never in the body,
		// never in storage.
		$ua      = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( (string) $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$session = ( new Session_Store() )->create( (int) $user->ID, $ip, $ua );
		Session_Cookie::queue_set( $session['token'] );

		( new Audit_Log() )->record(
			'session_login_success',
			sprintf( 'Dashboard session opened for %s', $user->user_login ),
			array( 'ip' => $ip, 'user' => (int) $user->ID )
		);

		return $this->ok( self::envelope( $user, (string) $session['csrfToken'] ) );
	}

	/**
	 * GET /auth/session — the SPA calls this on boot to restore the user
	 * envelope and re-obtain the in-memory CSRF token after a reload.
	 */
	public function session() {
		$row  = Session_Auth::current_session();
		$user = get_user_by( 'id', (int) ( $row['user_id'] ?? 0 ) );
		if ( ! ( $user instanceof \WP_User ) ) {
			// Session row outlived its user — treat as signed out.
			return new \WP_Error(
				'g2aba_auth_no_session',
				__( 'No active dashboard session.', 'g2a-business-api' ),
				array( 'status' => 401 )
			);
		}
		return $this->ok( self::envelope( $user, (string) ( $row['csrf_token'] ?? '' ) ) );
	}

	public function logout() {
		$row   = Session_Auth::current_session();
		$store = new Session_Store();
		$store->revoke( (int) ( $row['id'] ?? 0 ) );
		Session_Auth::forget();
		Session_Cookie::queue_clear();

		( new Audit_Log() )->record(
			'session_logout',
			'Dashboard session logged out',
			array( 'ip' => Rate_Limiter::client_ip(), 'user' => (int) ( $row['user_id'] ?? 0 ) )
		);

		return $this->ok( array( 'ok' => true ) );
	}

	public function revoke_all() {
		$row     = Session_Auth::current_session();
		$user_id = (int) ( $row['user_id'] ?? 0 );
		$count   = ( new Session_Store() )->revoke_all_for_user( $user_id );
		Session_Auth::forget();
		Session_Cookie::queue_clear();

		( new Audit_Log() )->record(
			'session_revoked_all',
			sprintf( 'All dashboard sessions revoked (%d)', $count ),
			array( 'ip' => Rate_Limiter::client_ip(), 'user' => $user_id, 'revoked' => $count )
		);

		return $this->ok(
			array(
				'ok'      => true,
				'revoked' => $count,
			)
		);
	}

	/**
	 * The response envelope shared by login and GET /auth/session.
	 *
	 * `capabilities` lists the g2a dashboard caps the user effectively
	 * holds (manage_options implies both, mirroring Capabilities).
	 *
	 * @return array{user: array{id:int, displayName:string, email:string, capabilities:array<int,string>}, csrfToken:string}
	 */
	public static function envelope( \WP_User $user, string $csrf_token ): array {
		$caps = array();
		if ( user_can( $user, Capabilities::READ ) || user_can( $user, 'manage_options' ) ) {
			$caps[] = Capabilities::READ;
		}
		if ( user_can( $user, Capabilities::ADMIN ) || user_can( $user, 'manage_options' ) ) {
			$caps[] = Capabilities::ADMIN;
		}

		return array(
			'user'      => array(
				'id'           => (int) $user->ID,
				'displayName'  => $user->display_name ? $user->display_name : $user->user_login,
				'email'        => (string) $user->user_email,
				'capabilities' => $caps,
			),
			'csrfToken' => $csrf_token,
		);
	}
}
