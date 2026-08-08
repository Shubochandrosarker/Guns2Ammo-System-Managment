<?php

namespace G2A\POS\API;

use G2A\POS\Domain\PaymentService;
use WP_REST_Request;

final class PaymentController {

	public static function collect( WP_REST_Request $request ) {
		$order_id = (int) $request->get_param( 'id' );
		if ( $order_id <= 0 ) {
			return new \WP_Error( 'invalid_order', 'Invalid order id.', array( 'status' => 400 ) );
		}

		$result = PaymentService::collect( $order_id, (array) $request->get_json_params() );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'payment_failed', $result['error'], array( 'status' => 422 ) );
		}

		return $result;
	}

	public static function refund( WP_REST_Request $request ) {
		$order_id = (int) $request->get_param( 'id' );
		if ( $order_id <= 0 ) {
			return new \WP_Error( 'invalid_order', 'Invalid order id.', array( 'status' => 400 ) );
		}

		$result = PaymentService::refund( $order_id, (array) $request->get_json_params() );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'refund_failed', $result['error'], array( 'status' => 422 ) );
		}

		return $result;
	}
}
