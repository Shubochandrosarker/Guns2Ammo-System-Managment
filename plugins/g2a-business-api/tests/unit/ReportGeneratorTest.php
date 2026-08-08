<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Reports\Report_Generator;

class ReportGeneratorTest extends TestCase {
	public function test_compose_daily_covers_all_channels() {
		$body = Report_Generator::compose_daily(
			new Range( '2026-07-02', '2026-07-02' ),
			412_800, // $4,128 revenue
			38,
			5,
			2,
			1_204
		);
		$this->assertStringContainsString( 'daily summary for 2026-07-02', $body );
		$this->assertStringContainsString( '$4,128', $body );
		$this->assertStringContainsString( 'Bookings: 38', $body );
		$this->assertStringContainsString( '5 renewed · 2 expired', $body );
		$this->assertStringContainsString( '1204', $body );
	}

	public function test_compose_weekly_delegates_stay_stable_for_handler_tests() {
		// This composer is what Weekly_Report_Handler::compose_body forwards to.
		// The existing WeeklyReportHandlerTest pins the wording — this test
		// pins the same wording against the shared generator too so refactors
		// of the generator break both symmetrically.
		$body = Report_Generator::compose_weekly(
			new Range( '2026-06-27', '2026-07-03' ),
			array( 'totalRevenue' => 4_812_400, 'revenueGrowthPct' => 12.4 ),
			array(
				'bookingsByType'   => array(
					array( 'type' => 'Range Lane', 'count' => 412 ),
					array( 'type' => 'CCW Class',  'count' =>  74 ),
				),
				'topBookingType' => 'Range Lane',
			),
			array( 'active' => 612, 'renewals' => 39, 'expired' => 27 ),
			array( 'revenue' => 2_106_800, 'orders' => 236 ),
			array( 'clicks' => 5_412 )
		);
		$this->assertStringContainsString( '2026-06-27 to 2026-07-03', $body );
		$this->assertStringContainsString( '486 (Range Lane leading)', $body );
	}

	public function test_compose_monthly_signs_growth_and_formats_mrr() {
		$body = Report_Generator::compose_monthly(
			new Range( '2026-06-04', '2026-07-03' ),
			5_120_000,
			-4.2,
			618,
			1_240_000
		);
		$this->assertStringContainsString( 'Trailing 30d revenue: $51,200', $body );
		$this->assertStringContainsString( '-4.2%', $body );
		$this->assertStringContainsString( 'Active shooters: 618', $body );
		$this->assertStringContainsString( 'MRR: $12,400', $body );
	}

	public function test_compose_seo_lists_top_queries_when_present() {
		$body = Report_Generator::compose_seo(
			new Range( '2026-06-04', '2026-07-03' ),
			array(
				'clicks'          => 5_412,
				'impressions'     => 82_100,
				'averagePosition' => 12.4,
				'topQueries'      => array(
					array( 'query' => 'gun range mesa', 'clicks' => 812 ),
					array( 'query' => 'ccw class arizona', 'clicks' => 402 ),
				),
			)
		);
		$this->assertStringContainsString( 'Clicks: 5412', $body );
		$this->assertStringContainsString( 'Avg position: 12.4', $body );
		$this->assertStringContainsString( 'gun range mesa — 812 clicks', $body );
	}

	public function test_compose_seo_omits_top_queries_when_absent() {
		$body = Report_Generator::compose_seo(
			new Range( '2026-06-04', '2026-07-03' ),
			array( 'clicks' => 100, 'impressions' => 500, 'averagePosition' => 20.0 )
		);
		$this->assertStringNotContainsString( 'Top queries:', $body );
	}

	public function test_compose_bookings_sums_and_lists_by_type() {
		$body = Report_Generator::compose_bookings(
			new Range( '2026-06-04', '2026-07-03' ),
			array(
				'bookingsByType' => array(
					array( 'type' => 'Range Lane', 'count' => 412, 'revenue' => 8_240_000 ),
					array( 'type' => 'CCW Class',  'count' =>  74, 'revenue' => 4_440_000 ),
				),
				'topBookingType' => 'Range Lane',
				'cancellations'  => 12,
				'noShows'        => 4,
			)
		);
		$this->assertStringContainsString( 'Total bookings: 486', $body );
		$this->assertStringContainsString( 'Top type: Range Lane', $body );
		$this->assertStringContainsString( 'Cancellations: 12', $body );
		$this->assertStringContainsString( 'No-shows: 4', $body );
		$this->assertStringContainsString( 'Range Lane — 412 bookings, $82,400', $body );
	}

	public function test_compose_members_reads_all_keys() {
		$body = Report_Generator::compose_members(
			new Range( '2026-06-04', '2026-07-03' ),
			array(
				'active'    => 612,
				'renewals'  => 39,
				'expired'   => 27,
				'mrr'       => 1_240_000,
				'churnRate' => 2.4,
			)
		);
		$this->assertStringContainsString( 'Active: 612', $body );
		$this->assertStringContainsString( 'MRR: $12,400', $body );
		$this->assertStringContainsString( 'Churn rate: 2.4%', $body );
	}

	public function test_compose_woo_prints_orders_and_top_products() {
		$body = Report_Generator::compose_woo(
			new Range( '2026-06-04', '2026-07-03' ),
			array(
				'revenue'           => 2_106_800,
				'orders'            => 236,
				'averageOrderValue' => 8_927,
				'topProducts'       => array(
					array( 'name' => '9mm 1000rd case', 'unitsSold' => 82 ),
					array( 'name' => 'AR-15 lower',     'unitsSold' => 14 ),
				),
			)
		);
		$this->assertStringContainsString( 'Revenue: $21,068', $body );
		$this->assertStringContainsString( 'Orders: 236', $body );
		$this->assertStringContainsString( '9mm 1000rd case — 82 sold', $body );
	}

	public function test_compose_ai_recs_lists_top_gaps() {
		$body = Report_Generator::compose_ai_recs(
			new Range( '2026-06-27', '2026-07-03' ),
			array( 'open' => array( array( 'id' => 'ins-a' ), array( 'id' => 'ins-b' ) ) ),
			array(
				array( 'title' => 'CCW page below fold', 'priority' => 'high' ),
				array( 'title' => 'No hours on GBP',      'priority' => 'medium' ),
			)
		);
		$this->assertStringContainsString( 'Open AI insights: 2', $body );
		$this->assertStringContainsString( 'Business gaps: 2', $body );
		$this->assertStringContainsString( '[high] CCW page below fold', $body );
	}
}
