<?php
/**
 * GET /analytics/*
 *
 * One controller for the five analytics endpoints the dashboard uses. Each
 * route delegates to a provider so the transport code stays tiny.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Cache;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Insightistic_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analytics_Controller extends REST_Controller {
	public function register_routes(): void {
		$common_args = array(
			'from' => array( 'type' => 'string' ),
			'to'   => array( 'type' => 'string' ),
		);

		foreach (
			array(
				'overview'     => 'overview',
				'bookings'     => 'bookings',
				'memberships'  => 'memberships',
				'store'        => 'store',
				'seo'          => 'seo',
				'insightistic' => 'insightistic',
			) as $slug => $method
		) {
			register_rest_route(
				$this->namespace,
				'/analytics/' . $slug,
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, $method ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
					'args'                => $common_args,
				)
			);
		}
	}

	public function overview( \WP_REST_Request $req ) {
		$range = Range::from_request( $req );
		$data  = Cache::remember(
			'overview_' . $range->from . '_' . $range->to,
			60,
			static function () use ( $range ) {
				return ( new Revenue_Provider() )->overview( $range );
			}
		);
		return $this->ok( $data );
	}

	public function bookings( \WP_REST_Request $req ) {
		$range = Range::from_request( $req );
		$data  = Cache::remember(
			'bookings_' . $range->from . '_' . $range->to,
			60,
			static function () use ( $range ) {
				return ( new Booking_Provider() )->analytics( $range );
			}
		);
		return $this->ok( $data );
	}

	public function memberships( \WP_REST_Request $req ) {
		$range = Range::from_request( $req );
		$data  = Cache::remember(
			'memberships_' . $range->from . '_' . $range->to,
			60,
			static function () use ( $range ) {
				return ( new Membership_Provider() )->analytics( $range );
			}
		);
		return $this->ok( $data );
	}

	public function store( \WP_REST_Request $req ) {
		$range = Range::from_request( $req );
		$data  = Cache::remember(
			'store_' . $range->from . '_' . $range->to,
			60,
			static function () use ( $range ) {
				return ( new Store_Provider() )->analytics( $range );
			}
		);
		return $this->ok( $data );
	}

	public function seo( \WP_REST_Request $req ) {
		$range = Range::from_request( $req );
		$data  = Cache::remember(
			'seo_' . $range->from . '_' . $range->to,
			300,
			static function () use ( $range ) {
				return ( new SEO_Provider() )->analytics( $range );
			}
		);
		return $this->ok( $data );
	}

	public function insightistic( \WP_REST_Request $req ) {
		$range = Range::from_request( $req );
		$data  = Cache::remember(
			'insightistic_' . $range->from . '_' . $range->to,
			300,
			static function () use ( $range ) {
				return ( new Insightistic_Provider() )->analytics( $range );
			}
		);
		return $this->ok( $data );
	}
}
