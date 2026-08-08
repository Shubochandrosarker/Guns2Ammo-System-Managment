<?php

namespace G2A\POS\Wholesalers\Lipseys;

use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerSyncRepository;
use G2A\POS\Database\MapRuleRepository;

final class LipseysCsvImporter {

	private const BATCH_FLUSH = 500;

	public function import( int $wholesalerId, string $filePath, array $options = array() ): array {
		if ( ! is_readable( $filePath ) ) {
			return array(
				'ok'    => false,
				'error' => 'file_not_readable',
				'path'  => $filePath,
			);
		}

		$syncRepo     = new WholesalerSyncRepository();
		$productRepo  = new WholesalerProductRepository();
		$categoryRepo = new WholesalerCategoryRepository();
		$mapRepo      = new MapRuleRepository();

		$fileLabel     = (string) ( $options['file_label'] ?? basename( $filePath ) );
		$writeMapRules = (bool) ( $options['write_map_rules'] ?? true );
		$syncRunId     = $syncRepo->start( $wholesalerId, 'catalog_csv', 'csv', $fileLabel );

		$stats = array(
			'rows_total'         => 0,
			'rows_created'       => 0,
			'rows_updated'       => 0,
			'rows_skipped'       => 0,
			'rows_failed'        => 0,
			'map_rules_upserted' => 0,
			'categories_seen'    => array(),
		);

		$fh = fopen( $filePath, 'r' );
		if ( ! $fh ) {
			$syncRepo->finish( $syncRunId, 'failed', $stats, 'fopen_failed' );
			return array(
				'ok'          => false,
				'error'       => 'fopen_failed',
				'sync_run_id' => $syncRunId,
			);
		}

		$header = fgetcsv( $fh, 0, ',', '"', '\\' );
		if ( ! $header ) {
			fclose( $fh );
			$syncRepo->finish( $syncRunId, 'failed', $stats, 'empty_header' );
			return array(
				'ok'          => false,
				'error'       => 'empty_header',
				'sync_run_id' => $syncRunId,
			);
		}

		$idx = array();
		foreach ( $header as $i => $col ) {
			$idx[ strtoupper( trim( (string) $col ) ) ] = $i;
		}

		$required = array( 'ITEMNO', 'DESCRIPTION1', 'UPC', 'MANUFACTURER' );
		foreach ( $required as $col ) {
			if ( ! array_key_exists( $col, $idx ) ) {
				fclose( $fh );
				$syncRepo->finish( $syncRunId, 'failed', $stats, "missing_column:$col" );
				return array(
					'ok'          => false,
					'error'       => "missing_column:$col",
					'sync_run_id' => $syncRunId,
				);
			}
		}

		$now            = current_time( 'mysql' );
		$categoryCounts = array();

		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
			++$stats['rows_total'];
			try {
				$sku = trim( (string) ( $row[ $idx['ITEMNO'] ] ?? '' ) );
				if ( $sku === '' ) {
					++$stats['rows_skipped'];
					continue;
				}

				$mapped                  = LipseysCatalogMapper::mapRow( $row, $idx );
				$mapped['wholesaler_id'] = $wholesalerId;
				$mapped['vendor_sku']    = $sku;
				$mapped['last_seen_at']  = $now;

				$result = $productRepo->upsert( $mapped );
				if ( $result === 'created' ) {
					++$stats['rows_created'];
				} elseif ( $result === 'updated' ) {
					++$stats['rows_updated'];
				} else {
					++$stats['rows_skipped'];
				}

				$category = (string) ( $mapped['vendor_category'] ?? '' );
				if ( $category !== '' ) {
					$categoryCounts[ $category ] = ( $categoryCounts[ $category ] ?? 0 ) + 1;
					if ( ! isset( $stats['categories_seen'][ $category ] ) ) {
						$stats['categories_seen'][ $category ] = $mapped['item_type'] ?? null;
					}
				}

				if ( $writeMapRules && ! empty( $mapped['map_price'] ) && (float) $mapped['map_price'] > 0 ) {
					$mapRepo->upsertFromVendor(
						array(
							'sku'           => $sku,
							'upc'           => $mapped['upc'] ?? null,
							'manufacturer'  => $mapped['manufacturer'] ?? null,
							'map_price'     => (float) $mapped['map_price'],
							'wholesaler_id' => $wholesalerId,
							'source'        => 'lipseys_csv',
						)
					);
					++$stats['map_rules_upserted'];
				}
			} catch ( \Throwable $e ) {
				++$stats['rows_failed'];
				if ( $stats['rows_failed'] <= 20 ) {
					$stats['errors'][] = array(
						'sku'   => $sku ?? null,
						'error' => $e->getMessage(),
					);
				}
			}

			if ( $stats['rows_total'] % self::BATCH_FLUSH === 0 ) {
				$syncRepo->update_progress( $syncRunId, $stats );
			}
		}
		fclose( $fh );

		foreach ( $categoryCounts as $category => $count ) {
			$categoryRepo->touch( $wholesalerId, $category, $stats['categories_seen'][ $category ] ?? null, $count );
		}
		unset( $stats['categories_seen'] );

		$syncRepo->finish( $syncRunId, 'completed', $stats );

		return array(
			'ok'          => true,
			'sync_run_id' => $syncRunId,
			'stats'       => $stats,
		);
	}
}
