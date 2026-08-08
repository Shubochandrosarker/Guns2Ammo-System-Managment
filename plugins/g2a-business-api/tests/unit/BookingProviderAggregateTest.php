<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Range;

class BookingProviderAggregateTest extends TestCase {
	private function range(): Range {
		return new Range( '2026-06-01', '2026-06-30' );
	}

	public function test_empty_rows_produce_zeroed_payload() {
		$out = Booking_Provider::aggregate( array(), $this->range() );

		$this->assertSame( array(), $out['bookingsByType'] );
		$this->assertSame( array( 'paid' => 0, 'unpaid' => 0 ), $out['paidVsUnpaid'] );
		$this->assertSame( 0.0, $out['cancellationRate'] );
		$this->assertSame( 0.0, $out['noShowRate'] );
		$this->assertSame( '—', $out['topBookingType'] );
		$this->assertSame( array(), $out['revenueSeries'] );
		$this->assertSame( array( 'from' => '2026-06-01', 'to' => '2026-06-30' ), $out['range'] );
	}

	public function test_paid_confirmed_and_completed_all_count_as_paid() {
		$rows = array(
			array( 'status' => 'paid',      'paid_amount' => '50.00', 'created_at' => '2026-06-02 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'confirmed', 'paid_amount' => '25.00', 'created_at' => '2026-06-03 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'completed', 'paid_amount' => '25.00', 'created_at' => '2026-06-04 10:00:00', 'type_name' => 'Range Lane' ),
		);
		$out = Booking_Provider::aggregate( $rows, $this->range() );

		$this->assertSame( 3, $out['paidVsUnpaid']['paid'] );
		$this->assertSame( 0, $out['paidVsUnpaid']['unpaid'] );
		$this->assertSame( 10000, $out['bookingsByType'][0]['revenue'] );
	}

	public function test_unpaid_statuses_carry_no_revenue() {
		$rows = array(
			array( 'status' => 'pending',  'paid_amount' => '80.00', 'created_at' => '2026-06-02 10:00:00', 'type_name' => 'CCW Class' ),
			array( 'status' => 'reserved', 'paid_amount' => '80.00', 'created_at' => '2026-06-02 11:00:00', 'type_name' => 'CCW Class' ),
			array( 'status' => 'refunded', 'paid_amount' => '80.00', 'created_at' => '2026-06-02 12:00:00', 'type_name' => 'CCW Class' ),
		);
		$out = Booking_Provider::aggregate( $rows, $this->range() );

		$this->assertSame( 0, $out['paidVsUnpaid']['paid'] );
		$this->assertSame( 3, $out['paidVsUnpaid']['unpaid'] );
		$this->assertSame( 0, $out['bookingsByType'][0]['revenue'] );
		$this->assertSame( 3, $out['bookingsByType'][0]['count'] );
	}

	public function test_cancellation_and_no_show_rates() {
		$rows = array(
			array( 'status' => 'paid',      'paid_amount' => '40.00', 'created_at' => '2026-06-02 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'paid',      'paid_amount' => '40.00', 'created_at' => '2026-06-03 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'cancelled', 'paid_amount' => '0.00',  'created_at' => '2026-06-04 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'no_show',   'paid_amount' => '0.00',  'created_at' => '2026-06-05 10:00:00', 'type_name' => 'Range Lane' ),
		);
		$out = Booking_Provider::aggregate( $rows, $this->range() );

		$this->assertSame( 25.0, $out['cancellationRate'] );
		$this->assertSame( 25.0, $out['noShowRate'] );
	}

	public function test_types_are_sorted_by_revenue_and_top_type_reported() {
		$rows = array(
			array( 'status' => 'paid', 'paid_amount' => '30.00',  'created_at' => '2026-06-02 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'paid', 'paid_amount' => '150.00', 'created_at' => '2026-06-02 11:00:00', 'type_name' => 'CCW Class' ),
		);
		$out = Booking_Provider::aggregate( $rows, $this->range() );

		$this->assertSame( 'CCW Class', $out['bookingsByType'][0]['type'] );
		$this->assertSame( 'CCW Class', $out['topBookingType'] );
	}

	public function test_revenue_series_is_sorted_by_day_in_cents() {
		$rows = array(
			array( 'status' => 'paid', 'paid_amount' => '20.00', 'created_at' => '2026-06-05 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'paid', 'paid_amount' => '10.00', 'created_at' => '2026-06-02 10:00:00', 'type_name' => 'Range Lane' ),
			array( 'status' => 'paid', 'paid_amount' => '15.00', 'created_at' => '2026-06-02 16:00:00', 'type_name' => 'Range Lane' ),
		);
		$out = Booking_Provider::aggregate( $rows, $this->range() );

		$this->assertSame(
			array(
				array( 'date' => '2026-06-02', 'value' => 2500 ),
				array( 'date' => '2026-06-05', 'value' => 2000 ),
			),
			$out['revenueSeries']
		);
	}

	public function test_paid_statuses_fallback_matches_booking_engine() {
		$this->assertSame( array( 'paid', 'confirmed', 'completed' ), Booking_Provider::paid_statuses() );
	}
}
