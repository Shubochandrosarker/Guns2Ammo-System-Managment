<?php

namespace G2A\POS\API;

use G2A\POS\Database\CustomerProfileRepository;
use G2A\POS\Database\CustomerRepository;
use G2A\POS\Database\LoyaltyRepository;
use WP_REST_Request;

final class CrmController {

	public static function search( WP_REST_Request $request ) {
		$q = sanitize_text_field( (string) $request->get_param( 'q' ) );
		return array( 'items' => ( new CustomerRepository() )->search( $q, 25 ) );
	}

	public static function profile( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( $id <= 0 ) {
			return new \WP_Error( 'invalid_id', 'Customer ID required', array( 'status' => 400 ) );
		}
		$user = get_userdata( $id );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', 'Customer not found', array( 'status' => 404 ) );
		}
		$profile_repo = new CustomerProfileRepository();
		$profile      = $profile_repo->find( $id ) ?? $profile_repo->recompute_lifetime( $id );
		$loyalty      = ( new LoyaltyRepository() )->balance( $id );

		return array(
			'id'      => $id,
			'name'    => $user->display_name,
			'email'   => $user->user_email,
			'phone'   => (string) get_user_meta( $id, 'billing_phone', true ),
			'address' => array(
				'address_1' => (string) get_user_meta( $id, 'billing_address_1', true ),
				'city'      => (string) get_user_meta( $id, 'billing_city', true ),
				'state'     => (string) get_user_meta( $id, 'billing_state', true ),
				'postcode'  => (string) get_user_meta( $id, 'billing_postcode', true ),
			),
			'profile' => $profile,
			'loyalty' => $loyalty,
			'history' => $profile_repo->purchase_history( $id, 25 ),
		);
	}

	public static function update_profile( WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$body    = $request->get_json_params() ?: array();
		$repo    = new CustomerProfileRepository();
		$profile = $repo->upsert( $id, $body );
		return array( 'profile' => $profile );
	}

	public static function recompute( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return array( 'profile' => ( new CustomerProfileRepository() )->recompute_lifetime( $id ) );
	}
}
