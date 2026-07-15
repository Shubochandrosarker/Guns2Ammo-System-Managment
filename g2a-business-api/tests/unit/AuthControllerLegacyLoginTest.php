<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\REST\Auth_Controller;
use WordPressistic\G2ABA\REST\Auth_Session_Controller;

require_once __DIR__ . '/SessionsWpdbFake.php';

/**
 * The legacy Basic-auth handshake stays functional during the migration
 * window, but 0.2.0 added rate limiting, a `deprecated` response flag, and
 * `legacy_basic_login` audit entries. These tests pin those three.
 */
class AuthControllerLegacyLoginTest extends TestCase {
	private Auth_Controller $controller;

	protected function setUp(): void {
		$GLOBALS['wpdb'] = new Sessions_Fake_WPDB(); // Advisory-lock answers for Rate_Limiter.

		$GLOBALS['g2aba_test_transients'] = array();
		$GLOBALS['g2aba_test_options']    = array();
		$GLOBALS['g2aba_test_user_caps']  = array();
		$GLOBALS['g2aba_test_users']      = array();
		unset( $GLOBALS['g2aba_test_app_password_auth'] );

		$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );

		$this->controller = new Auth_Controller();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['g2aba_test_app_password_auth'] );
	}

	private function login( string $email ) {
		return $this->controller->login( new \WP_REST_Request( array( 'email' => $email, 'password' => 'app-pass' ) ) );
	}

	public function test_successful_legacy_login_is_flagged_deprecated_and_audited() {
		$user               = new \WP_User( 5, 'owner', 'owner@guns2ammo.com' );
		$user->display_name = 'Range Owner';

		$GLOBALS['g2aba_test_users']['email']['owner@guns2ammo.com'] = $user;
		$GLOBALS['g2aba_test_app_password_auth']                     = $user;
		$GLOBALS['g2aba_test_user_caps'][5]                          = array( Capabilities::READ, Capabilities::ADMIN );

		$res = $this->login( 'owner@guns2ammo.com' );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertTrue( $res->data['deprecated'] );
		$this->assertSame( 'Range Owner', $res->data['displayName'] );

		$log = $GLOBALS['g2aba_test_options']['g2aba_audit_log'];
		$this->assertSame( 'legacy_basic_login', $log[0]['kind'] );
		$this->assertSame( '203.0.113.8', $log[0]['meta']['ip'] );
	}

	public function test_legacy_login_shares_the_per_account_rate_limit() {
		// Unknown email → every attempt fails, charging the buckets first.
		for ( $i = 0; $i < Auth_Session_Controller::USER_LIMIT; $i++ ) {
			$res = $this->login( 'nobody@guns2ammo.com' );
			$this->assertInstanceOf( \WP_Error::class, $res );
			$this->assertSame( 'g2aba_auth_invalid', $res->code );
		}

		$blocked = $this->login( 'nobody@guns2ammo.com' );
		$this->assertInstanceOf( \WP_REST_Response::class, $blocked );
		$this->assertSame( 429, $blocked->status );
		$this->assertSame( 'g2aba_rate_limited', $blocked->data['code'] );
		$this->assertArrayHasKey( 'Retry-After', $blocked->headers );
	}

	public function test_legacy_login_shares_the_per_ip_rate_limit() {
		for ( $i = 0; $i < Auth_Session_Controller::IP_LIMIT; $i++ ) {
			$this->login( "probe{$i}@guns2ammo.com" ); // Rotate accounts: only the IP bucket accumulates.
		}

		$blocked = $this->login( 'fresh@guns2ammo.com' );
		$this->assertInstanceOf( \WP_REST_Response::class, $blocked );
		$this->assertSame( 429, $blocked->status );
	}
}
