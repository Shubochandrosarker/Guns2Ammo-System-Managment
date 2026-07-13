<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_NICS;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-nics.php';

final class NicsBusinessDayTest extends TestCase {

	/** @dataProvider fixtures */
	public function test_three_business_days_from_now_skips_weekends( string $from, string $expected ): void {
		Functions\when( 'current_time' )->justReturn( strtotime( $from ) );

		$ref = new \ReflectionMethod( G2A_NICS::class, 'three_business_days_from_now' );
		$ref->setAccessible( true );
		$this->assertSame( $expected, $ref->invoke( null ) );
	}

	public static function fixtures(): array {
		return [
			// Monday + 3 business days = Thursday.
			'starting Monday lands on Thursday'     => [ '2026-07-06 09:00:00', '2026-07-09' ],
			// Thursday + 3 business days skips Sat/Sun -> the following Tuesday.
			'starting Thursday skips the weekend'   => [ '2026-07-09 09:00:00', '2026-07-14' ],
			// Friday + 3 business days skips Sat/Sun -> Wednesday.
			'starting Friday skips the weekend too' => [ '2026-07-10 09:00:00', '2026-07-15' ],
			// Monday Nov 23, 2026 + 3 business days would land on Thanksgiving
			// (Thu Nov 26, 2026) if only weekends were skipped -- the holiday
			// must also not count, pushing the true expiry to Friday Nov 27.
			'skips Thanksgiving, not just the weekend' => [ '2026-11-23 09:00:00', '2026-11-27' ],
		];
	}
}
