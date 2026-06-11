<?php

namespace G2A\POS\API;

use G2A\POS\Database\LaneReservationRepository;
use G2A\POS\Database\MembershipRepository;
use WP_REST_Request;

final class MembershipBillingController {

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new MembershipRepository() )->list_active( 200 ) );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$id   = ( new MembershipRepository() )->create( $body );
		return array( 'id' => $id );
	}

	public static function cancel( WP_REST_Request $request ) {
		$ok = ( new MembershipRepository() )->cancel( (int) $request['id'] );
		return array( 'ok' => $ok );
	}

	public static function lanes_for_day( WP_REST_Request $request ) {
		$date     = sanitize_text_field( (string) $request->get_param( 'date' ) ) ?: date( 'Y-m-d' );
		$location = (int) $request->get_param( 'location_id' );
		return array(
			'date'         => $date,
			'reservations' => ( new LaneReservationRepository() )->list_for_day( $date, $location ),
		);
	}

	public static function reserve_lane( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: array();
		$result = ( new LaneReservationRepository() )->reserve( $body );
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['error'], $result['error'], array( 'status' => 409 ) );
		}
		return $result;
	}

	public static function lane_status( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		$ok   = ( new LaneReservationRepository() )->update_status( $id, (string) ( $body['status'] ?? '' ) );
		return array( 'ok' => $ok );
	}
}
