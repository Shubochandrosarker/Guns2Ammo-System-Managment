<?php
/**
 * Referral code generation and normalisation.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Codes {

	public const PREFIX = 'G2A-';
	public const LENGTH = 6;

	/**
	 * Crockford base32: the digits, minus I, L, O and U.
	 *
	 * I/L/O are dropped because they are indistinguishable from 1 and 0 when
	 * read aloud at the counter; U is dropped so the alphabet cannot spell
	 * anything unfortunate. A range is a face-to-face business — staff read
	 * these to customers over a desk.
	 */
	public const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * A random candidate code. Uniqueness is enforced by the DB, not here.
	 *
	 * @return string
	 */
	public static function generate() {
		$alphabet = self::ALPHABET;
		$max      = strlen( $alphabet ) - 1;
		$code     = '';

		for ( $i = 0; $i < self::LENGTH; $i++ ) {
			$code .= $alphabet[ wp_rand( 0, $max ) ];
		}

		return self::PREFIX . $code;
	}

	/**
	 * Normalise anything a human might type or paste into canonical form.
	 *
	 * Accepts "g2a-x1y2z3", "X1Y2Z3", " G2A X1Y2Z3 ". Applies Crockford's
	 * substitutions (I/L -> 1, O -> 0) so a code read aloud and mistyped
	 * still resolves.
	 *
	 * @param string $raw Raw user input.
	 * @return string Canonical code, or '' when the input cannot be a code.
	 */
	public static function normalize( $raw ) {
		$value = strtoupper( trim( (string) $raw ) );
		$value = preg_replace( '/[^A-Z0-9]/', '', $value );

		if ( '' === (string) $value ) {
			return '';
		}

		if ( 0 === strpos( $value, 'G2A' ) ) {
			$value = substr( $value, 3 );
		}

		$value = strtr( $value, array( 'I' => '1', 'L' => '1', 'O' => '0' ) );

		if ( strlen( $value ) !== self::LENGTH ) {
			return '';
		}

		if ( strlen( $value ) !== strspn( $value, self::ALPHABET ) ) {
			return '';
		}

		return self::PREFIX . $value;
	}

	/**
	 * True when the string is a well-formed code. Says nothing about whether
	 * it exists.
	 *
	 * @param string $raw Candidate.
	 * @return bool
	 */
	public static function is_valid_format( $raw ) {
		return '' !== self::normalize( $raw );
	}

	/**
	 * Public share link for a code.
	 *
	 * @param string $code Canonical code.
	 * @return string
	 */
	public static function share_url( $code ) {
		return add_query_arg( 'ref', rawurlencode( $code ), home_url( '/' ) );
	}
}
