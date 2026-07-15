<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Auth\Session_Auth;
use WordPressistic\G2ABA\Auth\Session_Store;
use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\REST\Auth_Session_Controller;

require_once __DIR__ . '/SessionsWpdbFake.php';

class AuthSessionControllerTest extends TestCase {
	private Sessions_Fake_WPDB $wpdb;
	private Auth_Session_Controller $controller;

	protected function setUp(): void {
		$this->wpdb      = new Sessions_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		$GLOBALS['g2aba_test_transients'] = array();
		$GLOBALS['g2aba_test_options']    = array();
		$GLOBALS['g2aba_test_user_caps']  = array();
		$GLOBALS['g2aba_test_users']      = array();
		unset( $GLOBALS['g2aba_test_wp_authenticate'] );

		$_COOKIE                = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR'] );

		Session_Auth::forget();
		$this->controller = new Auth_Session_Controller();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['g2aba_test_wp_authenticate'] );
		$_COOKIE = array();
		Session_Auth::forget();
	}

	private static function make_user( int $id = 5, string $login = 'owner', string $email = 'owner@guns2ammo.com' ): \WP_User {
		$user               = new \WP_User( $id, $login, $email );
		$user->display_name = 'Range Owner';
		return $user;
	}

	private function grant( int $user_id, array $caps ): void {
		$GLOBALS['g2aba_test_user_caps'][ $user_id ] = $caps;
	}

	private function login_request( string $username = 'owner', string $password = 'secret' ) {
		return $this->controller->login( new \WP_REST_Request( array( 'username' => $username, 'password' => $password ) ) );
	}

	private function audit_kinds(): array {
		$log = $GLOBALS['g2aba_test_options']['g2aba_audit_log'] ?? array();
		return array_map( static fn( $e ) => $e['kind'], $log );
	}

	// ---- login: success --------------------------------------------------------

	public function test_login_success_returns_the_contract_envelope() {
		$user = self::make_user();
		$this->grant( 5, array( Capabilities::READ, Capabilities::ADMIN ) );
		$GLOBALS['g2aba_test_wp_authenticate'] = $user;

		$res = $this->login_request();

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 200, $res->status );

		$data = $res->data;
		$this->assertSame( 5, $data['user']['id'] );
		$this->assertSame( 'Range Owner', $data['user']['displayName'] );
		$this->assertSame( 'owner@guns2ammo.com', $data['user']['email'] );
		$this->assertSame( array( Capabilities::READ, Capabilities::ADMIN ), $data['user']['capabilities'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $data['csrfToken'] );

		// A session row was created, storing only the token hash.
		$this->assertCount( 1, $this->wpdb->inserted );
		$stored = $this->wpdb->inserted[0]['data'];
		$this->assertSame( 5, $stored['user_id'] );
		$this->assertSame( '203.0.113.7', $stored['ip'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $stored['token_hash'] );
		// The CSRF token is returned in the body; the cookie token is not.
		$this->assertSame( $data['csrfToken'], $stored['csrf_token'] );
		$this->assertNotSame( $data['csrfToken'], $stored['token_hash'] );
		$this->assertStringNotContainsString( $stored['token_hash'], wp_json_encode( $data ) );

		$this->assertContains( 'session_login_success', $this->audit_kinds() );
	}

	public function test_read_only_user_gets_only_the_read_capability() {
		$user = self::make_user( 9, 'clerk', 'clerk@guns2ammo.com' );
		$this->grant( 9, array( Capabilities::READ ) );
		$GLOBALS['g2aba_test_wp_authenticate'] = $user;

		$res = $this->login_request( 'clerk' );
		$this->assertSame( array( Capabilities::READ ), $res->data['user']['capabilities'] );
	}

	// ---- login: failure ----------------------------------------------------------

	public function test_login_failure_is_generic_and_audited_with_ip() {
		$GLOBALS['g2aba_test_wp_authenticate'] = new \WP_Error( 'invalid_username', 'Unknown username: attacker.' );

		$res = $this->login_request( 'attacker', 'guess' );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'g2aba_auth_invalid', $res->code );
		$this->assertSame( 401, $res->data['status'] );
		// Core's enumerating message never leaks through.
		$this->assertStringNotContainsString( 'attacker', $res->get_error_message() );
		$this->assertSame( array(), $this->wpdb->inserted );

		$log = $GLOBALS['g2aba_test_options']['g2aba_audit_log'];
		$this->assertSame( 'session_login_failed', $log[0]['kind'] );
		$this->assertSame( '203.0.113.7', $log[0]['meta']['ip'] );
	}

	public function test_login_without_capability_is_refused_and_creates_no_session() {
		$user = self::make_user( 12, 'subscriber' );
		$GLOBALS['g2aba_test_wp_authenticate'] = $user; // No caps granted.

		$res = $this->login_request( 'subscriber' );

		$this->assertInstanceOf( \WP_Error::class, $res );
		$this->assertSame( 'g2aba_auth_no_capability', $res->code );
		$this->assertSame( 403, $res->data['status'] );
		$this->assertSame( array(), $this->wpdb->inserted );
		$this->assertContains( 'session_login_failed', $this->audit_kinds() );
	}

	// ---- login: rate limiting ------------------------------------------------------

	public function test_sixth_attempt_for_one_username_is_rate_limited() {
		$GLOBALS['g2aba_test_wp_authenticate'] = new \WP_Error( 'x', 'bad' );

		for ( $i = 0; $i < Auth_Session_Controller::USER_LIMIT; $i++ ) {
			$res = $this->login_request( 'owner', 'wrong' );
			$this->assertInstanceOf( \WP_Error::class, $res, "attempt {$i} should reach credential validation" );
		}

		$blocked = $this->login_request( 'owner', 'wrong' );
		$this->assertInstanceOf( \WP_REST_Response::class, $blocked );
		$this->assertSame( 429, $blocked->status );
		$this->assertSame( 'g2aba_rate_limited', $blocked->data['code'] );
		$this->assertArrayHasKey( 'Retry-After', $blocked->headers );
		$this->assertGreaterThan( 0, (int) $blocked->headers['Retry-After'] );
	}

	public function test_eleventh_attempt_from_one_ip_is_rate_limited_across_usernames() {
		$GLOBALS['g2aba_test_wp_authenticate'] = new \WP_Error( 'x', 'bad' );

		for ( $i = 0; $i < Auth_Session_Controller::IP_LIMIT; $i++ ) {
			$res = $this->login_request( 'user' . $i, 'wrong' ); // Rotate usernames: only the IP bucket accumulates.
			$this->assertInstanceOf( \WP_Error::class, $res );
		}

		$blocked = $this->login_request( 'user-fresh', 'wrong' );
		$this->assertInstanceOf( \WP_REST_Response::class, $blocked );
		$this->assertSame( 429, $blocked->status );
	}

	public function test_rate_limit_is_charged_before_credentials_are_checked() {
		$GLOBALS['g2aba_test_wp_authenticate_calls'] = array();
		$GLOBALS['g2aba_test_wp_authenticate']       = new \WP_Error( 'x', 'bad' );

		for ( $i = 0; $i <= Auth_Session_Controller::USER_LIMIT; $i++ ) {
			$this->login_request( 'owner', 'wrong' );
		}

		// The blocked 6th attempt never reached wp_authenticate.
		$this->assertCount( Auth_Session_Controller::USER_LIMIT, $GLOBALS['g2aba_test_wp_authenticate_calls'] );
	}

	// ---- GET /auth/session ------------------------------------------------------------

	public function test_session_endpoint_returns_the_envelope_for_the_cookie_session() {
		$csrf = str_repeat( 'c', 64 );
		$this->bind_session( 5, $csrf );

		$user = self::make_user();
		$this->grant( 5, array( Capabilities::READ ) );
		$GLOBALS['g2aba_test_users']['id'][5] = $user;

		$res = $this->controller->session();
		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( 5, $res->data['user']['id'] );
		$this->assertSame( $csrf, $res->data['csrfToken'] );
	}

	public function test_session_endpoint_requires_a_cookie_session() {
		$check = $this->controller->session_permissions_check();
		$this->assertInstanceOf( \WP_Error::class, $check );
		$this->assertSame( 401, $check->data['status'] );
	}

	// ---- logout / revoke-all ------------------------------------------------------------

	public function test_logout_revokes_the_row_and_unbinds_the_session() {
		$this->bind_session( 5 );

		$res = $this->controller->logout();

		$this->assertSame( array( 'ok' => true ), $res->data );
		$this->assertStringContainsString( 'SET revoked_at', $this->wpdb->queries[1] );
		$this->assertStringContainsString( 'id = 11', $this->wpdb->queries[1] );
		$this->assertFalse( Session_Auth::is_cookie_authenticated() );
		$this->assertContains( 'session_logout', $this->audit_kinds() );
	}

	public function test_revoke_all_revokes_every_session_of_the_user() {
		$this->bind_session( 5 );
		$this->wpdb->query_return = 4;

		$res = $this->controller->revoke_all();

		$this->assertSame( 4, $res->data['revoked'] );
		$this->assertStringContainsString( 'user_id = 5', $this->wpdb->queries[1] );
		$this->assertFalse( Session_Auth::is_cookie_authenticated() );
		$this->assertContains( 'session_revoked_all', $this->audit_kinds() );
	}

	/**
	 * Authenticate the "request" through the real middleware path: put a
	 * live row in the fake DB, present its cookie, run determine_current_user.
	 */
	private function bind_session( int $user_id, ?string $csrf = null ): void {
		$token = str_repeat( '3', 64 );
		$now   = time();

		$this->wpdb->row = array(
			'id'           => 11,
			'user_id'      => $user_id,
			'token_hash'   => Session_Store::hash_token( $token ),
			'csrf_token'   => $csrf ?? str_repeat( 'c', 64 ),
			'created_at'   => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
			'ip'           => '203.0.113.7',
			'user_agent'   => 'phpunit',
			'revoked_at'   => null,
		);
		$_COOKIE['g2aba_session'] = $token;

		$this->assertSame( $user_id, Session_Auth::determine_current_user( 0 ) );
		$this->assertTrue( $this->controller->session_permissions_check() );
	}
}
