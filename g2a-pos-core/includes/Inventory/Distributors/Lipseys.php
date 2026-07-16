<?php

namespace G2A\POS\Inventory\Distributors;

final class Lipseys implements Adapter {

	public function slug(): string {
		return 'lipseys'; }
	public function label(): string {
		return "Lipsey's"; }

	public function header_signature(): array {
		return array( 'itemno', 'item_no', 'description1', 'manufacturermodelno', 'upc', 'msrp', 'dealerprice' );
	}

	public function map_row( array $raw ): ?array {
		$h = AdapterHelpers::pick( $raw, array( 'itemNo', 'item_no', 'ItemNo', 'StockNumber' ) );
		if ( ! $h ) {
			return null;
		}

		$desc1     = AdapterHelpers::pick( $raw, array( 'description1', 'Description1' ) ) ?? '';
		$desc2     = AdapterHelpers::pick( $raw, array( 'description2', 'Description2' ) ) ?? '';
		$type_hint = AdapterHelpers::pick( $raw, array( 'type', 'Type', 'ItemType' ) );
		$action    = AdapterHelpers::pick( $raw, array( 'action', 'Action' ) );
		$class     = AdapterHelpers::classify_type( $type_hint, $desc1 . ' ' . $desc2 );

		return array(
			'source'                  => $this->slug(),
			'source_ref'              => $h,
			'upc'                     => AdapterHelpers::upc( AdapterHelpers::pick( $raw, array( 'upc', 'UPC', 'upcCode' ) ) ),
			'manufacturer_sku'        => AdapterHelpers::pick( $raw, array( 'manufacturerModelNo', 'ManufacturerModelNo', 'ManufacturerPartNo' ) ),
			'manufacturer'            => AdapterHelpers::pick( $raw, array( 'manufacturer', 'Manufacturer' ) ),
			'model'                   => AdapterHelpers::pick( $raw, array( 'model', 'Model', 'modelNo' ) ),
			'name'                    => trim( ( $desc1 ?: '' ) . ' ' . ( $desc2 ?: '' ) ) ?: $h,
			'description'             => trim( $desc1 . "\n" . $desc2 ),
			'item_type'               => $class['item_type'],
			'firearm_type'            => $class['firearm_type'],
			'caliber'                 => AdapterHelpers::pick( $raw, array( 'caliberGauge', 'Caliber', 'Gauge' ) ),
			'magazine_capacity'       => AdapterHelpers::int( $raw, array( 'capacity', 'Capacity', 'magCapacity' ) ),
			'barrel_length_in'        => self::parse_barrel( AdapterHelpers::pick( $raw, array( 'barrelLength', 'BarrelLength' ) ) ),
			'is_semi_auto'            => $action ? ( stripos( $action, 'semi' ) !== false ) : null,
			'has_detachable_magazine' => AdapterHelpers::bool( $raw, array( 'detachableMagazine', 'magazineDetachable' ) ),
			'msrp'                    => AdapterHelpers::money( $raw, array( 'msrp', 'MSRP', 'retailMap' ) ),
			'dealer_cost'             => AdapterHelpers::money( $raw, array( 'dealerPrice', 'DealerPrice', 'price' ) ),
			'quantity'                => AdapterHelpers::int( $raw, array( 'quantity', 'Quantity', 'qtyAvailable' ) ),
			'image_filename'          => AdapterHelpers::pick( $raw, array( 'imageName', 'ImageName', 'IMAGENAME' ) ),
			'metadata'                => array(
				'lipseys_item' => $h,
				'finish'       => AdapterHelpers::pick( $raw, array( 'finish', 'Finish' ) ),
				'sights'       => AdapterHelpers::pick( $raw, array( 'sights', 'Sights' ) ),
			),
		);
	}

	private static function parse_barrel( ?string $raw ): ?float {
		if ( ! $raw ) {
			return null;
		}
		if ( preg_match( '/([\d.]+)/', $raw, $m ) ) {
			return (float) $m[1];
		}
		return null;
	}
}
