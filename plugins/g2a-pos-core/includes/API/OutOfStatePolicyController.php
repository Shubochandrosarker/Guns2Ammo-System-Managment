<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\State\OutOfStatePolicy;
use WP_REST_Request;

final class OutOfStatePolicyController {

	public static function get( WP_REST_Request $request ) {
		return array(
			'global'      => OutOfStatePolicy::mode_for( null ),
			'by_location' => (array) get_option( 'g2a_pos_out_of_state_policy_by_location', array() ),
			'modes'       => OutOfStatePolicy::MODES,
			'default'     => OutOfStatePolicy::DEFAULT_MODE,
		);
	}

	public static function set_global( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$mode = (string) ( $body['mode'] ?? '' );
		if ( ! OutOfStatePolicy::set_global( $mode ) ) {
			return new \WP_Error(
				'invalid_mode',
				'mode must be one of: ' . implode( ', ', OutOfStatePolicy::MODES ),
				array( 'status' => 400 )
			);
		}
		return array( 'global' => OutOfStatePolicy::mode_for( null ) );
	}

	public static function set_location( WP_REST_Request $request ) {
		$body        = $request->get_json_params() ?: array();
		$location_id = (int) ( $body['location_id'] ?? 0 );
		if ( $location_id <= 0 ) {
			return new \WP_Error( 'invalid_location', 'location_id required (>0)', array( 'status' => 400 ) );
		}
		$mode = isset( $body['mode'] ) ? (string) $body['mode'] : null;
		if ( $mode !== null && $mode !== '' && ! in_array( $mode, OutOfStatePolicy::MODES, true ) ) {
			return new \WP_Error(
				'invalid_mode',
				'mode must be one of: ' . implode( ', ', OutOfStatePolicy::MODES ),
				array( 'status' => 400 )
			);
		}
		OutOfStatePolicy::set_for_location( $location_id, $mode === '' ? null : $mode );
		return self::get( $request );
	}

	public static function evaluate( WP_REST_Request $request ) {
		$cs  = (string) $request->get_param( 'customer_state' );
		$ss  = (string) $request->get_param( 'store_state' );
		$loc = $request->get_param( 'location_id' );
		return OutOfStatePolicy::evaluate(
			$cs,
			$ss,
			array(
				'location_id' => $loc !== null ? (int) $loc : null,
			)
		);
	}

	public static function override( WP_REST_Request $request ) {
		if ( ! current_user_can( 'g2a_pos_manage_compliance' ) && ! current_user_can( 'g2a_pos_manage_settings' ) ) {
			return new \WP_Error( 'forbidden', 'Override requires g2a_pos_manage_compliance.', array( 'status' => 403 ) );
		}
		$body   = $request->get_json_params() ?: array();
		$cs     = (string) ( $body['customer_state'] ?? '' );
		$ss     = (string) ( $body['store_state'] ?? '' );
		$reason = (string) ( $body['reason'] ?? '' );
		if ( $cs === '' || $ss === '' || $reason === '' ) {
			return new \WP_Error(
				'invalid_input',
				'customer_state, store_state, and reason are required',
				array( 'status' => 400 )
			);
		}
		$order_id = isset( $body['order_id'] ) ? (int) $body['order_id'] : null;
		OutOfStatePolicy::record_override( get_current_user_id(), $cs, $ss, $order_id, $reason );
		return array(
			'ok'          => true,
			'recorded_at' => current_time( 'mysql' ),
		);
	}
}
