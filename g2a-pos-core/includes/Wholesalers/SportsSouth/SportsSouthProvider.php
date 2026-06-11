<?php

namespace G2A\POS\Wholesalers\SportsSouth;

use G2A\POS\Wholesalers\AbstractWholesalerProvider;

/**
 * Sports South wholesaler provider.
 *
 * Catalog ingestion: the merchant downloads the daily catalog export (CSV)
 * from the Sports South dealer portal. The columns vary slightly across
 * exports; the importer accepts the common shapes documented below.
 *
 * Drop-ship / live inventory: SOAP at
 * https://webservices.theshootingwarehouse.com/. Requires CustomerNumber +
 * Username + Password + Source (set in wholesaler credentials).
 */
final class SportsSouthProvider extends AbstractWholesalerProvider {

	public const CODE = 'sports_south';

	public function code(): string {
		return self::CODE; }
	public function displayName(): string {
		return 'Sports South'; }
	public function supportsCatalogCsv(): bool {
		return true; }
	public function supportsApi(): bool {
		return true; }
	public function supportsDropShip(): bool {
		return true; }
	public function supportsRealTimeInventory(): bool {
		return true; }

	public function importCatalogCsv( int $wholesalerId, string $absoluteFilePath, array $options = array() ): array {
		return ( new SportsSouthCsvImporter() )->import( $wholesalerId, $absoluteFilePath, $options );
	}

	public function syncInventory( int $wholesalerId, array $options = array() ): array {
		return array(
			'ok'     => false,
			'error'  => 'requires_credentials',
			'detail' => 'Sports South inventory pulls use SOAP method IncrementalOnhandUpdate; needs account credentials.',
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
			'detail' => 'Sports South order submission uses SOAP method OrderEntry; needs account credentials.',
		);
	}
}
