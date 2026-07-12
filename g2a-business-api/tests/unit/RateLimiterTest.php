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

	public function test_takes_and_releases_the_advisory_lock_around_the_bucket_readwrite() {
		$GLOBALS['wpdb'] = new Rate_Limiter_Fake_WPDB();
		try {
			$rl = new Rate_Limiter( 'test-lock', 5, 60 );
			$this->assertTrue( $rl->hit( '10.0.0.1' )['allowed'] );

			$this->assertSame( 1, $GLOBALS['wpdb']->get_lock_calls );
			$this->assertSame( 1, $GLOBALS['wpdb']->release_lock_calls );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	public function test_fails_closed_when_the_advisory_lock_cannot_be_acquired() {
		$fake            = new Rate_Limiter_Fake_WPDB();
		$fake->lock_grant = false;
		$GLOBALS['wpdb'] = $fake;
		try {
			$rl     = new Rate_Limiter( 'test-lock-busy', 5, 60 );
			$result = $rl->hit( '10.0.0.1' );

			$this->assertFalse( $result['allowed'] );
			$this->assertSame( 0, $result['remaining'] );
			// Never got past the lock, so the bucket was never touched.
			$this->assertSame( 0, $fake->release_lock_calls );
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}
}

/**
 * Minimal `$wpdb` double: answers GET_LOCK/RELEASE_LOCK via prepared-query
 * pattern matching (the only two statements Rate_Limiter issues), and
 * counts calls so tests can assert the lock is actually taken and released.
 */
class Rate_Limiter_Fake_WPDB {
	public bool $lock_grant        = true;
	public int  $get_lock_calls    = 0;
	public int  $release_lock_calls = 0;

	public function prepare( string $query, ...$args ): string {
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			$pos = strpos( $query, is_int( $arg ) ? '%d' : '%s' );
			if ( false !== $pos ) {
				$query = substr_replace( $query, (string) $arg, $pos, 2 );
			}
		}
		return $query;
	}

	public function get_var( string $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			$this->get_lock_calls++;
			return $this->lock_grant ? 1 : 0;
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			$this->release_lock_calls++;
			return 1;
		}
		return null;
	}
}
