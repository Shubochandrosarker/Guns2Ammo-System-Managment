<?php
/**
 * Thin wrapper around the transients API.
 *
 * Every analytics response passes through here so we can trade one WP option
 * lookup for a cascade of Woo/Memberistic queries. TTL is short by default
 * because the dashboard is meant to feel live.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cache {
	private const PREFIX      = 'g2aba_';
	private const DEFAULT_TTL = 60;

	/**
	 * @template T
	 * @param callable():T $producer
	 * @return T
	 */
	public static function remember( string $key, int $ttl, callable $producer ) {
		if ( defined( 'G2ABA_DISABLE_CACHE' ) && G2ABA_DISABLE_CACHE ) {
			return $producer();
		}

		$full = self::PREFIX . $key;
		$hit  = get_transient( $full );
		if ( false !== $hit ) {
			return $hit;
		}

		$value = $producer();
		set_transient( $full, $value, $ttl > 0 ? $ttl : self::DEFAULT_TTL );
		return $value;
	}

	public static function forget( string $key ): void {
		delete_transient( self::PREFIX . $key );
	}
}
