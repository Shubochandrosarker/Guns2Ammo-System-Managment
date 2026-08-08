<?php

namespace G2A\POS\API;

use G2A\POS\Database\ClassPaymentRepository;
use WP_REST_Request;

final class ClassPaymentController {

	public static function record( WP_REST_Request $request ) {
		$enrollment_id = (int) $request['id'];
		$body          = $request->get_json_params() ?: array();
		$amount        = (float) ( $body['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_input', 'amount > 0 required', array( 'status' => 400 ) );
		}
		$id = ( new ClassPaymentRepository() )->record(
			$enrollment_id,
			$amount,
			(string) ( $body['payment_method'] ?? 'cash' ),
			$body['reference'] ?? null
		);
		return array(
			'id'       => $id,
			'payments' => ( new ClassPaymentRepository() )->list_for_enrollment( $enrollment_id ),
		);
	}

	public static function index( WP_REST_Request $request ) {
		$enrollment_id = (int) $request['id'];
		return array( 'items' => ( new ClassPaymentRepository() )->list_for_enrollment( $enrollment_id ) );
	}
}
