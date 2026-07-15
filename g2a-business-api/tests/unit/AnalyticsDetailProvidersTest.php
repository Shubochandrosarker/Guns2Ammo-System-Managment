<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Waiver_Summary_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Woo_Analytics_Provider;
use WordPressistic\G2ABA\Range;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class AnalyticsDetailProvidersTest extends TestCase {
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
		return new Range( '2026-06-01', '2026-06-03' );
	}

	// ---- pure helpers -----------------------------------------------------------

	public function test_previous_period_is_adjacent_and_equal_length() {
		$prev = $this->range()->previous();
		$this->assertSame( '2026-05-29', $prev->from );
		$this->assertSame( '2026-05-31', $prev->to );
		$this->assertSame( 3, $prev->days() );

		// Month/year boundary.
		$jan = ( new Range( '2026-01-01', '2026-01-31' ) )->previous();
		$this->assertSame( '2025-12-01', $jan->from );
		$this->assertSame( '2025-12-31', $jan->to );
	}

	public function test_pct_delta_math_and_zero_previous_null() {
		$this->assertSame( 100.0, Analytics_Provider_Base::pct_delta( 200, 100 ) );
		$this->assertSame( -50.0, Analytics_Provider_Base::pct_delta( 50, 100 ) );
		$this->assertSame( -100.0, Analytics_Provider_Base::pct_delta( 0, 5 ) );
		$this->assertSame( 33.33, Analytics_Provider_Base::pct_delta( 400, 300 ), 'rounded to 2dp' );
		$this->assertNull( Analytics_Provider_Base::pct_delta( 10, 0 ), 'zero previous must be null, never INF' );
		$this->assertNull( Analytics_Provider_Base::pct_delta( 0, 0 ) );
	}

	public function test_fill_daily_series_fills_missing_days_with_zeros_in_order() {
		$series = Analytics_Provider_Base::fill_daily_series(
			new Range( '2026-06-28', '2026-07-02' ),
			array(
				'2026-06-29' => array( 'count' => 2, 'revenueCents' => 500 ),
				'2026-07-02' => array( 'count' => 1, 'revenueCents' => 100 ),
			),
			array( 'count' => 0, 'revenueCents' => 0 )
		);

		$this->assertSame(
			array(
				array( 'date' => '2026-06-28', 'count' => 0, 'revenueCents' => 0 ),
				array( 'date' => '2026-06-29', 'count' => 2, 'revenueCents' => 500 ),
				array( 'date' => '2026-06-30', 'count' => 0, 'revenueCents' => 0 ),
				array( 'date' => '2026-07-01', 'count' => 0, 'revenueCents' => 0 ),
				array( 'date' => '2026-07-02', 'count' => 1, 'revenueCents' => 100 ),
			),
			$series,
			'month boundary crossed, missing days zero-filled, oldest first'
		);

		$year = Analytics_Provider_Base::fill_daily_series(
			new Range( '2025-07-02', '2026-07-02' ),
			array(),
			array( 'v' => 0 )
		);
		$this->assertCount( 366, $year, 'covers the full bounded window' );
	}

	public function test_build_trends_shape_and_null_deltas_on_all_zero_previous() {
		$trends = Analytics_Provider_Base::build_trends(
			10,
			5000,
			array( 'count' => 5, 'revenueCents' => 2500, 'netCents' => 2400 )
		);
		$this->assertSame(
			array( 'count' => 5, 'revenueCents' => 2500, 'netCents' => 2400 ),
			$trends['previous']
		);
		$this->assertSame( 100.0, $trends['deltas']['countPct'] );
		$this->assertSame( 100.0, $trends['deltas']['revenuePct'] );

		$zero = Analytics_Provider_Base::build_trends( 10, 5000, array( 'count' => 0, 'revenueCents' => 0, 'netCents' => 0 ) );
		$this->assertNull( $zero['deltas']['countPct'] );
		$this->assertNull( $zero['deltas']['revenuePct'] );
	}

	// ---- bookings detail -----------------------------------------------------------

	private function seed_booking_detail_fake(): void {
		$this->wpdb->tables      = array( 'wp_g2ab_bookings', 'wp_g2ab_payments', 'wp_g2ab_booking_types' );
		$this->wpdb->results_map = array(
			// Q: summary booking-status counts (single line SQL).
			'AS c FROM wp_g2ab_bookings'   => array(
				array( 'status' => 'paid', 'c' => 3 ),
				array( 'status' => 'pending', 'c' => 1 ),
			),
			// Q: daily net revenue (grouped by date + status).
			'AS d, status'                 => array(
				array( 'd' => '2026-06-01', 'status' => 'succeeded', 'amount_sum' => '60.00', 'refund_sum' => '0.00' ),
				array( 'd' => '2026-06-01', 'status' => 'pending', 'amount_sum' => '10.00', 'refund_sum' => '0.00' ),
				array( 'd' => '2026-06-03', 'status' => 'succeeded', 'amount_sum' => '40.00', 'refund_sum' => '0.00' ),
			),
			// Q: daily booking counts.
			'DATE(created_at) AS d'        => array(
				array( 'd' => '2026-06-01', 'c' => 3 ),
				array( 'd' => '2026-06-03', 'c' => 1 ),
			),
			// Q: per-type net revenue (payments joined to bookings).
			'AS type_id, p.status'         => array(
				array( 'type_id' => 1, 'status' => 'succeeded', 'amount_sum' => '75.00', 'refund_sum' => '0.00' ),
				array( 'type_id' => 1, 'status' => 'pending', 'amount_sum' => '50.00', 'refund_sum' => '0.00' ),
				array( 'type_id' => 2, 'status' => 'succeeded', 'amount_sum' => '25.00', 'refund_sum' => '0.00' ),
			),
			// Q: per-type booking counts (with names).
			'AS type_id,'                  => array(
				array( 'type_id' => 1, 'name' => 'Lane Rental', 'c' => 3 ),
				array( 'type_id' => 2, 'name' => 'CCW Class', 'c' => 1 ),
			),
			// Q: current-period payment ledger.
			"BETWEEN '2026-06-01 00:00:00'" => array(
				array( 'status' => 'succeeded', 'c' => 2, 'booking_c' => 2, 'amount_sum' => '100.00', 'refund_sum' => '0.00' ),
			),
			// Q: previous-period payment ledger.
			"BETWEEN '2026-05-29 00:00:00'" => array(
				array( 'status' => 'succeeded', 'c' => 1, 'booking_c' => 1, 'amount_sum' => '50.00', 'refund_sum' => '0.00' ),
			),
		);
		$this->wpdb->var_map     = array(
			'NOT EXISTS'                     => 0,
			'SELECT MAX(updated_at)'         => '2026-06-03 12:00:00',
			"BETWEEN '2026-05-29"            => 1, // previous-period booking count
		);
	}

	public function test_booking_detail_by_type_series_and_trends() {
		$this->seed_booking_detail_fake();

		$detail = ( new Booking_Analytics_Provider() )->detail( $this->range() );

		$this->assertTrue( $detail['available'] );
		// Overview payload keys unchanged and still present.
		$this->assertSame( 4, $detail['count'] );
		$this->assertSame( 10000, $detail['revenueCents'] );

		// byType: counts + attributed net revenue per the status mapping.
		$this->assertSame(
			array(
				array( 'typeId' => 1, 'name' => 'Lane Rental', 'count' => 3, 'revenueCents' => 7500 ),
				array( 'typeId' => 2, 'name' => 'CCW Class', 'count' => 1, 'revenueCents' => 2500 ),
			),
			$detail['byType']
		);

		// Daily series: middle day zero-filled, pending excluded from revenue.
		$this->assertSame(
			array(
				array( 'date' => '2026-06-01', 'count' => 3, 'revenueCents' => 6000 ),
				array( 'date' => '2026-06-02', 'count' => 0, 'revenueCents' => 0 ),
				array( 'date' => '2026-06-03', 'count' => 1, 'revenueCents' => 4000 ),
			),
			$detail['series']
		);

		// Series revenue sums to the module's revenueCents.
		$this->assertSame(
			$detail['revenueCents'],
			array_sum( array_column( $detail['series'], 'revenueCents' ) )
		);

		// Trends vs previous period.
		$this->assertSame(
			array( 'count' => 1, 'revenueCents' => 5000, 'netCents' => 5000 ),
			$detail['trends']['previous']
		);
		$this->assertSame( 300.0, $detail['trends']['deltas']['countPct'] );
		$this->assertSame( 100.0, $detail['trends']['deltas']['revenuePct'] );
	}

	public function test_booking_detail_null_deltas_when_previous_period_all_zero() {
		$this->seed_booking_detail_fake();
		// Remove the previous-period answers: prev count -> null(0), prev ledger -> [].
		unset( $this->wpdb->var_map["BETWEEN '2026-05-29"] );
		unset( $this->wpdb->results_map["BETWEEN '2026-05-29 00:00:00'"] );

		$detail = ( new Booking_Analytics_Provider() )->detail( $this->range() );

		$this->assertSame(
			array( 'count' => 0, 'revenueCents' => 0, 'netCents' => 0 ),
			$detail['trends']['previous']
		);
		$this->assertNull( $detail['trends']['deltas']['countPct'] );
		$this->assertNull( $detail['trends']['deltas']['revenuePct'] );
	}

	public function test_booking_detail_unavailable_shape() {
		$detail = ( new Booking_Analytics_Provider() )->detail( $this->range() );

		$this->assertFalse( $detail['available'] );
		$this->assertSame( array(), $detail['byType'] );
		$this->assertSame( array(), $detail['series'] );
		$this->assertNull( $detail['trends']['deltas']['countPct'] );
		$this->assertSame( 0, $detail['count'] );
	}

	public function test_net_cents_by_key_only_counts_revenue_statuses() {
		$net = Booking_Analytics_Provider::net_cents_by_key(
			array(
				array( 'd' => '2026-06-01', 'status' => 'succeeded', 'amount_sum' => '10.00', 'refund_sum' => '0.00' ),
				array( 'd' => '2026-06-01', 'status' => 'partial_refund', 'amount_sum' => '20.00', 'refund_sum' => '5.00' ),
				array( 'd' => '2026-06-01', 'status' => 'pending', 'amount_sum' => '99.00', 'refund_sum' => '0.00' ),
				array( 'd' => '2026-06-02', 'status' => 'mystery', 'amount_sum' => '99.00', 'refund_sum' => '0.00' ),
			),
			'd'
		);
		$this->assertSame( array( '2026-06-01' => 2500 ), $net );
	}

	// ---- memberships detail ----------------------------------------------------------

	private function seed_membership_detail_fake( bool $with_activity = true ): void {
		$tables = array( 'wp_memberistic_memberships' );
		if ( $with_activity ) {
			$tables[] = 'wp_memberistic_activity';
		}
		$this->wpdb->tables      = $tables;
		$this->wpdb->results_map = array(
			'FROM wp_memberistic_activity' => array(
				array( 'activity_type' => 'membership_renewed', 'c' => 2 ),
				array( 'activity_type' => 'payment_past_due', 'c' => 1 ),
			),
			'DATE(created_at) AS d'        => array(
				array( 'd' => '2026-06-02', 'c' => 4 ),
			),
			'GROUP BY status'              => array(
				array( 'status' => 'active', 'c' => 20 ),
				array( 'status' => 'cancelled', 'c' => 3 ),
			),
		);
		$this->wpdb->var_map     = array(
			"WHERE status = 'cancelled'"       => 1,  // cancelledInRange
			'COALESCE(start_date, created_at)' => 20, // activeAtStart reconstruction
			"BETWEEN '2026-05-29"              => 2,  // previous-period new members
			"BETWEEN '2026-06-01"              => 5,  // current newInRange
			'CASE WHEN LOWER'                  => '0',
			'SELECT MAX(COALESCE(updated_at'   => '2026-06-03 08:00:00',
		);
	}

	public function test_membership_detail_renewals_churn_series_and_trends() {
		$this->seed_membership_detail_fake();

		$detail = ( new Membership_Analytics_Provider() )->detail( $this->range() );

		$this->assertTrue( $detail['available'] );
		$this->assertSame( 5, $detail['newInRange'] );
		$this->assertSame( 2, $detail['renewalsInRange'], 'membership_renewed activity rows in range' );
		$this->assertSame( 1, $detail['failedRenewals'], 'payment_past_due activity rows in range' );

		// churn = cancelledInRange / activeAtStart, as a percent.
		$this->assertSame( 1, $detail['cancelledInRange'] );
		$this->assertSame( 20, $detail['churn']['activeAtStart'] );
		$this->assertSame( 5.0, $detail['churn']['rate'] );
		$this->assertSame( 'cancelled_in_range/active_at_start', $detail['churn']['calculation'] );

		// Daily newMembers series, zero-filled.
		$this->assertSame(
			array(
				array( 'date' => '2026-06-01', 'newMembers' => 0 ),
				array( 'date' => '2026-06-02', 'newMembers' => 4 ),
				array( 'date' => '2026-06-03', 'newMembers' => 0 ),
			),
			$detail['series']
		);

		// Trends: counts vs previous; no payments table -> zero revenue both
		// sides -> revenuePct null.
		$this->assertSame( array( 'count' => 2, 'revenueCents' => 0, 'netCents' => 0 ), $detail['trends']['previous'] );
		$this->assertSame( 150.0, $detail['trends']['deltas']['countPct'] );
		$this->assertNull( $detail['trends']['deltas']['revenuePct'] );
	}

	public function test_membership_detail_zero_counters_and_null_churn_when_activity_log_missing() {
		$this->seed_membership_detail_fake( false );
		// activeAtStart also not derivable in this scenario.
		unset( $this->wpdb->var_map['COALESCE(start_date, created_at)'] );

		$detail = ( new Membership_Analytics_Provider() )->detail( $this->range() );

		$this->assertTrue( $detail['available'] );
		// Frontend contract types these as plain numbers.
		$this->assertSame( 0, $detail['renewalsInRange'] );
		$this->assertSame( 0, $detail['failedRenewals'] );
		// churn.rate IS nullable in the contract — never a fabricated 0%.
		$this->assertNull( $detail['churn']['activeAtStart'] );
		$this->assertNull( $detail['churn']['rate'] );
	}

	public function test_membership_detail_plan_breakdown_carries_active_and_mrr_cents() {
		$this->wpdb->tables      = array( 'wp_memberistic_memberships', 'wp_memberistic_plans' );
		$this->wpdb->results_map = array(
			'GROUP BY m.plan_id'          => array(
				array( 'plan_id' => 1, 'mrr_sum' => '123.45' ),
				array( 'plan_id' => 2, 'mrr_sum' => '50.00' ),
			),
			'FROM wp_memberistic_plans p' => array(
				array( 'plan_id' => 1, 'name' => 'Individual', 'count' => 8, 'revenue_sum' => '400.00' ),
				array( 'plan_id' => 2, 'name' => 'Family', 'count' => 2, 'revenue_sum' => '150.25' ),
			),
			'GROUP BY status'             => array( array( 'status' => 'active', 'c' => 10 ) ),
		);
		$this->wpdb->var_map     = array( 'CASE WHEN LOWER' => '173.45' );

		$detail = ( new Membership_Analytics_Provider() )->detail( $this->range() );

		// Frontend renders {planId, name, active, mrrCents}; the overview
		// keys (count, revenueCents) stay on the same rows, additively.
		$this->assertSame(
			array(
				array( 'planId' => 1, 'name' => 'Individual', 'count' => 8, 'revenueCents' => 40000, 'active' => 8, 'mrrCents' => 12345 ),
				array( 'planId' => 2, 'name' => 'Family', 'count' => 2, 'revenueCents' => 15025, 'active' => 2, 'mrrCents' => 5000 ),
			),
			$detail['planBreakdown'],
			'active mirrors the per-plan active count; per-plan MRR from the GROUP BY plan_id pass'
		);
	}

	public function test_churn_pure_math_percent_scale_and_null_cases() {
		// rate is a PERCENT (formatPercent renders "5.0%"), not a fraction.
		$this->assertSame( 5.0, Membership_Analytics_Provider::churn( 5, 100 )['rate'] );
		$this->assertSame( 33.33, Membership_Analytics_Provider::churn( 1, 3 )['rate'] );
		$this->assertNull( Membership_Analytics_Provider::churn( 3, 0 )['rate'], 'zero activeAtStart -> null, no division' );
		$this->assertNull( Membership_Analytics_Provider::churn( 3, null )['rate'] );
		$this->assertNull( Membership_Analytics_Provider::churn( 3, null )['activeAtStart'] );
		$this->assertSame(
			'cancelled_in_range/active_at_start',
			Membership_Analytics_Provider::churn( 0, null )['calculation']
		);
	}

	// ---- woocommerce detail (pure parts; wc_get_orders needs Woo) --------------------

	public function test_top_by_revenue_orders_desc_slices_ten_and_keeps_column_order() {
		$rows = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$rows[ 'p' . $i ] = array(
				'productId'    => $i,
				'name'         => 'Product ' . $i,
				'qty'          => $i,
				'revenueCents' => $i * 100,
			);
		}

		$top = Woo_Analytics_Provider::top_by_revenue( $rows, array( 'productId', 'name', 'qty', 'revenueCents' ) );

		$this->assertCount( 10, $top );
		$this->assertSame( 12, $top[0]['productId'], 'highest revenue first' );
		$this->assertSame( 3, $top[9]['productId'], 'products 1 and 2 fell off the top-10' );
		$this->assertSame(
			array( 'productId', 'name', 'qty', 'revenueCents' ),
			array_keys( $top[0] )
		);
		$revenues = array_column( $top, 'revenueCents' );
		$sorted   = $revenues;
		rsort( $sorted );
		$this->assertSame( $sorted, $revenues, 'strictly descending by revenue' );
	}

	public function test_top_by_revenue_tie_break_is_deterministic() {
		$top = Woo_Analytics_Provider::top_by_revenue(
			array(
				'b' => array( 'term' => 'b-term', 'name' => 'B', 'revenueCents' => 500, 'qty' => 1 ),
				'a' => array( 'term' => 'a-term', 'name' => 'A', 'revenueCents' => 500, 'qty' => 1 ),
			),
			array( 'term', 'name', 'revenueCents', 'qty' )
		);
		$this->assertSame( array( 'a-term', 'b-term' ), array_column( $top, 'term' ) );
	}

	public function test_woo_detail_unavailable_shape() {
		$this->assertFalse( class_exists( '\WooCommerce' ), 'precondition: no Woo in the unit harness' );

		$detail = ( new Woo_Analytics_Provider() )->detail( $this->range() );
		$this->assertFalse( $detail['available'] );
		$this->assertSame( array(), $detail['topProducts'] );
		$this->assertSame( array(), $detail['byCategory'] );
		$this->assertSame( array(), $detail['series'] );
		$this->assertNull( $detail['trends']['deltas']['countPct'] );
	}

	// ---- waivers detail ---------------------------------------------------------------

	public function test_waiver_detail_buckets_and_signings_per_source() {
		$this->wpdb->tables = array( 'wp_memberistic_people', 'wp_memberistic_waivers_archive' );

		$months     = Waiver_Summary_Provider::last_twelve_months( gmdate( 'Y-m-d H:i:s' ) );
		$this_month = end( $months );

		$this->wpdb->row_map     = array(
			'AS d30'            => array( 'd30' => 4, 'd60' => 7, 'd90' => 9 ),
			'AS expiring_count' => array(
				'current_count'  => 10,
				'expiring_count' => 4,
				'expired_count'  => 1,
				'missing_count'  => 2,
				'last_updated'   => '2026-06-03 09:00:00',
			),
			'COUNT(*) AS total' => array( 'total' => 100, 'current_count' => 90 ),
		);
		$this->wpdb->results_map = array(
			'DATE_FORMAT(waiver_signed_at' => array(
				array( 'm' => $this_month, 'c' => 5 ),
			),
			'DATE_FORMAT(signed_at'        => array(
				array( 'm' => $this_month, 'c' => 8 ),
			),
		);

		$detail = ( new Waiver_Summary_Provider() )->detail( $this->range() );

		$this->assertTrue( $detail['available'] );
		$this->assertSame( array( 'd30' => 4, 'd60' => 7, 'd90' => 9 ), $detail['expiring'] );

		// Two labelled 12-month series — never summed across sources.
		foreach ( array( 'archive', 'people' ) as $source ) {
			$this->assertCount( 12, $detail['signings'][ $source ] );
			$this->assertSame( $months, array_column( $detail['signings'][ $source ], 'month' ) );
		}
		$this->assertSame( 8, end( $detail['signings']['archive'] )['count'] );
		$this->assertSame( 5, end( $detail['signings']['people'] )['count'] );
		$this->assertSame( 0, $detail['signings']['archive'][0]['count'], 'older months zero-filled' );

		// No PII keys anywhere in the payload.
		$json = wp_json_encode( $detail );
		foreach ( array( 'email', 'phone', 'first_name', 'last_name', 'full_name' ) as $pii ) {
			$this->assertStringNotContainsString( $pii, (string) $json );
		}
	}

	public function test_waiver_monthly_helpers_are_pure_and_dense() {
		$months = Waiver_Summary_Provider::last_twelve_months( '2026-07-15 10:00:00' );
		$this->assertCount( 12, $months );
		$this->assertSame( '2025-08', $months[0] );
		$this->assertSame( '2026-07', $months[11] );

		$series = Waiver_Summary_Provider::fill_monthly_series(
			$months,
			array( array( 'm' => '2026-01', 'c' => 7 ) )
		);
		$this->assertCount( 12, $series );
		$this->assertSame( 7, $series[ array_search( '2026-01', $months, true ) ]['count'] );
		$this->assertSame( 0, $series[0]['count'] );
	}
}
