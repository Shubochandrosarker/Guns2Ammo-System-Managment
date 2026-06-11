<?php

namespace G2A\POS\Wholesalers\Davidsons;

use G2A\POS\Wholesalers\AbstractWholesalerProvider;

/**
 * Davidson's Inc. wholesaler provider.
 *
 * Catalog ingestion: real CSV importer mapping the Davidson's "Product Catalog"
 * download. Drop-ship/order/live-inventory go through the REST API at
 * https://apidocs.davidsonsinc.com — the client class is documented; methods
 * return `requires_credentials` until the merchant connects an account.
 */
final class DavidsonsProvider extends AbstractWholesalerProvider {

	public const CODE = 'davidsons';

	public function code(): string {
		return self::CODE; }
	public function displayName(): string {
		return "Davidson's Inc."; }
	public function supportsCatalogCsv(): bool {
		return true; }
	public function supportsApi(): bool {
		return true; }
	public function supportsDropShip(): bool {
		return true; }
	public function supportsRealTimeInventory(): bool {
		return true; }

	public function importCatalogCsv( int $wholesalerId, string $absoluteFilePath, array $options = array() ): array {
		return ( new DavidsonsCsvImporter() )->import( $wholesalerId, $absoluteFilePath, $options );
	}

	public function syncInventory( int $wholesalerId, array $options = array() ): array {
		return array(
			'ok'     => false,
			'error'  => 'requires_credentials',
			'detail' => 'Davidson\'s REST inventory endpoint needs account credentials. See DavidsonsApiClient docblock for setup.',
		);
	}

	public function submitDropShipOrder( int $wholesalerId, array $order ): array {
		$policy = $this->checkDropShipCompliance( $wholesalerId, $order );
		if ( ! $policy['ok'] ) {
			return array(
				'ok'      => false,
				'error'   => $policy['error'],
				'message' => $policy['message'],
			);
		}
		return array(
			'ok'     => false,
			'error'  => 'requires_credentials',
			'detail' => 'Davidson\'s drop-ship order endpoint needs account credentials.',
		);
	}
}
