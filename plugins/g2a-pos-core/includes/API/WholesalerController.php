<?php

namespace G2A\POS\API;

use G2A\POS\Database\WholesalerRepository;
use G2A\POS\Database\WholesalerProductRepository;
use G2A\POS\Database\WholesalerCategoryRepository;
use G2A\POS\Database\WholesalerOrderRepository;
use G2A\POS\Support\Logger;
use G2A\POS\Wholesalers\Media\VendorImageMirror;
use G2A\POS\Wholesalers\Media\VendorImageUrls;
use G2A\POS\Wholesalers\Promotion\VendorProductPromoter;
use G2A\POS\Wholesalers\Routing\MultiVendorRouter;
use G2A\POS\Wholesalers\WholesalerRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class WholesalerController {

	public static function list( WP_REST_Request $req ): WP_REST_Response {
		$repo = new WholesalerRepository();
		$rows = array_map(
			static function ( array $row ): array {
				unset( $row['credentials'] );
				return $row;
			},
			$repo->all()
		);
		return new WP_REST_Response(
			array(
				'ok'          => true,
				'wholesalers' => $rows,
			)
		);
	}

	public static function upsert( WP_REST_Request $req ) {
		$params = $req->get_json_params() ?: $req->get_params();
		$code   = sanitize_key( (string) ( $params['provider_code'] ?? '' ) );
		if ( $code === '' || ! WholesalerRegistry::get( $code ) ) {
			return new WP_Error( 'invalid_provider', 'Unknown provider code', array( 'status' => 400 ) );
		}
		$repo = new WholesalerRepository();
		$id   = $repo->upsert(
			array(
				// Pass the row id through when editing so the repository can
				// update in place even if the account number changed.
				'id'             => isset( $params['id'] ) ? (int) $params['id'] : 0,
				'provider_code'  => $code,
				'display_name'   => sanitize_text_field( (string) ( $params['display_name'] ?? '' ) ),
				'account_number' => sanitize_text_field( (string) ( $params['account_number'] ?? '' ) ),
				'api_endpoint'   => esc_url_raw( (string) ( $params['api_endpoint'] ?? '' ) ),
				'credentials'    => is_array( $params['credentials'] ?? null ) ? $params['credentials'] : null,
				'settings'       => is_array( $params['settings'] ?? null ) ? $params['settings'] : null,
				'status'         => sanitize_key( (string) ( $params['status'] ?? 'active' ) ),
			)
		);
		return new WP_REST_Response(
			array(
				'ok' => true,
				'id' => $id,
			)
		);
	}

	public static function browse( WP_REST_Request $req ): WP_REST_Response {
		$repo = new WholesalerProductRepository();
		$rows = $repo->search(
			array(
				'wholesaler_id' => (int) $req->get_param( 'wholesaler_id' ),
				'term'          => (string) $req->get_param( 'q' ),
				'category'      => (string) $req->get_param( 'category' ),
				'item_type'     => (string) $req->get_param( 'item_type' ),
				'in_stock'      => (bool) $req->get_param( 'in_stock' ),
				'dropship_only' => (bool) $req->get_param( 'dropship_only' ),
				'limit'         => (int) ( $req->get_param( 'limit' ) ?: 50 ),
				'offset'        => (int) ( $req->get_param( 'offset' ) ?: 0 ),
			)
		);
		return new WP_REST_Response(
			array(
				'ok'    => true,
				'items' => $rows,
			)
		);
	}

	public static function categories( WP_REST_Request $req ): WP_REST_Response {
		$repo = new WholesalerCategoryRepository();
		$rows = $repo->all( (int) $req->get_param( 'wholesaler_id' ) );
		return new WP_REST_Response(
			array(
				'ok'         => true,
				'categories' => $rows,
			)
		);
	}

	public static function update_category( WP_REST_Request $req ) {
		$repo = new WholesalerCategoryRepository();
		$id   = (int) $req->get_param( 'id' );
		if ( $id <= 0 ) {
			return new WP_Error( 'invalid_category', 'Invalid category id', array( 'status' => 400 ) );
		}
		$flags = array(
			'import_enabled'   => (int) (bool) $req->get_param( 'import_enabled' ),
			'dropship_enabled' => (int) (bool) $req->get_param( 'dropship_enabled' ),
			'markup_percent'   => $req->get_param( 'markup_percent' ) !== null ? (float) $req->get_param( 'markup_percent' ) : null,
		);
		// G2A-CRIT-003: wc_category_id is the mapping VendorProductPromoter
		// applies at promote time — only touch it when the caller actually
		// sends it, so existing integrations that don't know about this
		// field yet can't accidentally null out a mapping on every save.
		if ( $req->get_param( 'wc_category_id' ) !== null ) {
			$wcCategoryId          = (int) $req->get_param( 'wc_category_id' );
			$flags['wc_category_id'] = $wcCategoryId > 0 ? $wcCategoryId : null;
		}
		$repo->setFlags( $id, $flags );
		return new WP_REST_Response( array( 'ok' => true ) );
	}

	public static function import_csv( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$repoW        = new WholesalerRepository();
		$w            = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsCatalogCsv() ) {
			return new WP_Error( 'unsupported', 'Provider does not support CSV import', array( 'status' => 400 ) );
		}

		$files = $req->get_file_params();
		if ( empty( $files['file']['tmp_name'] ) || ! is_uploaded_file( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'CSV file is required (multipart "file")', array( 'status' => 400 ) );
		}
		$tmp   = (string) $files['file']['tmp_name'];
		$label = (string) ( $files['file']['name'] ?? basename( $tmp ) );

		$result = $provider->importCatalogCsv( $wholesalerId, $tmp, array( 'file_label' => $label ) );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'import_failed',
				(string) ( $result['error'] ?? 'unknown' ),
				array(
					'status' => 500,
					'detail' => $result,
				)
			);
		}
		$repoW->markSyncedNow( $wholesalerId );
		return new WP_REST_Response( $result );
	}

	/**
	 * POST /wholesalers/{id}/catalog/api-sync — full catalog pull over the
	 * provider's API (currently Lipsey's CatalogFeed), no CSV upload needed.
	 */
	public static function sync_catalog_api( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$repoW        = new WholesalerRepository();
		$w            = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsApi() || ! method_exists( $provider, 'importCatalogApi' ) ) {
			return new WP_Error( 'unsupported', 'Provider does not support API catalog sync', array( 'status' => 400 ) );
		}
		$result = $provider->importCatalogApi( $wholesalerId );
		if ( empty( $result['ok'] ) ) {
			return new WP_Error(
				'catalog_sync_failed',
				(string) ( $result['error'] ?? 'unknown' ),
				array(
					'status' => ( $result['error'] ?? '' ) === 'credentials_missing' ? 400 : 502,
					'detail' => $result,
				)
			);
		}
		$repoW->markSyncedNow( $wholesalerId );
		return new WP_REST_Response( $result );
	}

	public static function sync_inventory( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$repoW        = new WholesalerRepository();
		$w            = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsRealTimeInventory() ) {
			return new WP_Error( 'unsupported', 'Provider does not support live inventory', array( 'status' => 400 ) );
		}
		$result = $provider->syncInventory( $wholesalerId );
		if ( empty( $result['ok'] ) ) {
			// Mirror sync_catalog_api/import_csv: report upstream failures with
			// a real error status and keep the provider's error + detail in the
			// response body so the admin UI can show the actual reason.
			return new WP_Error(
				'inventory_sync_failed',
				(string) ( $result['error'] ?? 'unknown' ),
				array(
					'status' => ( $result['error'] ?? '' ) === 'credentials_missing' ? 400 : 502,
					'detail' => $result,
				)
			);
		}
		$repoW->markSyncedNow( $wholesalerId );
		return new WP_REST_Response( $result );
	}

	/**
	 * POST /wholesalers/{id}/test-credentials — run only the provider's
	 * authentication step for the stored credentials (no feed access) and
	 * report the exact upstream response summary.
	 */
	public static function test_credentials( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$repoW        = new WholesalerRepository();
		$w            = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! method_exists( $provider, 'testCredentials' ) ) {
			return new WP_Error( 'unsupported', 'Provider does not support credential testing', array( 'status' => 400 ) );
		}
		$result = $provider->testCredentials( $wholesalerId );
		if ( ! empty( $result['ok'] ) ) {
			return new WP_REST_Response( $result );
		}
		return new WP_REST_Response(
			$result,
			( $result['error'] ?? '' ) === 'credentials_missing' ? 400 : 502
		);
	}

	public static function submit_dropship( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$body         = $req->get_json_params() ?: $req->get_params();
		$items        = (array) ( $body['items'] ?? array() );

		// Auto-route via multi-vendor router when caller passes only UPCs.
		$routing = null;
		if ( $wholesalerId <= 0 && $items && empty( $items[0]['vendor_sku'] ) && ! empty( $items[0]['upc'] ) ) {
			$route = MultiVendorRouter::routeByUpc(
				(string) $items[0]['upc'],
				(int) ( $items[0]['quantity'] ?? 1 ),
				array( 'dropship_only' => true )
			);
			if ( ! empty( $route['ok'] ) ) {
				$wholesalerId = (int) $route['best']['wholesaler_id'];
				// Translate UPC items to vendor SKUs for that wholesaler.
				$items   = array_map(
					static function ( $it ) use ( $route ) {
						if ( empty( $it['vendor_sku'] ) && ! empty( $it['upc'] ) ) {
							$it['vendor_sku'] = $route['best']['vendor_sku'];
						}
						return $it;
					},
					$items
				);
				$routing = $route;
			} else {
				return new WP_Error(
					'no_route',
					'No wholesaler can fulfill the requested UPC(s)',
					array(
						'status' => 422,
						'detail' => $route,
					)
				);
			}
		}

		$repoW = new WholesalerRepository();
		$w     = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsDropShip() ) {
			return new WP_Error( 'unsupported', 'Drop-ship not supported', array( 'status' => 400 ) );
		}
		if ( ! $items ) {
			return new WP_Error( 'no_items', 'At least one item is required', array( 'status' => 400 ) );
		}
		$result = $provider->submitDropShipOrder(
			$wholesalerId,
			array(
				'pos_order_id'           => isset( $body['pos_order_id'] ) ? (int) $body['pos_order_id'] : null,
				'wc_order_id'            => isset( $body['wc_order_id'] ) ? (int) $body['wc_order_id'] : null,
				'po_number'              => $body['po_number'] ?? null,
				'warehouse'              => $body['warehouse'] ?? null,
				'overnight'              => (bool) ( $body['overnight'] ?? false ),
				'message_for_sales_exec' => $body['message_for_sales_exec'] ?? null,
				'items'                  => $items,
				'billing'                => (array) ( $body['billing'] ?? array() ),
				'ship_to'                => (array) ( $body['ship_to'] ?? array() ),
			)
		);
		if ( $routing ) {
			$result['routing'] = $routing;
		}
		return new WP_REST_Response( $result );
	}

	public static function submit_warehouse( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$repoW        = new WholesalerRepository();
		$w            = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsWarehouseOrder() ) {
			return new WP_Error( 'unsupported', 'Warehouse order not supported', array( 'status' => 400 ) );
		}
		$body  = $req->get_json_params() ?: $req->get_params();
		$items = (array) ( $body['items'] ?? array() );
		if ( ! $items ) {
			return new WP_Error( 'no_items', 'At least one item is required', array( 'status' => 400 ) );
		}
		$result = $provider->submitWarehouseOrder(
			$wholesalerId,
			array(
				'pos_order_id'       => isset( $body['pos_order_id'] ) ? (int) $body['pos_order_id'] : null,
				'wc_order_id'        => isset( $body['wc_order_id'] ) ? (int) $body['wc_order_id'] : null,
				'po_number'          => $body['po_number'] ?? null,
				'email_confirmation' => (bool) ( $body['email_confirmation'] ?? true ),
				'items'              => $items,
			)
		);
		return new WP_REST_Response( $result );
	}

	public static function validate_item( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$sku          = (string) $req->get_param( 'sku' );
		if ( $sku === '' ) {
			return new WP_Error( 'no_sku', 'sku is required', array( 'status' => 400 ) );
		}
		$repoW = new WholesalerRepository();
		$w     = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsItemValidation() ) {
			return new WP_Error( 'unsupported', 'Item validation not supported', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $provider->validateItem( $wholesalerId, $sku ) );
	}

	public static function lookup_upc( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$upc          = (string) $req->get_param( 'upc' );
		if ( $upc === '' ) {
			return new WP_Error( 'no_upc', 'upc is required', array( 'status' => 400 ) );
		}
		$repoW = new WholesalerRepository();
		$w     = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider ) {
			return new WP_Error( 'unsupported', 'Provider unavailable', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $provider->lookupByUpc( $wholesalerId, $upc ) );
	}

	public static function one_day_shipping( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$date         = (string) ( $req->get_param( 'date' ) ?: current_time( 'n/j/Y' ) );
		$repoW        = new WholesalerRepository();
		$w            = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider || ! $provider->supportsOneDayShippingCheck() ) {
			return new WP_Error( 'unsupported', 'One-day shipping check not supported', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $provider->checkOneDayShipping( $wholesalerId, $date ) );
	}

	public static function order_status( WP_REST_Request $req ) {
		$repoW = new WholesalerRepository();
		$w     = $repoW->find( (int) $req->get_param( 'wholesaler_id' ) );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
		if ( ! $provider ) {
			return new WP_Error( 'unsupported', 'Provider unavailable', array( 'status' => 400 ) );
		}
		$ref = (string) $req->get_param( 'external_ref' );
		if ( $ref === '' ) {
			return new WP_Error( 'no_ref', 'external_ref is required', array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $provider->fetchOrderStatus( (int) $w['id'], $ref ) );
	}

	public static function orders( WP_REST_Request $req ): WP_REST_Response {
		$repo = new WholesalerOrderRepository();
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'orders' => $repo->listRecent( (int) ( $req->get_param( 'limit' ) ?: 50 ) ),
			)
		);
	}

	/**
	 * Side-load a vendor product's CDN image into the WP media library.
	 * Idempotent — returns the existing attachment_id on repeat calls.
	 * Optionally attaches to a WooCommerce product as the featured image.
	 */
	public static function mirror_image( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$body         = $req->get_json_params() ?: $req->get_params();

		// The SKU may arrive either as a path segment (legacy route) or in
		// the request body (the route the admin UI uses). Body first: real
		// vendor SKUs contain '/', '#' and spaces, and a '/' inside a path
		// segment is decoded by the web server *before* WP matches routes,
		// so those SKUs could never match the path route at all — they came
		// back as a bare "No route was found matching the URL and request
		// method." with nothing in any log to explain it.
		$sku = (string) ( $body['vendor_sku'] ?? '' );
		if ( $sku === '' ) {
			$sku = (string) $req->get_param( 'vendor_sku' );
		}
		if ( trim( $sku ) === '' ) {
			return new WP_Error( 'no_sku', 'vendor_sku is required', array( 'status' => 400 ) );
		}

		$repoW = new WholesalerRepository();
		$w     = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}

		$repoP   = new WholesalerProductRepository();
		$product = $repoP->find( $wholesalerId, $sku );
		if ( ! $product ) {
			return new WP_Error( 'not_found', 'Vendor product not found', array( 'status' => 404 ) );
		}

		$providerCode = (string) $w['provider_code'];
		$url          = self::cdnUrlFor( $providerCode, (string) ( $product['image_filename'] ?? '' ) );
		if ( ! $url ) {
			$detail = trim( (string) ( $product['image_filename'] ?? '' ) ) === ''
				? 'This catalog row has no image in the vendor feed.'
				: sprintf( 'No image host is configured for provider "%s" — set one via the g2a_pos_vendor_image_cdn_bases option.', $providerCode );
			return new WP_Error( 'no_image', $detail, array( 'status' => 422 ) );
		}

		$wcProductId = isset( $body['wc_product_id'] ) ? (int) $body['wc_product_id'] : (int) ( $product['wc_product_id'] ?? 0 );
		$setFeatured = ! empty( $body['set_featured'] );

		try {
			$result = VendorImageMirror::mirror(
				$url,
				array(
					'vendor_sku'    => $sku,
					'provider_code' => $providerCode,
					'wc_product_id' => $wcProductId,
					'set_featured'  => $setFeatured,
					'alt'           => (string) ( $product['name'] ?? '' ),
				)
			);
		} catch ( \Throwable $e ) {
			Logger::exception(
				'Vendor image mirror REST call failed',
				$e,
				array(
					'wholesaler_id' => $wholesalerId,
					'vendor_sku'    => $sku,
				)
			);
			return new WP_Error( 'mirror_failed', 'Image mirroring failed: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
		$result['source_url'] = $url;
		$result['vendor_sku'] = $sku;

		return new WP_REST_Response( $result, ! empty( $result['ok'] ) ? 200 : 422 );
	}

	/**
	 * Mirror every catalog image for a wholesaler, one batch at a time.
	 *
	 * Mirroring row-by-row from the catalog table was the only way to get
	 * vendor imagery in, which is unusable for a 50k-row feed. This walks the
	 * rows that actually carry an image reference and side-loads each one,
	 * returning a cursor so the caller can keep going until `remaining` hits
	 * zero. Individual failures never abort the batch — they're counted and
	 * sampled into `failures` so one bad CDN filename doesn't cost every
	 * other image in the batch.
	 */
	public static function mirror_images( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$body         = $req->get_json_params() ?: $req->get_params();

		$repoW = new WholesalerRepository();
		$w     = $repoW->find( $wholesalerId );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Wholesaler not found', array( 'status' => 404 ) );
		}
		$providerCode = (string) $w['provider_code'];

		$limit       = max( 1, min( 200, (int) ( $body['limit'] ?? 50 ) ) );
		$offset      = max( 0, (int) ( $body['offset'] ?? 0 ) );
		$setFeatured = array_key_exists( 'set_featured', $body ) ? ! empty( $body['set_featured'] ) : true;
		$linkedOnly  = ! empty( $body['linked_only'] );

		$repoP = new WholesalerProductRepository();
		$rows  = $repoP->withImages( $wholesalerId, $limit, $offset, $linkedOnly );
		$total = $repoP->countWithImages( $wholesalerId, $linkedOnly );

		$stats    = array(
			'processed' => 0,
			'imported'  => 0,
			'reused'    => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);
		$failures = array();

		foreach ( $rows as $row ) {
			++$stats['processed'];
			$url = self::cdnUrlFor( $providerCode, (string) ( $row['image_filename'] ?? '' ) );
			if ( ! $url ) {
				++$stats['skipped'];
				continue;
			}
			$wcProductId = (int) ( $row['wc_product_id'] ?? 0 );
			try {
				$result = VendorImageMirror::mirror(
					$url,
					array(
						'vendor_sku'    => (string) $row['vendor_sku'],
						'provider_code' => $providerCode,
						'wc_product_id' => $wcProductId,
						'set_featured'  => $setFeatured && $wcProductId > 0,
						'alt'           => (string) ( $row['name'] ?? '' ),
					)
				);
			} catch ( \Throwable $e ) {
				Logger::exception(
					'Vendor image bulk mirror failed',
					$e,
					array(
						'wholesaler_id' => $wholesalerId,
						'vendor_sku'    => $row['vendor_sku'] ?? null,
					)
				);
				$result = array(
					'ok'      => false,
					'error'   => 'exception',
					'message' => $e->getMessage(),
				);
			}

			if ( ! empty( $result['ok'] ) ) {
				if ( ! empty( $result['reused'] ) ) {
					++$stats['reused'];
				} else {
					++$stats['imported'];
				}
				continue;
			}

			++$stats['failed'];
			if ( count( $failures ) < 20 ) {
				$failures[] = array(
					'vendor_sku' => (string) $row['vendor_sku'],
					'url'        => $url,
					'error'      => (string) ( $result['error'] ?? 'unknown' ),
					'message'    => (string) ( $result['message'] ?? $result['detail'] ?? '' ),
				);
			}
		}

		$nextOffset = $offset + count( $rows );

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'stats'       => $stats,
				'failures'    => $failures,
				'offset'      => $offset,
				'next_offset' => $nextOffset,
				'total'       => $total,
				'remaining'   => max( 0, $total - $nextOffset ),
				'done'        => count( $rows ) < $limit || $nextOffset >= $total,
			)
		);
	}

	public static function promote( WP_REST_Request $req ) {
		$wholesalerId = (int) $req->get_param( 'wholesaler_id' );
		$sku          = (string) $req->get_param( 'vendor_sku' );
		if ( $sku === '' ) {
			return new WP_Error( 'no_sku', 'vendor_sku is required', array( 'status' => 400 ) );
		}
		$body = $req->get_json_params() ?: $req->get_params();
		try {
			$result = VendorProductPromoter::promote(
				$wholesalerId,
				$sku,
				array(
					'publish'        => ! empty( $body['publish'] ),
					'markup_pct'     => isset( $body['markup_pct'] ) ? (float) $body['markup_pct'] : null,
					'sell_price'     => isset( $body['sell_price'] ) ? (float) $body['sell_price'] : null,
					'wc_category_id' => isset( $body['wc_category_id'] ) ? (int) $body['wc_category_id'] : null,
				)
			);
		} catch ( \Throwable $e ) {
			Logger::exception(
				'Vendor product promote REST call failed',
				$e,
				array(
					'wholesaler_id' => $wholesalerId,
					'vendor_sku'    => $sku,
				)
			);
			return new WP_Error( 'promote_failed', 'Promotion failed: ' . $e->getMessage(), array( 'status' => 500 ) );
		}
		return new WP_REST_Response( $result, ! empty( $result['ok'] ) ? 200 : 422 );
	}

	public static function route_upc( WP_REST_Request $req ): WP_REST_Response {
		$result = MultiVendorRouter::routeByUpc(
			(string) $req->get_param( 'upc' ),
			(int) ( $req->get_param( 'qty' ) ?: 1 ),
			array( 'dropship_only' => (bool) $req->get_param( 'dropship_only' ) )
		);
		return new WP_REST_Response( $result, ! empty( $result['ok'] ) ? 200 : 404 );
	}

	private static function cdnUrlFor( string $providerCode, string $imageFilename ): ?string {
		return VendorImageUrls::cdnUrl( $providerCode, $imageFilename );
	}
}
