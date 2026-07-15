<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\REST\Analytics_Detail_Controller;
use WordPressistic\G2ABA\REST\Dashboard_Controller;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class AnalyticsDetailControllerTest extends TestCase {
	private Analytics_Fake_WPDB $wpdb;
	private Analytics_Detail_Controller $controller;

	protected function setUp(): void {
		$this->wpdb      = new Analytics_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		$GLOBALS['g2aba_test_options']           = array();
		$GLOBALS['g2aba_test_transients']        = array();
		$GLOBALS['g2aba_test_current_user_caps'] = array();
		$GLOBALS['g2aba_test_rest_routes']       = array();
		unset( $GLOBALS['g2aba_test_timezone_string'] );

		$this->controller = new Analytics_Detail_Controller();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['g2aba_test_current_user_caps'] = array();
	}

	private function request( array $params = array() ): \WP_REST_Request {
		return new \WP_REST_Request( $params );
	}

	// ---- route registration -----------------------------------------------------------

	public function test_registers_four_get_routes_with_read_gate() {
		$this->controller->register_routes();

		$routes = $GLOBALS['g2aba_test_rest_routes'];
		$this->assertSame(
			array( '/analytics/bookings', '/analytics/memberships', '/analytics/woocommerce', '/analytics/waivers' ),
			array_column( $routes, 'route' )
		);
		foreach ( $routes as $route ) {
			$this->assertSame( 'g2a/v1', $route['namespace'] );
			$this->assertSame( 'GET', $route['args']['methods'] );
			$this->assertSame(
				array( $this->controller, 'read_permissions_check' ),
				$route['args']['permission_callback'],
				'same read-capability gate as /dashboard/overview'
			);
		}
	}

	public function test_read_capability_gate() {
		$denied = $this->controller->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $denied );

		$GLOBALS['g2aba_test_current_user_caps'] = array( Capabilities::READ );
		$this->assertTrue( $this->controller->read_permissions_check() );
	}

	// ---- envelope + range validation ----------------------------------------------------

	public function test_detail_returns_400_envelope_on_bad_range() {
		foreach ( array( 'bookings', 'memberships', 'woocommerce', 'waivers' ) as $method ) {
			$res = $this->controller->$method( $this->request( array( 'from' => 'nope' ) ) );
			$this->assertSame( 400, $res->status, $method );
			$this->assertFalse( $res->data['success'], $method );
			$this->assertSame( 'invalid_date_format', $res->data['error']['code'], $method );
		}
	}

	public function test_detail_envelope_unavailable_shape_when_source_missing() {
		foreach (
			array(
				'bookings'    => array( 'byType', 'series', 'trends' ),
				'memberships' => array( 'renewalsInRange', 'failedRenewals', 'churn', 'series', 'trends' ),
				'woocommerce' => array( 'topProducts', 'byCategory', 'series', 'trends', 'truncated' ),
				'waivers'     => array( 'expiring', 'signings' ),
			) as $method => $keys
		) {
			$res  = $this->controller->$method( $this->request( array( 'from' => '2026-06-01', 'to' => '2026-06-30' ) ) );
			$body = $res->data;

			$this->assertSame( 200, $res->status, $method );
			$this->assertTrue( $body['success'], $method );
			$this->assertSame(
				array( 'requestId', 'generatedAt', 'timezone', 'currency', 'source', 'freshness' ),
				array_keys( $body['meta'] ),
				$method
			);
			$this->assertSame( array(), $body['meta']['source'], "$method: unavailable -> no sources" );
			$this->assertSame( 'unavailable', $body['meta']['freshness']['status'], $method );

			// Frontend contract: unavailability is meta.freshness, no
			// data.available boolean on the detail routes.
			$this->assertArrayNotHasKey( 'available', $body['data'], $method );
			$this->assertSame( array( 'from' => '2026-06-01', 'to' => '2026-06-30' ), $body['data']['range'], $method );
			foreach ( $keys as $key ) {
				$this->assertArrayHasKey( $key, $body['data'], "$method detail must always carry '$key'" );
			}
		}
	}

	public function test_bookings_detail_success_envelope_lists_source_and_is_cached() {
		$this->wpdb->tables      = array( 'wp_g2ab_bookings', 'wp_g2ab_payments' );
		$this->wpdb->results_map = array(
			"BETWEEN '2026-06-01 00:00:00'" => array(
				array( 'status' => 'succeeded', 'c' => 1, 'booking_c' => 1, 'amount_sum' => '10.00', 'refund_sum' => '0.00' ),
			),
		);

		$res  = $this->controller->bookings( $this->request( array( 'from' => '2026-06-01', 'to' => '2026-06-03' ) ) );
		$body = $res->data;

		$this->assertTrue( $body['success'] );
		$this->assertArrayNotHasKey( 'available', $body['data'], 'availability is meta.freshness, not a data key' );
		$this->assertSame( array( 'g2a-booking-engine' ), $body['meta']['source'] );
		$this->assertSame( 'fresh', $body['meta']['freshness']['status'] );
		$this->assertArrayHasKey( 'byType', $body['data'] );
		$this->assertArrayHasKey( 'series', $body['data'] );
		$this->assertArrayHasKey( 'trends', $body['data'] );
		$this->assertCount( 3, $body['data']['series'], 'zero-filled across the requested range' );

		// Cached under the DETAIL key, not the overview-summary key.
		$this->assertArrayHasKey(
			'g2aba_analytics_booking-analytics-provider-detail_2026-06-01_2026-06-03_read',
			$GLOBALS['g2aba_test_transients']
		);
		$this->assertArrayNotHasKey(
			'g2aba_analytics_booking-analytics-provider_2026-06-01_2026-06-03_read',
			$GLOBALS['g2aba_test_transients']
		);
	}

	// ---- cache key distinctness -----------------------------------------------------------

	public function test_summary_detail_and_series_cache_keys_are_distinct() {
		$range    = new Range( '2026-06-01', '2026-06-30' );
		$provider = new Booking_Analytics_Provider();

		$summary_key = Analytics_Provider_Base::cache_key( $provider->provider_key(), $range, 'read' );
		$detail_key  = Analytics_Provider_Base::cache_key( $provider->provider_key() . '-detail', $range, 'read' );
		$series_key  = Analytics_Provider_Base::cache_key( $provider->provider_key() . '-series', $range, 'read' );

		$this->assertCount( 3, array_unique( array( $summary_key, $detail_key, $series_key ) ) );
	}

	public function test_cached_detail_and_daily_revenue_write_distinct_transients() {
		$range    = new Range( '2026-06-01', '2026-06-03' );
		$provider = new Booking_Analytics_Provider();

		$provider->cached_summary( $range );
		$provider->cached_detail( $range );
		$provider->cached_daily_revenue( $range );

		$keys = array_keys( $GLOBALS['g2aba_test_transients'] );
		sort( $keys );
		$this->assertSame(
			array(
				'g2aba_analytics_booking-analytics-provider-detail_2026-06-01_2026-06-03_read',
				'g2aba_analytics_booking-analytics-provider-series_2026-06-01_2026-06-03_read',
				'g2aba_analytics_booking-analytics-provider_2026-06-01_2026-06-03_read',
			),
			$keys
		);
	}

	// ---- overview series ---------------------------------------------------------------------

	public function test_overview_series_sums_equal_module_revenue_totals() {
		$this->wpdb->tables      = array( 'wp_g2ab_bookings', 'wp_g2ab_payments' );
		$this->wpdb->results_map = array(
			// Daily net revenue (date + status GROUP BY) — must sum to the
			// summary ledger below.
			'AS d, status'          => array(
				array( 'd' => '2026-06-01', 'status' => 'succeeded', 'amount_sum' => '60.00', 'refund_sum' => '0.00' ),
				array( 'd' => '2026-06-03', 'status' => 'succeeded', 'amount_sum' => '40.00', 'refund_sum' => '0.00' ),
			),
			// Summary payment ledger.
			'FROM wp_g2ab_payments' => array(
				array( 'status' => 'succeeded', 'c' => 2, 'booking_c' => 2, 'amount_sum' => '100.00', 'refund_sum' => '0.00' ),
			),
		);

		$controller = new Dashboard_Controller();
		$res        = $controller->overview( new \WP_REST_Request( array( 'from' => '2026-06-01', 'to' => '2026-06-03' ) ) );
		$data       = $res->data['data'];

		$this->assertCount( 3, $data['series'] );
		$this->assertSame( array( '2026-06-01', '2026-06-02', '2026-06-03' ), array_column( $data['series'], 'date' ) );

		// Sums tie out against the module revenue block.
		$this->assertSame( $data['revenue']['bookingsCents'], array_sum( array_column( $data['series'], 'bookingsCents' ) ) );
		$this->assertSame( $data['revenue']['membershipsCents'], array_sum( array_column( $data['series'], 'membershipsCents' ) ) );
		$this->assertSame( $data['revenue']['wooCents'], array_sum( array_column( $data['series'], 'wooCents' ) ) );
		$this->assertSame( $data['revenue']['totalCents'], array_sum( array_column( $data['series'], 'totalCents' ) ) );
		$this->assertSame( 10000, $data['revenue']['bookingsCents'], 'sanity: fake ledger recognised' );

		// Per-row total = sum of the parts.
		foreach ( $data['series'] as $row ) {
			$this->assertSame(
				$row['bookingsCents'] + $row['membershipsCents'] + $row['wooCents'],
				$row['totalCents']
			);
		}
	}

	public function test_build_series_is_pure_and_zero_fills() {
		$series = Dashboard_Controller::build_series(
			new Range( '2026-06-01', '2026-06-03' ),
			array( '2026-06-01' => 100 ),
			array( '2026-06-03' => 50 ),
			array( '2026-06-01' => 25 )
		);

		$this->assertSame(
			array(
				array( 'date' => '2026-06-01', 'bookingsCents' => 100, 'membershipsCents' => 0, 'wooCents' => 25, 'totalCents' => 125 ),
				array( 'date' => '2026-06-02', 'bookingsCents' => 0, 'membershipsCents' => 0, 'wooCents' => 0, 'totalCents' => 0 ),
				array( 'date' => '2026-06-03', 'bookingsCents' => 0, 'membershipsCents' => 50, 'wooCents' => 0, 'totalCents' => 50 ),
			),
			$series
		);
	}
}
