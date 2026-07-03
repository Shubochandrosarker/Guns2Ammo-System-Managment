<?php
/**
 * HMAC signer for unsubscribe links.
 *
 * The public opt-out endpoint expects `{email, token}` where token is
 * `HMAC-SHA256(email + '|' + expires, AUTH_KEY_derived)` in base64url. The
 * token both binds the click to an email and time-boxes it so an old link
 * can't be replayed years later against a re-subscribed user.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Opt_Out_Signer {
	private const KEY_SALT     = 'g2aba:opt-out-link';
	private const TTL_SECONDS  = 60 * 60 * 24 * 90; // 90 days.

	public static function sign( string $email, int $expires ): string {
		$normalized = Opt_Out_Store::key( $email );
		if ( '' === $normalized ) {
			return '';
		}
		$mac = hash_hmac( 'sha256', $normalized . '|' . $expires, self::derive_key(), true );
		return self::b64url( $mac );
	}

	/**
	 * @return array{ok:true,email:string}|array{ok:false,error:string}
	 */
	public static function verify( string $email, int $expires, string $token ): array {
		$normalized = Opt_Out_Store::key( $email );
		if ( '' === $normalized ) {
			return array( 'ok' => false, 'error' => 'invalid_email' );
		}
		if ( $expires <= time() ) {
			return array( 'ok' => false, 'error' => 'expired' );
		}
		$expected = self::sign( $normalized, $expires );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			return array( 'ok' => false, 'error' => 'bad_token' );
		}
		return array( 'ok' => true, 'email' => $normalized );
	}

	public static function make_link( string $email, string $base_url = '' ): string {
		$expires = time() + self::TTL_SECONDS;
		$token   = self::sign( $email, $expires );
		if ( '' === $token ) {
			return '';
		}
		$base = '' !== $base_url ? $base_url : ( function_exists( 'home_url' ) ? (string) home_url( '/opt-out' ) : '/opt-out' );
		return $base
			. '?email=' . rawurlencode( $email )
			. '&expires=' . $expires
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
