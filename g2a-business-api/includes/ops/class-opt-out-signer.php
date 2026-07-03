<?php
/**
 * HMAC signer for unsubscribe links — now category-aware.
 *
 * Token payload: `email + '|' + expires + '|' + category`. The category is
 * bound into the HMAC so a scanner cannot forge an all-categories opt-out
 * from a marketing-only token.
 *
 * Backward compatibility: if the caller omits `category`, we default to
 * `all` — exactly the Phase-8 behaviour. Existing links minted by Phase 8
 * were signed without a category segment, so they only verify with
 * category='all' (or when the caller passes 'all' explicitly).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opt_Out_Signer {
	private const KEY_SALT    = 'g2aba:opt-out-link';
	private const TTL_SECONDS = 60 * 60 * 24 * 90; // 90 days.

	public static function sign( string $email, int $expires, string $category = Opt_Out_Store::CATEGORY_ALL ): string {
		$normalized = Opt_Out_Store::key( $email );
		$cat        = Opt_Out_Store::normalize_category( $category );
		if ( '' === $normalized || '' === $cat ) {
			return '';
		}
		$payload = $normalized . '|' . $expires . '|' . $cat;
		return self::b64url( hash_hmac( 'sha256', $payload, self::derive_key(), true ) );
	}

	/**
	 * @return array{ok:true,email:string,category:string}|array{ok:false,error:string}
	 */
	public static function verify( string $email, int $expires, string $token, string $category = Opt_Out_Store::CATEGORY_ALL ): array {
		$normalized = Opt_Out_Store::key( $email );
		$cat        = Opt_Out_Store::normalize_category( $category );
		if ( '' === $normalized ) {
			return array( 'ok' => false, 'error' => 'invalid_email' );
		}
		if ( '' === $cat ) {
			return array( 'ok' => false, 'error' => 'invalid_category' );
		}
		if ( $expires <= time() ) {
			return array( 'ok' => false, 'error' => 'expired' );
		}
		$expected = self::sign( $normalized, $expires, $cat );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			return array( 'ok' => false, 'error' => 'bad_token' );
		}
		return array( 'ok' => true, 'email' => $normalized, 'category' => $cat );
	}

	public static function make_link( string $email, string $category = Opt_Out_Store::CATEGORY_ALL, string $base_url = '' ): string {
		$expires = time() + self::TTL_SECONDS;
		$cat     = Opt_Out_Store::normalize_category( $category );
		$token   = self::sign( $email, $expires, $cat );
		if ( '' === $token ) {
			return '';
		}
		$base = '' !== $base_url ? $base_url : ( function_exists( 'home_url' ) ? (string) home_url( '/opt-out' ) : '/opt-out' );
		return $base
			. '?email=' . rawurlencode( $email )
			. '&expires=' . $expires
			. '&category=' . rawurlencode( $cat )
			. '&token=' . $token;
	}

	private static function derive_key(): string {
		$material = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'g2aba-fallback-not-secure';
		return hash( 'sha256', self::KEY_SALT . '|' . $material, true );
	}

	private static function b64url( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe wire format.
	}
}
