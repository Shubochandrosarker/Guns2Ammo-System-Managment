<?php

namespace G2A\POS\API;

use G2A\POS\Database\LaneMaintenanceRepository;
use WP_REST_Request;

final class LaneMaintenanceController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new LaneMaintenanceRepository() )->list(
				array(
					'status'    => $request->get_param( 'status' ),
					'lane_code' => $request->get_param( 'lane_code' ),
					'limit'     => (int) ( $request->get_param( 'limit' ) ?: 100 ),
				)
			),
		);
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['lane_code'] ) || empty( $body['ticket_type'] ) ) {
			return new \WP_Error( 'invalid_input', 'lane_code + ticket_type required', array( 'status' => 400 ) );
		}
		$id = ( new LaneMaintenanceRepository() )->create( $body );
		return array(
			'id'     => $id,
			'ticket' => ( new LaneMaintenanceRepository() )->find( $id ),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new LaneMaintenanceRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Ticket not found', array( 'status' => 404 ) );
		}
		return array( 'ticket' => $row );
	}

	public static function start( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		( new LaneMaintenanceRepository() )->start( $id );
		return array( 'ticket' => ( new LaneMaintenanceRepository() )->find( $id ) );
	}

	public static function close( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		( new LaneMaintenanceRepository() )->close(
			$id,
			isset( $body['labor_minutes'] ) ? (int) $body['labor_minutes'] : null,
			isset( $body['cost_amount'] ) ? (float) $body['cost_amount'] : null,
			isset( $body['parts_used'] ) ? (array) $body['parts_used'] : null,
			$body['notes'] ?? null
		);
		return array( 'ticket' => ( new LaneMaintenanceRepository() )->find( $id ) );
	}

	public static function lanes_out( WP_REST_Request $request ) {
		$loc = (int) $request->get_param( 'location_id' );
		return array( 'lanes_out_of_service' => ( new LaneMaintenanceRepository() )->lanes_out_of_service( $loc ) );
	}
}
