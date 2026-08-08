<?php

namespace G2A\POS\API;

use G2A\POS\Database\LaneReservationRepository;
use G2A\POS\Database\MembershipRepository;
use G2A\POS\Integrations\BookingEngineSync;
use WP_REST_Request;

final class MembershipBillingController {

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new MembershipRepository() )->list_active( 200 ) );
	}

	public static function create( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$id   = ( new MembershipRepository() )->create( $body );

		if ( $id > 0 ) {
			/**
			 * Fires after a membership is sold at the POS counter.
			 *
			 * Lets other plugins (e.g. Memberistic) mirror the sale so it isn't
			 * invisible to the member portal, waiver tracking, QR verification,
			 * and corporate-group tooling — previously nothing listened for this.
			 *
			 * @param int   $id   The new g2a_memberships row id.
			 * @param array $body Raw request payload passed to MembershipRepository::create().
			 */
			do_action( 'g2a_pos_membership_sold', $id, $body );
		}

		return array( 'id' => $id );
	}

	public static function cancel( WP_REST_Request $request ) {
		$ok = ( new MembershipRepository() )->cancel( (int) $request['id'] );
		return array( 'ok' => $ok );
	}

	public static function lanes_for_day( WP_REST_Request $request ) {
		$date     = sanitize_text_field( (string) $request->get_param( 'date' ) ) ?: date( 'Y-m-d' );
		$location = (int) $request->get_param( 'location_id' );

		// Read-only merge: g2a-booking-engine bookings for the same day, when
		// that plugin is installed, so the cashier sees web bookings alongside
		// POS-native reservations.
		$engine = array();
		try {
			$engine = BookingEngineSync::bookings_for_day( $date );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}

		return array(
			'date'            => $date,
			'reservations'    => ( new LaneReservationRepository() )->list_for_day( $date, $location ),
			'engine_active'   => BookingEngineSync::is_active(),
			'engine_bookings' => $engine,
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
