<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Auth\Session_Auth;
use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Woo_Analytics_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\REST\Dashboard_Controller;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class DashboardControllerTest extends TestCase {
	private Analytics_Fake_WPDB $wpdb;
	private Dashboard_Controller $controller;

	protected function setUp(): void {
		$this->wpdb      = new Analytics_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		$GLOBALS['g2aba_test_options']           = array();
		$GLOBALS['g2aba_test_transients']        = array();
		$GLOBALS['g2aba_test_current_user_caps'] = array();
		$GLOBALS['g2aba_test_rest_routes']       = array();
		unset( $GLOBALS['g2aba_test_timezone_string'] );

		$this->controller = new Dashboard_Controller();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['g2aba_test_current_user_caps'] = array();
	}

	private function request( array $params = array() ): \WP_REST_Request {
		return new \WP_REST_Request( $params );
	}

	// ---- date bounding -----------------------------------------------------------

	public function test_parse_range_defaults_to_last_30_days() {
		$parsed = Dashboard_Controller::parse_range( '', '' );
		$this->assertTrue( $parsed['ok'] );
		$this->assertSame( 30, $parsed['range']->days() );
		$this->assertSame( gmdate( 'Y-m-d' ), $parsed['range']->to );
	}

	public function test_parse_range_rejects_malformed_dates() {
		foreach ( array( '2026-6-01', 'yesterday', '2026-02-30', '20260601', "2026-06-01'--" ) as $bad ) {
			$parsed = Dashboard_Controller::parse_range( $bad, '2026-06-30' );
			$this->assertFalse( $parsed['ok'], $bad );
			$this->assertSame( 'invalid_date_format', $parsed['code'], $bad );
		}
	}

	public function test_parse_range_rejects_inverted_range() {
		$parsed = Dashboard_Controller::parse_range( '2026-07-01', '2026-06-01' );
		$this->assertFalse( $parsed['ok'] );
		$this->assertSame( 'invalid_date_range', $parsed['code'] );
	}

	public function test_parse_range_bounds_at_366_days() {
		$ok = Dashboard_Controller::parse_range( '2025-07-01', '2026-07-01' );
		$this->assertTrue( $ok['ok'], '366 days inclusive is allowed' );

		$too_long = Dashboard_Controller::parse_range( '2025-06-30', '2026-07-01' );
		$this->assertFalse( $too_long['ok'] );
		$this->assertSame( 'date_range_too_long', $too_long['code'] );
	}

	public function test_overview_returns_400_envelope_on_bad_range() {
		$res = $this->controller->overview( $this->request( array( 'from' => 'nope' ) ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 400, $res->status );
		$this->assertFalse( $res->data['success'] );
		$this->assertSame( 'invalid_date_format', $res->data['error']['code'] );
		$this->assertArrayHasKey( 'requestId', $res->data['error'] );
	}

	// ---- overview shape ------------------------------------------------------------

	public function test_overview_envelope_shape_with_all_sources_unavailable() {
		$res = $this->controller->overview( $this->request( array( 'from' => '2026-06-01', 'to' => '2026-06-30' ) ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->status );

		$body = $res->data;
		$this->assertTrue( $body['success'] );
		$this->assertSame(
			array( 'requestId', 'generatedAt', 'timezone', 'currency', 'source', 'freshness' ),
			array_keys( $body['meta'] )
		);
		$this->assertSame( array(), $body['meta']['source'], 'nothing available -> no sources' );
		$this->assertSame( 'unavailable', $body['meta']['freshness']['status'] );

		$data = $body['data'];
		$this->assertSame(
			array( 'range', 'revenue', 'series', 'bookings', 'memberships', 'woocommerce', 'waivers', 'alerts', 'modules' ),
			array_keys( $data )
		);
		$this->assertCount( 30, $data['series'], 'series is zero-filled across the whole range even when every source is unavailable' );
		$this->assertSame(
			array(
				'date'             => '2026-06-01',
				'bookingsCents'    => 0,
				'membershipsCents' => 0,
				'wooCents'         => 0,
				'totalCents'       => 0,
			),
			$data['series'][0]
		);
		$this->assertSame(
			array(
				'bookingsCents'    => 0,
				'membershipsCents' => 0,
				'wooCents'         => 0,
				'totalCents'       => 0,
			),
			$data['revenue']
		);

		// Frontend contract keys survive the unavailable path.
		foreach ( array( 'count', 'paid', 'unpaid', 'revenueCents' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['bookings'] );
		}
		foreach ( array( 'active', 'new', 'expired', 'mrrCents' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['memberships'] );
		}
		foreach ( array( 'orders', 'revenueCents' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['woocommerce'] );
		}
		foreach ( array( 'signed', 'pending' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['waivers'] );
		}

		// Modules: honest unavailable flags + freshness blocks.
		$this->assertSame(
			array( 'bookings', 'memberships', 'woocommerce', 'waivers' ),
			array_keys( $data['modules'] )
		);
		foreach ( $data['modules'] as $module ) {
			$this->assertFalse( $module['available'] );
			$this->assertSame( array(), $module['source'] );
			$this->assertSame( 'unavailable', $module['freshness']['status'] );
		}

		// One *_unavailable alert per module.
		$codes = array_column( $data['alerts'], 'code' );
		foreach ( array( 'bookings_unavailable', 'memberships_unavailable', 'woocommerce_unavailable', 'waivers_unavailable' ) as $code ) {
			$this->assertContains( $code, $codes );
		}
	}

	public function test_overview_revenue_rollup_and_sources_when_bookings_available() {
		$this->wpdb->tables      = array( 'wp_g2ab_bookings', 'wp_g2ab_payments' );
		$this->wpdb->results_map = array(
			'FROM wp_g2ab_payments' => array(
				array( 'status' => 'succeeded', 'c' => 2, 'booking_c' => 2, 'amount_sum' => '100.00', 'refund_sum' => '0.00' ),
			),
		);

		$res  = $this->controller->overview( $this->request( array( 'from' => '2026-06-01', 'to' => '2026-06-30' ) ) );
		$body = $res->data;

		$this->assertSame( 10000, $body['data']['revenue']['bookingsCents'] );
		$this->assertSame( 10000, $body['data']['revenue']['totalCents'] );
		$this->assertSame( array( 'g2a-booking-engine' ), $body['meta']['source'] );
		$this->assertSame( 'stale', $body['meta']['freshness']['status'], 'mixed availability -> stale' );
		$this->assertTrue( $body['data']['modules']['bookings']['available'] );
		$this->assertSame( array( 'g2a-booking-engine' ), $body['data']['modules']['bookings']['source'] );
	}

	// ---- capability gate ------------------------------------------------------------

	public function test_read_capability_gate() {
		$denied = $this->controller->read_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $denied );

		$GLOBALS['g2aba_test_current_user_caps'] = array( Capabilities::READ );
		$this->assertTrue( $this->controller->read_permissions_check() );
	}

	public function test_route_registers_get_with_read_permission_callback() {
		$this->controller->register_routes();

		$routes = $GLOBALS['g2aba_test_rest_routes'];
		$this->assertCount( 1, $routes );
		$this->assertSame( 'g2a/v1', $routes[0]['namespace'] );
		$this->assertSame( '/dashboard/overview', $routes[0]['route'] );
		$this->assertSame( 'GET', $routes[0]['args']['methods'] );
		$this->assertSame(
			array( $this->controller, 'read_permissions_check' ),
			$routes[0]['args']['permission_callback']
		);
	}

	// ---- CSRF exemption (GET) ---------------------------------------------------------

	public function test_overview_is_csrf_exempt_because_get() {
		$this->assertFalse(
			Session_Auth::requires_csrf( 'GET', '/g2a/v1/dashboard/overview', true ),
			'GET must never require the CSRF header'
		);
		$this->assertTrue(
			Session_Auth::requires_csrf( 'POST', '/g2a/v1/dashboard/overview', true ),
			'sanity: unsafe methods on cookie sessions DO require CSRF'
		);
	}

	// ---- cache key isolation -----------------------------------------------------------

	public function test_cache_keys_isolate_range_and_capability_tier() {
		$r1 = new Range( '2026-06-01', '2026-06-30' );
		$r2 = new Range( '2026-05-01', '2026-05-31' );

		$this->assertNotSame(
			Analytics_Provider_Base::cache_key( 'woocommerce', $r1, 'read' ),
			Analytics_Provider_Base::cache_key( 'woocommerce', $r2, 'read' )
		);
		$this->assertNotSame(
			Analytics_Provider_Base::cache_key( 'woocommerce', $r1, 'read' ),
			Analytics_Provider_Base::cache_key( 'woocommerce', $r1, 'admin' )
		);
		$this->assertNotSame(
			Analytics_Provider_Base::cache_key( 'woocommerce', $r1, 'read' ),
			Analytics_Provider_Base::cache_key( 'g2a-booking-engine', $r1, 'read' )
		);
	}

	public function test_cached_summary_writes_separate_transients_per_tier() {
		$range    = new Range( '2026-06-01', '2026-06-30' );
		$provider = new Booking_Analytics_Provider();

		$GLOBALS['g2aba_test_current_user_caps'] = array();
		$provider->cached_summary( $range );

		$GLOBALS['g2aba_test_current_user_caps'] = array( Capabilities::ADMIN );
		$provider->cached_summary( $range );

		$keys = array_keys( $GLOBALS['g2aba_test_transients'] );
		sort( $keys );
		$this->assertSame(
			array(
				'g2aba_analytics_booking-analytics-provider_2026-06-01_2026-06-30_admin',
				'g2aba_analytics_booking-analytics-provider_2026-06-01_2026-06-30_read',
			),
			$keys
		);
	}

	public function test_providers_sharing_a_source_plugin_get_distinct_cache_keys() {
		$r = new Range( '2026-06-01', '2026-06-30' );
		$m = new \WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider();
		$w = new \WordPressistic\G2ABA\Providers\Analytics\Waiver_Summary_Provider();

		$this->assertSame( $m->source_slug(), $w->source_slug(), 'both read Memberistic' );
		$this->assertNotSame(
			Analytics_Provider_Base::cache_key( $m->provider_key(), $r, 'read' ),
			Analytics_Provider_Base::cache_key( $w->provider_key(), $r, 'read' )
		);
	}

	// ---- provider unavailable via class_exists ------------------------------------------

	public function test_woo_provider_unavailable_when_woocommerce_class_missing() {
		$this->assertFalse( class_exists( '\WooCommerce' ), 'precondition: no Woo in the unit harness' );

		$provider = new Woo_Analytics_Provider();
		$this->assertFalse( $provider->is_available() );

		$summary = $provider->summary( new Range( '2026-06-01', '2026-06-30' ) );
		$this->assertFalse( $summary['available'] );
		$this->assertSame( 0, $summary['orders'] );
		$this->assertSame( 0, $summary['revenueCents'] );
		$this->assertSame( 'unavailable', $provider->freshness()['status'] );
	}

	// ---- alert derivation (pure) ----------------------------------------------------------

	public function test_derive_alerts_stripe_and_reconciliation_and_waiver_spike() {
		$modules = array(
			'bookings'    => array( 'available' => true ),
			'memberships' => array( 'available' => true ),
			'woocommerce' => array( 'available' => true ),
			'waivers'     => array( 'available' => true ),
		);
		$bookings = array( 'reconciliation' => array( 'warnings' => 3 ) );
		$waivers  = array( 'counts' => array( 'expiring30d' => 30, 'current' => 100 ) );

		$alerts = Dashboard_Controller::derive_alerts( $modules, $bookings, $waivers, 2 );
		$codes  = array_column( $alerts, 'code' );

		$this->assertSame( array( 'waivers_expiring_spike', 'stripe_cancel_failures', 'booking_reconciliation_warnings' ), $codes );
		foreach ( $alerts as $alert ) {
			$this->assertContains( $alert['severity'], array( 'critical', 'error', 'warning', 'info' ) );
			$this->assertNotSame( '', $alert['message'] );
		}

		// Quiet system -> no alerts.
		$quiet = Dashboard_Controller::derive_alerts(
			$modules,
			array( 'reconciliation' => array( 'warnings' => 0 ) ),
			array( 'counts' => array( 'expiring30d' => 2, 'current' => 100 ) ),
			0
		);
		$this->assertSame( array(), $quiet );
	}
}
