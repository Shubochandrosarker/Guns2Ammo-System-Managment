<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\State\EligibilityCheck;
use G2A\POS\Compliance\State\StateRules;
use G2A\POS\Database\StateRulesRepository;
use WP_REST_Request;

final class StateRulesController {

	public static function index( WP_REST_Request $request ) {
		return array( 'rules' => StateRules::all() );
	}

	public static function get( WP_REST_Request $request ) {
		$code  = strtoupper( (string) $request->get_param( 'state' ) );
		$rules = StateRules::for( $code );
		if ( ! $rules ) {
			return new \WP_Error( 'not_found', 'State rules not found.', array( 'status' => 404 ) ); }
		return array( 'rules' => $rules );
	}

	public static function update( WP_REST_Request $request ) {
		$code    = strtoupper( (string) $request->get_param( 'state' ) );
		$payload = $request->get_json_params() ?: array();
		unset( $payload['state_code'] );
		$repo = new StateRulesRepository();
		$ok   = $repo->update( $code, $payload );
		StateRules::invalidate();
		if ( ! $ok ) {
			return new \WP_Error( 'update_failed', 'Update failed.', array( 'status' => 500 ) ); }
		return array( 'rules' => StateRules::for( $code ) );
	}

	public static function evaluate( WP_REST_Request $request ) {
		$payload    = $request->get_json_params() ?: array();
		$transferee = (array) ( $payload['transferee'] ?? array() );
		$firearms   = (array) ( $payload['firearms'] ?? array() );
		$context    = (array) ( $payload['context'] ?? array() );
		return EligibilityCheck::evaluate( $transferee, $firearms, $context );
	}
}
