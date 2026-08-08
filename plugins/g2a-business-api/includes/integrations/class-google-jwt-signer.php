<?php
/**
 * RS256 JWT signer for Google service-account credentials.
 *
 * Google service accounts authenticate by signing a JWT (header + claim set)
 * with the service account's RSA private key, then exchanging that JWT for
 * an OAuth access token at https://oauth2.googleapis.com/token. The signed
 * JWT is the whole of Google's identity check — there is no shared secret.
 *
 * Isolated in its own class so the crypto is testable without touching HTTP.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Integrations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Google_JWT_Signer {
	/**
	 * Build the base64url-encoded segments of a signed JWT.
	 *
	 * @param array  $claims    Claim set (iss, scope, aud, iat, exp, sub?).
	 * @param string $private_pem PEM-formatted PKCS#8 or PKCS#1 RSA private key.
	 *
	 * @return string `header.payload.signature`
	 * @throws \RuntimeException When the key can't be loaded or signing fails.
	 */
	public function sign( array $claims, string $private_pem ): string {
		$header = array(
			'alg' => 'RS256',
			'typ' => 'JWT',
		);

		$segments  = self::b64url( wp_json_encode( $header ) )
			. '.' . self::b64url( wp_json_encode( $claims ) );

		$key = openssl_pkey_get_private( $private_pem );
		if ( false === $key ) {
			throw new \RuntimeException( 'g2aba: unable to load service-account private key' );
		}

		$signature = '';
		$ok        = openssl_sign( $segments, $signature, $key, OPENSSL_ALGO_SHA256 );
		// PHP 8.0 keeps openssl_free_key(); PHP 8.4+ deprecates it and GCs.
		// Explicit unset is safe on both.
		unset( $key );

		if ( ! $ok || '' === $signature ) {
			throw new \RuntimeException( 'g2aba: openssl_sign returned failure' );
		}

		return $segments . '.' . self::b64url( $signature );
	}

	/**
	 * Assemble the standard Google-token claim set. Kept public so tests
	 * can assert the exact time-window shape without going through sign().
	 */
	public function claims_for(
		string $client_email,
		string $scope,
		int $now,
		int $ttl_seconds = 3600
	): array {
		return array(
			'iss'   => $client_email,
			'scope' => $scope,
			'aud'   => 'https://oauth2.googleapis.com/token',
			'iat'   => $now,
			'exp'   => $now + max( 60, $ttl_seconds ),
		);
	}

	public static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- JWT wire format.
	}
}
