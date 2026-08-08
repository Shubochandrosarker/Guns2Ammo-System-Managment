<?php

namespace WpisticFFL\Tests\Unit;

use WpisticFFL\G2A_Ad_Ledger;
use WpisticFFL\G2A_Verification_Phase_B;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-ad-ledger.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-verification-phase-b.php';

/**
 * Two classes carry their own copy of the same spreadsheet-formula-injection
 * guard (customer-supplied names can otherwise plant a `=HYPERLINK(...)`
 * payload that executes when staff open a CSV export in Excel/Sheets).
 * Testing both keeps them from silently drifting apart.
 */
final class CsvInjectionTest extends TestCase {

	/** @dataProvider values */
	public function test_ad_ledger_csv_cell_neutralizes_formulas( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->invoke( G2A_Ad_Ledger::class, 'csv_cell', $input ) );
	}

	/** @dataProvider values */
	public function test_verification_phase_b_csv_cell_neutralizes_formulas( string $input, string $expected ): void {
		$this->assertSame( $expected, $this->invoke( G2A_Verification_Phase_B::class, 'csv_cell', $input ) );
	}

	public static function values(): array {
		return [
			'plain text passes through' => [ 'Acme Firearms', 'Acme Firearms' ],
			'leading = gets quoted'      => [ '=SUM(A1:A9)', "'=SUM(A1:A9)" ],
			'leading + gets quoted'      => [ '+1+1', "'+1+1" ],
			'leading - gets quoted'      => [ '-1+1', "'-1+1" ],
			'leading @ gets quoted'      => [ '@HYPERLINK("x")', '\'@HYPERLINK("x")' ],
			'empty string stays empty'   => [ '', '' ],
		];
	}

	private function invoke( string $class, string $method, string $arg ): string {
		$ref = new \ReflectionMethod( $class, $method );
		$ref->setAccessible( true );
		return $ref->invoke( null, $arg );
	}
}
