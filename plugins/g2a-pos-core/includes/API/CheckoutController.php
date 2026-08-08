<?php

namespace G2A\POS\API;

use G2A\POS\Domain\CartService;
use G2A\POS\Domain\OrderEngine;
use G2A\POS\Domain\ProductCatalog;
use WP_REST_Request;

final class CheckoutController {

	public static function quote( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$cart = CartService::get( $uid );
		return array(
			'cart'   => $cart,
			'totals' => CartService::totals( $cart ),
		);
	}

	public static function submit( WP_REST_Request $request ) {
		$uid  = get_current_user_id();
		$cart = CartService::get( $uid );
		if ( empty( $cart['items'] ) ) {
			return new \WP_Error( 'empty_cart', 'Cart is empty.', array( 'status' => 422 ) );
		}

		$input            = (array) $request->get_json_params();
		$normalized_items = array_map( static fn( $item ) => ProductCatalog::normalize_line( $item ), $cart['items'] );
		$subtotal         = 0.0;
		foreach ( $normalized_items as $line ) {
			$subtotal += (float) $line['line_total'];
		}
		$subtotal = round( $subtotal, 2 );
		// Clerks may pass a discount; tax is always computed server-side.
		$discount = min( $subtotal, max( 0.0, (float) ( $input['discount_total'] ?? 0 ) ) );
		$tax      = \G2A\POS\Domain\TaxService::tax_for_lines( $normalized_items, $discount );
		$totals   = array(
			'subtotal'       => $subtotal,
			'tax_total'      => $tax,
			'discount_total' => $discount,
			'grand_total'    => round( $subtotal + $tax - $discount, 2 ),
		);
		$payload  = array(
			'register_code'    => $input['register_code'] ?? $cart['register_code'] ?? '',
			'location_id'      => $input['location_id'] ?? $cart['location_id'] ?? 1,
			'customer_id'      => $input['customer_id'] ?? $cart['customer_id'] ?? null,
			'subtotal'         => (float) $totals['subtotal'],
			'tax_total'        => (float) $totals['tax_total'],
			'discount_total'   => (float) $totals['discount_total'],
			'grand_total'      => (float) $totals['grand_total'],
			'payment_status'   => sanitize_key( (string) ( $input['payment_status'] ?? 'pending' ) ),
			'payment_method'   => sanitize_key( (string) ( $input['payment_method'] ?? '' ) ),
			'compliance_state' => sanitize_key( (string) ( $input['compliance_state'] ?? 'pending_review' ) ),
			'items'            => $normalized_items,
		);

		$result = OrderEngine::create( $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'checkout_failed', $result['error'], array( 'status' => 422 ) );
		}

		CartService::clear( $uid );
		return $result;
	}
}
