<?php

namespace G2A\POS\Wholesalers\Rsr;

use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerSyncRepository;

/**
 * Importer for RSR's nightly inventory feed (rsrinventory-new.txt).
 *
 * Format: pipe-delimited, NO header row, 76+ fields per record. Field
 * positions are documented in RSR's "Inventory File Layout" PDF. Only the
 * fields we actually use are referenced here; everything else is ignored.
 *
 *   0  RSR stock number
 *   1  UPC code (12 digits)
 *   2  Product description (short)
 *   3  Department number
 *   4  Manufacturer ID
 *   5  Retail price (MSRP)
 *   6  Dealer price (cost)
 *   7  Product weight (lbs)
 *   8  Inventory quantity
 *   9  Model number
 *  10  Mfg part number / NIC
 *  11  Allocated/closeout flag
 *  12  Full description
 *  13  Image filename (high-res)
 *  ...
 *  46  MAP retail (when present)
 *  73  Drop-ship eligible (Y/N)
 *  75  Length / dimensions
 *
 * Field 0 is the SKU; if RSR ever swaps field ordering this class can be
 * adapted by adjusting the constants below.
 */
final class RsrFeedImporter {

	private const FIELD_SKU        = 0;
	private const FIELD_UPC        = 1;
	private const FIELD_SHORT_DESC = 2;
	private const FIELD_DEPARTMENT = 3;
	private const FIELD_MFG_ID     = 4;
	private const FIELD_MSRP       = 5;
	private const FIELD_COST       = 6;
	private const FIELD_WEIGHT     = 7;
	private const FIELD_QTY        = 8;
	private const FIELD_MODEL      = 9;
	private const FIELD_MFG_PART   = 10;
	private const FIELD_LONG_DESC  = 12;
	private const FIELD_IMAGE      = 13;
	private const FIELD_MAP        = 46;
	private const FIELD_DROPSHIP   = 73;

	public function import( int $wholesalerId, string $path, array $options = array() ): array {
		if ( ! is_readable( $path ) ) {
			return array(
				'ok'    => false,
				'error' => 'feed_not_readable',
			);
		}
		$fh = fopen( $path, 'r' );
		if ( ! $fh ) {
			return array(
				'ok'    => false,
				'error' => 'fopen_failed',
			);
		}

		$sync         = new WholesalerSyncRepository();
		$productRepo  = new WholesalerProductRepository();
		$categoryRepo = new WholesalerCategoryRepository();
		$mapRepo      = new MapRuleRepository();
		$runId        = $sync->start( $wholesalerId, 'catalog_csv', 'rsr_pipe_feed', basename( $path ) );

		$stats = array(
			'rows_total'         => 0,
			'rows_created'       => 0,
			'rows_updated'       => 0,
			'rows_skipped'       => 0,
			'rows_failed'        => 0,
			'map_rules_upserted' => 0,
		);
		$cats  = array();

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( $line === '' ) {
				continue;
			}
			++$stats['rows_total'];
			try {
				$cols = explode( '|', $line );
				$sku  = trim( $cols[ self::FIELD_SKU ] ?? '' );
				if ( $sku === '' ) {
					++$stats['rows_skipped'];
					continue;
				}
				$m                  = self::mapRow( $cols );
				$m['wholesaler_id'] = $wholesalerId;
				$m['vendor_sku']    = $sku;
				$m['last_seen_at']  = current_time( 'mysql' );
				$action             = $productRepo->upsert( $m );
				if ( $action === 'created' ) {
					++$stats['rows_created'];
				} elseif ( $action === 'updated' ) {
					++$stats['rows_updated'];
				} else {
					++$stats['rows_skipped'];
				}

				if ( ! empty( $m['vendor_category'] ) ) {
					$cats[ $m['vendor_category'] ] = ( $cats[ $m['vendor_category'] ] ?? 0 ) + 1;
				}
				if ( ! empty( $m['map_price'] ) && (float) $m['map_price'] > 0 ) {
					$mapRepo->upsertFromVendor(
						array(
							'sku'           => $sku,
							'upc'           => $m['upc'] ?? null,
							'manufacturer'  => $m['manufacturer'] ?? null,
							'map_price'     => (float) $m['map_price'],
							'wholesaler_id' => $wholesalerId,
							'source'        => 'rsr_feed',
						)
					);
					++$stats['map_rules_upserted'];
				}
			} catch ( \Throwable $e ) {
				++$stats['rows_failed'];
			}
		}
		fclose( $fh );
		foreach ( $cats as $name => $count ) {
			$categoryRepo->touch( $wholesalerId, (string) $name, null, $count );
		}
		$sync->finish( $runId, 'completed', $stats );
		return array(
			'ok'          => true,
			'sync_run_id' => $runId,
			'stats'       => $stats,
		);
	}

	public static function mapRow( array $cols ): array {
		$col       = static fn( int $i ): ?string => isset( $cols[ $i ] ) ? trim( (string) $cols[ $i ] ) : null;
		$num       = static function ( int $i ) use ( $col ): ?float {
			$v = $col( $i );
			if ( $v === null || $v === '' ) {
				return null;
			}
			$clean = preg_replace( '/[^0-9.\-]/', '', $v );
			return $clean === '' ? null : (float) $clean;
		};
		$shortDesc = $col( self::FIELD_SHORT_DESC ) ?? '';

		return array(
			'upc'             => $col( self::FIELD_UPC ),
			'mfg_part'        => $col( self::FIELD_MFG_PART ),
			'manufacturer'    => $col( self::FIELD_MFG_ID ),
			'model'           => $col( self::FIELD_MODEL ),
			'vendor_category' => $col( self::FIELD_DEPARTMENT ),
			'vendor_type'     => $col( self::FIELD_DEPARTMENT ),
			'item_type'       => self::classifyByDepartment( $col( self::FIELD_DEPARTMENT ), $shortDesc ),
			'name'            => $shortDesc ?: ( $col( self::FIELD_LONG_DESC ) ?: '(no description)' ),
			'description'     => $col( self::FIELD_LONG_DESC ),
			'msrp'            => $num( self::FIELD_MSRP ),
			'wholesale_price' => $num( self::FIELD_COST ),
			'current_price'   => $num( self::FIELD_COST ),
			'map_price'       => $num( self::FIELD_MAP ),
			'stock_qty'       => (int) ( $num( self::FIELD_QTY ) ?? 0 ),
			'can_dropship'    => strtoupper( (string) $col( self::FIELD_DROPSHIP ) ) === 'Y' ? 1 : 0,
			'shipping_weight' => $num( self::FIELD_WEIGHT ),
			'image_filename'  => $col( self::FIELD_IMAGE ),
		);
	}

	private static function classifyByDepartment( ?string $dept, string $shortDesc ): ?string {
		// RSR departments — partial mapping (full table is 30+ values):
		//   1=Handguns, 2=Used Handguns, 3=Used Long Guns, 5=Long Guns,
		//  18=Ammunition, 30=Optics, 9=Magazines, 11=Holsters, 26=Cleaning
		$d = (int) ( $dept ?? 0 );
		if ( in_array( $d, array( 1, 2, 3, 5 ), true ) ) {
			return 'firearm';
		}
		if ( $d === 18 ) {
			return 'ammunition';
		}
		if ( $d === 30 ) {
			return 'optic';
		}
		$h = strtolower( $shortDesc );
		if ( str_contains( $h, 'silencer' ) || str_contains( $h, 'suppressor' ) ) {
			return 'nfa';
		}
		return 'accessory';
	}
}
