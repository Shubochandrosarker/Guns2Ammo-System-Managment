<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Chattanooga;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-chattanooga.php';

/**
 * Chattanooga's CONTEXT = 'chattanooga' string is folded into the AES key
 * derivation the same way Lipsey's 'lipseys' context is (see
 * G2A_Distributor_Support::secret_key()'s own docblock: it must never
 * change once deployed, or every already-encrypted token at rest is
 * silently orphaned). Mirrors LipseysCredentialEncryptionTest.php so a
 * future accidental change to this class's CONTEXT/settings() shape is
 * caught by a test, not discovered against a live dealer account.
 */
final class ChattanoogaCredentialEncryptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = [] ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-fixed-salt-value' );
	}

	public function test_empty_token_is_left_untouched(): void {
		Functions\when( 'get_option' )->justReturn( [ 'sid' => 'dealer1', 'token' => '' ] );
		Functions\expect( 'update_option' )->never();

		$settings = G2A_Chattanooga::settings();

		$this->assertSame( '', $settings['token'] );
	}

	public function test_legacy_plaintext_decrypts_passthrough_then_round_trips_after_transparent_reencryption(): void {
		$plain = 'Corr3ct-Horse-Battery-Staple!';
		Functions\when( 'get_option' )->justReturn( [ 'sid' => 'dealer1', 'token' => $plain ] );

		$captured     = null;
		$update_calls = 0;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured, &$update_calls ) {
			++$update_calls;
			$captured = $value;
			return true;
		} );

		$first = G2A_Chattanooga::settings();
		$this->assertSame( $plain, $first['token'], 'A legacy plaintext value must still flow through unchanged on the read that upgrades it.' );
		$this->assertSame( 1, $update_calls, 'The legacy value must be re-stored encrypted exactly once.' );
		$this->assertStringStartsWith( 'enc:v1:', $captured['token'] );
		$this->assertNotSame( $plain, $captured['token'], 'The re-stored value must not be the bare plaintext.' );

		// Simulate the next page load: get_option() now returns the
		// already-encrypted value captured above -- ciphertext-at-rest is
		// stable, so update_option() must not fire again.
		Functions\when( 'get_option' )->justReturn( $captured );

		$second = G2A_Chattanooga::settings();
		$this->assertSame( $plain, $second['token'], 'The encrypted value must decrypt back to the exact original token.' );
		$this->assertSame( 1, $update_calls, 'An already-encrypted value must not be re-written.' );
	}

	public function test_tampered_ciphertext_fails_closed_to_an_empty_token(): void {
		$plain = 'another-real-token-42';
		Functions\when( 'get_option' )->justReturn( [ 'sid' => 'dealer1', 'token' => $plain ] );

		$captured = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
			$captured = $value;
			return true;
		} );
		G2A_Chattanooga::settings(); // produces a real ciphertext in $captured

		$tampered                = $captured;
		$payload                 = substr( $tampered['token'], strlen( 'enc:v1:' ) );
		$raw                     = base64_decode( $payload, true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF ); // flip the last ciphertext byte
		$tampered['token']       = 'enc:v1:' . base64_encode( $raw );

		Functions\when( 'get_option' )->justReturn( $tampered );

		$settings = G2A_Chattanooga::settings();

		$this->assertSame( '', $settings['token'], 'GCM must fail closed on a tampered auth tag/ciphertext, never return garbage as a "token".' );
	}
}
