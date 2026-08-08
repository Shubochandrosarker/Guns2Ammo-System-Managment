<?php

namespace G2A\POS\API;

use G2A\POS\Database\NfaRepository;
use WP_REST_Request;

final class NfaController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new NfaRepository() )->list_items(
				array(
					'nfa_class' => $request->get_param( 'nfa_class' ),
					'status'    => $request->get_param( 'status' ),
				)
			),
		);
	}

	public static function create_item( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['serial_number'] ) ) {
			return new \WP_Error( 'invalid_input', 'serial_number required', array( 'status' => 400 ) );
		}
		$id = ( new NfaRepository() )->create_item( $body );
		return array(
			'id'   => $id,
			'item' => ( new NfaRepository() )->find_item( $id ),
		);
	}

	public static function get_item( WP_REST_Request $request ) {
		$row = ( new NfaRepository() )->find_item( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'NFA item not found', array( 'status' => 404 ) );
		}
		return array( 'item' => $row );
	}

	public static function file_form( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['form_type'] ) ) {
			return new \WP_Error( 'invalid_input', 'form_type required (Form 3/4/5)', array( 'status' => 400 ) );
		}
		$form_id = ( new NfaRepository() )->file_form( $id, $body );
		return array(
			'form_id' => $form_id,
			'item'    => ( new NfaRepository() )->find_item( $id ),
		);
	}

	public static function update_form( WP_REST_Request $request ) {
		$form_id = (int) $request['form_id'];
		$body    = $request->get_json_params() ?: array();
		( new NfaRepository() )->update_form( $form_id, $body );
		return array( 'ok' => true );
	}

	public static function trusts( WP_REST_Request $request ) {
		return array( 'items' => ( new NfaRepository() )->list_trusts() );
	}

	public static function create_trust( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['trust_name'] ) || empty( $body['grantor_name'] ) ) {
			return new \WP_Error( 'invalid_input', 'trust_name and grantor_name required', array( 'status' => 400 ) );
		}
		$id = ( new NfaRepository() )->create_trust( $body );
		return array( 'id' => $id );
	}
}
