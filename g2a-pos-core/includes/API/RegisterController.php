<?php

namespace G2A\POS\API;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\RegisterSessionRepository;
use WP_REST_Request;

final class RegisterController {

	public static function current( WP_REST_Request $request ) {
		$register_code = sanitize_text_field( (string) $request->get_param( 'register_code' ) );
		$location_id   = (int) $request->get_param( 'location_id' );

		if ( $register_code === '' || $location_id <= 0 ) {
			return new \WP_Error( 'invalid_input', 'register_code and location_id are required.', array( 'status' => 400 ) );
		}

		$session = ( new RegisterSessionRepository() )->get_open_session( $register_code, $location_id );
		return array( 'session' => $session );
	}

	public static function open_list( WP_REST_Request $request ) {
		$location_id = (int) $request->get_param( 'location_id' );
		$items       = ( new RegisterSessionRepository() )->list_open_sessions( $location_id );

		return array( 'items' => $items );
	}

	public static function open( WP_REST_Request $request ) {
		$register_code = sanitize_text_field( (string) $request->get_param( 'register_code' ) );
		$location_id   = (int) $request->get_param( 'location_id' );
		$opening_cash  = (float) $request->get_param( 'opening_cash' );

		if ( $register_code === '' || $location_id <= 0 ) {
			return new \WP_Error( 'invalid_input', 'register_code and location_id are required.', array( 'status' => 400 ) );
		}

		$id = ( new RegisterSessionRepository() )->open( $register_code, $location_id, $opening_cash );
		( new AuditLogRepository() )->add( 'register.open', 'register_session', (string) $id, null, array( 'opening_cash' => $opening_cash ) );

		return array( 'register_session_id' => $id );
	}

	public static function close( WP_REST_Request $request ) {
		$session_id   = (int) $request->get_param( 'register_session_id' );
		$closing_cash = (float) $request->get_param( 'closing_cash' );
		$notes        = sanitize_text_field( (string) $request->get_param( 'notes' ) );

		if ( $session_id <= 0 ) {
			return new \WP_Error( 'invalid_input', 'register_session_id is required.', array( 'status' => 400 ) );
		}

		$actor_id    = get_current_user_id();
		$force_close = current_user_can( 'g2a_pos_manage_settings' ) || current_user_can( 'manage_options' );
		$ok          = ( new RegisterSessionRepository() )->close( $session_id, $closing_cash, $actor_id, $force_close, $notes ?: null );
		if ( ! $ok ) {
			return new \WP_Error( 'close_failed', 'Unable to close register session (ownership or state mismatch).', array( 'status' => 422 ) );
		}

		( new AuditLogRepository() )->add( 'register.close', 'register_session', (string) $session_id, null, array( 'closing_cash' => $closing_cash ) );

		return array(
			'register_session_id' => $session_id,
			'status'              => 'closed',
		);
	}
}
