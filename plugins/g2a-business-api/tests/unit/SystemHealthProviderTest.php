<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Analytics\System_Health_Provider;
use WordPressistic\G2ABA\REST\Health_Controller;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class SystemHealthProviderTest extends TestCase {
	private Analytics_Fake_WPDB $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new Analytics_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		$GLOBALS['g2aba_test_options']        = array();
		$GLOBALS['g2aba_test_cron_scheduled'] = array();
		unset( $GLOBALS['g2aba_test_timezone_string'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['g2aba_test_cron_scheduled'] = array();
	}

	public function test_report_shape_booleans_and_counts_only() {
		$GLOBALS['g2aba_test_options']['active_plugins'] = array(
			'g2a-booking-engine/g2a-booking-engine.php',
			'woocommerce/woocommerce.php',
		);
		$GLOBALS['g2aba_test_cron_scheduled']['g2aba_sessions_cleanup'] = array(
			'ts'       => 1780000000,
			'interval' => 'daily',
			'args'     => array(),
		);

		$report = ( new System_Health_Provider() )->report();

		$this->assertTrue( $report['available'] );

		// Plugins: full roster present, active flags honest, version ''
		// (typed string on the FE) when get_plugins() metadata is not loadable.
		$plugins = array_column( $report['plugins'], null, 'slug' );
		$this->assertCount( 7, $report['plugins'] );
		$this->assertTrue( $plugins['g2a-booking-engine']['active'] );
		$this->assertTrue( $plugins['woocommerce']['active'] );
		$this->assertFalse( $plugins['verifyistic']['active'] );
		$this->assertFalse( $plugins['messageistic']['active'] );
		foreach ( $report['plugins'] as $row ) {
			$this->assertSame( array( 'slug', 'name', 'active', 'version' ), array_keys( $row ) );
			$this->assertIsString( $row['version'], 'FE types version as string; unknown = ""' );
		}

		// Cron: known hooks, ISO next run or null.
		$cron = array_column( $report['cron'], 'nextRunAt', 'hook' );
		$this->assertArrayHasKey( 'memberistic_daily_expire_memberships', $cron );
		$this->assertNull( $cron['memberistic_daily_expire_memberships'] );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', 1780000000 ), $cron['g2aba_sessions_cleanup'] );

		// Sessions table missing in this fake.
		$this->assertFalse( $report['sessions']['table'] );
		$this->assertSame( 0, $report['sessions']['activeCount'] );

		$this->assertSame( 0, $report['audit']['recentFailures24h'] );
		$this->assertFalse( $report['integrations']['stripe']['configured'] );
		$this->assertFalse( $report['integrations']['woo']['active'] );

		// No secrets/paths anywhere.
		$flat = wp_json_encode( $report );
		$this->assertStringNotContainsString( 'sk_', $flat );
		$this->assertStringNotContainsString( '/home/', $flat );
		$this->assertStringNotContainsString( 'wp-content', $flat );
	}

	public function test_sessions_active_count_when_table_present() {
		$this->wpdb->tables  = array( 'wp_g2aba_sessions' );
		$this->wpdb->var_map = array( 'FROM wp_g2aba_sessions' => 4 );

		$report = ( new System_Health_Provider() )->report();
		$this->assertTrue( $report['sessions']['table'] );
		$this->assertSame( 4, $report['sessions']['activeCount'] );
	}

	public function test_count_recent_failures_is_bounded_to_24h_and_fail_kinds() {
		$now     = strtotime( '2026-07-15T12:00:00Z' );
		$entries = array(
			array( 'kind' => 'email.send_failed', 'ts' => '2026-07-15T10:00:00Z' ),   // counts
			array( 'kind' => 'session_login_failed', 'ts' => '2026-07-15T01:00:00Z' ), // counts
			array( 'kind' => 'email.send_failed', 'ts' => '2026-07-10T10:00:00Z' ),   // too old
			array( 'kind' => 'email.sent', 'ts' => '2026-07-15T10:00:00Z' ),          // not a failure
			array( 'kind' => 'session_login_success', 'ts' => '2026-07-15T11:00:00Z' ),
		);
		$this->assertSame( 2, System_Health_Provider::count_recent_failures( $entries, $now ) );
		$this->assertSame( 0, System_Health_Provider::count_recent_failures( array(), $now ) );
	}

	public function test_audit_failures_flow_into_report() {
		$GLOBALS['g2aba_test_options']['g2aba_audit_log'] = array(
			array( 'kind' => 'session_login_failed', 'ts' => gmdate( 'c' ) ),
			array( 'kind' => 'email.send_failed', 'ts' => gmdate( 'c' ) ),
		);
		$report = ( new System_Health_Provider() )->report();
		$this->assertSame( 2, $report['audit']['recentFailures24h'] );
	}

	public function test_health_controller_wraps_report_in_envelope() {
		$res = ( new Health_Controller() )->checks();

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->status );

		$body = $res->data;
		$this->assertTrue( $body['success'] );
		$this->assertSame(
			array( 'plugins', 'cron', 'sessions', 'audit', 'integrations' ),
			array_keys( $body['data'] )
		);
		$this->assertSame( array( 'g2a-business-api' ), $body['meta']['source'] );
		$this->assertSame( 'fresh', $body['meta']['freshness']['status'] );
		$this->assertSame( 'USD', $body['meta']['currency'] );
	}
}
