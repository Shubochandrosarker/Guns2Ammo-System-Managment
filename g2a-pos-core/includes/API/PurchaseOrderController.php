<?php

namespace G2A\POS\API;

use G2A\POS\Database\PurchaseOrderRepository;
use WP_REST_Request;

final class PurchaseOrderController {

	public static function index( WP_REST_Request $request ) {
		return array(
			'items' => ( new PurchaseOrderRepository() )->list(
				array(
					'status' => $request->get_param( 'status' ),
				)
			),
		);
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['items'] ) ) {
			return new \WP_Error( 'invalid_input', 'items required', array( 'status' => 400 ) );
		}
		$id = ( new PurchaseOrderRepository() )->create( $body );
		return array(
			'id' => $id,
			'po' => ( new PurchaseOrderRepository() )->find( $id ),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$row = ( new PurchaseOrderRepository() )->find( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'PO not found', array( 'status' => 404 ) );
		}
		return array( 'po' => $row );
	}

	public static function submit( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		( new PurchaseOrderRepository() )->submit( $id, $body['external_ref'] ?? null );
		return array( 'po' => ( new PurchaseOrderRepository() )->find( $id ) );
	}

	public static function receive( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$body    = $request->get_json_params() ?: array();
		$item_id = (int) ( $body['po_item_id'] ?? 0 );
		$qty     = (float) ( $body['quantity'] ?? 0 );
		if ( $item_id <= 0 || $qty <= 0 ) {
			return new \WP_Error( 'invalid_input', 'po_item_id and quantity > 0 required', array( 'status' => 400 ) );
		}
		$serials = $body['serial_numbers'] ?? array();
		$result  = ( new PurchaseOrderRepository() )->receive( $id, $item_id, $qty, is_array( $serials ) ? $serials : array() );
		return array(
			'receipt' => $result,
			'po'      => ( new PurchaseOrderRepository() )->find( $id ),
		);
	}
}
