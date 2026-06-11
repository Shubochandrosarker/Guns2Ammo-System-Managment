<?php

namespace G2A\POS\Security;

/**
 * Envelope encryption for PII at rest.
 * KEK is derived from G2A_POS_KEK env/constant or a wp_option fallback.
 * Each row gets a fresh data key (DEK) wrapped by the KEK and prefixed onto the ciphertext.
 */
final class Crypto {

	private const VERSION         = 'g2av1';
	private const OPTION_KEK      = 'g2a_pos_kek_v1';
	private const OPTION_KEK_SALT = 'g2a_pos_kek_salt_v1';

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	public static function encrypt( string $plaintext ): string {
		if ( ! self::available() ) {
			return $plaintext;
		}
		$kek         = self::kek();
		$dek         = random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		$nonce_d     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ct          = sodium_crypto_secretbox( $plaintext, $nonce_d, $dek );
		$nonce_k     = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$wrapped_dek = sodium_crypto_secretbox( $dek, $nonce_k, $kek );

		return implode(
			'.',
			array(
				self::VERSION,
				sodium_bin2base64( $nonce_k, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING ),
				sodium_bin2base64( $wrapped_dek, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING ),
				sodium_bin2base64( $nonce_d, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING ),
				sodium_bin2base64( $ct, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING ),
			)
		);
	}

	public static function decrypt( string $payload ): ?string {
		if ( ! self::available() || ! str_starts_with( $payload, self::VERSION . '.' ) ) {
			return $payload;
		}
		$parts = explode( '.', $payload );
		if ( count( $parts ) !== 5 ) {
			return null;
		}
		try {
			$nonce_k     = sodium_base642bin( $parts[1], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
			$wrapped_dek = sodium_base642bin( $parts[2], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
			$nonce_d     = sodium_base642bin( $parts[3], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
			$ct          = sodium_base642bin( $parts[4], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
			$dek         = sodium_crypto_secretbox_open( $wrapped_dek, $nonce_k, self::kek() );
			if ( $dek === false ) {
				return null;
			}
			$pt = sodium_crypto_secretbox_open( $ct, $nonce_d, $dek );
			return $pt === false ? null : $pt;
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Blind index for equality lookups on encrypted columns (e.g. last+first+DOB).
	 */
	public static function blind_index( string $value, string $context = 'default' ): string {
		return hash_hmac( 'sha256', $context . '|' . strtolower( trim( $value ) ), self::kek() );
	}

	private static function kek(): string {
		$raw = defined( 'G2A_POS_KEK' ) ? G2A_POS_KEK : ( getenv( 'G2A_POS_KEK' ) ?: '' );
		if ( $raw === '' ) {
			$stored = (string) ( function_exists( 'get_option' ) ? get_option( self::OPTION_KEK, '' ) : '' );
			if ( $stored === '' && function_exists( 'update_option' ) ) {
				$stored = sodium_bin2base64( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
				update_option( self::OPTION_KEK, $stored );
			}
			$raw = $stored;
		}

		// Preferred path: caller supplied a base64 (URL-safe, no padding) of the exact key length.
		try {
			$bin = sodium_base642bin( $raw, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
			if ( strlen( $bin ) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) {
				return $bin;
			}
		} catch ( \Throwable ) {
			$bin = $raw; // not base64; treat as passphrase below
		}

		// Fallback: derive the KEK from the supplied secret with libsodium pwhash (Argon2id) and a stored salt.
		return self::derive_kek_from_passphrase( $raw );
	}

	private static function derive_kek_from_passphrase( string $secret ): string {
		$salt_b64 = (string) ( function_exists( 'get_option' ) ? get_option( self::OPTION_KEK_SALT, '' ) : '' );
		if ( $salt_b64 === '' ) {
			$salt = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
			if ( function_exists( 'update_option' ) ) {
				update_option( self::OPTION_KEK_SALT, sodium_bin2base64( $salt, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING ) );
			}
		} else {
			try {
				$salt = sodium_base642bin( $salt_b64, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING );
			} catch ( \Throwable ) {
				$salt = sodium_crypto_generichash( $salt_b64, '', SODIUM_CRYPTO_PWHASH_SALTBYTES );
			}
			if ( strlen( $salt ) !== SODIUM_CRYPTO_PWHASH_SALTBYTES ) {
				$salt = sodium_crypto_generichash( $salt, '', SODIUM_CRYPTO_PWHASH_SALTBYTES );
			}
		}

		return sodium_crypto_pwhash(
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
			$secret,
			$salt,
			SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
			SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
		);
	}
}
