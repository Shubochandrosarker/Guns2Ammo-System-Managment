<?php

namespace G2A\POS\API;

use G2A\POS\Security\Crypto;
use G2A\POS\Security\Totp;
use WP_REST_Request;

final class TotpController {

	public static function enroll( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return new \WP_Error( 'unauthenticated', 'Sign in first.', array( 'status' => 401 ) );
		}
		$secret = Totp::generate_secret();
		self::write_row( $uid, $secret, 0 );
		$email = function_exists( 'wp_get_current_user' ) ? ( wp_get_current_user()->user_email ?: ( 'user-' . $uid ) ) : ( 'user-' . $uid );
		return array(
			'otpauth_uri' => Totp::otpauth_uri( $email, $secret ),
			'secret'      => $secret,
		);
	}

	public static function confirm( WP_REST_Request $req ) {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return new \WP_Error( 'unauthenticated', 'Sign in first.', array( 'status' => 401 ) );
		}
		$payload = $req->get_json_params() ?: array();
		$code    = (string) ( $payload['code'] ?? '' );
		$row     = self::read_row( $uid );
		if ( ! $row ) {
			return new \WP_Error( 'not_enrolled', 'Enroll first.', array( 'status' => 404 ) );
		}
		if ( ! Totp::verify( $row['secret'], $code ) ) {
			return new \WP_Error( 'invalid_code', 'Code did not match.', array( 'status' => 422 ) );
		}
		self::write_row( $uid, $row['secret'], 1 );
		return array( 'ok' => true );
	}

	public static function verify_request( WP_REST_Request $req ): bool {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		$row = self::read_row( $uid );
		if ( ! $row || ! $row['confirmed'] ) {
			return false;
		}
		$code = (string) ( $req->get_header( 'x_g2a_totp' ) ?? '' );
		if ( $code === '' ) {
			return false;
		}
		return Totp::verify( $row['secret'], $code );
	}

	private static function read_row( int $uid ): ?array {
		global $wpdb;
		$t   = $wpdb->prefix . 'g2a_user_totp';
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d LIMIT 1", $uid ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$secret = Crypto::decrypt( (string) $row['secret_encrypted'] );
		if ( $secret === null ) {
			return null;
		}
		return array(
			'secret'    => $secret,
			'confirmed' => (int) $row['confirmed'],
		);
	}

	private static function write_row( int $uid, string $secret, int $confirmed ): void {
		global $wpdb;
		$t        = $wpdb->prefix . 'g2a_user_totp';
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE user_id = %d LIMIT 1", $uid ) );
		$now      = current_time( 'mysql' );
		$row      = array(
			'user_id'          => $uid,
			'secret_encrypted' => Crypto::encrypt( $secret ),
			'confirmed'        => $confirmed,
			'updated_at'       => $now,
		);
		if ( $existing ) {
			$wpdb->update( $t, $row, array( 'user_id' => $uid ) );
		} else {
			$row['created_at'] = $now;
			$wpdb->insert( $t, $row );
		}
	}
}
