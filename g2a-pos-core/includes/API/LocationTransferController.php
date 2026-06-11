<?php

namespace G2A\POS\API;

use G2A\POS\Database\LocationTransferRepository;
use WP_REST_Request;

final class LocationTransferController {

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new LocationTransferRepository() )->list() );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['from_location_id'] ) || empty( $body['to_location_id'] ) || empty( $body['items'] ) ) {
			return new \WP_Error(
				'invalid_input',
				'from_location_id, to_location_id, items required',
				array( 'status' => 400 )
			);
		}
		if ( (int) $body['from_location_id'] === (int) $body['to_location_id'] ) {
			return new \WP_Error( 'invalid_input', 'from and to locations must differ', array( 'status' => 400 ) );
		}
		$id = ( new LocationTransferRepository() )->create( $body );
		return array(
			'id'       => $id,
			'transfer' => ( new LocationTransferRepository() )->find( $id ),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new LocationTransferRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Transfer not found', array( 'status' => 404 ) );
		}
		return array( 'transfer' => $row );
	}

	public static function ship( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$ok = ( new LocationTransferRepository() )->ship( $id, get_current_user_id() );
		return array(
			'ok'       => $ok,
			'transfer' => ( new LocationTransferRepository() )->find( $id ),
		);
	}

	public static function receive( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$ok = ( new LocationTransferRepository() )->receive( $id, get_current_user_id() );
		return array(
			'ok'       => $ok,
			'transfer' => ( new LocationTransferRepository() )->find( $id ),
		);
	}
}
