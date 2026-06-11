<?php

namespace G2A\POS\Wholesalers\Crypto;

/**
 * Versioned authenticated encryption for credentials stored in WordPress.
 *
 * New values use AES-256-GCM (enc2). Legacy AES-CBC/base64 values are
 * read only so installations can migrate them on the next successful save.
 */
final class CredentialCipher {

	private const PREFIX        = 'enc2:';
	private const LEGACY_PREFIX = 'enc1:';

	public static function encrypt( array $data ): string {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			throw new \RuntimeException( 'OpenSSL is required to store credentials securely.' );
		}
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			throw new \RuntimeException( 'Unable to encode credentials.' );
		}
		$nonce      = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $json, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( $ciphertext === false || strlen( $tag ) !== 16 ) {
			throw new \RuntimeException( 'Unable to encrypt credentials.' );
		}
		return self::PREFIX . base64_encode( $nonce . $tag . $ciphertext );
	}

	public static function decrypt( string $raw ): array {
		if ( $raw === '' ) {
			return array();
		}
		if ( str_starts_with( $raw, self::PREFIX ) ) {
			return self::decryptAuthenticated( substr( $raw, strlen( self::PREFIX ) ) );
		}
		if ( str_starts_with( $raw, self::LEGACY_PREFIX ) ) {
			return self::decryptLegacyCbc( substr( $raw, strlen( self::LEGACY_PREFIX ) ) );
		}
		$decoded = json_decode( base64_decode( $raw, true ) ?: '', true );
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function needsMigration( string $raw ): bool {
		return $raw !== '' && ! str_starts_with( $raw, self::PREFIX );
	}

	private static function decryptAuthenticated( string $encoded ): array {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return array();
		}
		$blob = base64_decode( $encoded, true );
		if ( $blob === false || strlen( $blob ) <= 28 ) {
			return array();
		}
		$nonce      = substr( $blob, 0, 12 );
		$tag        = substr( $blob, 12, 16 );
		$ciphertext = substr( $blob, 28 );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $nonce, $tag );
		if ( $plain === false ) {
			return array();
		}
		$decoded = json_decode( $plain, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function decryptLegacyCbc( string $encoded ): array {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return array();
		}
		$blob = base64_decode( $encoded, true );
		if ( $blob === false || strlen( $blob ) < 17 ) {
			return array();
		}
		$iv         = substr( $blob, 0, 16 );
		$ciphertext = substr( $blob, 16 );
		$plain      = openssl_decrypt( $ciphertext, 'aes-256-cbc', self::legacyKey(), OPENSSL_RAW_DATA, $iv );
		if ( $plain === false ) {
			return array();
		}
		$decoded = json_decode( $plain, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private static function key(): string {
		if ( ! defined( 'AUTH_KEY' ) || ! is_string( AUTH_KEY ) || strlen( AUTH_KEY ) < 32 ) {
			throw new \RuntimeException( 'A strong WordPress AUTH_KEY is required to decrypt credentials.' );
		}
		return hash( 'sha256', 'g2a-pos|credentials|v2|' . AUTH_KEY, true );
	}

	private static function legacyKey(): string {
		if ( ! defined( 'AUTH_KEY' ) || ! is_string( AUTH_KEY ) || AUTH_KEY === '' ) {
			return hash( 'sha256', 'g2a-pos|g2a-pos-fallback-key', true );
		}
		return hash( 'sha256', 'g2a-pos|' . AUTH_KEY, true );
	}
}
