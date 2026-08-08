<?php

namespace G2A\POS\Inventory\Distributors;

final class SportsSouth implements Adapter {

	public function slug(): string {
		return 'sports_south'; }
	public function label(): string {
		return 'Sports South'; }

	public function header_signature(): array {
		return array( 'itemno', 'idesc', 'imfg', 'imfgcd', 'iprice1', 'iprice2', 'qtyoh' );
	}

	public function map_row( array $raw ): ?array {
		$h = AdapterHelpers::pick( $raw, array( 'ITEMNO', 'itemno' ) );
		if ( ! $h ) {
			return null;
		}

		$desc      = AdapterHelpers::pick( $raw, array( 'IDESC', 'idesc', 'description' ) ) ?? '';
		$type_hint = AdapterHelpers::pick( $raw, array( 'ITYPE', 'itype', 'category' ) );
		$class     = AdapterHelpers::classify_type( $type_hint, $desc );

		return array(
			'source'            => $this->slug(),
			'source_ref'        => $h,
			'upc'               => AdapterHelpers::upc( AdapterHelpers::pick( $raw, array( 'IUPC', 'iupc', 'upc' ) ) ),
			'manufacturer_sku'  => AdapterHelpers::pick( $raw, array( 'IMFGCD', 'imfgcd', 'mfgcd' ) ),
			'manufacturer'      => AdapterHelpers::pick( $raw, array( 'IMFG', 'imfg', 'mfg' ) ),
			'model'             => AdapterHelpers::pick( $raw, array( 'IMODEL', 'imodel', 'model' ) ),
			'name'              => $desc ?: $h,
			'description'       => $desc,
			'item_type'         => $class['item_type'],
			'firearm_type'      => $class['firearm_type'],
			'caliber'           => AdapterHelpers::pick( $raw, array( 'ICALIBER', 'icaliber', 'caliber' ) ),
			'magazine_capacity' => AdapterHelpers::int( $raw, array( 'ICAPACITY', 'icapacity', 'capacity' ) ),
			'msrp'              => AdapterHelpers::money( $raw, array( 'IPRICE1', 'iprice1', 'msrp' ) ),
			'dealer_cost'       => AdapterHelpers::money( $raw, array( 'IPRICE2', 'iprice2', 'dealer_price', 'cost' ) ),
			'quantity'          => AdapterHelpers::int( $raw, array( 'QTYOH', 'qtyoh', 'qty_on_hand' ) ),
			'metadata'          => array(
				'sports_south_item' => $h,
			),
		);
	}
}
