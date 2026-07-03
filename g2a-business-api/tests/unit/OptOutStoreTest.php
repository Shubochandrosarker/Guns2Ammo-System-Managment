<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;

class OptOutStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_record_and_opted_out_round_trip() {
		$store = new Opt_Out_Store();
		$this->assertFalse( $store->opted_out( 'Priya@Example.com' ) );

		$store->record( 'Priya@Example.com' );
		// Case-insensitive: the key normalisation should match the mixed-case lookup.
		$this->assertTrue( $store->opted_out( 'priya@example.com' ) );
		$this->assertTrue( $store->opted_out( 'PRIYA@EXAMPLE.COM' ) );
	}

	public function test_restore_removes_entry() {
		$store = new Opt_Out_Store();
		$store->record( 'x@example.com' );
		$store->restore( 'X@Example.com' );
		$this->assertFalse( $store->opted_out( 'x@example.com' ) );
	}

	public function test_invalid_email_is_a_noop() {
		$store = new Opt_Out_Store();
		$store->record( 'not-an-email' );
		$this->assertSame( 0, $store->count() );
		$this->assertFalse( $store->opted_out( 'not-an-email' ) );
	}

	public function test_key_normalisation() {
		$this->assertSame( 'priya@example.com', Opt_Out_Store::key( '  Priya@Example.COM  ' ) );
		$this->assertSame( '', Opt_Out_Store::key( 'nope' ) );
		$this->assertSame( '', Opt_Out_Store::key( '' ) );
	}
}
