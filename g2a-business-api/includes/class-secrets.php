<?php
/**
 * Encrypt-at-rest storage for AI provider API keys.
 *
 * Uses openssl_encrypt(aes-256-gcm) with a key derived from WordPress'
 * AUTH_KEY constant. Ciphertext + IV + tag are base64-packed and stored in
 * the `g2aba_secrets` option. Plaintext keys are ONLY reconstructed inside
 * PHP — they never appear in REST responses.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Secrets {
	private const OPTION = 'g2aba_secrets';
	private const CIPHER = 'aes-256-gcm';

	public static function put( string $id, string $plaintext ): void {
		$secrets              = self::all_raw();
		$secrets[ $id ]       = self::encrypt( $plaintext );
		update_option( self::OPTION, $secrets, false );
	}

	public static function get( string $id ): ?string {
		$secrets = self::all_raw();
		if ( ! isset( $secrets[ $id ] ) ) {
			return null;
		}
		return self::decrypt( $secrets[ $id ] );
	}

	public static function forget( string $id ): void {
		$secrets = self::all_raw();
		unset( $secrets[ $id ] );
		update_option( self::OPTION, $secrets, false );
	}

	public static function has( string $id ): bool {
		$secrets = self::all_raw();
		return isset( $secrets[ $id ] );
	}

	public static function mask( string $id ): string {
		$plain = self::get( $id );
		if ( null === $plain || strlen( $plain ) < 8 ) {
			return '****';
		}
		return substr( $plain, 0, 4 ) . '****' . substr( $plain, -4 );
	}

	private static function all_raw(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	private static function derive_key(): string {
		$material = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'g2aba-fallback-key-not-secure';
		return hash( 'sha256', 'g2aba:' . $material, true );
	}

	public static function encrypt( string $plaintext ): string {
		$key = self::derive_key();
		$iv  = random_bytes( 12 );
		$tag = '';

		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $ciphertext ) {
			// Deliberately loud — never silently store plaintext.
			throw new \RuntimeException( 'g2aba: openssl_encrypt failed' );
		}

		return base64_encode( $iv . $tag . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage format.
	}

	public static function decrypt( string $packed ): ?string {
		$raw = base64_decode( $packed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage format.
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return null;
		}

		$iv         = substr( $raw, 0, 12 );
		$tag        = substr( $raw, 12, 16 );
		$ciphertext = substr( $raw, 28 );

		$plain = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			self::derive_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		return false === $plain ? null : $plain;
	}
}
