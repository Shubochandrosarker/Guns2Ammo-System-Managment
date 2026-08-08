<?php

namespace WpisticFFL\Tests\Unit;

use Brain\Monkey\Functions;
use WpisticFFL\G2A_Lipseys;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-lipseys.php';

/**
 * v1.15.1 added at-rest AES-256-GCM encryption for the Lipsey's dealer
 * password with zero test coverage. G2A_Lipseys::settings() is the only
 * public entry point that exercises encrypt_secret()/decrypt_secret()/
 * secret_key() (all private), so these tests drive it end-to-end through
 * a captured get_option()/update_option() round trip rather than reaching
 * for reflection.
 */
final class LipseysCredentialEncryptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults = [] ) {
			return array_merge( $defaults, (array) $args );
		} );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-fixed-salt-value' );
	}

	public function test_empty_password_is_left_untouched(): void {
		Functions\when( 'get_option' )->justReturn( [ 'email' => 'dealer@example.test', 'password' => '' ] );
		Functions\expect( 'update_option' )->never();

		$settings = G2A_Lipseys::settings();

		$this->assertSame( '', $settings['password'] );
	}

	public function test_legacy_plaintext_decrypts_passthrough_then_round_trips_after_transparent_reencryption(): void {
		$plain = 'Corr3ct-Horse-Battery-Staple!';
		Functions\when( 'get_option' )->justReturn( [ 'email' => 'dealer@example.test', 'password' => $plain ] );

		$captured    = null;
		$update_calls = 0;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured, &$update_calls ) {
			++$update_calls;
			$captured = $value;
			return true;
		} );

		$first = G2A_Lipseys::settings();
		$this->assertSame( $plain, $first['password'], 'A legacy plaintext value must still flow through unchanged on the read that upgrades it.' );
		$this->assertSame( 1, $update_calls, 'The legacy value must be re-stored encrypted exactly once.' );
		$this->assertStringStartsWith( 'enc:v1:', $captured['password'] );
		$this->assertNotSame( $plain, $captured['password'], 'The re-stored value must not be the bare plaintext.' );

		// Simulate the next page load: get_option() now returns the
		// already-encrypted value captured above -- ciphertext-at-rest is
		// stable, so update_option() must not fire again.
		Functions\when( 'get_option' )->justReturn( $captured );

		$second = G2A_Lipseys::settings();
		$this->assertSame( $plain, $second['password'], 'The encrypted value must decrypt back to the exact original password.' );
		$this->assertSame( 1, $update_calls, 'An already-encrypted value must not be re-written.' );
	}

	public function test_tampered_ciphertext_fails_closed_to_an_empty_password(): void {
		$plain = 'another-real-password-42';
		Functions\when( 'get_option' )->justReturn( [ 'email' => 'dealer@example.test', 'password' => $plain ] );

		$captured = null;
		Functions\when( 'update_option' )->alias( function ( $key, $value ) use ( &$captured ) {
			$captured = $value;
			return true;
		} );
		G2A_Lipseys::settings(); // produces a real ciphertext in $captured

		$tampered            = $captured;
		$payload             = substr( $tampered['password'], strlen( 'enc:v1:' ) );
		$raw                 = base64_decode( $payload, true );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 0xFF ); // flip the last ciphertext byte
		$tampered['password'] = 'enc:v1:' . base64_encode( $raw );

		Functions\when( 'get_option' )->justReturn( $tampered );

		$settings = G2A_Lipseys::settings();

		$this->assertSame( '', $settings['password'], 'GCM must fail closed on a tampered auth tag/ciphertext, never return garbage as a "password".' );
	}
}
