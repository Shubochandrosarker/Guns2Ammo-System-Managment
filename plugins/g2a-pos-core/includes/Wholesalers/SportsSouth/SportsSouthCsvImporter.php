<?php

namespace G2A\POS\Wholesalers\SportsSouth;

use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerSyncRepository;

/**
 * CSV importer for Sports South dealer catalog exports.
 *
 * Recognized columns (case-insensitive):
 *   ITEMNO|item_number|SKU, IDESC|description, IMODEL|model, MFGINO|mfg,
 *   IMFGNO|mfg_part_number, ITUPC|UPC, ICOST|wholesale_price|cost,
 *   MFPRC|MSRP|retail_price, MAPP|MAP|map_price, IQTY|qty|quantity,
 *   ICATEGORY|department, ITYPE|type, CHANSHIP|drop_ship, FFLREQ|ffl,
 *   PIC|image, WEIGHT|shipping_weight
 */
final class SportsSouthCsvImporter {

	public function import( int $wholesalerId, string $csvPath, array $options = array() ): array {
		if ( ! is_readable( $csvPath ) ) {
			return array(
				'ok'    => false,
				'error' => 'csv_not_readable',
			);
		}
		$fh     = fopen( $csvPath, 'r' );
		$header = $fh ? fgetcsv( $fh, 0, ',', '"', '\\' ) : null;
		if ( ! $header ) {
			if ( $fh ) {
				fclose( $fh );
			}
			return array(
				'ok'    => false,
				'error' => 'empty_csv',
			);
		}
		$idx = array();
		foreach ( $header as $i => $col ) {
			$idx[ strtolower( trim( (string) $col ) ) ] = $i;
		}

		$sync         = new WholesalerSyncRepository();
		$productRepo  = new WholesalerProductRepository();
		$categoryRepo = new WholesalerCategoryRepository();
		$mapRepo      = new MapRuleRepository();
		$runId        = $sync->start( $wholesalerId, 'catalog_csv', 'csv', basename( $csvPath ) );

		$stats = array(
			'rows_total'         => 0,
			'rows_created'       => 0,
			'rows_updated'       => 0,
			'rows_skipped'       => 0,
			'rows_failed'        => 0,
			'map_rules_upserted' => 0,
		);
		$cats  = array();

		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
			++$stats['rows_total'];
			try {
				$m = self::mapRow( $row, $idx );
				if ( ! $m || ! $m['vendor_sku'] ) {
					++$stats['rows_skipped'];
					continue;
				}
				$m['wholesaler_id'] = $wholesalerId;
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
							'sku'           => $m['vendor_sku'],
							'upc'           => $m['upc'] ?? null,
							'manufacturer'  => $m['manufacturer'] ?? null,
							'map_price'     => (float) $m['map_price'],
							'wholesaler_id' => $wholesalerId,
							'source'        => 'sports_south_csv',
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

	public static function mapRow( array $row, array $idx ): ?array {
		$v    = static function ( array $cols ) use ( $row, $idx ): ?string {
			foreach ( $cols as $c ) {
				$k = strtolower( $c );
				if ( isset( $idx[ $k ] ) ) {
					$val = $row[ $idx[ $k ] ] ?? '';
					$val = trim( (string) $val );
					if ( $val !== '' ) {
						return $val;
					}
				}
			}
			return null;
		};
		$num  = static function ( array $cols ) use ( $v ): ?float {
			$raw = $v( $cols );
			if ( $raw === null ) {
				return null;
			}
			$clean = preg_replace( '/[^0-9.\-]/', '', $raw );
			return $clean === '' ? null : (float) $clean;
		};
		$bool = static fn( array $cols ) => in_array( strtoupper( (string) $v( $cols ) ), array( 'Y', 'YES', 'TRUE', '1' ), true ) ? 1 : 0;

		$sku = $v( array( 'itemno', 'item_number', 'sku' ) );
		if ( ! $sku ) {
			return null;
		}

		return array(
			'vendor_sku'      => $sku,
			'upc'             => $v( array( 'itupc', 'upc' ) ),
			'mfg_part'        => $v( array( 'imfgno', 'mfg_part_number' ) ),
			'manufacturer'    => $v( array( 'mfgino', 'manufacturer', 'mfg' ) ),
			'model'           => $v( array( 'imodel', 'model' ) ),
			'vendor_category' => $v( array( 'icategory', 'category', 'department' ) ),
			'vendor_type'     => $v( array( 'itype', 'type' ) ),
			'item_type'       => self::classifyItemType( $v( array( 'itype', 'type' ) ), $v( array( 'idesc', 'description' ) ) ?: '' ),
			'name'            => $v( array( 'idesc', 'description' ) ) ?: $sku,
			'description'     => $v( array( 'idesc2', 'long_description', 'description' ) ),
			'msrp'            => $num( array( 'mfprc', 'msrp', 'retail_price' ) ),
			'wholesale_price' => $num( array( 'icost', 'wholesale_price', 'cost' ) ),
			'current_price'   => $num( array( 'icost', 'wholesale_price', 'cost' ) ),
			'map_price'       => $num( array( 'mapp', 'map', 'map_price' ) ),
			'stock_qty'       => (int) ( $num( array( 'iqty', 'qty', 'quantity' ) ) ?? 0 ),
			'can_dropship'    => $bool( array( 'chanship', 'drop_ship', 'dropship' ) ),
			'ffl_required'    => $bool( array( 'fflreq', 'ffl_required', 'ffl' ) ),
			'shipping_weight' => $num( array( 'weight', 'shipping_weight' ) ),
			'image_filename'  => $v( array( 'pic', 'image', 'image_filename' ) ),
		);
	}

	private static function classifyItemType( ?string $hint, string $desc ): ?string {
		$h = strtolower( ( $hint ?? '' ) . ' ' . $desc );
		return match ( true ) {
			str_contains( $h, 'pistol' ) || str_contains( $h, 'rifle' ) || str_contains( $h, 'shotgun' ) || str_contains( $h, 'firearm' ) => 'firearm',
			str_contains( $h, 'ammo' ) || str_contains( $h, 'ammunition' ) => 'ammunition',
			str_contains( $h, 'optic' ) || str_contains( $h, 'scope' ) => 'optic',
			str_contains( $h, 'silencer' ) || str_contains( $h, 'nfa' ) => 'nfa',
			default => 'accessory',
		};
	}
}
