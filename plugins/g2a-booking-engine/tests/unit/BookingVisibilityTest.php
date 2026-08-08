<?php
/**
 * Unit tests for G2AB_Booking_Visibility (includes/class-booking-visibility.php).
 */

use PHPUnit\Framework\TestCase;

final class BookingVisibilityTest extends TestCase {

	protected function setUp(): void {
		g2ab_tests_reset_state();
	}

	/* ── is_operational() ── */

	public function testOperationalStatusesAreOperational(): void {
		foreach ( array( 'confirmed', 'paid', 'completed', 'partially_refunded', 'no_show' ) as $status ) {
			$this->assertTrue(
				G2AB_Booking_Visibility::is_operational( array( 'status' => $status ) ),
				"'{$status}' must be operational regardless of payment mode."
			);
		}
	}

	public function testPendingIsNeverOperational(): void {
		$this->assertFalse( G2AB_Booking_Visibility::is_operational( array( 'status' => 'pending' ) ) );
		$this->assertFalse( G2AB_Booking_Visibility::is_operational( array(
			'status'       => 'pending',
			'payment_mode' => 'in_store',
			'source'       => 'frontdesk',
		) ) );
	}

	public function testReservedInStoreWebIsNotOperational(): void {
		$this->assertFalse( G2AB_Booking_Visibility::is_operational( array(
			'status'       => 'reserved',
			'payment_mode' => 'in_store',
			'source'       => 'web',
		) ), 'A public web pay-at-store attempt must never be operational.' );
	}

	public function testReservedInStoreStaffSourcesAreOperational(): void {
		foreach ( array( 'frontdesk', 'admin', 'walk_in' ) as $source ) {
			$this->assertTrue(
				G2AB_Booking_Visibility::is_operational( array(
					'status'       => 'reserved',
					'payment_mode' => 'in_store',
					'source'       => $source,
				) ),
				"Staff-created reserved/in_store booking (source={$source}) must be operational."
			);
		}
	}

	public function testReservedWithoutInStoreModeIsNotOperational(): void {
		$this->assertFalse( G2AB_Booking_Visibility::is_operational( array(
			'status'       => 'reserved',
			'payment_mode' => 'online',
			'source'       => 'frontdesk',
		) ) );
	}

	public function testObjectRowsAreSupported(): void {
		$this->assertTrue( G2AB_Booking_Visibility::is_operational( (object) array( 'status' => 'paid' ) ) );
	}

	/* ── operational_sql() (string assertions) ── */

	public function testOperationalSqlContainsStaffSourceClause(): void {
		$sql = G2AB_Booking_Visibility::operational_sql( 'b' );

		$this->assertStringContainsString( "b.status = 'reserved'", $sql );
		$this->assertStringContainsString( "b.payment_mode = 'in_store'", $sql );
		$this->assertStringContainsString( 'b.source IN (', $sql );
		foreach ( array( "'frontdesk'", "'admin'", "'manual'", "'walk_in'" ) as $source ) {
			$this->assertStringContainsString( $source, $sql );
		}
	}

	public function testOperationalSqlNeverMatchesPlainReservedWebRows(): void {
		$sql = G2AB_Booking_Visibility::operational_sql( 'b' );

		// The status IN (...) list must not include 'reserved' — reserved rows
		// only qualify through the guarded staff-source clause.
		$this->assertMatchesRegularExpression( '/b\.status IN \(([^)]*)\)/', $sql );
		preg_match( '/b\.status IN \(([^)]*)\)/', $sql, $m );
		$this->assertStringNotContainsString( "'reserved'", $m[1] );

		// 'web' is not a staff source, so the source list must not contain it.
		preg_match( '/b\.source IN \(([^)]*)\)/', $sql, $sources );
		$this->assertNotEmpty( $sources );
		$this->assertStringNotContainsString( "'web'", $sources[1] );

		// And the reserved clause is always AND-guarded by payment_mode + source.
		$this->assertMatchesRegularExpression(
			"/b\.status = 'reserved' AND b\.payment_mode = 'in_store' AND b\.source IN \(/",
			$sql
		);
	}

	public function testOperationalSqlSanitizesAlias(): void {
		$sql = G2AB_Booking_Visibility::operational_sql( 'x; DROP TABLE--' );
		$this->assertStringNotContainsString( ';', $sql );
		$this->assertStringNotContainsString( 'DROP TABLE', $sql );
	}
}
