<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Analytics\Waiver_Summary_Provider;
use WordPressistic\G2ABA\Range;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class WaiverSummaryProviderTest extends TestCase {
	private Analytics_Fake_WPDB $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new Analytics_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
		unset( $GLOBALS['g2aba_test_timezone_string'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private function range(): Range {
		return new Range( '2026-06-01', '2026-06-30' );
	}

	public function test_unavailable_when_no_store_exists() {
		$provider = new Waiver_Summary_Provider();
		$this->assertFalse( $provider->is_available() );
		$this->assertSame( array(), $provider->sources() );

		$summary = $provider->summary( $this->range() );
		$this->assertFalse( $summary['available'] );
		$this->assertSame( 0, $summary['signed'] );
		$this->assertSame( 0, $summary['pending'] );
		$this->assertFalse( $summary['stores']['people'] );
		$this->assertFalse( $summary['stores']['archive'] );
	}

	public function test_counts_only_no_pii_people_store_only() {
		$this->wpdb->tables  = array( 'wp_memberistic_people' );
		$this->wpdb->row_map = array(
			'FROM wp_memberistic_people' => array(
				'current_count'  => 120,
				'expiring_count' => 14,
				'expired_count'  => 9,
				'missing_count'  => 31,
				'last_updated'   => '2026-06-28 09:30:00',
			),
		);

		$provider = new Waiver_Summary_Provider();
		$this->assertTrue( $provider->is_available() );
		$this->assertSame( array( 'memberistic-people' ), $provider->sources() );

		$summary = $provider->summary( $this->range() );

		$this->assertTrue( $summary['available'] );
		// Frontend contract keys.
		$this->assertSame( 120, $summary['signed'] );
		$this->assertSame( 40, $summary['pending'], 'pending = missing + expired' );
		// Detail counts.
		$this->assertSame(
			array( 'current' => 120, 'expiring30d' => 14, 'expired' => 9, 'missing' => 31 ),
			$summary['counts']
		);
		$this->assertSame( array( 'total' => 0, 'current' => 0 ), $summary['archive'] );
		$this->assertSame( array( 'people' => true, 'archive' => false ), $summary['stores'] );
		$this->assertSame( '2026-06-28T09:30:00Z', $summary['lastUpdatedAt'] );

		// No person-level fields anywhere in the payload.
		$flat = wp_json_encode( $summary );
		foreach ( array( 'email', 'phone', 'full_name', 'name' ) as $pii_key ) {
			$this->assertStringNotContainsString( '"' . $pii_key . '"', $flat );
		}
	}

	public function test_archive_contributes_when_present() {
		$this->wpdb->tables  = array( 'wp_memberistic_people', 'wp_memberistic_waivers_archive' );
		$this->wpdb->row_map = array(
			'FROM wp_memberistic_people'          => array(
				'current_count'  => 1,
				'expiring_count' => 0,
				'expired_count'  => 0,
				'missing_count'  => 0,
				'last_updated'   => null,
			),
			'FROM wp_memberistic_waivers_archive' => array(
				'total'         => 1793,
				'current_count' => 1500,
			),
		);

		$provider = new Waiver_Summary_Provider();
		$this->assertSame( array( 'memberistic-people', 'memberistic-waivers-archive' ), $provider->sources() );

		$summary = $provider->summary( $this->range() );
		$this->assertSame( array( 'total' => 1793, 'current' => 1500 ), $summary['archive'] );
		$this->assertSame( array( 'people' => true, 'archive' => true ), $summary['stores'] );
	}
}
