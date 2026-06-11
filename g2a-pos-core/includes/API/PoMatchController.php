<?php

namespace G2A\POS\API;

use G2A\POS\Database\PoMatchRepository;
use WP_REST_Request;

final class PoMatchController {

	public static function record_asn( WP_REST_Request $request ) {
		$po_id = (int) $request['id'];
		$body  = $request->get_json_params() ?: array();
		$id    = ( new PoMatchRepository() )->record_asn( $po_id, $body );
		return array( 'id' => $id );
	}

	public static function record_invoice( WP_REST_Request $request ) {
		$po_id = (int) $request['id'];
		$body  = $request->get_json_params() ?: array();
		if ( empty( $body['invoice_number'] ) || ! isset( $body['invoice_total'] ) ) {
			return new \WP_Error(
				'invalid_input',
				'invoice_number and invoice_total required',
				array( 'status' => 400 )
			);
		}
		return ( new PoMatchRepository() )->record_invoice( $po_id, $body );
	}

	public static function bundle( WP_REST_Request $request ) {
		return ( new PoMatchRepository() )->po_bundle( (int) $request['id'] );
	}

	public static function backorders( WP_REST_Request $request ) {
		return array( 'items' => ( new PoMatchRepository() )->backorders() );
	}
}
