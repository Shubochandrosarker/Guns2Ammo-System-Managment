<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\Form4473;
use G2A\POS\Database\Form4473Repository;
use WP_REST_Request;

final class Form4473Controller {

	public static function index( WP_REST_Request $request ) {
		$items = ( new Form4473Repository() )->list(
			array(
				'transaction_status' => $request->get_param( 'transaction_status' ),
				'q'                  => $request->get_param( 'q' ),
				'limit'              => (int) ( $request->get_param( 'limit' ) ?: 50 ),
			)
		);
		return array( 'items' => $items );
	}

	public static function start( WP_REST_Request $request ) {
		$payload = $request->get_json_params() ?: array();
		$result  = Form4473::start( $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error(
				'form4473_start',
				$result['error'],
				array(
					'status'      => 422,
					'eligibility' => $result['eligibility'] ?? null,
				)
			);
		}
		return $result;
	}

	public static function get( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$repo = new Form4473Repository();
		$form = $repo->find( $id );
		if ( ! $form ) {
			return new \WP_Error( 'not_found', '4473 not found.', array( 'status' => 404 ) ); }
		return array( 'form' => $form );
	}

	public static function sign_transferee( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = Form4473::sign_transferee( $id );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'form4473_sign', $result['error'], array( 'status' => 422 ) ); }
		return $result;
	}

	public static function sign_transferor( WP_REST_Request $request ) {
		$id     = (int) $request->get_param( 'id' );
		$result = Form4473::sign_transferor( $id );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'form4473_sign_transferor', $result['error'], array( 'status' => 422 ) ); }
		return $result;
	}

	public static function transfer( WP_REST_Request $request ) {
		$id      = (int) $request->get_param( 'id' );
		$payload = $request->get_json_params() ?: array();
		$result  = Form4473::complete_transfer( $id, $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'form4473_transfer', $result['error'], array( 'status' => 422 ) ); }
		return $result;
	}
}
