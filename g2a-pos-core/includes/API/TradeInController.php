<?php

namespace G2A\POS\API;

use G2A\POS\Database\TradeInRepository;
use WP_REST_Request;

final class TradeInController {

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new TradeInRepository() )->list() );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['customer_name'] ) || empty( $body['serial_number'] ) || empty( $body['manufacturer'] ) ) {
			return new \WP_Error(
				'invalid_input',
				'customer_name, manufacturer, serial_number required',
				array( 'status' => 400 )
			);
		}
		return ( new TradeInRepository() )->intake( $body );
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new TradeInRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Trade-in not found', array( 'status' => 404 ) );
		}
		return array( 'tradein' => $row );
	}
}
