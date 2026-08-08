<?php

namespace G2A\POS\API;

use G2A\POS\Database\ClassRepository;
use WP_REST_Request;

final class ClassController {

	public static function classes( WP_REST_Request $request ) {
		return array( 'classes' => ( new ClassRepository() )->list_classes() );
	}

	public static function upsert_class( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$id   = ( new ClassRepository() )->upsert_class( $body );
		return array( 'id' => $id );
	}

	public static function sessions( WP_REST_Request $request ) {
		return array(
			'sessions' => ( new ClassRepository() )->list_sessions(
				array(
					'from' => $request->get_param( 'from' ),
					'to'   => $request->get_param( 'to' ),
				)
			),
		);
	}

	public static function create_session( WP_REST_Request $request ) {
		$body     = $request->get_json_params() ?: array();
		$class_id = (int) ( $body['class_id'] ?? 0 );
		if ( $class_id <= 0 || empty( $body['starts_at'] ) || empty( $body['ends_at'] ) ) {
			return new \WP_Error( 'invalid_input', 'class_id, starts_at, ends_at required', array( 'status' => 400 ) );
		}
		$id = ( new ClassRepository() )->schedule_session( $class_id, $body );
		return array( 'id' => $id );
	}

	public static function enroll( WP_REST_Request $request ) {
		$session_id = (int) $request['id'];
		$body       = $request->get_json_params() ?: array();
		$result     = ( new ClassRepository() )->enroll( $session_id, $body );
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['error'], $result['error'], array( 'status' => 409 ) );
		}
		return $result;
	}

	public static function roster( WP_REST_Request $request ) {
		return array( 'enrollments' => ( new ClassRepository() )->list_enrollments( (int) $request['id'] ) );
	}
}
