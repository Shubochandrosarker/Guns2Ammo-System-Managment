<?php

namespace G2A\POS\Integrations;

use G2A\POS\Database\ExternalRefRepository;
use G2A\POS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Full WooCommerce → POS catalog scan + incremental sync.
 *
 * The POS catalog is WooCommerce's `product` post type, but the POS-side
 * lookup tables (g2a_inventory_external_refs for the Identity Graph,
 * g2a_barcodes for scanner lookups) are only populated by distributor CSV
 * imports. Products created directly in WooCommerce never land there, so
 * they're invisible to identity matching and barcode scans.
 *
 * This service walks the WooCommerce catalog in 100-product batches and
 * upserts every product's SKU / UPC (_upc or _global_unique_id) / name /
 * price / stock / categories into those tables — idempotently, keyed by
 * product id + SKU (same repositories the CSV importers use).
 *
 * Triggers:
 *   - POST /integrations/woo/scan (manual, returns counts)
 *   - save_post_product / woocommerce_update_product / woocommerce_delete_product
 *   - nightly cron: g2a_pos_woo_catalog_sync
 */
final class WooCatalogSync {

	public const CRON_HOOK = 'g2a_pos_woo_catalog_sync';
	public const SOURCE    = 'woocommerce';

	private const BATCH_SIZE = 100;

	public static function boot(): void {
		add_action( self::CRON_HOOK, array( self::class, 'cron_scan' ) );
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		add_action( 'save_post_product', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_update_product', array( self::class, 'on_product_saved' ), 20, 1 );
		add_action( 'woocommerce_delete_product', array( self::class, 'on_product_deleted' ), 20, 1 );
		add_action( 'woocommerce_trash_product', array( self::class, 'on_product_deleted' ), 20, 1 );
	}

	public static function cron_scan(): void {
		try {
			self::scan();
		} catch ( \Throwable $e ) {
			Logger::exception( 'Woo catalog scan failed', $e );
		}
	}

	/**
	 * Full catalog scan. Returns counts for the UI.
	 *
	 * @return array{scanned:int,linked:int,created_refs:int,updated_refs:int,barcodes:int,skipped:int,batches:int}
	 */
	public static function scan(): array {
		$stats = array(
			'scanned'      => 0,
			'linked'       => 0,
			'created_refs' => 0,
			'updated_refs' => 0,
			'barcodes'     => 0,
			'skipped'      => 0,
			'batches'      => 0,
		);
		if ( ! function_exists( 'wc_get_products' ) ) {
			$stats['error'] = 'woocommerce_inactive';
			return $stats;
		}

		$page = 1;
		do {
			$products = wc_get_products(
				array(
					'limit'   => self::BATCH_SIZE,
					'page'    => $page,
					'status'  => array( 'publish', 'draft', 'private' ),
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			++$stats['batches'];
			foreach ( $products as $product ) {
				++$stats['scanned'];
				$r = self::sync_product( $product );
				if ( $r === null ) {
					++$stats['skipped'];
					continue;
				}
				++$stats['linked'];
				if ( $r['created'] ) {
					++$stats['created_refs'];
				} else {
					++$stats['updated_refs'];
				}
				$stats['barcodes'] += $r['barcode'] ? 1 : 0;
			}
			++$page;
		} while ( count( $products ) === self::BATCH_SIZE );

		update_option(
			'g2a_pos_woo_catalog_last_scan',
			array(
				'at'    => current_time( 'mysql' ),
				'stats' => $stats,
			),
			false
		);
		do_action( 'g2a_pos_woo_catalog_synced', $stats );
		return $stats;
	}

	/** Incremental: a single product changed. */
	public static function on_product_saved( int $product_id ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $product_id ) ) {
			return;
		}
		try {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				self::sync_product( $product );
			}
		} catch ( \Throwable $e ) {
			Logger::exception( 'Woo product incremental sync failed', $e, array( 'product_id' => $product_id ) );
		}
	}

	/** Incremental: product removed — drop the POS-side mappings. */
	public static function on_product_deleted( int $product_id ): void {
		global $wpdb;
		try {
			$wpdb->delete(
				$wpdb->prefix . 'g2a_inventory_external_refs',
				array(
					'product_id' => $product_id,
					'source'     => self::SOURCE,
				)
			);
			$wpdb->delete( $wpdb->prefix . 'g2a_barcodes', array( 'product_id' => $product_id ) );
		} catch ( \Throwable $e ) {
			Logger::exception( 'Woo product delete sync failed', $e, array( 'product_id' => $product_id ) );
		}
	}

	/**
	 * Map one WC product into the POS lookup tables.
	 *
	 * @param object $product WC_Product.
	 * @return array{created:bool,barcode:bool}|null null when the product has no usable identifier.
	 */
	private static function sync_product( $product ): ?array {
		$product_id = (int) $product->get_id();
		if ( $product_id <= 0 ) {
			return null;
		}
		$sku = (string) $product->get_sku();
		// Read identifier meta with get_post_meta — these are WC-internal meta
		// keys and WC_Product->get_meta() raises doing_it_wrong for them.
		$upc = (string) get_post_meta( $product_id, '_upc', true );
		if ( $upc === '' ) {
			$upc = (string) get_post_meta( $product_id, '_global_unique_id', true );
		}

		$source_ref = 'wc:' . $product_id;
		$categories = array();
		if ( function_exists( 'wp_get_post_terms' ) ) {
			$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
			if ( is_array( $terms ) ) {
				$categories = array_map( 'strval', $terms );
			}
		}

		$refs    = new ExternalRefRepository();
		$created = $refs->find_product_id( self::SOURCE, $source_ref, null, null ) === null;
		$refs->upsert(
			$product_id,
			self::SOURCE,
			$source_ref,
			$upc !== '' ? $upc : null,
			$sku !== '' ? $sku : null,
			array(
				'name'       => (string) $product->get_name(),
				'price'      => (float) $product->get_price(),
				'stock'      => $product->get_stock_quantity() !== null ? (float) $product->get_stock_quantity() : null,
				'status'     => (string) $product->get_status(),
				'categories' => $categories,
			)
		);

		$barcode = false;
		if ( $upc !== '' ) {
			$barcode = self::upsert_barcode( $product_id, $upc );
		}

		return array(
			'created' => $created,
			'barcode' => $barcode,
		);
	}

	/** Idempotent g2a_barcodes upsert (same shape as Inventory\Importer). */
	private static function upsert_barcode( int $product_id, string $upc ): bool {
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
			return false;
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
		return true;
	}
}
