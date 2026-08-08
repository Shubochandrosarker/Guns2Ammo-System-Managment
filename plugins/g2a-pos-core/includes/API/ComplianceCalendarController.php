<?php

namespace G2A\POS\API;

use G2A\POS\Database\ComplianceCalendarRepository;
use WP_REST_Request;

final class ComplianceCalendarController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new ComplianceCalendarRepository() )->list(
				array(
					'status' => $request->get_param( 'status' ),
				)
			),
		);
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['title'] ) || empty( $body['due_at'] ) ) {
			return new \WP_Error( 'invalid_input', 'title and due_at required', array( 'status' => 400 ) );
		}
		$id = ( new ComplianceCalendarRepository() )->create( $body );
		return array( 'id' => $id );
	}

	public static function complete( WP_REST_Request $request ) {
		$ok = ( new ComplianceCalendarRepository() )->complete( (int) $request['id'] );
		return array( 'ok' => $ok );
	}
}
