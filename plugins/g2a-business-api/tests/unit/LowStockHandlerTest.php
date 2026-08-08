<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Low_Stock_Handler;

class LowStockHandlerTest extends TestCase {
	public function test_compose_body_lists_each_sku() {
		$body = Low_Stock_Handler::compose_body(
			array(
				array( 'name' => 'Federal 9mm 115gr FMJ 1000rd', 'sku' => 'FED-9M-1000', 'qty' => 4, 'threshold' => 10 ),
				array( 'name' => 'Cheap Import Holster',           'sku' => '',           'qty' => 0, 'threshold' => 3 ),
			)
		);
		$this->assertStringContainsString( 'FED-9M-1000', $body );
		$this->assertStringContainsString( '4 in stock (threshold 10)', $body );
		// Empty SKU falls back to "n/a" so the line doesn't parse ambiguously.
		$this->assertStringContainsString( 'SKU n/a', $body );
	}

	public function test_run_returns_diagnostic_when_woo_absent() {
		// wc_get_products is not defined in the test bootstrap, so run() must
		// bail with the diagnostic string — not throw.
		$this->assertSame(
			'Skipped — WooCommerce is not active.',
			Low_Stock_Handler::run()
		);
	}
}
