<?php

namespace G2A\POS\Wholesalers\Lipseys;

use G2A\POS\Database\WholesalerRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerOrderRepository;
use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Wholesalers\AbstractWholesalerProvider;

final class LipseysProvider extends AbstractWholesalerProvider {

	public const CODE = 'lipseys';

	public function code(): string {
		return self::CODE; }
	public function displayName(): string {
		return "Lipsey's Inc."; }
	public function supportsCatalogCsv(): bool {
		return true; }
	public function supportsApi(): bool {
		return true; }
	public function supportsDropShip(): bool {
		return true; }
	public function supportsRealTimeInventory(): bool {
		return true; }
	public function supportsItemValidation(): bool {
		return true; }
	public function supportsWarehouseOrder(): bool {
		return true; }
	public function supportsOneDayShippingCheck(): bool {
		return true; }

	public function importCatalogCsv( int $wholesalerId, string $absoluteFilePath, array $options = array() ): array {
		$importer = new LipseysCsvImporter();
		return $importer->import( $wholesalerId, $absoluteFilePath, $options );
	}

	/**
	 * Full catalog sync over the API (GET /api/Integration/Items/CatalogFeed)
	 * — no CSV upload required. Upserts every item into wholesaler_products
	 * using the same normalized mapping as the CSV importer.
	 */
	public function importCatalogApi( int $wholesalerId, array $options = array() ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}
		try {
			$feed = $client->fetchCatalog();
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
		if ( (int) ( $feed['_status'] ?? 200 ) !== 200 ) {
			return array(
				'ok'    => false,
				'error' => 'catalog_feed_http_' . (int) $feed['_status'],
			);
		}
		// Lipsey's returns HTTP 200 with an API-level failure envelope
		// (e.g. {"success":false,"authorized":false,"errors":[...]}). Treat
		// that as a hard failure — never "successfully imported 0 rows",
		// which would wrongly mark the wholesaler synced.
		$authorized = $feed['authorized'] ?? $feed['Authorized'] ?? true;
		$success    = $feed['success'] ?? $feed['Success'] ?? true;
		if ( false === $authorized || false === $success ) {
			$errs = $feed['errors'] ?? $feed['Errors'] ?? array();
			return array(
				'ok'    => false,
				'error' => 'lipseys_api_rejected',
				'detail' => is_array( $errs ) ? implode( '; ', array_map( 'strval', $errs ) ) : (string) $errs,
			);
		}
		$items = $feed['data'] ?? $feed['Items'] ?? $feed['items'] ?? null;
		if ( ! is_array( $items ) ) {
			return array(
				'ok'    => false,
				'error' => 'unexpected_response_shape',
			);
		}
		if ( array() === $items ) {
			// An empty feed from an authorized account is suspicious — report
			// it rather than silently marking a successful zero-row import.
			return array(
				'ok'    => false,
				'error' => 'empty_catalog_feed',
			);
		}

		$productRepo = new WholesalerProductRepository();
		$mapRepo     = new MapRuleRepository();
		$now         = current_time( 'mysql' );
		$stats       = array(
			'rows_total'         => 0,
			'rows_created'       => 0,
			'rows_updated'       => 0,
			'rows_skipped'       => 0,
			'rows_failed'        => 0,
			'map_rules_upserted' => 0,
			'errors'             => array(),
		);

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			++$stats['rows_total'];
			try {
				$mapped = LipseysCatalogMapper::mapApiItem( $item );
				$sku    = (string) ( $mapped['vendor_sku'] ?? '' );
				if ( $sku === '' ) {
					++$stats['rows_skipped'];
					continue;
				}
				$mapped['wholesaler_id'] = $wholesalerId;
				$mapped['last_seen_at']  = $now;

				$result = $productRepo->upsert( $mapped );
				if ( $result === 'created' ) {
					++$stats['rows_created'];
				} elseif ( $result === 'updated' ) {
					++$stats['rows_updated'];
				} else {
					++$stats['rows_skipped'];
				}

				// Mirror the CSV importer: persist the vendor MAP so the
				// storefront MAP-pricing guard knows the floor. Without this,
				// API-synced products could be advertised below MAP.
				if ( ! empty( $mapped['map_price'] ) && (float) $mapped['map_price'] > 0 ) {
					$mapRepo->upsertFromVendor(
						array(
							'sku'           => $sku,
							'upc'           => $mapped['upc'] ?? null,
							'manufacturer'  => $mapped['manufacturer'] ?? null,
							'map_price'     => (float) $mapped['map_price'],
							'wholesaler_id' => $wholesalerId,
							'source'        => 'lipseys_api',
						)
					);
					++$stats['map_rules_upserted'];
				}
			} catch ( \Throwable $e ) {
				++$stats['rows_failed'];
				if ( count( $stats['errors'] ) < 20 ) {
					$stats['errors'][] = $e->getMessage();
				}
			}
		}

		return array(
			'ok'    => true,
			'stats' => $stats,
		);
	}

	public function syncInventory( int $wholesalerId, array $options = array() ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}
		try {
			$snapshot = $client->fetchPricingQuantityFeed();
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}

		if ( (int) ( $snapshot['_status'] ?? 200 ) !== 200 ) {
			return array(
				'ok'    => false,
				'error' => 'pricing_feed_http_' . (int) ( $snapshot['_status'] ?? 0 ),
			);
		}
		// Lipsey's returns HTTP 200 with an API-level failure envelope
		// (e.g. {"success":false,"authorized":false,"errors":[...]}). Treat that
		// as a hard failure — never "successfully synced 0 rows", which would
		// wrongly mark the wholesaler as synced. Mirrors importCatalogApi().
		$authorized = $snapshot['authorized'] ?? $snapshot['Authorized'] ?? true;
		$success    = $snapshot['success'] ?? $snapshot['Success'] ?? true;
		if ( false === $authorized || false === $success ) {
			$errs = $snapshot['errors'] ?? $snapshot['Errors'] ?? array();
			return array(
				'ok'     => false,
				'error'  => 'lipseys_api_rejected',
				'detail' => is_array( $errs ) ? implode( '; ', array_map( 'strval', $errs ) ) : (string) $errs,
			);
		}

		$items = $snapshot['data'] ?? $snapshot['Items'] ?? $snapshot['items'] ?? null;
		if ( ! is_array( $items ) ) {
			return array(
				'ok'    => false,
				'error' => 'unexpected_response_shape',
			);
		}
		if ( array() === $items ) {
			// An empty feed from an authorized account is suspicious — report it
			// rather than silently marking a successful zero-row sync.
			return array(
				'ok'    => false,
				'error' => 'empty_pricing_feed',
			);
		}

		$productRepo = new WholesalerProductRepository();
		$updated     = 0;
		foreach ( $items as $row ) {
			$sku = (string) ( $row['itemNo'] ?? $row['ItemNo'] ?? $row['ITEMNO'] ?? '' );
			if ( $sku === '' ) {
				continue;
			}
			$qty   = (int) ( $row['quantity'] ?? $row['Quantity'] ?? 0 );
			$price = isset( $row['currentPrice'] ) ? (float) $row['currentPrice'] : (
				isset( $row['price'] ) ? (float) $row['price'] : null
			);
			$productRepo->updateLive( $wholesalerId, $sku, $qty, $price );
			++$updated;
		}

		return array(
			'ok'           => true,
			'rows_updated' => $updated,
		);
	}

	public function validateItem( int $wholesalerId, string $sku ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}
		try {
			$resp = $client->validateItem( $sku );
			return array(
				'ok'       => true,
				'response' => $resp,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	public function lookupByUpc( int $wholesalerId, string $upc ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}
		try {
			$resp = $client->catalogFeedItem( $upc );
			return array(
				'ok'       => true,
				'response' => $resp,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	public function checkOneDayShipping( int $wholesalerId, string $date ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}
		try {
			$resp = $client->checkOneDayShipping( $date );
			return array(
				'ok'       => true,
				'response' => $resp,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Ship to the dealer's FFL on file at Lipsey's (no third-party drop-ship).
	 */
	public function submitWarehouseOrder( int $wholesalerId, array $order ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}

		$payload   = $this->buildWarehouseOrderPayload( $order );
		$orderRepo = new WholesalerOrderRepository();
		$localId   = $orderRepo->create(
			array(
				'wholesaler_id'   => $wholesalerId,
				'pos_order_id'    => $order['pos_order_id'] ?? null,
				'wc_order_id'     => $order['wc_order_id'] ?? null,
				'order_type'      => 'warehouse',
				'status'          => 'submitting',
				'items'           => $order['items'] ?? array(),
				'request_payload' => $payload,
				'actor_id'        => get_current_user_id(),
			)
		);

		try {
			$resp = $client->submitWarehouseOrder( $payload );
		} catch ( \Throwable $e ) {
			$orderRepo->markFailed( $localId, $e->getMessage() );
			return array(
				'ok'             => false,
				'error'          => $e->getMessage(),
				'local_order_id' => $localId,
			);
		}

		$extRef = (string) ( $resp['orderNumber'] ?? $resp['OrderNumber'] ?? $resp['PONumber'] ?? $resp['PoNumber'] ?? '' );
		$status = ( $resp['_status'] ?? 200 ) === 200 && ( $extRef !== '' || empty( $resp['errors'] ) ) ? 'submitted' : 'failed';
		$orderRepo->markSubmitted( $localId, $extRef, $status, $resp );

		return array(
			'ok'             => $status === 'submitted',
			'local_order_id' => $localId,
			'external_ref'   => $extRef,
			'response'       => $resp,
		);
	}

	/**
	 * Drop-ship to a third party (typically the customer's transferring FFL).
	 * Uses Lipsey's /Order/DropShip endpoint with Billing and Shipping fields.
	 */
	public function submitDropShipOrder( int $wholesalerId, array $order ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}

		// Enforce Lipsey's Dropship Program Guidelines before building/sending the order.
		$policy = $this->checkDropShipCompliance( $wholesalerId, $order );
		if ( ! $policy['ok'] ) {
			return array(
				'ok'      => false,
				'error'   => $policy['error'],
				'message' => $policy['message'],
			);
		}
		$order['items'] = $policy['items'];

		$payload   = $this->buildDropShipPayload( $order );
		$orderRepo = new WholesalerOrderRepository();
		$localId   = $orderRepo->create(
			array(
				'wholesaler_id'   => $wholesalerId,
				'pos_order_id'    => $order['pos_order_id'] ?? null,
				'wc_order_id'     => $order['wc_order_id'] ?? null,
				'order_type'      => 'dropship',
				'status'          => 'submitting',
				'ship_to_ffl'     => $order['ship_to']['ffl'] ?? null,
				'ship_to_name'    => $payload['ShippingName'] ?? null,
				'ship_to_address' => $payload['ShippingAddressLine1'] ?? null,
				'ship_to_city'    => $payload['ShippingAddressCity'] ?? null,
				'ship_to_state'   => $payload['ShippingAddressState'] ?? null,
				'ship_to_zip'     => $payload['ShippingAddressZip'] ?? null,
				'ship_method'     => ! empty( $payload['Overnight'] ) ? 'overnight' : 'best_way',
				'items'           => $order['items'],
				'request_payload' => $payload,
				'actor_id'        => get_current_user_id(),
			)
		);

		try {
			$resp = $client->submitDropShipOrder( $payload );
		} catch ( \Throwable $e ) {
			$orderRepo->markFailed( $localId, $e->getMessage() );
			return array(
				'ok'             => false,
				'error'          => $e->getMessage(),
				'local_order_id' => $localId,
			);
		}

		$extRef = (string) ( $resp['orderNumber'] ?? $resp['OrderNumber'] ?? $resp['PONumber'] ?? $resp['PoNumber'] ?? '' );
		$status = ( $resp['_status'] ?? 200 ) === 200 && ( $extRef !== '' || empty( $resp['errors'] ) ) ? 'submitted' : 'failed';
		$orderRepo->markSubmitted( $localId, $extRef, $status, $resp );

		return array(
			'ok'             => $status === 'submitted',
			'local_order_id' => $localId,
			'external_ref'   => $extRef,
			'response'       => $resp,
		);
	}

	public function fetchOrderStatus( int $wholesalerId, string $externalRef ): array {
		$client = $this->client( $wholesalerId );
		if ( ! $client ) {
			return array(
				'ok'    => false,
				'error' => 'credentials_missing',
			);
		}
		try {
			$resp = $client->orderStatus( $externalRef );
			return array(
				'ok'       => true,
				'response' => $resp,
			);
		} catch ( \Throwable $e ) {
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	private function client( int $wholesalerId ): ?LipseysApiClient {
		$repo = new WholesalerRepository();
		$row  = $repo->find( $wholesalerId );
		if ( ! $row ) {
			return null;
		}
		$creds    = $repo->decodeCredentials( $row );
		$email    = (string) ( $creds['email'] ?? '' );
		$password = (string) ( $creds['password'] ?? '' );
		if ( $email === '' || $password === '' ) {
			return null;
		}
		$endpoint = (string) ( $row['api_endpoint'] ?? '' );
		return new LipseysApiClient( $wholesalerId, $email, $password, $endpoint ?: 'https://api.lipseys.com' );
	}

	/**
	 * Lipsey's drop-ship program rules (firearm/accessory separation, CA
	 * accessory block) on top of the universal FFL guard.
	 *
	 * @return array<string,mixed>
	 */
	protected function dropShipRules(): array {
		return LipseysDropShipPolicy::rules();
	}

	private function buildWarehouseOrderPayload( array $order ): array {
		$payload = LipseysPayloadBuilder::warehouseOrder( $order );
		if ( $payload['PONumber'] === 'G2A-0' ) {
			$payload['PONumber'] = 'G2A-' . time();
		}
		return $payload;
	}

	private function buildDropShipPayload( array $order ): array {
		$payload = LipseysPayloadBuilder::dropShip( $order );
		if ( $payload['PoNumber'] === 'G2A-0' ) {
			$payload['PoNumber'] = 'G2A-' . time();
		}
		return $payload;
	}
}
