<?php
/**
 * GET /insights/business-gaps
 *
 * The gap engine cross-references analytics to surface concrete leaks.
 * Right now it evaluates a small handful of rules the client already asked
 * for (renewal rate < 65%, CCW conversion < 8%). New rules should be added
 * one at a time with a comment linking to the business justification.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Gaps_Provider;
use WordPressistic\G2ABA\Providers\GBP_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gaps_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/insights/business-gaps',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );

		$bookings    = ( new Booking_Provider() )->analytics( $range );
		$memberships = ( new Membership_Provider() )->analytics( $range );
		$store       = ( new Store_Provider() )->analytics( $range );
		$seo         = ( new SEO_Provider() )->analytics( $range );
		$gbp         = ( new GBP_Provider() )->performance( $range );

		$gaps = ( new Gaps_Provider() )->detect( $bookings, $memberships, $store, $seo, $gbp );
		return $this->ok( $gaps );
	}
}
