<?php

namespace G2A\POS\API;

use G2A\POS\Database\ConsignmentRepository;
use WP_REST_Request;

final class ConsignmentController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new ConsignmentRepository() )->list(
				array(
					'status' => $request->get_param( 'status' ),
				)
			),
		);
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['consignor_name'] ) || empty( $body['firearm_serial'] ) ) {
			return new \WP_Error( 'invalid_input', 'consignor_name and firearm_serial required', array( 'status' => 400 ) );
		}
		$id = ( new ConsignmentRepository() )->create( $body );
		return array(
			'id'          => $id,
			'consignment' => ( new ConsignmentRepository() )->find( $id ),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new ConsignmentRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Consignment not found', array( 'status' => 404 ) );
		}
		return array( 'consignment' => $row );
	}

	public static function sell( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$body   = $request->get_json_params() ?: array();
		$result = ( new ConsignmentRepository() )->mark_sold(
			$id,
			(float) ( $body['sold_price'] ?? 0 ),
			isset( $body['pos_order_id'] ) ? (int) $body['pos_order_id'] : null
		);
		return array(
			'result'      => $result,
			'consignment' => ( new ConsignmentRepository() )->find( $id ),
		);
	}

	public static function payout( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		$ok   = ( new ConsignmentRepository() )->record_payout( $id, (string) ( $body['reference'] ?? '' ) );
		return array(
			'ok'          => $ok,
			'consignment' => ( new ConsignmentRepository() )->find( $id ),
		);
	}
}
