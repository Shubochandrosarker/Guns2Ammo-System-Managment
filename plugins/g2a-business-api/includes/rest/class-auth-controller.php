<?php
/**
 * POST /auth/login
 *
 * Validates a WP username + application password (WP 5.6+ native) and returns
 * a JSON session envelope the dashboard stores in localStorage. The token
 * itself is opaque to the frontend — subsequent requests carry the WP
 * application-password credentials the browser already has (basic auth).
 *
 * We intentionally do NOT mint our own JWT here — that's a separate,
 * long-lived credential the operator would then have to rotate manually.
 * Reusing application-passwords keeps auth centralized in WP.
 *
 * DEPRECATED (since 0.2.0): the Basic-auth flow is superseded by the
 * server-managed cookie sessions in Auth_Session_Controller
 * (POST /auth/session/login). This route stays functional during the
 * migration window but is rate-limited, flags itself `deprecated` in the
 * response, and audit-logs every use (`legacy_basic_login`) so a cutoff
 * can be planned from real usage data.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Rate_Limiter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auth_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/auth/login',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
				'description'         => 'DEPRECATED: use POST /auth/session/login (cookie session) instead. This Basic-auth handshake will be removed after the dashboard migration.',
				'args'                => array(
					'email'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
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
			'/auth/me',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'me' ),
				// Reuse read gate — /auth/me is called on every page load and
				// must reject unauthenticated calls with the same 401/403 shape
				// the rest of the API uses.
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	/**
	 * Returns the session envelope for the currently authenticated user.
	 *
	 * The frontend calls this on load: if the stored basic-auth token still
	 * resolves against WP, the dashboard trusts the local session; if the
	 * token is stale/revoked, WP rejects with 401/403 and the frontend clears
	 * localStorage + shows the login screen.
	 */
	public function me() {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id > 0 && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user instanceof \WP_User ) {
				return $this->ok(
					array(
						'displayName' => $user->display_name ? $user->display_name : $user->user_login,
						'role'        => $this->role_for( $user ),
						'caps'        => array(
							'read'  => user_can( $user, Capabilities::READ ),
							'admin' => user_can( $user, Capabilities::ADMIN ) || user_can( $user, 'manage_options' ),
						),
					)
				);
			}
		}
		// Deliberately generic to avoid signalling capability details when the
		// session is invalid — read_permissions_check should have already 401'd
		// unauthenticated calls; this is a defense-in-depth branch.
		return new \WP_Error(
			'g2aba_auth_unknown',
			__( 'Not signed in.', 'g2a-business-api' ),
			array( 'status' => 401 )
		);
	}

	public function login( \WP_REST_Request $req ) {
		$email    = (string) $req->get_param( 'email' );
		$password = (string) $req->get_param( 'password' );

		// Same buckets as POST /auth/session/login (Auth_Session_Controller)
		// so alternating endpoints doesn't double an attacker's budget:
		// 10/15min per IP, 5/15min per account identifier.
		$ip       = Rate_Limiter::client_ip();
		$ip_check = ( new Rate_Limiter( Auth_Session_Controller::IP_SCOPE, Auth_Session_Controller::IP_LIMIT, Auth_Session_Controller::LIMIT_WINDOW ) )->hit( $ip );
		if ( ! $ip_check['allowed'] ) {
			return $this->too_many_requests( $ip_check['retryAfter'] );
		}
		if ( '' !== $email ) {
			$user_check = ( new Rate_Limiter( Auth_Session_Controller::USERNAME_SCOPE, Auth_Session_Controller::USER_LIMIT, Auth_Session_Controller::LIMIT_WINDOW ) )->hit_key( strtolower( $email ) );
			if ( ! $user_check['allowed'] ) {
				return $this->too_many_requests( $user_check['retryAfter'] );
			}
		}

		if ( '' === $email || '' === $password ) {
			return new \WP_Error(
				'g2aba_auth_missing',
				__( 'Email and password are required.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return new \WP_Error(
				'g2aba_auth_invalid',
				__( 'Invalid credentials.', 'g2a-business-api' ),
				array( 'status' => 401 )
			);
		}

		// Prefer application-password auth. `wp_authenticate_application_password`
		// exists on WP 5.6+ and is the officially supported cross-origin path.
		if ( function_exists( 'wp_authenticate_application_password' ) ) {
			$auth = wp_authenticate_application_password( null, $user->user_login, $password );
			if ( ! ( $auth instanceof \WP_User ) ) {
				return new \WP_Error(
					'g2aba_auth_invalid',
					__( 'Invalid credentials.', 'g2a-business-api' ),
					array( 'status' => 401 )
				);
			}
			$user = $auth;
		} elseif ( ! wp_check_password( $password, $user->user_pass, $user->ID ) ) {
			return new \WP_Error(
				'g2aba_auth_invalid',
				__( 'Invalid credentials.', 'g2a-business-api' ),
				array( 'status' => 401 )
			);
		}

		if ( ! user_can( $user, Capabilities::READ ) && ! user_can( $user, 'manage_options' ) ) {
			return new \WP_Error(
				'g2aba_auth_no_capability',
				__( 'This account cannot access the dashboard.', 'g2a-business-api' ),
				array( 'status' => 403 )
			);
		}

		// Track every legacy handshake so the Basic-auth cutoff (migration
		// step 5) can be scheduled from real usage instead of guesswork.
		( new Audit_Log() )->record(
			'legacy_basic_login',
			sprintf( 'Legacy basic-auth login for %s (deprecated route)', $user->user_login ),
			array( 'ip' => $ip, 'user' => (int) $user->ID )
		);

		return $this->ok(
			array(
				'token'       => wp_generate_password( 32, false ),
				'displayName' => $user->display_name ? $user->display_name : $user->user_login,
				'role'        => $this->role_for( $user ),
				// Signal to clients that this flow is going away — the
				// replacement is POST /auth/session/login.
				'deprecated'  => true,
			)
		);
	}

	private function role_for( \WP_User $user ): string {
		if ( user_can( $user, Capabilities::ADMIN ) || user_can( $user, 'manage_options' ) ) {
			return 'owner';
		}
		if ( user_can( $user, 'edit_shop_orders' ) || in_array( 'shop_manager', (array) $user->roles, true ) ) {
			return 'manager';
		}
		return 'analyst';
	}
}
