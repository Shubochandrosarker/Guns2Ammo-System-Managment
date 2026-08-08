<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider;
use WordPressistic\G2ABA\Range;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class MembershipAnalyticsProviderTest extends TestCase {
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

	public function test_unavailable_without_memberships_table() {
		$provider = new Membership_Analytics_Provider();
		$this->assertFalse( $provider->is_available() );

		$summary = $provider->summary( $this->range() );
		$this->assertFalse( $summary['available'] );
		$this->assertSame( 0, $summary['active'] );
		$this->assertSame( 0, $summary['mrrCents'] );
		$this->assertSame( array(), $summary['planBreakdown'] );
		$this->assertSame( 'unavailable', $provider->freshness()['status'] );
	}

	public function test_summary_counts_and_contract_keys() {
		$this->wpdb->tables      = array( 'wp_memberistic_memberships' ); // no plans/payments tables
		$this->wpdb->results_map = array(
			'GROUP BY status' => array(
				array( 'status' => 'active', 'c' => 40 ),
				array( 'status' => 'pending', 'c' => 3 ),
				array( 'status' => 'past_due', 'c' => 2 ),
				array( 'status' => 'expired', 'c' => 7 ),
				array( 'status' => 'cancelled', 'c' => 5 ),
				array( 'status' => 'paused', 'c' => 1 ), // -> other
			),
		);
		$this->wpdb->var_map     = array(
			'WHERE created_at BETWEEN'   => 6,
			"status = 'cancelled'"        => 2,
			'CASE WHEN LOWER'             => '1234.56', // MRR dollars
			'SELECT MAX(COALESCE(updated_at' => '2026-06-30 12:00:00',
		);

		$summary = ( new Membership_Analytics_Provider() )->summary( $this->range() );

		$this->assertTrue( $summary['available'] );
		// Frontend contract keys.
		$this->assertSame( 40, $summary['active'] );
		$this->assertSame( 6, $summary['new'] );
		$this->assertSame( 7, $summary['expired'] );
		$this->assertSame( 123456, $summary['mrrCents'] );
		// Rich detail.
		$this->assertSame(
			array(
				'active'    => 40,
				'pending'   => 3,
				'past_due'  => 2,
				'expired'   => 7,
				'cancelled' => 5,
				'other'     => 1,
			),
			$summary['counts']
		);
		$this->assertSame( 2, $summary['cancelledInRange'] );
		$this->assertSame( 0, $summary['revenueCents'], 'no payments table -> zero, never fabricated' );
		$this->assertSame( array(), $summary['planBreakdown'] );
		$this->assertSame( '2026-06-30T12:00:00Z', $summary['lastUpdatedAt'] );
	}

	public function test_plan_breakdown_and_revenue_with_all_tables() {
		$this->wpdb->tables      = array( 'wp_memberistic_memberships', 'wp_memberistic_plans', 'wp_memberistic_payments' );
		$this->wpdb->results_map = array(
			'GROUP BY status' => array( array( 'status' => 'active', 'c' => 10 ) ),
			'FROM wp_memberistic_plans p' => array(
				array( 'plan_id' => 1, 'name' => 'Individual', 'count' => 8, 'revenue_sum' => '400.00' ),
				array( 'plan_id' => 2, 'name' => 'Family', 'count' => 2, 'revenue_sum' => '150.25' ),
			),
		);
		$this->wpdb->var_map     = array(
			'SELECT COALESCE(SUM(amount), 0) FROM wp_memberistic_payments' => '550.25',
			'CASE WHEN LOWER' => '450.00',
		);

		$summary = ( new Membership_Analytics_Provider() )->summary( $this->range() );

		$this->assertSame( 55025, $summary['revenueCents'] );
		$this->assertSame( 45000, $summary['mrrCents'] );
		$this->assertSame(
			array(
				array( 'planId' => 1, 'name' => 'Individual', 'count' => 8, 'revenueCents' => 40000 ),
				array( 'planId' => 2, 'name' => 'Family', 'count' => 2, 'revenueCents' => 15025 ),
			),
			$summary['planBreakdown']
		);
	}
}
