<?php

namespace G2A\POS\Wholesalers\Lipseys;

/**
 * Pure-function mapping from a Lipsey's CSV row (with a column index) to the
 * normalized wholesaler_products schema. Extracted from LipseysCsvImporter so
 * the row-level mapping can be unit-tested without a database.
 */
final class LipseysCatalogMapper {

	/**
	 * Columns whose raw string value is preserved in the `attributes` JSON blob.
	 */
	public const ATTRIBUTE_COLUMNS = array(
		'ACTION',
		'BARRELLENGTH',
		'CAPACITY',
		'FINISH',
		'OVERALLLENGTH',
		'RECEIVER',
		'SAFETY',
		'SIGHTS',
		'STOCKFRAMEGRIPS',
		'MAGAZINE',
		'WEIGHT',
		'CHAMBER',
		'DRILLEDANDTAPPED',
		'RATEOFTWIST',
		'ADDITIONALFEATURE1',
		'ADDITIONALFEATURE2',
		'ADDITIONALFEATURE3',
		'BOUNDBOOKMANUFACTURER',
		'BOUNDBOOKMODEL',
		'BOUNDBOOKTYPE',
		'NFATHREADPATTERN',
		'NFAATTACHMENTMETHOD',
		'NFABAFFLETYPE',
		'NFADBREDUCTION',
		'SILENCEROUTSIDEDIAMETER',
		'NFAFORM3CALIBER',
		'OPTICMAGNIFICATION',
		'MAINTUBESIZE',
		'OBJECTIVESIZE',
		'OPTICADJUSTMENTS',
		'RETICLE',
		'SIGHTSTYPE',
		'CASE',
		'CHOKE',
		'DBREDUCTION',
		'FINISHTYPE',
		'FRAME',
		'GRIPTYPE',
		'HANDGUNSLIDEMATERIAL',
		'ITEMLENGTH',
		'ITEMWIDTH',
		'ITEMHEIGHT',
		'PACKAGELENGTH',
		'PACKAGEWIDTH',
		'PACKAGEHEIGHT',
		'ADJUSTABLEOBJECTIVE',
		'ILLUMINATEDRETICLE',
		'SILENCERCANBEDISASSEMBLED',
		'SILENCERCONSTRUCTIONMATERIAL',
		'SCOPECOVERINCLUDED',
		'EXCLUSIVETYPE',
		'SPECIAL',
	);

	/**
	 * Map one JSON item from the API CatalogFeed (GET
	 * /api/Integration/Items/CatalogFeed) to the same normalized
	 * wholesaler_products schema as the CSV path. The API uses camelCase
	 * field names that are case-insensitively identical to the CSV headers
	 * (itemNo ↔ ITEMNO, caliberGauge ↔ CALIBERGAUGE, …), so we uppercase the
	 * keys and reuse mapRow().
	 *
	 * @param array<string,mixed> $item Decoded API item object.
	 * @return array<string,mixed> normalized product row
	 */
	public static function mapApiItem( array $item ): array {
		$row = array();
		$idx = array();
		$i   = 0;
		foreach ( $item as $key => $value ) {
			$idx[ strtoupper( (string) $key ) ] = $i;
			if ( is_bool( $value ) ) {
				$row[ $i ] = $value ? 'TRUE' : 'FALSE';
			} elseif ( is_scalar( $value ) ) {
				$row[ $i ] = (string) $value;
			} else {
				$row[ $i ] = '';
			}
			++$i;
		}
		return self::mapRow( $row, $idx );
	}

	/**
	 * @param array<int,string> $row CSV row (0-indexed)
	 * @param array<string,int> $idx Map of UPPERCASE column name -> 0-based index
	 * @return array<string,mixed> normalized product row
	 */
	public static function mapRow( array $row, array $idx ): array {
		$val  = static function ( string $col ) use ( $row, $idx ): ?string {
			if ( ! isset( $idx[ $col ] ) ) {
				return null;
			}
			$i = $idx[ $col ];
			return isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : null;
		};
		$bool = static function ( string $col ) use ( $val ): int {
			$v = strtoupper( (string) $val( $col ) );
			return ( $v === 'TRUE' || $v === '1' || $v === 'Y' || $v === 'YES' ) ? 1 : 0;
		};
		$num  = static function ( string $col ) use ( $val ): ?float {
			$v = $val( $col );
			if ( $v === null || $v === '' ) {
				return null;
			}
			$v = preg_replace( '/[^0-9.\-]/', '', $v );
			return $v === '' ? null : (float) $v;
		};

		$itemType           = strtolower( (string) $val( 'ITEMTYPE' ) );
		$normalizedItemType = match ( true ) {
			str_contains( $itemType, 'firearm' ) => 'firearm',
			str_contains( $itemType, 'ammo' ) => 'ammunition',
			str_contains( $itemType, 'accessory' ) => 'accessory',
			str_contains( $itemType, 'optic' ) => 'optic',
			str_contains( $itemType, 'silencer' ) || str_contains( $itemType, 'nfa' ) => 'nfa',
			default => $itemType !== '' ? $itemType : null,
		};

		$attributes = array();
		foreach ( self::ATTRIBUTE_COLUMNS as $col ) {
			$v = $val( $col );
			if ( $v !== null && $v !== '' ) {
				$attributes[ strtolower( $col ) ] = $v;
			}
		}

		return array(
			'vendor_sku'        => $val( 'ITEMNO' ),
			'upc'               => $val( 'UPC' ) ?: null,
			'mfg_part'          => $val( 'MANUFACTURERMODELNO' ) ?: null,
			'manufacturer'      => $val( 'MANUFACTURER' ) ?: null,
			'model'             => $val( 'MODEL' ) ?: null,
			'family'            => $val( 'FAMILY' ) ?: null,
			'item_group'        => $val( 'ITEMGROUP' ) ?: null,
			'vendor_category'   => $val( 'TYPE' ) ?: null,
			'vendor_type'       => $val( 'TYPE' ) ?: null,
			'item_type'         => $normalizedItemType,
			'name'              => $val( 'DESCRIPTION1' ) ?: 'Unnamed',
			'description'       => $val( 'DESCRIPTION2' ) ?: null,
			'caliber'           => $val( 'CALIBERGAUGE' ) ?: null,
			'msrp'              => $num( 'MSRP' ),
			'wholesale_price'   => $num( 'PRICE' ),
			'current_price'     => $num( 'CURRENTPRICE' ),
			'map_price'         => $num( 'RETAILMAP' ),
			'stock_qty'         => (int) ( $num( 'QUANTITY' ) ?? 0 ),
			'allocated'         => $bool( 'ALLOCATED' ),
			'can_dropship'      => $bool( 'CANDROPSHIP' ),
			'on_sale'           => $bool( 'ONSALE' ),
			'ffl_required'      => $bool( 'FFLREQUIRED' ),
			'sot_required'      => $bool( 'SOTREQUIRED' ),
			'exclusive'         => $bool( 'EXCLUSIVE' ),
			'country_of_origin' => $val( 'COUNTRYOFORIGIN' ) ?: null,
			'shipping_weight'   => $num( 'SHIPPINGWEIGHT' ),
			'image_filename'    => $val( 'IMAGENAME' ) ?: null,
			'attributes'        => $attributes ? wp_json_encode( $attributes ) : null,
		);
	}
}
