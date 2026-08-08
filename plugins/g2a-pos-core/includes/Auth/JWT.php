<?php

namespace G2A\POS\Auth;

final class JWT {

	public static function bootstrap(): void {
		add_filter( 'determine_current_user', array( self::class, 'authenticate' ), 15 );
	}

	public static function authenticate( $user_id ) {
		if ( ! empty( $user_id ) || ! defined( 'REST_REQUEST' ) || REST_REQUEST !== true ) {
			return $user_id;
		}

		$token = self::read_bearer_token();
		if ( $token === '' ) {
			return $user_id;
		}

		$payload = self::decode_token( $token );

		if ( ! $payload || empty( $payload['sub'] ) || (int) ( $payload['exp'] ?? 0 ) < time() ) {
			return $user_id;
		}

		$token_version = (int) get_user_meta( (int) $payload['sub'], 'g2a_pos_token_version', true );
		if ( (int) ( $payload['tv'] ?? 1 ) !== max( 1, $token_version ?: 1 ) ) {
			return $user_id;
		}

		return (int) $payload['sub'];
	}

	/**
	 * Extract the Bearer token from the request.
	 *
	 * Apache/PHP-FPM and CGI SAPIs frequently drop the `Authorization` header
	 * from `$_SERVER['HTTP_AUTHORIZATION']`, so fall back to the redirected
	 * variant and to the SAPI header list. This is what lets a cross-origin
	 * SPA (e.g. the standalone POS app) authenticate by Bearer token rather
	 * than cookies. Hosts that strip it entirely still need the documented
	 * `.htaccess` rewrite, but these fallbacks cover the common cases.
	 */
	private static function read_bearer_token(): string {
		$header = (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? '' );

		if ( $header === '' && ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		if ( $header === '' && function_exists( 'getallheaders' ) ) {
			$header = self::header_from_list( getallheaders() );
		}

		if ( $header === '' && function_exists( 'apache_request_headers' ) ) {
			$header = self::header_from_list( apache_request_headers() );
		}

		if ( ! preg_match( '/Bearer\s+(.+)$/i', $header, $matches ) ) {
			return '';
		}

		return trim( $matches[1] );
	}

	/**
	 * Case-insensitively pull the Authorization value out of a SAPI header map.
	 *
	 * @param array<string,string> $headers
	 */
	private static function header_from_list( $headers ): string {
		foreach ( (array) $headers as $key => $value ) {
			if ( strcasecmp( (string) $key, 'Authorization' ) === 0 ) {
				return (string) $value;
			}
		}
		return '';
	}

	public static function issue_token( int $user_id, int $ttl = 3600 ): string {
		$header    = self::base64_url_encode(
			wp_json_encode(
				array(
					'alg' => 'HS256',
					'typ' => 'JWT',
				)
			)
		);
		$payload   = self::base64_url_encode(
			wp_json_encode(
				array(
					'sub' => $user_id,
					'iat' => time(),
					'exp' => time() + $ttl,
					'tv'  => self::token_version( $user_id ),
					'jti' => wp_generate_uuid4(),
				)
			)
		);
		$signature = self::sign( "{$header}.{$payload}" );

		return "{$header}.{$payload}.{$signature}";
	}

	private static function decode_token( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( count( $parts ) !== 3 ) {
			return null;
		}

		[$header, $payload, $signature] = $parts;
		if ( ! hash_equals( self::sign( "{$header}.{$payload}" ), $signature ) ) {
			return null;
		}

		$decoded = json_decode( self::base64_url_decode( $payload ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private static function sign( string $input ): string {
		return self::base64_url_encode( hash_hmac( 'sha256', $input, wp_salt( 'auth' ), true ) );
	}

	private static function base64_url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function base64_url_decode( string $data ): string {
		return base64_decode( strtr( $data, '-_', '+/' ) ) ?: '';
	}

	private static function token_version( int $user_id ): int {
		$v = (int) get_user_meta( $user_id, 'g2a_pos_token_version', true );
		if ( $v <= 0 ) {
			$v = 1;
			update_user_meta( $user_id, 'g2a_pos_token_version', $v );
		}
		return $v;
	}

	public static function revoke_user_tokens( int $user_id ): void {
		$v = (int) get_user_meta( $user_id, 'g2a_pos_token_version', true );
		update_user_meta( $user_id, 'g2a_pos_token_version', max( 1, $v + 1 ) );
	}
}
