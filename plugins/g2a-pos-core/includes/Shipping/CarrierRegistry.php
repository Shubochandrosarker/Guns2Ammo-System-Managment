<?php

namespace G2A\POS\Shipping;

final class CarrierRegistry {

	/** @var CarrierInterface[] */
	private static array $carriers = array();

	public static function register( CarrierInterface $carrier ): void {
		self::$carriers[ $carrier->code() ] = $carrier;
	}

	public static function get( string $code ): ?CarrierInterface {
		return self::$carriers[ $code ] ?? null;
	}

	public static function all(): array {
		return self::$carriers;
	}

	public static function boot(): void {
		self::register( new UpsCarrier() );
		self::register( new FedExCarrier() );
	}
}
