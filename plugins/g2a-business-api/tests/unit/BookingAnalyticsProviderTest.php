<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Range;

require_once __DIR__ . '/AnalyticsWpdbFake.php';

class BookingAnalyticsProviderTest extends TestCase {
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

	// ---- payment-status mapping ------------------------------------------------

	public function test_classify_payment_status_mapping_table() {
		foreach ( array( 'succeeded', 'captured', 'paid', 'completed', 'refunded', 'partial_refund' ) as $s ) {
			$this->assertSame( 'revenue', Booking_Analytics_Provider::classify_payment_status( $s ), $s );
		}
		foreach ( array( 'pending', 'failed', 'cancelled', 'voided' ) as $s ) {
			$this->assertSame( 'excluded', Booking_Analytics_Provider::classify_payment_status( $s ), $s );
		}
		$this->assertSame( 'unknown', Booking_Analytics_Provider::classify_payment_status( 'weird_state' ) );
		$this->assertSame( 'revenue', Booking_Analytics_Provider::classify_payment_status( ' SUCCEEDED ' ), 'case/space-insensitive' );
	}

	// ---- money-cents math --------------------------------------------------------

	public function test_aggregate_payment_rows_money_math() {
		$rows = array(
			array( 'status' => 'succeeded', 'c' => 3, 'booking_c' => 3, 'amount_sum' => '150.00', 'refund_sum' => '0.00' ),
			array( 'status' => 'captured', 'c' => 1, 'booking_c' => 1, 'amount_sum' => '49.99', 'refund_sum' => '0.00' ),
			array( 'status' => 'partial_refund', 'c' => 1, 'booking_c' => 1, 'amount_sum' => '100.00', 'refund_sum' => '25.50' ),
			array( 'status' => 'refunded', 'c' => 2, 'booking_c' => 2, 'amount_sum' => '80.00', 'refund_sum' => '80.00' ),
			array( 'status' => 'pending', 'c' => 4, 'booking_c' => 4, 'amount_sum' => '400.00', 'refund_sum' => '0.00' ),
			array( 'status' => 'failed', 'c' => 2, 'booking_c' => 2, 'amount_sum' => '75.00', 'refund_sum' => '0.00' ),
			array( 'status' => 'mystery', 'c' => 1, 'booking_c' => 1, 'amount_sum' => '10.00', 'refund_sum' => '0.00' ),
		);

		$agg = Booking_Analytics_Provider::aggregate_payment_rows( $rows );

		// gross = 15000 + 4999 + 10000 + 8000 = 37999 (pending/failed/mystery excluded).
		$this->assertSame( 37999, $agg['grossCents'] );
		// refunds = 2550 + 8000 = 10550.
		$this->assertSame( 10550, $agg['refundCents'] );
		$this->assertSame( 27449, $agg['netCents'] );
		$this->assertSame( 7, $agg['includedCount'] );
		$this->assertSame( 7, $agg['includedBookingCount'] );
		$this->assertSame( 6, $agg['excludedCount'] );
		$this->assertSame( 1, $agg['unknownCount'] );
		$this->assertSame( array( 'pending' => 4, 'failed' => 2, 'mystery' => 1 ), $agg['excludedByStatus'] );
	}

	public function test_aggregate_payment_rows_empty_is_all_zero() {
		$agg = Booking_Analytics_Provider::aggregate_payment_rows( array() );
		$this->assertSame( 0, $agg['grossCents'] );
		$this->assertSame( 0, $agg['refundCents'] );
		$this->assertSame( 0, $agg['netCents'] );
	}

	// ---- unavailable path --------------------------------------------------------

	public function test_summary_unavailable_when_tables_missing() {
		$this->wpdb->tables = array(); // No booking engine schema at all.
		$provider           = new Booking_Analytics_Provider();

		$this->assertFalse( $provider->is_available() );

		$summary = $provider->summary( $this->range() );
		$this->assertFalse( $summary['available'] );
		$this->assertSame( array( 'from' => '2026-06-01', 'to' => '2026-06-30' ), $summary['range'] );
		$this->assertSame( 0, $summary['revenue']['netCents'] );
		$this->assertSame( 0, $summary['count'] );
		$this->assertNull( $summary['lastUpdatedAt'] );

		$freshness = $provider->freshness();
		$this->assertSame( 'unavailable', $freshness['status'] );
		$this->assertNull( $freshness['lastUpdatedAt'] );
	}

	public function test_summary_unavailable_when_only_bookings_table_exists() {
		$this->wpdb->tables = array( 'wp_g2ab_bookings' ); // payments ledger missing.
		$provider           = new Booking_Analytics_Provider();
		$this->assertFalse( $provider->is_available() );
	}

	// ---- full summary over the fake --------------------------------------------

	public function test_summary_aggregates_real_shapes() {
		$this->wpdb->tables      = array( 'wp_g2ab_bookings', 'wp_g2ab_payments' );
		$this->wpdb->results_map = array(
			// Booking status counts.
			'FROM wp_g2ab_bookings WHERE created_at' => array(
				array( 'status' => 'paid', 'c' => 5 ),
				array( 'status' => 'pending', 'c' => 2 ),
				array( 'status' => 'cancelled', 'c' => 1 ),
			),
			// Payment ledger grouped by status.
			'FROM wp_g2ab_payments'                  => array(
				array( 'status' => 'succeeded', 'c' => 5, 'booking_c' => 5, 'amount_sum' => '500.00', 'refund_sum' => '0.00' ),
				array( 'status' => 'oddball', 'c' => 1, 'booking_c' => 1, 'amount_sum' => '5.00', 'refund_sum' => '0.00' ),
			),
		);
		$this->wpdb->var_map     = array(
			'NOT EXISTS'          => 2,                     // orphaned paid bookings
			'SELECT MAX(updated_at)' => '2026-06-29 17:45:00', // freshness marker
		);

		$summary = ( new Booking_Analytics_Provider() )->summary( $this->range() );

		$this->assertTrue( $summary['available'] );
		// Contract keys.
		$this->assertSame( 8, $summary['count'] );
		$this->assertSame( 5, $summary['paid'] );
		$this->assertSame( 3, $summary['unpaid'] );
		$this->assertSame( 50000, $summary['revenueCents'] );
		// Rich detail.
		$this->assertSame( 50000, $summary['revenue']['grossCents'] );
		$this->assertSame( 0, $summary['revenue']['refundCents'] );
		$this->assertSame( 50000, $summary['revenue']['netCents'] );
		$this->assertSame( 10000, $summary['avgBookingValueCents'] );
		$this->assertSame( array( 'paid' => 5, 'pending' => 2, 'cancelled' => 1 ), $summary['byStatus'] );
		// Reconciliation: 2 orphans + 1 unknown-status payment.
		$this->assertSame( 3, $summary['reconciliation']['warnings'] );
		$this->assertSame( 2, $summary['reconciliation']['paidBookingsWithoutPayment'] );
		$this->assertSame( 1, $summary['reconciliation']['unknownStatusPayments'] );
		// Site tz stubbed to UTC, so DB local time == UTC.
		$this->assertSame( '2026-06-29T17:45:00Z', $summary['lastUpdatedAt'] );

		$this->assertSame( 'fresh', ( new Booking_Analytics_Provider() )->freshness()['status'] );
	}

	public function test_last_updated_at_converts_site_timezone_to_utc() {
		$GLOBALS['g2aba_test_timezone_string'] = 'America/Phoenix'; // UTC-7, no DST.

		$this->wpdb->tables  = array( 'wp_g2ab_bookings', 'wp_g2ab_payments' );
		$this->wpdb->var_map = array( 'SELECT MAX(updated_at)' => '2026-06-29 17:00:00' );

		$summary = ( new Booking_Analytics_Provider() )->summary( $this->range() );
		$this->assertSame( '2026-06-30T00:00:00Z', $summary['lastUpdatedAt'] );
	}
}
