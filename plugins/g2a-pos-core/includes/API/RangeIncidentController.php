<?php

namespace G2A\POS\API;

use G2A\POS\Database\RangeIncidentRepository;
use G2A\POS\Range\IncidentService;
use WP_REST_Request;

final class RangeIncidentController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new RangeIncidentRepository() )->list(
				array(
					'severity' => $request->get_param( 'severity' ),
					'status'   => $request->get_param( 'status' ),
					'q'        => $request->get_param( 'q' ),
					'limit'    => (int) ( $request->get_param( 'limit' ) ?: 100 ),
				)
			),
		);
	}

	public static function severities( WP_REST_Request $request ) {
		return array( 'severities' => RangeIncidentRepository::SEVERITY );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['description'] ) ) {
			return new \WP_Error( 'invalid_input', 'description required', array( 'status' => 400 ) );
		}
		return IncidentService::report( $body );
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new RangeIncidentRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Incident not found', array( 'status' => 404 ) );
		}
		return array( 'incident' => $row );
	}

	public static function close( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		( new RangeIncidentRepository() )->close( $id );
		return array( 'incident' => ( new RangeIncidentRepository() )->find( $id ) );
	}

	public static function customer_history( WP_REST_Request $request ) {
		$cid = (int) $request['customer_id'];
		return array( 'items' => ( new RangeIncidentRepository() )->customer_flag_history( $cid ) );
	}
}
