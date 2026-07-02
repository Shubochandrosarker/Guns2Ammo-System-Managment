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
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Capabilities;

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
	}

	public function login( \WP_REST_Request $req ) {
		$email    = (string) $req->get_param( 'email' );
		$password = (string) $req->get_param( 'password' );

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

		return $this->ok(
			array(
				'token'       => wp_generate_password( 32, false ),
				'displayName' => $user->display_name ? $user->display_name : $user->user_login,
				'role'        => $this->role_for( $user ),
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
