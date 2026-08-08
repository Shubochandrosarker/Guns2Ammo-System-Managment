<?php
/**
 * Money helpers. Everything on the wire is USD cents (int).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Money {
	public static function to_cents( float $dollars ): int {
		return (int) round( $dollars * 100 );
	}

	public static function to_cents_from_string( string $amount ): int {
		// WooCommerce & Stripe often expose amounts as strings; parse safely.
		$clean = preg_replace( '/[^0-9.\-]/', '', $amount );
		if ( '' === $clean || null === $clean ) {
			return 0;
		}
		return self::to_cents( (float) $clean );
	}

	public static function sum_cents( array $items, string $key ): int {
		$total = 0;
		foreach ( $items as $item ) {
			if ( isset( $item[ $key ] ) ) {
				$total += (int) $item[ $key ];
			}
		}
		return $total;
	}
}
