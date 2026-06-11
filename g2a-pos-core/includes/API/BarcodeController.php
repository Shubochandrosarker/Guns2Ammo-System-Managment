<?php

namespace G2A\POS\API;

use G2A\POS\Database\BarcodeRepository;
use G2A\POS\Domain\CartService;
use G2A\POS\Domain\ProductCatalog;
use WP_REST_Request;

final class BarcodeController {

	public static function find( WP_REST_Request $request ) {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( $code === '' ) {
			return new \WP_Error( 'missing_code', 'Barcode is required.', array( 'status' => 400 ) );
		}

		$row = ( new BarcodeRepository() )->find( $code );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Barcode not found.', array( 'status' => 404 ) );
		}

		$product = ProductCatalog::normalize_line(
			array(
				'product_id'    => (int) $row['product_id'],
				'variation_id'  => $row['variation_id'] ? (int) $row['variation_id'] : null,
				'serial_number' => (string) ( $row['serial_number'] ?? '' ),
				'quantity'      => 1,
			)
		);

		return array(
			'item'    => $row,
			'product' => $product,
		);
	}

	public static function scan_to_cart( WP_REST_Request $request ) {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		if ( $code === '' ) {
			return new \WP_Error( 'missing_code', 'Barcode is required.', array( 'status' => 400 ) );
		}

		$row = ( new BarcodeRepository() )->find( $code );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Barcode not found.', array( 'status' => 404 ) );
		}

		$normalized = ProductCatalog::normalize_line(
			array(
				'product_id'    => (int) $row['product_id'],
				'variation_id'  => $row['variation_id'] ? (int) $row['variation_id'] : null,
				'serial_number' => (string) ( $row['serial_number'] ?? '' ),
				'quantity'      => 1,
			)
		);

		$cart = CartService::add_item(
			get_current_user_id(),
			array(
				'product_id'    => $normalized['product_id'],
				'variation_id'  => $normalized['variation_id'],
				'sku'           => $normalized['sku'],
				'name'          => $normalized['name'],
				'item_type'     => $normalized['item_type'],
				'serial_number' => $normalized['serial_number'],
				'quantity'      => $normalized['quantity'],
				'unit_price'    => $normalized['unit_price'],
				'line_total'    => $normalized['line_total'],
			)
		);

		return array(
			'item'   => $row,
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}
}
