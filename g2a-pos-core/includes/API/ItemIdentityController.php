<?php

namespace G2A\POS\API;

use G2A\POS\Catalog\IdentityMatcher;
use G2A\POS\Catalog\IdentityService;
use G2A\POS\Database\ItemIdentityRepository;
use WP_REST_Request;

final class ItemIdentityController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new ItemIdentityRepository() )->search(
				array(
					'q'               => $request->get_param( 'q' ),
					'manufacturer'    => $request->get_param( 'manufacturer' ),
					'item_type'       => $request->get_param( 'item_type' ),
					'review_required' => $request->get_param( 'review_required' ),
					'limit'           => (int) ( $request->get_param( 'limit' ) ?: 50 ),
				)
			),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$repo = new ItemIdentityRepository();
		$row  = $repo->find( $id );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Identity not found', array( 'status' => 404 ) );
		}
		$row['links'] = $repo->links_for( $id );
		return array( 'identity' => $row );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['manufacturer'] ) || empty( $body['model'] ) ) {
			return new \WP_Error( 'invalid_input', 'manufacturer + model required', array( 'status' => 400 ) );
		}
		$id = ( new ItemIdentityRepository() )->create( $body );
		return array(
			'id'       => $id,
			'identity' => ( new ItemIdentityRepository() )->find( $id ),
		);
	}

	public static function update( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		( new ItemIdentityRepository() )->update( $id, $body );
		return array( 'identity' => ( new ItemIdentityRepository() )->find( $id ) );
	}

	public static function match( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$cand = (array) ( $body['candidate'] ?? array() );
		if ( empty( $cand['manufacturer'] ) ) {
			return new \WP_Error( 'invalid_input', 'candidate.manufacturer required', array( 'status' => 400 ) );
		}
		$repo       = new ItemIdentityRepository();
		$candidates = $repo->candidates( $cand, 100 );
		$scored     = array();
		foreach ( $candidates as $c ) {
			$s = IdentityMatcher::score( $cand, $c );
			if ( $s > 0.10 ) {
				$scored[] = array(
					'score'    => $s,
					'identity' => $c,
				);
			}
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array(
			'threshold' => IdentityMatcher::THRESHOLD,
			'matches'   => array_slice( $scored, 0, 10 ),
		);
	}

	public static function resolve( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$cand = (array) ( $body['candidate'] ?? array() );
		$link = (array) ( $body['link'] ?? array() );
		if ( empty( $cand['manufacturer'] ) ) {
			return new \WP_Error( 'invalid_input', 'candidate.manufacturer required', array( 'status' => 400 ) );
		}
		return IdentityService::resolve_or_create( $cand, $link );
	}

	public static function add_link( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['source_type'] ) || empty( $body['source_ref'] ) ) {
			return new \WP_Error( 'invalid_input', 'source_type + source_ref required', array( 'status' => 400 ) );
		}
		$link_id = ( new ItemIdentityRepository() )->link(
			$id,
			(string) $body['source_type'],
			(string) $body['source_ref'],
			array(
				'wholesaler_id' => $body['wholesaler_id'] ?? null,
				'confidence'    => isset( $body['confidence'] ) ? (float) $body['confidence'] : 1.0,
				'matched_by'    => $body['matched_by'] ?? 'manual',
				'metadata'      => $body['metadata'] ?? null,
			)
		);
		return array( 'link_id' => $link_id );
	}

	public static function merge( WP_REST_Request $request ) {
		$from = (int) $request['from'];
		$into = (int) $request['into'];
		$body = $request->get_json_params() ?: array();
		$ok   = ( new ItemIdentityRepository() )->merge( $from, $into, (string) ( $body['reason'] ?? '' ) );
		if ( ! $ok ) {
			return new \WP_Error( 'merge_failed', 'merge failed', array( 'status' => 422 ) );
		}
		return array(
			'ok'   => true,
			'into' => ( new ItemIdentityRepository() )->find( $into ),
		);
	}

	public static function sources( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$res = IdentityService::sources( $id );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( $res['error'] ?? 'failed', 'Not found', array( 'status' => 404 ) );
		}
		return $res;
	}

	public static function backfill( WP_REST_Request $request ) {
		// Walks every wholesaler product + every Woo product with a UPC ref
		// and pushes them through resolve_or_create. Idempotent — safe to
		// re-run; existing links are no-ops.
		global $wpdb;
		$p       = $wpdb->prefix;
		$created = 0;
		$matched = 0;
		$linked  = 0;
		$limit   = max( 1, min( 1000, (int) ( $request->get_param( 'limit' ) ?: 200 ) ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, wholesaler_id, vendor_sku, upc, mfg_part, manufacturer, model, family,
                    caliber, item_type, msrp, image_filename
             FROM {$p}g2a_wholesaler_products
             WHERE manufacturer <> '' AND model <> ''
             ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as $r ) {
			$res = IdentityService::resolve_or_create(
				array(
					'item_type'    => $r['item_type'] ?: 'firearm',
					'manufacturer' => $r['manufacturer'],
					'model'        => $r['model'],
					'family'       => $r['family'],
					'caliber'      => $r['caliber'],
					'upc'          => $r['upc'],
					'mfg_part'     => $r['mfg_part'],
					'msrp'         => $r['msrp'],
				),
				array(
					'source_type'   => 'wholesaler_product',
					'source_ref'    => $r['vendor_sku'],
					'wholesaler_id' => (int) $r['wholesaler_id'],
				)
			);
			if ( $res['decision'] === 'created' ) {
				++$created;
			} elseif ( $res['decision'] === 'matched' ) {
				++$matched;
			}
			++$linked;
		}

		// Also walk a batch of published Woo products through the scanner so
		// the identity graph covers the shelf catalog, not just vendors.
		$woo = null;
		if ( function_exists( 'wc_get_product' ) ) {
			$woo_result = \G2A\POS\Integrations\WooProductScanner::scan_batch( min( 200, $limit ) );
			$woo_state  = $woo_result['state'] ?? array();
			$woo        = array(
				'processed' => (int) ( $woo_result['processed'] ?? 0 ),
				'created'   => (int) ( $woo_state['created'] ?? 0 ),
				'matched'   => (int) ( $woo_state['matched'] ?? 0 ),
				'status'    => (string) ( $woo_state['status'] ?? '' ),
			);
		}

		return array(
			'ok'        => true,
			'processed' => count( $rows ),
			'created'   => $created,
			'matched'   => $matched,
			'linked'    => $linked,
			'woo'       => $woo,
		);
	}
}
