<?php

namespace G2A\POS\Inventory\Distributors;

final class AdapterRegistry {

	/** @var array<string,Adapter>|null */
	private static ?array $cache = null;

	/**
	 * @return array<string,Adapter>
	 */
	public static function all(): array {
		if ( self::$cache === null ) {
			self::$cache = array();
			foreach ( array( new Lipseys(), new Rsr(), new Davidsons(), new SportsSouth() ) as $a ) {
				self::$cache[ $a->slug() ] = $a;
			}
		}
		return self::$cache;
	}

	public static function get( string $slug ): ?Adapter {
		return self::all()[ $slug ] ?? null;
	}

	/** Pick the adapter whose header signature best matches the CSV headers. */
	public static function detect( array $headers ): ?Adapter {
		$lower      = array_map( 'strtolower', array_map( 'trim', $headers ) );
		$best       = null;
		$best_score = 0;
		foreach ( self::all() as $adapter ) {
			$sig   = array_map( 'strtolower', $adapter->header_signature() );
			$score = count( array_intersect( $lower, $sig ) );
			if ( $score > $best_score ) {
				$best       = $adapter;
				$best_score = $score;
			}
		}
		return $best_score >= 2 ? $best : null;
	}
}
