<?php

namespace G2A\POS\Inventory\Distributors;

final class Rsr implements Adapter {

	public function slug(): string {
		return 'rsr'; }
	public function label(): string {
		return 'RSR Group'; }

	public function header_signature(): array {
		return array( 'rsr_stock_no', 'rsr_stocknum', 'rsr', 'mfg_part_no', 'retail_price', 'rsr_price' );
	}

	public function map_row( array $raw ): ?array {
		$h = AdapterHelpers::pick( $raw, array( 'rsr_stock_no', 'rsr_stocknum', 'rsr_stock_number', 'stock_no' ) );
		if ( ! $h ) {
			return null;
		}

		$desc  = AdapterHelpers::pick( $raw, array( 'description', 'product_description', 'item_description' ) ) ?? '';
		$dept  = AdapterHelpers::pick( $raw, array( 'dept_no', 'department', 'category' ) );
		$class = AdapterHelpers::classify_type( $dept, $desc );

		return array(
			'source'            => $this->slug(),
			'source_ref'        => $h,
			'upc'               => AdapterHelpers::upc( AdapterHelpers::pick( $raw, array( 'upc', 'upc_code' ) ) ),
			'manufacturer_sku'  => AdapterHelpers::pick( $raw, array( 'mfg_part_no', 'mfg_part_number', 'manufacturer_part_no' ) ),
			'manufacturer'      => AdapterHelpers::pick( $raw, array( 'mfg', 'mfg_name', 'manufacturer' ) ),
			'model'             => AdapterHelpers::pick( $raw, array( 'model', 'model_name' ) ),
			'name'              => $desc ?: $h,
			'description'       => $desc,
			'item_type'         => $class['item_type'],
			'firearm_type'      => $class['firearm_type'],
			'caliber'           => AdapterHelpers::pick( $raw, array( 'caliber', 'caliber_gauge' ) ),
			'magazine_capacity' => AdapterHelpers::int( $raw, array( 'capacity' ) ),
			'msrp'              => AdapterHelpers::money( $raw, array( 'retail_price', 'msrp' ) ),
			'dealer_cost'       => AdapterHelpers::money( $raw, array( 'rsr_price', 'dealer_price', 'price' ) ),
			'quantity'          => AdapterHelpers::int( $raw, array( 'qty_available', 'available_qty', 'inventory' ) ),
			'metadata'          => array(
				'rsr_stock_no' => $h,
				'dept_no'      => $dept,
				'allocated'    => AdapterHelpers::pick( $raw, array( 'allocated' ) ),
			),
		);
	}
}
