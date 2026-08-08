<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base;
use WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Interface;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\System_Health_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Waiver_Summary_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Woo_Analytics_Provider;
use WordPressistic\G2ABA\Range;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

/** Provider whose build always throws — exercises the never-fatal contract. */
class Throwing_Provider extends Analytics_Provider_Base {
	public function is_available(): bool {
		return true;
	}
	public function source_slug(): string {
		return 'throwing-source';
	}
	protected function empty_summary(): array {
		return array( 'value' => 0 );
	}
	protected function build_summary( Range $range ): array {
		throw new \RuntimeException( 'SECRET: SELECT * FROM wp_users; /var/www/html' );
	}
}

class AnalyticsProviderBaseTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wpdb']                  = new Analytics_Fake_WPDB();
		$GLOBALS['g2aba_test_transients'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function test_every_provider_implements_the_interface() {
		foreach (
			array(
				new Booking_Analytics_Provider(),
				new Membership_Analytics_Provider(),
				new Woo_Analytics_Provider(),
				new Waiver_Summary_Provider(),
				new System_Health_Provider(),
			) as $provider
		) {
			$this->assertInstanceOf( Analytics_Provider_Interface::class, $provider );
			$this->assertIsBool( $provider->is_available() );
			$this->assertNotSame( '', $provider->source_slug() );
			$freshness = $provider->freshness();
			$this->assertContains( $freshness['status'], array( 'fresh', 'stale', 'unavailable' ) );
			$this->assertArrayHasKey( 'lastUpdatedAt', $freshness );
		}
	}

	public function test_throwing_provider_degrades_to_unavailable_without_leaking() {
		$provider = new Throwing_Provider();
		$summary  = $provider->summary( new Range( '2026-06-01', '2026-06-30' ) );

		$this->assertFalse( $summary['available'] );
		$this->assertSame( 0, $summary['value'] );
		$this->assertSame( 'unavailable', $provider->freshness()['status'] );

		// Nothing from the exception (SQL, paths) may surface in the payload.
		$flat = wp_json_encode( $summary );
		$this->assertStringNotContainsString( 'SECRET', $flat );
		$this->assertStringNotContainsString( 'SELECT', $flat );
		$this->assertStringNotContainsString( '/var/www', $flat );
	}

	public function test_cached_summary_serves_the_transient_on_second_call() {
		$range    = new Range( '2026-06-01', '2026-06-30' );
		$provider = new Booking_Analytics_Provider();

		$first = $provider->cached_summary( $range );
		$this->assertFalse( $first['available'] );

		// Second call must be a pure cache hit: poison the fake so a re-query
		// would now find tables — the cached (unavailable) result must win.
		$GLOBALS['wpdb']->tables = array( 'wp_g2ab_bookings', 'wp_g2ab_payments' );
		$second                  = $provider->cached_summary( $range );
		$this->assertFalse( $second['available'], 'served from transient, not re-queried' );
	}

	public function test_capability_tier_reflects_admin_cap() {
		$GLOBALS['g2aba_test_current_user_caps'] = array();
		$this->assertSame( 'read', Analytics_Provider_Base::capability_tier() );

		$GLOBALS['g2aba_test_current_user_caps'] = array( \WordPressistic\G2ABA\Capabilities::ADMIN );
		$this->assertSame( 'admin', Analytics_Provider_Base::capability_tier() );

		$GLOBALS['g2aba_test_current_user_caps'] = array();
	}
}
