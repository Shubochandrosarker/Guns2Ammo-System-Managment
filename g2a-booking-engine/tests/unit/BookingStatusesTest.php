<?php
/**
 * Unit tests for G2AB_Booking_Statuses (includes/services/class-booking-statuses.php).
 */

use PHPUnit\Framework\TestCase;

final class BookingStatusesTest extends TestCase {

	protected function setUp(): void {
		g2ab_tests_reset_state();
	}

	public function testPartiallyRefundedIsPresentInAll(): void {
		$all = G2AB_Booking_Statuses::all();
		$this->assertArrayHasKey( 'partially_refunded', $all );
		$this->assertSame( 'Partially Refunded', $all['partially_refunded'] );
		$this->assertTrue( G2AB_Booking_Statuses::is_valid( 'partially_refunded' ) );
	}

	public function testPartiallyRefundedIsBlocking(): void {
		$this->assertContains( 'partially_refunded', G2AB_Booking_Statuses::blocking() );
		$this->assertTrue( G2AB_Booking_Statuses::is_blocking( 'partially_refunded' ) );
	}

	public function testPartiallyRefundedHasDedicatedColor(): void {
		$this->assertSame( '#6D28D9', G2AB_Booking_Statuses::color( 'partially_refunded' ) );
		$this->assertNotSame(
			G2AB_Booking_Statuses::color( 'some_unknown_status' ),
			G2AB_Booking_Statuses::color( 'partially_refunded' ),
			'partially_refunded must not fall back to the default badge color.'
		);
	}

	public function testTerminalSetUnchanged(): void {
		$this->assertEqualsCanonicalizing(
			array( 'completed', 'cancelled', 'no_show', 'refunded', 'expired' ),
			G2AB_Booking_Statuses::terminal()
		);
		$this->assertNotContains( 'partially_refunded', G2AB_Booking_Statuses::terminal() );
	}
}
