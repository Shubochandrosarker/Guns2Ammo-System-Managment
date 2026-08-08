<?php

namespace G2A\POS\API;

use G2A\POS\Database\RepairTicketRepository;
use WP_REST_Request;

final class RepairController {

	public static function index( WP_REST_Request $request ) {
		$filters = array(
			'status' => $request->get_param( 'status' ),
			'q'      => $request->get_param( 'q' ),
		);
		$limit   = (int) ( $request->get_param( 'limit' ) ?: 50 );
		return array( 'items' => ( new RepairTicketRepository() )->list( $filters, $limit ) );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['customer_name'] ) || empty( $body['problem_description'] ) ) {
			return new \WP_Error( 'invalid_input', 'customer_name and problem_description required', array( 'status' => 400 ) );
		}
		$id = ( new RepairTicketRepository() )->create( $body );
		return array( 'id' => $id );
	}

	public static function get( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$row = ( new RepairTicketRepository() )->find( $id );
		if ( ! $row ) {
			return new \WP_Error( 'not_found', 'Ticket not found', array( 'status' => 404 ) );
		}
		return array( 'ticket' => $row );
	}

	public static function update( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		( new RepairTicketRepository() )->update( $id, $body );
		return array( 'ticket' => ( new RepairTicketRepository() )->find( $id ) );
	}

	public static function add_line( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$body    = $request->get_json_params() ?: array();
		$line_id = ( new RepairTicketRepository() )->add_line( $id, $body );
		return array(
			'line_id' => $line_id,
			'ticket'  => ( new RepairTicketRepository() )->find( $id ),
		);
	}
}
