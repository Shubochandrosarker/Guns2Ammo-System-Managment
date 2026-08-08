<?php
/**
 * GET /analytics/{bookings|memberships|woocommerce|waivers} — the Phase-D
 * per-module detail endpoints.
 *
 * Same contract as /dashboard/overview: canonical Response_Envelope,
 * integer USD cents, ISO-8601 UTC datetimes, read-capability gate, ranges
 * validated + bounded at 366 days, per-provider transient cache (distinct
 * `-detail` keys), and the honest `available: false` payload when a source
 * plugin is missing.
 *
 * /analytics/bookings and /analytics/memberships supersede the pre-0.4.0
 * bare-payload routes that used to live in Analytics_Controller;
 * /analytics/overview (legacy) is left untouched.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Waiver_Summary_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Woo_Analytics_Provider;
use WordPressistic\G2ABA\Response_Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analytics_Detail_Controller extends REST_Controller {
	public function register_routes(): void {
		$common_args = array(
			'from' => array( 'type' => 'string' ),
			'to'   => array( 'type' => 'string' ),
		);

		foreach (
			array(
				'bookings'    => 'bookings',
				'memberships' => 'memberships',
				'woocommerce' => 'woocommerce',
				'waivers'     => 'waivers',
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

	public function bookings( \WP_REST_Request $req ) {
		return $this->detail_response( $req, new Booking_Analytics_Provider() );
	}

	public function memberships( \WP_REST_Request $req ) {
		return $this->detail_response( $req, new Membership_Analytics_Provider() );
	}

	public function woocommerce( \WP_REST_Request $req ) {
		return $this->detail_response( $req, new Woo_Analytics_Provider() );
	}

	public function waivers( \WP_REST_Request $req ) {
		// Waiver detail is point-in-time (buckets + fixed 12-month series);
		// from/to are still accepted/validated so the envelope's range block
		// and cache keys behave exactly like the other detail routes.
		return $this->detail_response( $req, new Waiver_Summary_Provider() );
	}

	/**
	 * Shared transport: parse+bound the range, run the provider's cached
	 * detail build, wrap in the canonical envelope.
	 */
	private function detail_response( \WP_REST_Request $req, Analytics_Provider_Base $provider ) {
		$parsed = Dashboard_Controller::parse_range(
			(string) ( $req->get_param( 'from' ) ?? '' ),
			(string) ( $req->get_param( 'to' ) ?? '' )
		);
		if ( ! $parsed['ok'] ) {
			return Response_Envelope::error_response( $parsed['code'], $parsed['message'], 400 );
		}

		$detail    = $provider->cached_detail( $parsed['range'] );
		$available = (bool) ( $detail['available'] ?? false );

		// Frontend contract: unavailability is signalled through
		// meta.freshness.status === 'unavailable' (+ empty meta.source), the
		// same mechanism /dashboard/overview uses — no separate
		// data.available boolean on these four routes.
		unset( $detail['available'] );

		return $this->ok(
			Response_Envelope::success(
				$detail,
				$available ? array( $provider->source_slug() ) : array(),
				$provider->freshness()
			)
		);
	}
}
