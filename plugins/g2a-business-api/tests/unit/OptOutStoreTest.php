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
		// Case-insensitive lookup + default `all` bucket.
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

	public function test_category_scoped_opt_out_isolates_from_other_categories() {
		$store = new Opt_Out_Store();
		$store->record( 'priya@example.com', Opt_Out_Store::CATEGORY_MARKETING );

		$this->assertTrue(
			$store->opted_out( 'priya@example.com', Opt_Out_Store::CATEGORY_MARKETING ),
			'Marketing opt-out should stop marketing.'
		);
		$this->assertFalse(
			$store->opted_out( 'priya@example.com', Opt_Out_Store::CATEGORY_RENEWALS ),
			'Marketing opt-out should NOT stop renewals.'
		);
		$this->assertFalse(
			$store->opted_out( 'priya@example.com', Opt_Out_Store::CATEGORY_TRANSACTIONAL ),
			'Marketing opt-out should NOT stop transactional mail.'
		);
	}

	public function test_all_category_blocks_every_category() {
		$store = new Opt_Out_Store();
		$store->record( 'x@example.com', Opt_Out_Store::CATEGORY_ALL );

		foreach ( array( Opt_Out_Store::CATEGORY_MARKETING, Opt_Out_Store::CATEGORY_RENEWALS, Opt_Out_Store::CATEGORY_TRANSACTIONAL ) as $cat ) {
			$this->assertTrue( $store->opted_out( 'x@example.com', $cat ), "`all` opt-out must block category `$cat`." );
		}
	}

	public function test_category_scoped_restore() {
		$store = new Opt_Out_Store();
		$store->record( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING );
		$store->record( 'x@example.com', Opt_Out_Store::CATEGORY_RENEWALS );

		$store->restore( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING );
		$this->assertFalse( $store->opted_out( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING ) );
		$this->assertTrue(  $store->opted_out( 'x@example.com', Opt_Out_Store::CATEGORY_RENEWALS ) );
	}

	public function test_all_restore_drops_every_category() {
		$store = new Opt_Out_Store();
		$store->record( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING );
		$store->record( 'x@example.com', Opt_Out_Store::CATEGORY_RENEWALS );

		$store->restore( 'x@example.com', Opt_Out_Store::CATEGORY_ALL );
		$this->assertFalse( $store->opted_out( 'x@example.com', Opt_Out_Store::CATEGORY_MARKETING ) );
		$this->assertFalse( $store->opted_out( 'x@example.com', Opt_Out_Store::CATEGORY_RENEWALS ) );
		$this->assertSame( 0, $store->count() );
	}

	public function test_normalize_category_rejects_unknown() {
		$this->assertSame( '',                                       Opt_Out_Store::normalize_category( 'not-a-cat' ) );
		$this->assertSame( Opt_Out_Store::CATEGORY_MARKETING,        Opt_Out_Store::normalize_category( ' MARKETING ' ) );
		$this->assertSame( Opt_Out_Store::CATEGORY_ALL,              Opt_Out_Store::normalize_category( '' ) );
	}

	public function test_migrates_legacy_flat_row_shape_on_read() {
		// Phase-8 flat shape: no category nesting.
		$GLOBALS['g2aba_test_options']['g2aba_opt_outs'] = array(
			'legacy@example.com' => array(
				'email'      => 'legacy@example.com',
				'optedOutAt' => '2026-06-01T10:00:00Z',
				'reason'     => 'user_requested',
			),
		);

		$store = new Opt_Out_Store();
		// Should be opted out of every category via the migrated `all` bucket.
		$this->assertTrue( $store->opted_out( 'legacy@example.com', Opt_Out_Store::CATEGORY_MARKETING ) );
		$this->assertTrue( $store->opted_out( 'legacy@example.com', Opt_Out_Store::CATEGORY_RENEWALS ) );

		$entries = $store->all_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( Opt_Out_Store::CATEGORY_ALL, $entries[0]['category'] );
	}
}
