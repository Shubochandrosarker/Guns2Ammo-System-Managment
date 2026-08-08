<?php

namespace G2A\POS\API;

use G2A\POS\Integrations\RangeMembership;
use WP_REST_Request;

final class MembershipController {

	public static function providers( WP_REST_Request $req ) {
		$active    = RangeMembership::active_provider();
		$providers = array();
		foreach ( RangeMembership::providers() as $slug => $p ) {
			$providers[] = array(
				'slug'     => $slug,
				'label'    => $p->label(),
				'detected' => $p->detect(),
				'active'   => $active && $active->slug() === $slug,
			);
		}
		return array(
			'providers' => $providers,
			'pinned'    => (string) get_option( 'g2a_pos_membership_provider', '' ),
			'meta_map'  => (array) get_option( 'g2a_pos_membership_meta_map', array() ),
		);
	}

	public static function configure( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		if ( isset( $payload['provider'] ) ) {
			$slug = sanitize_key( (string) $payload['provider'] );
			update_option( 'g2a_pos_membership_provider', $slug );
		}
		if ( isset( $payload['meta_map'] ) && is_array( $payload['meta_map'] ) ) {
			$clean = array();
			foreach ( array( 'active_key', 'plan_key', 'expires_key', 'member_id_key' ) as $k ) {
				$clean[ $k ] = isset( $payload['meta_map'][ $k ] ) ? sanitize_text_field( (string) $payload['meta_map'][ $k ] ) : '';
			}
			update_option( 'g2a_pos_membership_meta_map', $clean );
		}
		return self::providers( $req );
	}

	public static function lookup( WP_REST_Request $req ) {
		$customer_id = (int) $req->get_param( 'customer_id' );
		if ( $customer_id <= 0 ) {
			return new \WP_Error( 'missing', 'customer_id required.', array( 'status' => 400 ) );
		}
		return array(
			'membership' => RangeMembership::status_for_customer( $customer_id ),
			'bookings'   => RangeMembership::bookings_for_customer( $customer_id ),
		);
	}
}
