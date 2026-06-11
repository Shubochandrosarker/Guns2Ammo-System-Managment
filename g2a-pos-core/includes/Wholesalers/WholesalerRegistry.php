<?php

namespace G2A\POS\Wholesalers;

use G2A\POS\Wholesalers\Davidsons\DavidsonsProvider;
use G2A\POS\Wholesalers\Lipseys\LipseysProvider;
use G2A\POS\Wholesalers\Rsr\RsrProvider;
use G2A\POS\Wholesalers\SportsSouth\SportsSouthProvider;

final class WholesalerRegistry {

	private static array $providers = array();
	private static bool $booted     = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::register( new LipseysProvider() );
		self::register( new DavidsonsProvider() );
		self::register( new RsrProvider() );
		self::register( new SportsSouthProvider() );
		self::$booted = true;

		do_action(
			'g2a_pos_register_wholesaler_providers',
			static function ( WholesalerProviderInterface $p ): void {
				self::register( $p );
			}
		);
	}

	public static function register( WholesalerProviderInterface $provider ): void {
		self::$providers[ $provider->code() ] = $provider;
	}

	public static function get( string $code ): ?WholesalerProviderInterface {
		self::boot();
		return self::$providers[ $code ] ?? null;
	}

	/** @return WholesalerProviderInterface[] */
	public static function all(): array {
		self::boot();
		return self::$providers;
	}
}
