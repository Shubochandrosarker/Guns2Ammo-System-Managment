<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Opt_Out_Signer;

class OptOutSignerTest extends TestCase {
	public function test_verify_accepts_matching_token() {
		$expires = time() + 3600;
		$token   = Opt_Out_Signer::sign( 'priya@example.com', $expires );

		$out = Opt_Out_Signer::verify( 'priya@example.com', $expires, $token );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( 'priya@example.com', $out['email'] );
	}

	public function test_verify_rejects_expired_token() {
		$expired = time() - 60;
		$token   = Opt_Out_Signer::sign( 'x@example.com', $expired );

		$out = Opt_Out_Signer::verify( 'x@example.com', $expired, $token );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'expired', $out['error'] );
	}

	public function test_verify_rejects_tampered_token() {
		$expires = time() + 3600;
		$token   = Opt_Out_Signer::sign( 'x@example.com', $expires );
		$broken  = substr_replace( $token, 'A', 0, 1 );

		$out = Opt_Out_Signer::verify( 'x@example.com', $expires, $broken );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'bad_token', $out['error'] );
	}

	public function test_verify_rejects_wrong_email() {
		$expires = time() + 3600;
		$token   = Opt_Out_Signer::sign( 'a@example.com', $expires );

		$out = Opt_Out_Signer::verify( 'b@example.com', $expires, $token );
		$this->assertFalse( $out['ok'] );
	}

	public function test_verify_normalises_email_case() {
		$expires = time() + 3600;
		$token   = Opt_Out_Signer::sign( 'PRIYA@EXAMPLE.COM', $expires );

		$out = Opt_Out_Signer::verify( 'priya@example.com', $expires, $token );
		$this->assertTrue( $out['ok'] );
	}

	public function test_verify_rejects_invalid_email() {
		$out = Opt_Out_Signer::verify( 'not-an-email', time() + 3600, 'xxx' );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'invalid_email', $out['error'] );
	}

	public function test_make_link_shape() {
		$link = Opt_Out_Signer::make_link( 'priya@example.com', 'https://guns2ammo.com/opt-out' );
		$this->assertStringStartsWith( 'https://guns2ammo.com/opt-out?email=', $link );
		$this->assertStringContainsString( '&expires=', $link );
		$this->assertStringContainsString( '&token=', $link );
	}
}
