<?php

namespace G2A\POS\Inventory;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\ExternalRefRepository;
use G2A\POS\Inventory\Distributors\Adapter;
use G2A\POS\Inventory\Distributors\AdapterRegistry;
use G2A\POS\Support\Logger;
use G2A\POS\Wholesalers\Media\LipseysImageUrls;
use G2A\POS\Wholesalers\Media\VendorImageMirror;

/**
 * Normalizes distributor / UPC CSV rows into WooCommerce products and
 * tracks the dedupe key in g2a_inventory_external_refs.
 *
 * Compliance fields (firearm_type, caliber, magazine_capacity, on_state_roster,
 * is_assault_weapon, etc.) flow through when the source provides them.
 * Rows that are firearms but missing critical fields are returned in the
 * `needs_compliance` bucket so the manager can finish them by hand.
 */
final class Importer {

	public function preview( string $csv_path, ?string $adapter_slug = null, int $limit = 50 ): array {
		$headers = CsvReader::peek_headers( $csv_path );
		$adapter = $adapter_slug ? AdapterRegistry::get( $adapter_slug ) : AdapterRegistry::detect( $headers );
		if ( ! $adapter ) {
			return array(
				'error'   => 'Could not detect a known distributor format. Pick one explicitly.',
				'headers' => $headers,
			);
		}
		$sample = array();
		$count  = 0;
		foreach ( CsvReader::rows( $csv_path ) as $raw ) {
			if ( $count >= $limit ) {
				break;
			}
			$norm = $adapter->map_row( $raw );
			if ( $norm ) {
				$sample[] = $norm;
				++$count;
			}
		}
		return array(
			'adapter'      => $adapter->slug(),
			'label'        => $adapter->label(),
			'headers'      => $headers,
			'sample'       => $sample,
			'sample_count' => $count,
		);
	}

	public function import( string $csv_path, ?string $adapter_slug = null, array $opts = array() ): array {
		$headers = CsvReader::peek_headers( $csv_path );
		$adapter = $adapter_slug ? AdapterRegistry::get( $adapter_slug ) : AdapterRegistry::detect( $headers );
		if ( ! $adapter ) {
			return array( 'error' => 'Could not detect distributor format.' );
		}
		return $this->import_from_adapter( $adapter, CsvReader::rows( $csv_path ), $opts );
	}

	public function import_from_adapter( Adapter $adapter, \Generator $rows, array $opts = array() ): array {
		$auto_publish = ! empty( $opts['auto_publish'] );
		$markup       = isset( $opts['markup_pct'] ) ? (float) $opts['markup_pct'] : 0;
		$dry_run      = ! empty( $opts['dry_run'] );

		$stats            = array(
			'rows_seen'    => 0,
			'rows_created' => 0,
			'rows_updated' => 0,
			'rows_skipped' => 0,
			'errors_count' => 0,
		);
		$needs_compliance = array();
		$errors           = array();

		$refs  = new ExternalRefRepository();
		$audit = new AuditLogRepository();

		foreach ( $rows as $raw ) {
			++$stats['rows_seen'];
			try {
				$norm = $adapter->map_row( $raw );
				if ( ! $norm ) {
					++$stats['rows_skipped'];
					continue;
				}
				$upc        = $norm['upc'] ?? null;
				$mfg_sku    = $norm['manufacturer_sku'] ?? null;
				$source_ref = $norm['source_ref'] ?? null;
				$source     = $norm['source'] ?? $adapter->slug();

				if ( ! $upc && ! $mfg_sku && ! $source_ref ) {
					++$stats['rows_skipped'];
					continue;
				}

				if ( ( $norm['item_type'] ?? null ) === 'firearm' ) {
					$missing = array();
					foreach ( array( 'firearm_type', 'caliber', 'manufacturer', 'model' ) as $req ) {
						if ( empty( $norm[ $req ] ) ) {
							$missing[] = $req;
						}
					}
					if ( $missing ) {
						$needs_compliance[] = array(
							'source_ref' => $source_ref,
							'upc'        => $upc,
							'missing'    => $missing,
							'row'        => $norm,
						);
					}
				}

				if ( $markup > 0 && ! empty( $norm['dealer_cost'] ) && empty( $norm['msrp'] ) ) {
					$norm['msrp'] = round( (float) $norm['dealer_cost'] * ( 1 + $markup / 100 ), 2 );
				}

				if ( $dry_run ) {
					++$stats['rows_updated'];
					continue;
				}

				$existing_pid = $refs->find_product_id( $source, $source_ref, $upc, $mfg_sku );
				$product_id   = $this->upsert_product( $norm, $existing_pid, $auto_publish );

				if ( ! $product_id ) {
					++$stats['rows_skipped'];
					continue;
				}

				$refs->upsert( $product_id, $source, $source_ref, $upc, $mfg_sku, $norm['metadata'] ?? array() );

				$this->mirror_image_if_available( $product_id, $norm, $mfg_sku ?: $source_ref );

				if ( $existing_pid ) {
					++$stats['rows_updated'];
				} else {
					++$stats['rows_created'];
				}
			} catch ( \Throwable $e ) {
				++$stats['errors_count'];
				$errors[] = array(
					'ref'     => $raw['itemNo'] ?? $raw['ITEMNO'] ?? null,
					'message' => $e->getMessage(),
				);
				if ( count( $errors ) >= 50 ) {
					break;
				}
			}
		}

		if ( ! $dry_run ) {
			$audit->add( 'inventory.import', 'distributor', $adapter->slug(), null, $stats + array( 'adapter' => $adapter->slug() ) );
		}

		return array(
			'stats'            => $stats,
			'errors'           => $errors,
			'needs_compliance' => $needs_compliance,
			'adapter'          => $adapter->slug(),
		);
	}

	/**
	 * Create or update a WooCommerce product (when WC is active), otherwise
	 * persist into a lightweight g2a_products meta-table substitute via
	 * postmeta on a custom post type. This keeps the plugin functional even
	 * if WooCommerce is uninstalled.
	 */
	private function upsert_product( array $norm, ?int $existing_pid, bool $auto_publish ): ?int {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return null;
		}

		$status = $auto_publish ? 'publish' : 'draft';
		$title  = (string) ( $norm['name'] ?? '' );
		$cost   = isset( $norm['dealer_cost'] ) ? (float) $norm['dealer_cost'] : null;
		$price  = isset( $norm['msrp'] ) ? (float) $norm['msrp'] : null;
		$sku    = (string) ( $norm['manufacturer_sku'] ?? $norm['source_ref'] ?? '' );

		$post_arr = array(
			'ID'           => $existing_pid ?: 0,
			'post_title'   => $title ?: 'Unnamed product',
			'post_content' => (string) ( $norm['description'] ?? '' ),
			'post_status'  => $existing_pid ? null : $status,
			'post_type'    => function_exists( 'wc_get_product' ) ? 'product' : 'g2a_product',
		);
		$post_arr = array_filter( $post_arr, static fn( $v ) => $v !== null );

		$product_id = $existing_pid
			? wp_update_post( array_filter( $post_arr ) + array( 'ID' => $existing_pid ), true )
			: wp_insert_post( $post_arr, true );

		if ( is_wp_error( $product_id ) || ! $product_id ) {
			return null;
		}

		$meta = array(
			'_sku'                   => $sku,
			'_regular_price'         => $price !== null ? (string) $price : '',
			'_price'                 => $price !== null ? (string) $price : '',
			'_wc_cog_cost'           => $cost !== null ? (string) $cost : '',
			'_g2a_item_type'         => (string) ( $norm['item_type'] ?? 'accessory' ),
			'_g2a_firearm_type'      => (string) ( $norm['firearm_type'] ?? '' ),
			'_g2a_caliber'           => (string) ( $norm['caliber'] ?? '' ),
			'_g2a_magazine_capacity' => $norm['magazine_capacity'] !== null ? (string) $norm['magazine_capacity'] : '',
			'_g2a_manufacturer'      => (string) ( $norm['manufacturer'] ?? '' ),
			'_g2a_model'             => (string) ( $norm['model'] ?? '' ),
			'_g2a_upc'               => (string) ( $norm['upc'] ?? '' ),
			'_g2a_source'            => (string) ( $norm['source'] ?? '' ),
			'_g2a_source_ref'        => (string) ( $norm['source_ref'] ?? '' ),
			'_g2a_distributor_qty'   => $norm['quantity'] !== null ? (string) $norm['quantity'] : '',
			'_stock_status'          => ( $norm['quantity'] ?? 0 ) > 0 ? 'instock' : 'outofstock',
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( (int) $product_id, $k, $v );
		}

		if ( ! empty( $norm['upc'] ) ) {
			$this->upsert_barcode( (int) $product_id, (string) $norm['upc'] );
		}

		return (int) $product_id;
	}

	/**
	 * Mirrors a distributor's product image into the media library and sets
	 * it as the featured image, when the adapter surfaced one and the
	 * product doesn't already have one. Only Lipsey's CDN mapping exists
	 * today; other distributors are silently skipped until their own
	 * image URL builder is added. Never throws — a mirror failure (bad
	 * filename, CDN timeout) must not fail the whole row import.
	 */
	private function mirror_image_if_available( int $product_id, array $norm, ?string $vendor_sku ): void {
		if ( ! function_exists( 'has_post_thumbnail' ) || has_post_thumbnail( $product_id ) ) {
			return;
		}

		$image_filename = $norm['image_filename'] ?? null;
		if ( ! $image_filename ) {
			return;
		}

		if ( ( $norm['source'] ?? null ) !== 'lipseys' ) {
			return;
		}

		$cdn_url = LipseysImageUrls::cdnUrl( (string) $image_filename );
		if ( ! $cdn_url ) {
			return;
		}

		try {
			VendorImageMirror::mirror(
				$cdn_url,
				array(
					'wc_product_id' => $product_id,
					'vendor_sku'    => $vendor_sku,
					'set_featured'  => true,
				)
			);
		} catch ( \Throwable $e ) {
			Logger::exception( 'Vendor image mirror failed', $e, array( 'product_id' => $product_id, 'vendor_sku' => $vendor_sku ) );
		}
	}

	private function upsert_barcode( int $product_id, string $upc ): void {
		global $wpdb;
		$t        = $wpdb->prefix . 'g2a_barcodes';
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE barcode_value = %s LIMIT 1", $upc ) );
		$now      = current_time( 'mysql' );
		if ( $existing ) {
			$wpdb->update(
				$t,
				array(
					'product_id' => $product_id,
					'updated_at' => $now,
				),
				array( 'id' => (int) $existing )
			);
			return;
		}
		$wpdb->insert(
			$t,
			array(
				'product_id'    => $product_id,
				'barcode_value' => $upc,
				'barcode_type'  => strlen( $upc ) === 13 ? 'ean13' : ( strlen( $upc ) === 12 ? 'upc_a' : 'code128' ),
				'is_primary'    => 1,
				'created_at'    => $now,
				'updated_at'    => $now,
			)
		);
	}
}
