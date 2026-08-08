<?php

namespace G2A\POS\Wholesalers;

interface WholesalerProviderInterface {

	public function code(): string;
	public function displayName(): string;

	public function supportsCatalogCsv(): bool;
	public function supportsApi(): bool;
	public function supportsDropShip(): bool;
	public function supportsRealTimeInventory(): bool;
	public function supportsItemValidation(): bool;
	public function supportsWarehouseOrder(): bool;
	public function supportsOneDayShippingCheck(): bool;

	public function importCatalogCsv( int $wholesalerId, string $absoluteFilePath, array $options = array() ): array;
	public function syncInventory( int $wholesalerId, array $options = array() ): array;

	public function validateItem( int $wholesalerId, string $sku ): array;
	public function lookupByUpc( int $wholesalerId, string $upc ): array;
	public function checkOneDayShipping( int $wholesalerId, string $date ): array;

	public function submitWarehouseOrder( int $wholesalerId, array $order ): array;
	public function submitDropShipOrder( int $wholesalerId, array $order ): array;
	public function fetchOrderStatus( int $wholesalerId, string $externalRef ): array;
}
