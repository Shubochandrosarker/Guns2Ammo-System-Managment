<?php

namespace G2A\POS\API;

use G2A\POS\Database\AmmoRentalRepository;
use G2A\POS\Database\BrassBuybackRepository;
use G2A\POS\Database\FirearmRentalRepository;
use G2A\POS\Database\RsoAssignmentRepository;
use WP_REST_Request;

final class RangeOpsController {

	// RSO -------------------------------------------------------------
	public static function rso_index( WP_REST_Request $request ) {
		$date = (string) ( $request->get_param( 'date' ) ?: date( 'Y-m-d' ) );
		return array(
			'date'   => $date,
			'shifts' => ( new RsoAssignmentRepository() )->list_for_day( $date ),
		);
	}

	public static function rso_assign( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['user_id'] ) || empty( $body['shift_start'] ) || empty( $body['shift_end'] ) ) {
			return new \WP_Error( 'invalid_input', 'user_id, shift_start, shift_end required', array( 'status' => 400 ) );
		}
		$id = ( new RsoAssignmentRepository() )->assign( $body );
		return array( 'id' => $id );
	}

	public static function rso_status( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		$ok   = ( new RsoAssignmentRepository() )->update_status( $id, (string) ( $body['status'] ?? '' ) );
		return array( 'ok' => $ok );
	}

	public static function rso_on_duty( WP_REST_Request $request ) {
		return array( 'shifts' => ( new RsoAssignmentRepository() )->on_duty( (int) $request->get_param( 'location_id' ) ) );
	}

	// Ammo rental ------------------------------------------------------
	public static function ammo_open( WP_REST_Request $request ) {
		return array( 'items' => ( new AmmoRentalRepository() )->list_open() );
	}

	public static function ammo_issue( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['customer_name'] ) || empty( $body['caliber'] ) || empty( $body['rounds_issued'] ) ) {
			return new \WP_Error(
				'invalid_input',
				'customer_name, caliber, rounds_issued required',
				array( 'status' => 400 )
			);
		}
		$id = ( new AmmoRentalRepository() )->issue( $body );
		return array( 'id' => $id );
	}

	public static function ammo_close( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		$ok   = ( new AmmoRentalRepository() )->close(
			$id,
			(int) ( $body['rounds_returned'] ?? 0 ),
			isset( $body['pos_order_id'] ) ? (int) $body['pos_order_id'] : null
		);
		return array( 'ok' => $ok );
	}

	// Firearm rental ---------------------------------------------------
	public static function firearm_open( WP_REST_Request $request ) {
		$repo = new FirearmRentalRepository();

		// Roll any past-due rentals over before listing, so the outstanding
		// view is honest the moment it's opened rather than waiting for a cron.
		$repo->mark_overdue();

		return array( 'items' => $repo->list_open() );
	}

	public static function firearm_recent( WP_REST_Request $request ) {
		$limit = (int) ( $request->get_param( 'limit' ) ?: 100 );
		return array( 'items' => ( new FirearmRentalRepository() )->recent( $limit ) );
	}

	public static function firearm_issue( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();

		// The repository owns validation (including the ID and waiver checks)
		// so the same rules apply to any other caller, not just this route.
		$result = ( new FirearmRentalRepository() )->issue( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'id' => $result );
	}

	public static function firearm_return( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$body   = $request->get_json_params() ?: array();
		$result = ( new FirearmRentalRepository() )->return_firearm( $id, $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'ok' => true );
	}

	// Brass buyback ----------------------------------------------------
	public static function brass_list( WP_REST_Request $request ) {
		return array( 'items' => ( new BrassBuybackRepository() )->list() );
	}

	public static function brass_record( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['caliber'] ) || ! isset( $body['payout'] ) ) {
			return new \WP_Error( 'invalid_input', 'caliber and payout required', array( 'status' => 400 ) );
		}
		return ( new BrassBuybackRepository() )->record( $body );
	}
}
