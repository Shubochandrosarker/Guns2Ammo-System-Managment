<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Money;

class MoneyTest extends TestCase {
	public function test_to_cents_rounds_half_up() {
		$this->assertSame( 100, Money::to_cents( 1.00 ) );
		$this->assertSame( 199, Money::to_cents( 1.99 ) );
		$this->assertSame( 200, Money::to_cents( 1.995 ) );
	}

	public function test_to_cents_from_string_strips_symbols() {
		$this->assertSame( 12995, Money::to_cents_from_string( '$129.95' ) );
		$this->assertSame( 12995, Money::to_cents_from_string( 'USD 129.95' ) );
		$this->assertSame( 0,     Money::to_cents_from_string( '' ) );
		$this->assertSame( 0,     Money::to_cents_from_string( 'invalid' ) );
	}

	public function test_sum_cents_ignores_missing_key() {
		$items = array(
			array( 'revenue' => 100 ),
			array( 'other'   => 999 ),
			array( 'revenue' => 250 ),
		);
		$this->assertSame( 350, Money::sum_cents( $items, 'revenue' ) );
	}
}
