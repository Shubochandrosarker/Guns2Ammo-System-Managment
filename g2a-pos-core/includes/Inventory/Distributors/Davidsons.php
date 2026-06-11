<?php

namespace G2A\POS\Inventory\Distributors;

final class Davidsons implements Adapter {

	public function slug(): string {
		return 'davidsons'; }
	public function label(): string {
		return "Davidson's"; }

	public function header_signature(): array {
		return array( 'item_number', 'manufacturer_part_number', 'upc', 'retail_price', 'wholesale_price' );
	}

	public function map_row( array $raw ): ?array {
		$h = AdapterHelpers::pick( $raw, array( 'item_number', 'itemNumber', 'sku' ) );
		if ( ! $h ) {
			return null;
		}

		$desc       = AdapterHelpers::pick( $raw, array( 'description', 'long_description' ) ) ?? '';
		$class_hint = AdapterHelpers::pick( $raw, array( 'type', 'category', 'class' ) );
		$class      = AdapterHelpers::classify_type( $class_hint, $desc );

		return array(
			'source'            => $this->slug(),
			'source_ref'        => $h,
			'upc'               => AdapterHelpers::upc( AdapterHelpers::pick( $raw, array( 'upc', 'upcCode' ) ) ),
			'manufacturer_sku'  => AdapterHelpers::pick( $raw, array( 'manufacturer_part_number', 'mfgPartNumber' ) ),
			'manufacturer'      => AdapterHelpers::pick( $raw, array( 'manufacturer', 'mfg' ) ),
			'model'             => AdapterHelpers::pick( $raw, array( 'model' ) ),
			'name'              => AdapterHelpers::pick( $raw, array( 'short_description', 'product_name' ) ) ?? $desc ?: $h,
			'description'       => $desc,
			'item_type'         => $class['item_type'],
			'firearm_type'      => $class['firearm_type'],
			'caliber'           => AdapterHelpers::pick( $raw, array( 'caliber', 'gauge' ) ),
			'magazine_capacity' => AdapterHelpers::int( $raw, array( 'capacity', 'magazine_capacity' ) ),
			'msrp'              => AdapterHelpers::money( $raw, array( 'retail_price', 'msrp' ) ),
			'dealer_cost'       => AdapterHelpers::money( $raw, array( 'wholesale_price', 'dealer_cost', 'price' ) ),
			'quantity'          => AdapterHelpers::int( $raw, array( 'quantity', 'qty_on_hand', 'onhand' ) ),
			'metadata'          => array(
				'davidsons_item' => $h,
				'finish'         => AdapterHelpers::pick( $raw, array( 'finish' ) ),
			),
		);
	}
}
