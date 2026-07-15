<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Auth\Session_Cookie;

class SessionCookieTest extends TestCase {
	public function test_set_cookie_header_carries_every_contract_attribute() {
		$token  = str_repeat( 'a', 64 );
		$header = Session_Cookie::build_header_value( $token, 43200 );

		$this->assertStringStartsWith( 'g2aba_session=' . $token . ';', $header );
		$this->assertStringContainsString( 'Max-Age=43200', $header );
		$this->assertStringContainsString( 'Path=/wp-json/g2a/v1', $header );
		$this->assertStringContainsString( 'Secure', $header );
		$this->assertStringContainsString( 'HttpOnly', $header );
		$this->assertStringContainsString( 'SameSite=None', $header );
	}

	public function test_clear_cookie_expires_in_the_past() {
		$header = Session_Cookie::build_header_value( '', -1 );

		$this->assertStringStartsWith( 'g2aba_session=;', $header );
		$this->assertStringContainsString( 'Max-Age=0', $header );
		$this->assertStringContainsString( 'Expires=Thu, 01 Jan 1970 00:00:00 GMT', $header );
		$this->assertStringContainsString( 'Path=/wp-json/g2a/v1', $header );
	}

	public function test_samesite_override_is_respected() {
		$header = Session_Cookie::build_header_value( str_repeat( 'a', 64 ), 60, 'Lax' );
		$this->assertStringContainsString( 'SameSite=Lax', $header );
	}

	public function test_samesite_defaults_to_none() {
		// apply_filters is a passthrough in the test bootstrap, so this pins
		// the shipped default (cross-origin dashboard).
		$this->assertSame( 'None', Session_Cookie::samesite() );
	}

	public function test_path_is_the_rest_namespace_root() {
		$this->assertSame( '/wp-json/g2a/v1', Session_Cookie::path() );
	}

	public function test_read_accepts_only_64_hex_values() {
		$token = str_repeat( 'f', 64 );
		$this->assertSame( $token, Session_Cookie::read( array( 'g2aba_session' => $token ) ) );

		$this->assertSame( '', Session_Cookie::read( array() ) );
		$this->assertSame( '', Session_Cookie::read( array( 'g2aba_session' => 'short' ) ) );
		$this->assertSame( '', Session_Cookie::read( array( 'g2aba_session' => strtoupper( $token ) ) ) );
		$this->assertSame( '', Session_Cookie::read( array( 'g2aba_session' => $token . 'z' ) ) );
	}
}
