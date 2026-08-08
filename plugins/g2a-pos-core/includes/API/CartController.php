<?php

namespace G2A\POS\API;

use G2A\POS\Domain\CartService;
use WP_REST_Request;

final class CartController {

	public static function get( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$cart = CartService::get( $uid );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}

	public static function set_context( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$cart = CartService::set_context( $uid, (array) $request->get_json_params() );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}

	public static function add_item( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$item = (array) $request->get_json_params();
		if ( (int) ( $item['product_id'] ?? 0 ) <= 0 ) {
			return new \WP_Error( 'invalid_item', 'product_id is required.', array( 'status' => 400 ) );
		}
		$cart = CartService::add_item( $uid, $item );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}

	public static function update_item( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$cart = CartService::update_item( $uid, $id, (array) $request->get_json_params() );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}

	public static function remove_item( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$id   = sanitize_text_field( (string) $request->get_param( 'id' ) );
		$cart = CartService::remove_item( $uid, $id );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}

	public static function clear( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$cart = CartService::clear( $uid );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}
}
