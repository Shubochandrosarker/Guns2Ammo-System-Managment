<?php

namespace WpisticFFL\Tests\Unit;

use WpisticFFL\G2A_Multi_Sale_Watcher;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-multi-sale-watcher.php';

final class MultiSaleWatcherTest extends TestCase {

	/** @dataProvider types */
	public function test_canonical_item_type( string $input, string $expected ): void {
		$this->assertSame( $expected, G2A_Multi_Sale_Watcher::canonical_item_type( $input ) );
	}

	public static function types(): array {
		return [
			'handgun stays handgun'    => [ 'handgun', 'handgun' ],
			'pistol maps to handgun'   => [ 'Pistol', 'handgun' ],
			'revolver maps to handgun' => [ ' REVOLVER ', 'handgun' ],
			'rifle maps to long_gun'   => [ 'rifle', 'long_gun' ],
			'shotgun maps to long_gun' => [ 'shotgun', 'long_gun' ],
			'unknown maps to long_gun' => [ 'something-else', 'long_gun' ],
		];
	}
}
