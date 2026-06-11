<?php

namespace G2A\POS\Wholesalers\Rsr;

use G2A\POS\Wholesalers\AbstractWholesalerProvider;

/**
 * RSR Group wholesaler provider.
 *
 * Catalog ingestion is via RSR's nightly FTP feed: rsrinventory-new.txt
 * (pipe-delimited, 76 fields per row, no header). The importer in this class
 * handles that file shape directly. The merchant configures the FTP pull as
 * a separate cron (or downloads manually) and uploads the file through the
 * Catalog Import UI.
 *
 * Drop-ship / live inventory go through RSR's SOAP API
 * (https://www.rsrgroup.com/SharingAPI/); the client lives in RsrSoapClient
 * once credentials are in hand.
 */
final class RsrProvider extends AbstractWholesalerProvider {

	public const CODE = 'rsr';

	public function code(): string {
		return self::CODE; }
	public function displayName(): string {
		return 'RSR Group'; }
	public function supportsCatalogCsv(): bool {
		return true; }
	public function supportsApi(): bool {
		return true; }
	public function supportsDropShip(): bool {
		return true; }
	public function supportsRealTimeInventory(): bool {
		return true; }

	public function importCatalogCsv( int $wholesalerId, string $absoluteFilePath, array $options = array() ): array {
		return ( new RsrFeedImporter() )->import( $wholesalerId, $absoluteFilePath, $options );
	}

	public function syncInventory( int $wholesalerId, array $options = array() ): array {
		return array(
			'ok'     => false,
			'error'  => 'requires_credentials',
			'detail' => 'RSR live inventory is via SOAP; supply username + password + key in wholesaler credentials.',
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
			'detail' => 'RSR drop-ship is via SOAP OrderEntry; needs account credentials.',
		);
	}
}
