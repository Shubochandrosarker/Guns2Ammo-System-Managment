<?php

namespace G2A\POS\Wholesalers;

use G2A\POS\Database\WholesalerProductRepository;

abstract class AbstractWholesalerProvider implements WholesalerProviderInterface {

	public function supportsCatalogCsv(): bool {
		return false; }
	public function supportsApi(): bool {
		return false; }
	public function supportsDropShip(): bool {
		return false; }
	public function supportsRealTimeInventory(): bool {
		return false; }
	public function supportsItemValidation(): bool {
		return false; }
	public function supportsWarehouseOrder(): bool {
		return false; }
	public function supportsOneDayShippingCheck(): bool {
		return false; }

	public function importCatalogCsv( int $wholesalerId, string $absoluteFilePath, array $options = array() ): array {
		return array(
			'ok'    => false,
			'error' => 'csv_unsupported',
		);
	}

	public function syncInventory( int $wholesalerId, array $options = array() ): array {
		return array(
			'ok'    => false,
			'error' => 'inventory_sync_unsupported',
		);
	}

	public function validateItem( int $wholesalerId, string $sku ): array {
		return array(
			'ok'    => false,
			'error' => 'validate_item_unsupported',
		);
	}

	public function lookupByUpc( int $wholesalerId, string $upc ): array {
		return array(
			'ok'    => false,
			'error' => 'upc_lookup_unsupported',
		);
	}

	public function checkOneDayShipping( int $wholesalerId, string $date ): array {
		return array(
			'ok'    => false,
			'error' => 'one_day_check_unsupported',
		);
	}

	public function submitWarehouseOrder( int $wholesalerId, array $order ): array {
		return array(
			'ok'    => false,
			'error' => 'warehouse_order_unsupported',
		);
	}

	public function submitDropShipOrder( int $wholesalerId, array $order ): array {
		return array(
			'ok'    => false,
			'error' => 'dropship_unsupported',
		);
	}

	public function fetchOrderStatus( int $wholesalerId, string $externalRef ): array {
		return array(
			'ok'    => false,
			'error' => 'status_unsupported',
		);
	}

	/**
	 * Drop-ship compliance rule set for this wholesaler.
	 *
	 * The default is the universal federal guard only (firearms must ship to a
	 * licensed FFL). Wholesalers with extra program rules — firearm/accessory
	 * separation, restricted ship-to states — override this.
	 *
	 * @return array<string,mixed>
	 */
	protected function dropShipRules(): array {
		return array(
			'require_destination_ffl'      => true,
			'firearm_accessory_separation' => false,
			'restricted_accessory_states'  => array(),
		);
	}

	/**
	 * Run the shared drop-ship compliance guard for this wholesaler. Items are
	 * enriched from the imported catalog so firearms and accessories classify
	 * correctly before the rules run.
	 *
	 * @param array<string,mixed> $order Drop-ship order (`items`, `ship_to`).
	 * @return array{ok:bool,error?:string,message?:string,items?:array<int,array<string,mixed>>}
	 */
	protected function checkDropShipCompliance( int $wholesalerId, array $order ): array {
		$items           = $this->enrichItemTypes( $wholesalerId, (array) ( $order['items'] ?? array() ) );
		$result          = WholesalerDropShipPolicy::validate(
			$items,
			(array) ( $order['ship_to'] ?? array() ),
			$this->dropShipRules()
		);
		$result['items'] = $items;
		return $result;
	}

	/**
	 * Fill in each item's item_type / ffl_required from the imported catalog so
	 * the drop-ship policy can classify firearms vs accessories accurately.
	 *
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	protected function enrichItemTypes( int $wholesalerId, array $items ): array {
		$repo = new WholesalerProductRepository();
		foreach ( $items as &$item ) {
			if ( ! empty( $item['item_type'] ) && isset( $item['ffl_required'] ) ) {
				continue;
			}
			$sku  = (string) ( $item['vendor_sku'] ?? $item['ItemNo'] ?? $item['itemNo'] ?? '' );
			$prod = $sku !== '' ? $repo->find( $wholesalerId, $sku ) : null;
			if ( ! $prod && ! empty( $item['upc'] ) ) {
				$prod = $repo->findByUpc( (string) $item['upc'] );
			}
			if ( $prod ) {
				if ( empty( $item['item_type'] ) ) {
					$item['item_type'] = $prod['item_type'] ?? null;
				}
				if ( ! isset( $item['ffl_required'] ) ) {
					$item['ffl_required'] = $prod['ffl_required'] ?? 0;
				}
			}
		}
		unset( $item );
		return $items;
	}
}
