<?php

namespace G2A\POS\Wholesalers\Davidsons;

use G2A\POS\Database\MapRuleRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerSyncRepository;

/**
 * CSV importer for Davidson's "Product Catalog" download.
 *
 * Column shape (case-insensitive; common variants accepted):
 *   item_number, manufacturer_part_number, manufacturer, model, upc,
 *   short_description, long_description, type|category, caliber|gauge,
 *   capacity, finish, retail_price (MSRP), wholesale_price (cost),
 *   map_price (sometimes "min_advertised_price"), quantity (on-hand),
 *   image_url, drop_ship (Y/N), ffl_required (Y/N), country_of_origin
 */
final class DavidsonsCsvImporter {

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
							'source'        => 'davidsons_csv',
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
			$categoryRepo->touch( $wholesalerId, $name, null, $count );
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
		$bool = static fn( array $cols ) => in_array( strtoupper( (string) $v( $cols ) ), array( 'Y', 'YES', 'TRUE', '1' ), true ) ? 1 : 0;
		$num  = static function ( array $cols ) use ( $v ): ?float {
			$raw = $v( $cols );
			if ( $raw === null ) {
				return null;
			}
			$clean = preg_replace( '/[^0-9.\-]/', '', $raw );
			return $clean === '' ? null : (float) $clean;
		};

		$sku = $v( array( 'item_number', 'itemnumber', 'sku' ) );
		if ( ! $sku ) {
			return null;
		}

		return array(
			'vendor_sku'        => $sku,
			'upc'               => $v( array( 'upc', 'upccode' ) ),
			'mfg_part'          => $v( array( 'manufacturer_part_number', 'mfgpartnumber', 'mfg_part_number' ) ),
			'manufacturer'      => $v( array( 'manufacturer', 'mfg' ) ),
			'model'             => $v( array( 'model' ) ),
			'family'            => $v( array( 'family' ) ),
			'vendor_category'   => $v( array( 'type', 'category', 'class' ) ),
			'vendor_type'       => $v( array( 'type', 'category' ) ),
			'item_type'         => self::classifyItemType( $v( array( 'type', 'category' ) ), $v( array( 'short_description', 'long_description' ) ) ?: '' ),
			'name'              => $v( array( 'short_description', 'product_name', 'long_description' ) ) ?: $sku,
			'description'       => $v( array( 'long_description', 'description' ) ),
			'caliber'           => $v( array( 'caliber', 'gauge' ) ),
			'msrp'              => $num( array( 'retail_price', 'msrp' ) ),
			'wholesale_price'   => $num( array( 'wholesale_price', 'dealer_cost', 'price' ) ),
			'current_price'     => $num( array( 'current_price', 'dealer_price', 'wholesale_price' ) ),
			'map_price'         => $num( array( 'map_price', 'min_advertised_price', 'map' ) ),
			'stock_qty'         => (int) ( $num( array( 'quantity', 'qty_on_hand', 'onhand' ) ) ?? 0 ),
			'can_dropship'      => $bool( array( 'drop_ship', 'dropship' ) ),
			'ffl_required'      => $bool( array( 'ffl_required', 'ffl' ) ),
			'country_of_origin' => $v( array( 'country_of_origin', 'coo' ) ),
			'image_filename'    => $v( array( 'image_url', 'image' ) ),
		);
	}

	private static function classifyItemType( ?string $hint, string $desc ): ?string {
		$h = strtolower( ( $hint ?? '' ) . ' ' . $desc );
		return match ( true ) {
			str_contains( $h, 'pistol' ) || str_contains( $h, 'rifle' ) || str_contains( $h, 'shotgun' ) || str_contains( $h, 'firearm' ) => 'firearm',
			str_contains( $h, 'ammo' ) || str_contains( $h, 'ammunition' ) || str_contains( $h, 'cartridge' ) => 'ammunition',
			str_contains( $h, 'optic' ) || str_contains( $h, 'scope' ) || str_contains( $h, 'sight' ) => 'optic',
			str_contains( $h, 'silencer' ) || str_contains( $h, 'nfa' ) => 'nfa',
			$hint => 'accessory',
			default => null,
		};
	}
}
