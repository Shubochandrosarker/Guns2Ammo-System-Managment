<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Range;

class RangeTest extends TestCase {
	public function test_days_counts_inclusive() {
		$range = new Range( '2026-06-01', '2026-06-30' );
		$this->assertSame( 30, $range->days() );
	}

	public function test_days_single_day() {
		$range = new Range( '2026-06-15', '2026-06-15' );
		$this->assertSame( 1, $range->days() );
	}

	public function test_to_array_shape() {
		$range = new Range( '2026-06-01', '2026-06-30' );
		$this->assertSame(
			array( 'from' => '2026-06-01', 'to' => '2026-06-30' ),
			$range->to_array()
		);
	}
}
