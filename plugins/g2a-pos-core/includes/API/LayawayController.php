<?php

namespace G2A\POS\API;

use G2A\POS\Database\LayawayRepository;
use WP_REST_Request;

final class LayawayController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new LayawayRepository() )->list(
				array(
					'status'       => $request->get_param( 'status' ),
					'layaway_type' => $request->get_param( 'layaway_type' ),
				),
				(int) ( $request->get_param( 'limit' ) ?: 100 )
			),
		);
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['customer_name'] ) || empty( $body['items'] ) ) {
			return new \WP_Error( 'invalid_input', 'customer_name and items required', array( 'status' => 400 ) );
		}
		$id = ( new LayawayRepository() )->create( $body );
		return array(
			'id'      => $id,
			'layaway' => ( new LayawayRepository() )->find( $id ),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new LayawayRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Layaway not found', array( 'status' => 404 ) );
		}
		return array( 'layaway' => $row );
	}

	public static function pay( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$body   = $request->get_json_params() ?: array();
		$amount = (float) ( $body['amount'] ?? 0 );
		$method = sanitize_text_field( (string) ( $body['payment_method'] ?? 'cash' ) );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_input', 'amount must be > 0', array( 'status' => 400 ) );
		}
		$payment_id = ( new LayawayRepository() )->record_payment( $id, $amount, $method, $body );
		return array(
			'payment_id' => $payment_id,
			'layaway'    => ( new LayawayRepository() )->find( $id ),
		);
	}

	public static function cancel( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		$ok   = ( new LayawayRepository() )->cancel( $id, (string) ( $body['reason'] ?? '' ), (float) ( $body['forfeited'] ?? 0 ) );
		return array( 'ok' => $ok );
	}
}
