<?php

namespace WpisticFFL\Tests\Unit;

use WpisticFFL\G2A_Status_Bridge;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-status-bridge.php';

final class StatusBridgeTest extends TestCase {

	/** @dataProvider mappings */
	public function test_map_wc_to_transfer( string $wc_status, ?string $expected ): void {
		$this->assertSame( $expected, G2A_Status_Bridge::map_wc_to_transfer( $wc_status ) );
	}

	public static function mappings(): array {
		return [
			'processing maps to payment_confirmed' => [ 'processing', 'payment_confirmed' ],
			'on-hold maps to payment_confirmed'    => [ 'on-hold', 'payment_confirmed' ],
			'completed maps to transferred'        => [ 'completed', 'transferred' ],
			'cancelled maps to cancelled'           => [ 'cancelled', 'cancelled' ],
			'failed maps to cancelled'              => [ 'failed', 'cancelled' ],
			'refunded maps to cancelled'            => [ 'refunded', 'cancelled' ],
			'unmapped status returns null'          => [ 'checkout-draft', null ],
		];
	}
}
