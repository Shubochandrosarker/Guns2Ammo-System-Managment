<?php

namespace G2A\POS\API;

use G2A\POS\Auth\JWT;
use G2A\POS\Permissions\Roles;
use WP_REST_Request;

final class AuthController {

	private const MAX_LOGIN_ATTEMPTS = 5;
	private const LOCKOUT_SECONDS    = 600;
	private const TOKEN_TTL          = 3600;

	public static function token( WP_REST_Request $request ) {
		$username = sanitize_user( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );
		$ip       = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );

		if ( self::is_rate_limited( $username, $ip ) ) {
			return new \WP_Error( 'rate_limited', 'Too many login attempts. Try again later.', array( 'status' => 429 ) );
		}

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) ) {
			self::record_failed_attempt( $username, $ip );
			return new \WP_Error( 'auth_failed', 'Invalid credentials.', array( 'status' => 401 ) );
		}

		if ( ! user_can( $user->ID, 'g2a_pos_access' ) ) {
			return new \WP_Error( 'forbidden', 'User does not have POS access.', array( 'status' => 403 ) );
		}

		self::clear_failed_attempts( $username, $ip );

		return self::issue_response( (int) $user->ID );
	}

	/**
	 * Exchange a still-valid Bearer token for a fresh one (sliding session).
	 *
	 * The caller is authenticated by the existing token via JWT::authenticate.
	 * POS access is re-checked so a revoked/downgraded account cannot keep
	 * refreshing. Lets all-shift staff stay signed in without re-entering
	 * credentials every hour.
	 */
	public static function refresh( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return new \WP_Error( 'unauthenticated', 'Not authenticated.', array( 'status' => 401 ) );
		}

		if ( ! user_can( $uid, 'g2a_pos_access' ) ) {
			return new \WP_Error( 'forbidden', 'User does not have POS access.', array( 'status' => 403 ) );
		}

		return self::issue_response( $uid );
	}

	/**
	 * Build the standard auth response: token, lifetime, and basic user info so
	 * the SPA can hydrate its session without an extra round-trip.
	 *
	 * @return array<string,mixed>
	 */
	private static function issue_response( int $user_id ): array {
		$user = get_userdata( $user_id );

		// Report the running plugin version and the caller's actual
		// capabilities. A cross-origin client cannot read either off the page
		// the way the wp-admin SPA can, and it must not infer permission from
		// role names — roles are editable per site, whereas capabilities are
		// what the REST routes actually check.
		$caps = array();
		foreach ( Roles::all_caps() as $cap ) {
			if ( user_can( $user_id, $cap ) ) {
				$caps[] = $cap;
			}
		}

		return array(
			'token'          => JWT::issue_token( $user_id, self::TOKEN_TTL ),
			'user_id'        => $user_id,
			'expires_in'     => self::TOKEN_TTL,
			'plugin_version' => G2A_POS_CORE_VERSION,
			'user'           => array(
				'id'           => $user_id,
				'name'         => $user ? $user->display_name : '',
				'roles'        => $user ? array_values( (array) $user->roles ) : array(),
				'capabilities' => $caps,
			),
		);
	}

	public static function revoke( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return new \WP_Error( 'unauthenticated', 'Not authenticated.', array( 'status' => 401 ) );
		}

		JWT::revoke_user_tokens( $uid );
		return array(
			'ok'      => true,
			'message' => 'Token family revoked. Please sign in again.',
		);
	}

	private static function throttle_key( string $username, string $ip ): string {
		return 'g2a_pos_auth_' . md5( strtolower( $username ) . '|' . $ip );
	}

	private static function is_rate_limited( string $username, string $ip ): bool {
		$value = get_transient( self::throttle_key( $username, $ip ) );
		return is_array( $value ) && ( (int) ( $value['count'] ?? 0 ) >= self::MAX_LOGIN_ATTEMPTS );
	}

	private static function record_failed_attempt( string $username, string $ip ): void {
		$key   = self::throttle_key( $username, $ip );
		$value = get_transient( $key );
		$count = is_array( $value ) ? (int) ( $value['count'] ?? 0 ) + 1 : 1;
		set_transient( $key, array( 'count' => $count ), self::LOCKOUT_SECONDS );
	}

	private static function clear_failed_attempts( string $username, string $ip ): void {
		delete_transient( self::throttle_key( $username, $ip ) );
	}
}
