<?php

namespace G2A\POS\API;

use G2A\POS\Database\CycleCountRepository;
use WP_REST_Request;

final class CycleCountController {

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new CycleCountRepository() )->list() );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['items'] ) ) {
			return new \WP_Error( 'invalid_input', 'items required', array( 'status' => 400 ) );
		}
		$id = ( new CycleCountRepository() )->open( $body );
		return array(
			'id'    => $id,
			'count' => ( new CycleCountRepository() )->find( $id ),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new CycleCountRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Count not found', array( 'status' => 404 ) );
		}
		return array( 'count' => $row );
	}

	public static function record( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$body   = $request->get_json_params() ?: array();
		$result = ( new CycleCountRepository() )->record(
			$id,
			(int) ( $body['item_id'] ?? 0 ),
			(float) ( $body['counted_qty'] ?? 0 ),
			isset( $body['notes'] ) ? (string) $body['notes'] : null
		);
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['error'], $result['error'], array( 'status' => 422 ) );
		}
		return array(
			'result' => $result,
			'count'  => ( new CycleCountRepository() )->find( $id ),
		);
	}

	public static function close( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		$ok = ( new CycleCountRepository() )->close( $id );
		return array(
			'ok'    => $ok,
			'count' => ( new CycleCountRepository() )->find( $id ),
		);
	}
}
