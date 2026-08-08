<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Cors;

class CorsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_exact_origin_matches() {
		$this->assertSame(
			'https://app.guns2ammo.com',
			Cors::match_origin( 'https://app.guns2ammo.com', array( 'https://app.guns2ammo.com' ) )
		);
	}

	public function test_match_is_case_insensitive_and_strips_trailing_slash() {
		$this->assertSame(
			'https://app.guns2ammo.com',
			Cors::match_origin( 'HTTPS://App.Guns2Ammo.com/', array( 'https://app.guns2ammo.com' ) )
		);
	}

	public function test_unlisted_origin_returns_null() {
		$this->assertNull(
			Cors::match_origin( 'https://evil.example.com', array( 'https://app.guns2ammo.com' ) )
		);
	}

	public function test_scheme_must_match() {
		$this->assertNull(
			Cors::match_origin( 'http://app.guns2ammo.com', array( 'https://app.guns2ammo.com' ) )
		);
	}

	public function test_port_must_match() {
		$allowed = array( 'http://localhost:5173' );
		$this->assertSame( 'http://localhost:5173', Cors::match_origin( 'http://localhost:5173', $allowed ) );
		$this->assertNull( Cors::match_origin( 'http://localhost:4173', $allowed ) );
	}

	public function test_prefix_origins_do_not_match() {
		// A malicious origin that merely *starts with* an allowed one must fail.
		$this->assertNull(
			Cors::match_origin( 'https://app.guns2ammo.com.evil.com', array( 'https://app.guns2ammo.com' ) )
		);
	}

	public function test_wildcard_in_allow_list_is_never_honoured() {
		$this->assertNull(
			Cors::match_origin( 'https://evil.example.com', array( '*' ) )
		);
	}

	public function test_garbage_origin_returns_null() {
		$allowed = array( 'https://app.guns2ammo.com' );
		$this->assertNull( Cors::match_origin( '', $allowed ) );
		$this->assertNull( Cors::match_origin( 'null', $allowed ) );
		$this->assertNull( Cors::match_origin( 'javascript:alert(1)', $allowed ) );
		$this->assertNull( Cors::match_origin( 'https://app.guns2ammo.com/path', $allowed ) );
	}

	public function test_normalize_origin_accepts_valid_shapes() {
		$this->assertSame( 'https://app.guns2ammo.com', Cors::normalize_origin( ' https://app.guns2ammo.com/ ' ) );
		$this->assertSame( 'http://localhost:5173', Cors::normalize_origin( 'http://localhost:5173' ) );
		$this->assertNull( Cors::normalize_origin( 'ftp://app.guns2ammo.com' ) );
		$this->assertNull( Cors::normalize_origin( 'https://' ) );
	}

	public function test_is_namespace_route_only_matches_g2a_v1() {
		$this->assertTrue( Cors::is_namespace_route( '/g2a/v1' ) );
		$this->assertTrue( Cors::is_namespace_route( '/g2a/v1/analytics/overview' ) );
		$this->assertFalse( Cors::is_namespace_route( '/wp/v2/posts' ) );
		$this->assertFalse( Cors::is_namespace_route( '/g2a/v10/x' ) );
	}

	public function test_allowed_origins_defaults_to_hosted_dashboard() {
		$this->assertSame( array( 'https://app.guns2ammo.com' ), Cors::allowed_origins() );
	}

	public function test_allowed_origins_reads_dashboard_settings() {
		$GLOBALS['g2aba_test_options']['g2aba_dashboard_settings'] = array(
			'allowedOrigins' => array( 'https://app.guns2ammo.com', 'https://staging.guns2ammo.com' ),
		);
		$this->assertSame(
			array( 'https://app.guns2ammo.com', 'https://staging.guns2ammo.com' ),
			Cors::allowed_origins()
		);
	}
}
