<?php

namespace G2A\POS\API;

use G2A\POS\Domain\ReceiptService;
use WP_REST_Request;

final class ReceiptController {

	public static function get( WP_REST_Request $request ) {
		$order_id = (int) $request->get_param( 'id' );
		if ( $order_id <= 0 ) {
			return new \WP_Error( 'invalid_order', 'Invalid order id.', array( 'status' => 400 ) );
		}

		$receipt = ReceiptService::build( $order_id );
		if ( ! $receipt ) {
			return new \WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		return array( 'receipt' => $receipt );
	}
}
