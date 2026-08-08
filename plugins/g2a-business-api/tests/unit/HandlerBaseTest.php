<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Automation_Store;
use WordPressistic\G2ABA\Automation\Handlers\Handler_Base;

/**
 * Concrete handler used to exercise the base class without pulling in a
 * real store or WooCommerce.
 */
final class Fake_Handler extends Handler_Base {
	public static string $will_throw = '';
	public static string $return_value = 'ok';

	public static function slug(): string {
		return 'weekly-business-report';
	}

	public static function run(): string {
		if ( '' !== self::$will_throw ) {
			throw new \RuntimeException( self::$will_throw );
		}
		return self::$return_value;
	}
}

class HandlerBaseTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
		Automation_Store::seed_defaults();
		Fake_Handler::$will_throw   = '';
		Fake_Handler::$return_value = 'ok';
	}

	public function test_invoke_records_result_on_store() {
		Fake_Handler::$return_value = 'Drafted the weekly report.';
		Fake_Handler::invoke();

		$rec = ( new Automation_Store() )->find( 'weekly-business-report' );
		$this->assertSame( 'Drafted the weekly report.', $rec['lastResult'] );
		$this->assertNotEmpty( $rec['lastRun'] );
		$this->assertSame( 1, $rec['runsLast7d'] );
	}

	public function test_invoke_captures_thrown_exceptions() {
		Fake_Handler::$will_throw = 'DB is on fire';
		Fake_Handler::invoke();

		$rec = ( new Automation_Store() )->find( 'weekly-business-report' );
		$this->assertStringContainsString( 'Handler failed', $rec['lastResult'] );
		$this->assertStringContainsString( 'DB is on fire',  $rec['lastResult'] );
	}
}
