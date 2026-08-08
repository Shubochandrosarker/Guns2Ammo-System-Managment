<?php
/**
 * Unit tests for G2AB_Booking_Transitions::is_allowed()
 * (includes/services/class-booking-transitions.php).
 *
 * transition() touches the database and is deliberately NOT exercised here.
 */

use PHPUnit\Framework\TestCase;

final class BookingTransitionsTest extends TestCase {

	protected function setUp(): void {
		g2ab_tests_reset_state();
	}

	public function testPendingToPaidIsAllowed(): void {
		$this->assertTrue( G2AB_Booking_Transitions::is_allowed( 'pending', 'paid' ) );
	}

	public function testPendingToCompletedIsNotAllowed(): void {
		$this->assertFalse( G2AB_Booking_Transitions::is_allowed( 'pending', 'completed' ) );
	}

	public function testRefundedIsTerminalNoExits(): void {
		$this->assertSame( array(), G2AB_Booking_Transitions::allowed()['refunded'] );
		foreach ( array_keys( G2AB_Booking_Statuses::all() ) as $to ) {
			if ( 'refunded' === $to ) {
				continue; // same-status is an idempotent no-op, not an exit.
			}
			$this->assertFalse(
				G2AB_Booking_Transitions::is_allowed( 'refunded', $to ),
				"refunded → {$to} must not be allowed."
			);
		}
	}

	public function testExpiredToPendingIsAllowed(): void {
		// An expired checkout hold can be revived when the customer resumes payment.
		$this->assertTrue( G2AB_Booking_Transitions::is_allowed( 'expired', 'pending' ) );
	}

	public function testPaidToPartiallyRefundedIsAllowed(): void {
		$this->assertTrue( G2AB_Booking_Transitions::is_allowed( 'paid', 'partially_refunded' ) );
	}

	public function testCancelledToCompletedIsNotAllowed(): void {
		$this->assertFalse( G2AB_Booking_Transitions::is_allowed( 'cancelled', 'completed' ) );
	}

	public function testSameStatusIsIdempotentNoOp(): void {
		$this->assertTrue( G2AB_Booking_Transitions::is_allowed( 'refunded', 'refunded' ) );
		$this->assertTrue( G2AB_Booking_Transitions::is_allowed( 'pending', 'pending' ) );
	}

	public function testUnknownFromStatusIsNotAllowed(): void {
		$this->assertFalse( G2AB_Booking_Transitions::is_allowed( 'bogus', 'paid' ) );
	}
}
