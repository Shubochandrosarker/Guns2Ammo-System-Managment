<?php

namespace G2A\POS\API;

use G2A\POS\Integrations\WooProductScanner;
use WP_REST_Request;

final class WooScanController {

	/**
	 * POST /woo/scan — start or continue the product backfill.
	 * Body (all optional): { reset: bool, batch: int, run_all: bool }
	 */
	public static function scan( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'woocommerce_inactive', 'WooCommerce is not active.', array( 'status' => 409 ) );
		}
		$body = $request->get_json_params() ?: array();

		if ( ! empty( $body['reset'] ) || WooProductScanner::state()['status'] === 'complete' ) {
			WooProductScanner::start();
		}

		if ( ! empty( $body['run_all'] ) ) {
			$result = WooProductScanner::run_until_done( 20 );
		} else {
			$result = WooProductScanner::scan_batch( (int) ( $body['batch'] ?? WooProductScanner::BATCH_SIZE ) );
		}
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error( (string) ( $result['error'] ?? 'scan_failed' ), 'Scan failed', array( 'status' => 500 ) );
		}
		return array(
			'ok'        => true,
			'processed' => (int) ( $result['processed'] ?? 0 ),
			'status'    => WooProductScanner::status(),
		);
	}

	/** GET /woo/scan/status */
	public static function status( WP_REST_Request $request ) {
		return array( 'status' => WooProductScanner::status() );
	}
}
