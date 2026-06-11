<?php

namespace G2A\POS\API;

use G2A\POS\Database\HardwareProfileRepository;
use G2A\POS\Hardware\CustomerDisplay;
use G2A\POS\Hardware\EscPosPrinter;
use WP_REST_Request;

final class HardwareController {

	public static function profiles( WP_REST_Request $request ) {
		return array( 'items' => ( new HardwareProfileRepository() )->list() );
	}

	public static function upsert_profile( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['register_code'] ) ) {
			return new \WP_Error( 'invalid_input', 'register_code required', array( 'status' => 400 ) );
		}
		$id = ( new HardwareProfileRepository() )->upsert( $body );
		return array( 'id' => $id );
	}

	public static function get_profile( WP_REST_Request $request ) {
		$code = (string) $request->get_param( 'register_code' );
		$loc  = (int) $request->get_param( 'location_id' );
		$row  = ( new HardwareProfileRepository() )->find( $code, $loc );
		return array( 'profile' => $row );
	}

	/** Returns the ESC/POS byte stream as base64 so the in-store driver
	 *  can pipe it straight to the printer over TCP:9100 or a print server. */
	public static function render_receipt( WP_REST_Request $request ) {
		$body  = $request->get_json_params() ?: array();
		$order = (array) ( $body['order'] ?? array() );
		$store = (array) ( $body['store'] ?? array() );
		$bytes = EscPosPrinter::render_receipt( $order, $store );
		return array(
			'format'       => 'escpos',
			'bytes_base64' => base64_encode( $bytes ),
			'length'       => strlen( $bytes ),
		);
	}

	public static function kick_drawer( WP_REST_Request $request ) {
		$bytes = ( new EscPosPrinter() )->kick_drawer()->bytes();
		return array(
			'format'       => 'escpos',
			'bytes_base64' => base64_encode( $bytes ),
		);
	}

	public static function customer_display( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		return CustomerDisplay::build(
			(array) ( $body['cart'] ?? array() ),
			isset( $body['welcome'] ) ? (string) $body['welcome'] : null
		);
	}
}
