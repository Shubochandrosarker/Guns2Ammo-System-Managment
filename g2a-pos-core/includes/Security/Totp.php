<?php

namespace G2A\POS\Security;

/**
 * RFC 6238 TOTP, HMAC-SHA1, 30s step, 6 digits.
 * Used for step-up auth on firearm transfers and refunds above threshold.
 */
final class Totp {

	public static function generate_secret( int $bytes = 20 ): string {
		return self::base32_encode( random_bytes( $bytes ) );
	}

	public static function code( string $secret_b32, ?int $timestamp = null, int $digits = 6, int $period = 30 ): string {
		$timestamp ??= time();
		$counter     = (int) floor( $timestamp / $period );
		$bin         = pack( 'J', $counter );
		$hash        = hash_hmac( 'sha1', $bin, self::base32_decode( $secret_b32 ), true );
		$offset      = ord( $hash[ strlen( $hash ) - 1 ] ) & 0x0f;
		$code        = ( ( ord( $hash[ $offset ] ) & 0x7f ) << 24 )
				| ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 )
				| ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 )
				| ( ord( $hash[ $offset + 3 ] ) & 0xff );
		return str_pad( (string) ( $code % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
	}

	public static function verify( string $secret_b32, string $code, int $window = 1, ?int $timestamp = null ): bool {
		$timestamp ??= time();
		$code        = trim( $code );
		for ( $i = -$window; $i <= $window; $i++ ) {
			if ( hash_equals( self::code( $secret_b32, $timestamp + ( $i * 30 ) ), $code ) ) {
				return true;
			}
		}
		return false;
	}

	public static function otpauth_uri( string $label, string $secret_b32, string $issuer = 'G2A POS' ): string {
		$params = http_build_query(
			array(
				'secret'    => $secret_b32,
				'issuer'    => $issuer,
				'algorithm' => 'SHA1',
				'digits'    => 6,
				'period'    => 30,
			)
		);
		return sprintf( 'otpauth://totp/%s:%s?%s', rawurlencode( $issuer ), rawurlencode( $label ), $params );
	}

	public static function base32_encode( string $bin ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$out      = '';
		$buffer   = 0;
		$bits     = 0;
		foreach ( str_split( $bin ) as $b ) {
			$buffer = ( $buffer << 8 ) | ord( $b );
			$bits  += 8;
			while ( $bits >= 5 ) {
				$bits -= 5;
				$out  .= $alphabet[ ( $buffer >> $bits ) & 0x1f ];
			}
		}
		if ( $bits > 0 ) {
			$out .= $alphabet[ ( $buffer << ( 5 - $bits ) ) & 0x1f ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32      = rtrim( strtoupper( preg_replace( '/[^A-Z2-7]/', '', $b32 ) ), '=' );
		$out      = '';
		$buffer   = 0;
		$bits     = 0;
		foreach ( str_split( $b32 ) as $c ) {
			$val = strpos( $alphabet, $c );
			if ( $val === false ) {
				continue;
			}
			$buffer = ( $buffer << 5 ) | $val;
			$bits  += 5;
			if ( $bits >= 8 ) {
				$bits -= 8;
				$out  .= chr( ( $buffer >> $bits ) & 0xff );
			}
		}
		return $out;
	}
}
