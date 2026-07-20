<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Distributor_Support;
use WpisticFFL\Tests\Unit\Support\FakeWpdb;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-db.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-distributor-support.php';
require_once __DIR__ . '/Support/FakeWpdb.php';

/**
 * G2A_Distributor_Support centralizes the crypto, catalog-upsert, and
 * order-bookkeeping plumbing every distributor client shares. Its crypto
 * half was extracted from G2A_Lipseys's own (already-tested) AES-256-GCM
 * code -- these tests cover that the extraction preserved behavior and
 * that it's correctly namespaced per distributor (a leaked key for one
 * distributor's secret shouldn't decrypt another's).
 */
final class DistributorSupportTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-fixed-salt-value' );
	}

	public function test_encrypt_then_decrypt_round_trips_with_the_same_context(): void {
		$plain     = 'hunter2-but-real';
		$encrypted = G2A_Distributor_Support::encrypt_secret( $plain, 'rsr' );

		$this->assertStringStartsWith( 'enc:v1:', $encrypted );
		$this->assertNotSame( $plain, $encrypted );
		$this->assertSame( $plain, G2A_Distributor_Support::decrypt_secret( $encrypted, 'rsr' ) );
	}

	public function test_a_value_encrypted_under_one_context_does_not_decrypt_under_another(): void {
		$encrypted = G2A_Distributor_Support::encrypt_secret( 'cross-distributor-secret', 'rsr' );

		$this->assertSame( '', G2A_Distributor_Support::decrypt_secret( $encrypted, 'bill-hicks' ), 'Wrong context must fail closed (GCM auth failure), not decrypt to garbage.' );
	}

	public function test_lipseys_context_string_is_unchanged_by_the_extraction(): void {
		// This exact derivation must never change -- see G2A_Lipseys::CONTEXT's
		// own docblock. A changed context here would silently orphan every
		// already-encrypted Lipsey's password stored before this refactor.
		$viaShared = G2A_Distributor_Support::secret_key( 'lipseys' );
		$expected  = hash( 'sha256', 'wpistic-ffl-lipseys|unit-test-fixed-salt-value', true );
		$this->assertSame( $expected, $viaShared );
	}

	public function test_decrypt_settings_secrets_upgrades_legacy_plaintext_exactly_once(): void {
		$updateCalls = 0;
		$captured    = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$updateCalls, &$captured ) {
			++$updateCalls;
			$captured = $value;
			return true;
		} );

		$settings = [ 'username' => 'dealer1', 'password' => 'plaintext-legacy-pass' ];
		$decrypted = G2A_Distributor_Support::decrypt_settings_secrets( $settings, [ 'password' ], 'sports-south', 'wpistic_ffl_sports_south_settings' );

		$this->assertSame( 'plaintext-legacy-pass', $decrypted['password'] );
		$this->assertSame( 1, $updateCalls );
		$this->assertStringStartsWith( 'enc:v1:', $captured['password'] );

		// Second read, now already-encrypted -- must not re-write.
		$second = G2A_Distributor_Support::decrypt_settings_secrets( $captured, [ 'password' ], 'sports-south', 'wpistic_ffl_sports_south_settings' );
		$this->assertSame( 'plaintext-legacy-pass', $second['password'] );
		$this->assertSame( 1, $updateCalls, 'An already-encrypted value must not be re-written on a later read.' );
	}

	public function test_upsert_catalog_item_writes_the_expected_row(): void {
		$fake = new FakeWpdb();
		$GLOBALS['wpdb'] = $fake;
		Functions\when( 'current_time' )->justReturn( '2026-07-16 00:00:00' );

		$ok = G2A_Distributor_Support::upsert_catalog_item( 'rsr', [
			'item_no' => 'ABC123', 'description' => 'Test Item', 'manufacturer' => 'Acme',
			'price' => 19.99, 'quantity' => 4, 'is_firearm' => true,
		] );

		$this->assertTrue( $ok );
		$this->assertCount( 1, $fake->queries );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $fake->queries[0]['sql'] );
		$this->assertContains( 'ABC123', $fake->queries[0]['args'] );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_upsert_catalog_item_rejects_a_blank_item_number(): void {
		$fake = new FakeWpdb();
		$GLOBALS['wpdb'] = $fake;

		$ok = G2A_Distributor_Support::upsert_catalog_item( 'rsr', [ 'item_no' => '  ' ] );

		$this->assertFalse( $ok );
		$this->assertCount( 0, $fake->queries );
		unset( $GLOBALS['wpdb'] );
	}

	public function test_record_distributor_order_writes_both_the_order_and_an_event(): void {
		$fake = new FakeWpdb();
		$GLOBALS['wpdb'] = $fake;
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) [ 'user_login' => 'staff1' ] );

		G2A_Distributor_Support::record_distributor_order( 'bill_hicks', [
			'transfer_id' => 42, 'po_number' => 'G2A-TEST', 'item_no' => 'X1', 'quantity' => 2,
			'ship_to_ffl' => '1-23-456-78-9A-01234', 'status' => 'submitted', 'note' => 'test note',
		] );

		$this->assertCount( 2, $fake->inserts );
		$this->assertSame( 'bill_hicks', $fake->inserts[0]['data']['distributor'] );
		$this->assertSame( 'submitted', $fake->inserts[0]['data']['status'] );
		$this->assertSame( 'distributor_order_submitted', $fake->inserts[1]['data']['event_type'] );
		unset( $GLOBALS['wpdb'] );
	}
}
