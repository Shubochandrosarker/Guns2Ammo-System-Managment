<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\NICS;
use G2A\POS\Database\NICSRepository;
use WP_REST_Request;

final class NICSController {

	public static function index( WP_REST_Request $request ) {
		$items = ( new NICSRepository() )->list(
			array(
				'status' => (string) ( $request->get_param( 'status' ) ?? '' ),
				'limit'  => (int) ( $request->get_param( 'limit' ) ?: 100 ),
			)
		);
		return array( 'items' => $items );
	}

	public static function initiate( WP_REST_Request $request ) {
		$payload      = $request->get_json_params() ?: array();
		$form_4473_id = (int) ( $payload['form_4473_id'] ?? 0 );
		if ( $form_4473_id <= 0 ) {
			return new \WP_Error( 'missing_form', 'form_4473_id is required.', array( 'status' => 400 ) ); }
		$result = NICS::initiate( $form_4473_id, $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'nics_initiate', $result['error'], array( 'status' => 422 ) ); }
		return $result;
	}

	public static function response( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$payload = $request->get_json_params() ?: array();
		$result  = NICS::record_response( $id, $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'nics_response', $result['error'], array( 'status' => 422 ) ); }
		return $result;
	}
}
