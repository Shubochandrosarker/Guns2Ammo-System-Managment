<?php

namespace G2A\POS\Integrations;

use G2A\POS\Catalog\IdentityService;
use G2A\POS\Database\BarcodeRepository;
use G2A\POS\Database\InventoryRepository;
use G2A\POS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Batched WooCommerce product backfill + ongoing sync.
 *
 * Walks every published WC product (50 per batch, resumable via an option
 * cursor) and upserts it into the POS layers that previously only filled in
 * from order hooks:
 *   - Identity graph (g2a_item_identities / g2a_item_identity_links) via
 *     IdentityService::resolve_or_create with source_type=woo_product.
 *   - Barcode index (g2a_barcodes) — UPC (get_global_unique_id) and SKU.
 *   - Inventory ledger (g2a_inventory_logs) — a stock snapshot row so the
 *     Inventory view has a record per stocked product.
 *
 * Ongoing sync: save_post_product + woocommerce_update_product re-run the
 * single-product upsert; a daily cron restarts a full scan to keep things
 * fresh.
 */
final class WooProductScanner {

	public const STATE_OPTION  = 'g2a_pos_woo_scan_state';
	public const BATCH_SIZE    = 50;
	public const CRON_HOOK     = 'g2a_pos_woo_scan_daily';
	public const CONTINUE_HOOK = 'g2a_pos_woo_scan_continue';

	/** Re-entrancy guard so our own writes never recurse through save_post. */
	private static bool $syncing = false;

	public static function boot(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		add_action( 'woocommerce_update_product', array( self::class, 'handle_product_update' ), 20, 1 );
		add_action( 'save_post_product', array( self::class, 'handle_save_post' ), 20, 2 );
	}

	public static function handle_product_update( $product_id ): void {
		self::safe_sync_id( (int) $product_id );
	}

	public static function handle_save_post( int $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post || $post->post_status !== 'publish' ) {
			return;
		}
		self::safe_sync_id( $post_id );
	}

	private static function safe_sync_id( int $product_id ): void {
		if ( self::$syncing || $product_id <= 0 ) {
			return;
		}
		self::$syncing = true;
		try {
			self::sync_product_id( $product_id );
		} catch ( \Throwable $e ) {
			Logger::exception( 'Woo product sync failed', $e, array( 'product_id' => $product_id ) );
		} finally {
			self::$syncing = false;
		}
	}

	// ---- Scan state -------------------------------------------------------

	public static function default_state(): array {
		return array(
			'status'      => 'idle', // idle | running | complete
			'cursor'      => 0,
			'scanned'     => 0,
			'created'     => 0,
			'matched'     => 0,
			'failed'      => 0,
			'started_at'  => null,
			'finished_at' => null,
		);
	}

	public static function state(): array {
		$state = get_option( self::STATE_OPTION );
		return is_array( $state ) ? array_merge( self::default_state(), $state ) : self::default_state();
	}

	public static function status(): array {
		$status = self::state();
		$total  = 0;
		if ( function_exists( 'wp_count_posts' ) ) {
			$counts = wp_count_posts( 'product' );
			$total  = (int) ( $counts->publish ?? 0 );
		}
		$status['total_published'] = $total;
		$status['woocommerce']     = function_exists( 'wc_get_product' );
		return $status;
	}

	/** Reset the cursor so the next batch starts from the beginning. */
	public static function start(): array {
		$state               = self::default_state();
		$state['status']     = 'running';
		$state['started_at'] = current_time( 'mysql' );
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}

	/**
	 * Process one batch of published products after the cursor. Resumable —
	 * each call advances the cursor and persists progress.
	 */
	public static function scan_batch( int $batch = self::BATCH_SIZE ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array(
				'ok'    => false,
				'error' => 'woocommerce_inactive',
			);
		}
		global $wpdb;
		$batch = max( 1, min( 200, $batch ) );
		$state = self::state();
		if ( $state['status'] === 'complete' ) {
			return array(
				'ok'    => true,
				'state' => $state,
			);
		}
		if ( $state['status'] === 'idle' ) {
			$state               = self::default_state();
			$state['status']     = 'running';
			$state['started_at'] = current_time( 'mysql' );
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = 'product' AND post_status = 'publish' AND ID > %d
                 ORDER BY ID ASC LIMIT %d",
				(int) $state['cursor'],
				$batch
			)
		) ?: array();

		foreach ( $ids as $id ) {
			$state['cursor'] = (int) $id;
			try {
				$res = self::sync_product_id( (int) $id );
				if ( $res ) {
					++$state['scanned'];
					if ( ( $res['decision'] ?? '' ) === 'created' ) {
						++$state['created'];
					} elseif ( ( $res['decision'] ?? '' ) === 'matched' ) {
						++$state['matched'];
					}
				}
			} catch ( \Throwable $e ) {
				++$state['failed'];
				Logger::exception( 'Woo scan batch item failed', $e, array( 'product_id' => (int) $id ) );
			}
		}

		if ( count( $ids ) < $batch ) {
			$state['status']      = 'complete';
			$state['finished_at'] = current_time( 'mysql' );
		}
		update_option( self::STATE_OPTION, $state, false );

		return array(
			'ok'        => true,
			'processed' => count( $ids ),
			'state'     => $state,
		);
	}

	/**
	 * Run batches until complete or the time budget runs out. If the budget
	 * runs out, a single cron event is scheduled to continue shortly.
	 */
	public static function run_until_done( int $time_budget_seconds = 20 ): array {
		$deadline = time() + max( 5, $time_budget_seconds );
		$last     = array( 'ok' => true );
		do {
			$last  = self::scan_batch();
			$state = $last['state'] ?? self::state();
			if ( empty( $last['ok'] ) || ( $state['status'] ?? '' ) === 'complete' ) {
				return $last;
			}
		} while ( time() < $deadline );

		if ( ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}
		return $last;
	}

	/** Daily cron: restart from the top so changed products refresh. */
	public static function cron_daily(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		self::start();
		self::run_until_done();
	}

	/** Continuation cron for scans that exceeded the time budget. */
	public static function cron_continue(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		self::run_until_done();
	}

	// ---- Single-product upsert -------------------------------------------

	public static function sync_product_id( int $product_id ): ?array {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product || $product->get_status() !== 'publish' ) {
			return null;
		}
		return self::sync_product( $product );
	}

	/**
	 * Upsert one WC product into identity graph + barcode index + inventory
	 * snapshot. Idempotent — re-running is a no-op unless data changed.
	 *
	 * @param object $product WC_Product instance (untyped — WooCommerce may be absent).
	 */
	public static function sync_product( $product ): array {
		$pid  = (int) $product->get_id();
		$sku  = trim( (string) $product->get_sku() );
		$name = (string) $product->get_name();

		// UPC/GTIN: WC 9.2+ exposes get_global_unique_id(); never read the
		// private meta key directly.
		$upc = '';
		if ( method_exists( $product, 'get_global_unique_id' ) ) {
			$upc = preg_replace( '/[^0-9]/', '', (string) $product->get_global_unique_id() );
		}

		$price = (float) $product->get_price();
		$qty   = $product->get_stock_quantity(); // null when not managing stock.

		$categories = get_the_terms( $pid, 'product_cat' );
		$cat_names  = array();
		if ( is_array( $categories ) ) {
			foreach ( $categories as $term ) {
				$cat_names[] = (string) $term->name;
			}
		}

		$make    = self::first_attribute( $product, array( 'make', 'manufacturer', 'brand' ) );
		$model   = self::first_attribute( $product, array( 'model' ) );
		$caliber = self::first_attribute( $product, array( 'caliber', 'caliber-gauge', 'calibergauge' ) );

		if ( $make === '' ) {
			$make = self::brand_from_taxonomy( $pid );
		}
		if ( $make === '' && $name !== '' ) {
			// Last resort so the matcher has something to bucket on.
			$make = (string) strtok( $name, ' ' );
		}
		if ( $model === '' ) {
			$model = $name;
		}

		$item_type = self::item_type_from_categories( $cat_names, $name );

		$candidate = array(
			'item_type'    => $item_type,
			'manufacturer' => $make,
			'model'        => $model,
			'caliber'      => $caliber,
			'upc'          => $upc,
			'msrp'         => $price > 0 ? $price : null,
			'description'  => wp_strip_all_tags( (string) $product->get_short_description() ),
			'tags'         => implode( ',', array_slice( $cat_names, 0, 5 ) ),
			'attributes'   => array(
				'woo_product_id' => $pid,
				'sku'            => $sku,
				'price'          => $price,
				'stock_qty'      => $qty,
				'categories'     => $cat_names,
			),
		);

		$identity = IdentityService::resolve_or_create(
			$candidate,
			array(
				'source_type' => 'woo_product',
				'source_ref'  => (string) $pid,
			)
		);

		// Barcode index — UPC first (primary), SKU as a secondary scan code.
		$barcodes = new BarcodeRepository();
		if ( $upc !== '' ) {
			$barcodes->upsert( $pid, $upc, 'upc', true );
		}
		if ( $sku !== '' && $sku !== $upc ) {
			$barcodes->upsert( $pid, $sku, 'code128', $upc === '' );
		}

		// Inventory snapshot — one ledger row per product, updated when the
		// Woo stock quantity drifts from the last recorded level.
		if ( $qty !== null ) {
			self::snapshot_inventory( $pid, (float) $qty );
		}

		return array(
			'product_id'  => $pid,
			'identity_id' => (int) ( $identity['identity_id'] ?? 0 ),
			'decision'    => (string) ( $identity['decision'] ?? '' ),
		);
	}

	private static function snapshot_inventory( int $product_id, float $qty ): void {
		$repo   = new InventoryRepository();
		$latest = $repo->latest_for_product( $product_id, 1 );
		$last   = $latest ? (float) ( $latest[0]['after_qty'] ?? 0 ) : null;
		if ( $last !== null && abs( $last - $qty ) < 0.0001 ) {
			return; // No drift since the last ledger row.
		}
		$before = $last ?? $qty;
		$repo->log(
			array(
				'product_id'     => $product_id,
				'variation_id'   => null,
				'location_id'    => (int) get_option( 'g2a_pos_default_location_id', 1 ),
				'event_type'     => 'woo_scan_snapshot',
				'quantity_delta' => $qty - $before,
				'before_qty'     => $before,
				'after_qty'      => $qty,
				'source_ref'     => 'woo_scan:' . $product_id,
				'metadata'       => array( 'channel' => 'woo_scan' ),
			)
		);
	}

	/** @param string[] $names */
	private static function first_attribute( $product, array $names ): string {
		foreach ( $names as $name ) {
			$v = trim( (string) $product->get_attribute( $name ) );
			if ( $v !== '' ) {
				// Multi-value attributes come back comma-separated — first wins.
				return trim( (string) strtok( $v, ',' ) );
			}
		}
		return '';
	}

	private static function brand_from_taxonomy( int $product_id ): string {
		foreach ( array( 'product_brand', 'pa_brand', 'pa_manufacturer' ) as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$terms = get_the_terms( $product_id, $tax );
			if ( is_array( $terms ) && $terms ) {
				return (string) $terms[0]->name;
			}
		}
		return '';
	}

	/** @param string[] $cat_names */
	private static function item_type_from_categories( array $cat_names, string $name ): string {
		$haystack = strtolower( implode( ' ', $cat_names ) . ' ' . $name );
		return match ( true ) {
			(bool) preg_match( '/suppressor|silencer|\bnfa\b|\bsbr\b|machine gun|full[- ]auto/', $haystack ) => 'nfa',
			(bool) preg_match( '/firearm|pistol|rifle|shotgun|handgun|revolver|receiver|\bgun\b/', $haystack ) => 'firearm',
			(bool) preg_match( '/\bammo\b|ammunition|cartridge/', $haystack ) => 'ammunition',
			(bool) preg_match( '/optic|scope|red dot|sight/', $haystack ) => 'optic',
			default => 'accessory',
		};
	}
}
