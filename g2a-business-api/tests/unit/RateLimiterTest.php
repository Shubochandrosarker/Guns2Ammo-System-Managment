<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Rate_Limiter;

class RateLimiterTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_transients'] = array();
	}

	public function test_allows_within_limit_then_blocks() {
		$rl = new Rate_Limiter( 'test', 3, 60 );

		$this->assertTrue( $rl->hit( '10.0.0.1' )['allowed'] );
		$this->assertTrue( $rl->hit( '10.0.0.1' )['allowed'] );
		$this->assertTrue( $rl->hit( '10.0.0.1' )['allowed'] );

		$blocked = $rl->hit( '10.0.0.1' );
		$this->assertFalse( $blocked['allowed'] );
		$this->assertGreaterThan( 0, $blocked['retryAfter'] );
	}

	public function test_ips_are_isolated() {
		$rl = new Rate_Limiter( 'test', 2, 60 );

		$rl->hit( '10.0.0.1' );
		$rl->hit( '10.0.0.1' );
		$this->assertFalse( $rl->hit( '10.0.0.1' )['allowed'] );

		// Different IP starts fresh.
		$this->assertTrue( $rl->hit( '10.0.0.2' )['allowed'] );
	}

	public function test_scopes_are_isolated() {
		$a = new Rate_Limiter( 'scope-a', 1, 60 );
		$b = new Rate_Limiter( 'scope-b', 1, 60 );

		$a->hit( '10.0.0.1' );
		$this->assertFalse( $a->hit( '10.0.0.1' )['allowed'] );
		// Same IP + different scope has its own bucket.
		$this->assertTrue( $b->hit( '10.0.0.1' )['allowed'] );
	}

	public function test_invalid_ip_is_allowed_without_incrementing_shared_bucket() {
		$rl = new Rate_Limiter( 'test', 1, 60 );
		$rl->hit( 'not-an-ip' );
		$rl->hit( 'not-an-ip' );
		// Real IP still has its full quota.
		$this->assertTrue( $rl->hit( '10.0.0.1' )['allowed'] );
	}

	public function test_client_ip_prefers_cloudflare_header() {
		$this->assertSame(
			'203.0.113.9',
			Rate_Limiter::client_ip( array( 'HTTP_CF_CONNECTING_IP' => '203.0.113.9', 'REMOTE_ADDR' => '198.51.100.1' ) )
		);
	}

	public function test_client_ip_falls_back_to_forwarded_for_then_remote_addr() {
		$this->assertSame(
			'203.0.113.9',
			Rate_Limiter::client_ip( array( 'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.1', 'REMOTE_ADDR' => '198.51.100.1' ) )
		);
		$this->assertSame(
			'198.51.100.1',
			Rate_Limiter::client_ip( array( 'REMOTE_ADDR' => '198.51.100.1' ) )
		);
	}

	public function test_client_ip_returns_empty_when_no_valid_ip_present() {
		$this->assertSame( '', Rate_Limiter::client_ip( array() ) );
		$this->assertSame( '', Rate_Limiter::client_ip( array( 'HTTP_X_FORWARDED_FOR' => 'garbage' ) ) );
	}
}
