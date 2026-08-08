<?php

namespace G2A\POS\API;

use G2A\POS\Domain\InventoryEngine;
use WP_REST_Request;

final class InventoryController {

	public static function product( WP_REST_Request $request ) {
		$product_id = (int) $request->get_param( 'product_id' );
		if ( $product_id <= 0 ) {
			return new \WP_Error( 'missing_field', 'product_id is required.', array( 'status' => 400 ) );
		}

		$repo = new \G2A\POS\Database\InventoryRepository();
		$logs = $repo->latest_for_product( $product_id, 50 );

		$woo_stock = null;
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product ) {
				$woo_stock = $product->get_stock_quantity();
			}
		}

		return array(
			'product_id'    => $product_id,
			'woo_stock_qty' => $woo_stock,
			'logs'          => $logs,
		);
	}

	public static function adjust( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		foreach ( array( 'product_id', 'location_id', 'quantity_delta' ) as $required ) {
			if ( ! isset( $payload[ $required ] ) ) {
				return new \WP_Error( 'missing_field', sprintf( '%s is required.', $required ), array( 'status' => 400 ) );
			}
		}

		$result = InventoryEngine::adjust( $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'adjust_failed', $result['error'], array( 'status' => 422 ) );
		}

		return $result;
	}
}
