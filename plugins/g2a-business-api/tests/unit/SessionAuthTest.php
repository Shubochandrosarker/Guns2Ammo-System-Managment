<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Auth\Session_Auth;
use WordPressistic\G2ABA\Auth\Session_Store;

require_once __DIR__ . '/SessionsWpdbFake.php';

class SessionAuthTest extends TestCase {
	private Sessions_Fake_WPDB $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new Sessions_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
		$_COOKIE         = array();
		Session_Auth::forget();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_COOKIE = array();
		Session_Auth::forget();
	}

	/**
	 * Install a live session row in the fake DB and present its cookie.
	 */
	private function present_live_cookie( int $user_id = 7, ?string $csrf = null ): array {
		$token = str_repeat( '2', 64 );
		$csrf  = $csrf ?? str_repeat( 'c', 64 );
		$now   = time();

		$this->wpdb->row = array(
			'id'           => 11,
			'user_id'      => $user_id,
			'token_hash'   => Session_Store::hash_token( $token ),
			'csrf_token'   => $csrf,
			'created_at'   => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
			'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
			'ip'           => '203.0.113.9',
			'user_agent'   => 'phpunit',
			'revoked_at'   => null,
		);
		$_COOKIE['g2aba_session'] = $token;

		return array( 'token' => $token, 'csrf' => $csrf );
	}

	// ---- determine_current_user ---------------------------------------------

	public function test_live_cookie_session_resolves_the_user() {
		$this->present_live_cookie( 7 );

		$this->assertSame( 7, Session_Auth::determine_current_user( 0 ) );
		$this->assertTrue( Session_Auth::is_cookie_authenticated() );
		$this->assertSame( 11, (int) Session_Auth::current_session()['id'] );
	}

	public function test_basic_auth_user_wins_and_no_session_binding_happens() {
		$this->present_live_cookie( 7 );

		$this->assertSame( 3, Session_Auth::determine_current_user( 3 ) );
		$this->assertFalse( Session_Auth::is_cookie_authenticated() );
	}

	public function test_expired_session_cookie_authenticates_nobody() {
		$this->present_live_cookie( 7 );
		$this->wpdb->row['expires_at'] = gmdate( 'Y-m-d H:i:s', time() - 10 );

		$this->assertFalse( Session_Auth::determine_current_user( false ) );
		$this->assertFalse( Session_Auth::is_cookie_authenticated() );
	}

	public function test_revoked_session_cookie_authenticates_nobody() {
		$this->present_live_cookie( 7 );
		$this->wpdb->row['revoked_at'] = gmdate( 'Y-m-d H:i:s' );

		$this->assertFalse( Session_Auth::determine_current_user( false ) );
	}

	public function test_absent_cookie_is_a_passthrough() {
		$this->assertFalse( Session_Auth::determine_current_user( false ) );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	// ---- rest_authentication_errors -------------------------------------------

	public function test_authentication_errors_vouches_for_the_session_user() {
		$this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$this->assertTrue( Session_Auth::rest_authentication_errors( null ) );
	}

	public function test_authentication_errors_defers_to_earlier_verdicts() {
		$err = new \WP_Error( 'x', 'y' );
		$this->assertSame( $err, Session_Auth::rest_authentication_errors( $err ) );
		$this->assertNull( Session_Auth::rest_authentication_errors( null ) );
	}

	// ---- CSRF decision matrix --------------------------------------------------

	public function test_requires_csrf_matrix() {
		$ns = '/g2a/v1';

		// Safe methods never need CSRF.
		$this->assertFalse( Session_Auth::requires_csrf( 'GET', $ns . '/tasks', true ) );
		$this->assertFalse( Session_Auth::requires_csrf( 'HEAD', $ns . '/tasks', true ) );
		$this->assertFalse( Session_Auth::requires_csrf( 'OPTIONS', $ns . '/tasks', true ) );

		// Unsafe method + cookie auth => required.
		$this->assertTrue( Session_Auth::requires_csrf( 'POST', $ns . '/tasks', true ) );
		$this->assertTrue( Session_Auth::requires_csrf( 'DELETE', $ns . '/model-connections/1', true ) );
		$this->assertTrue( Session_Auth::requires_csrf( 'POST', $ns . '/auth/session/logout', true ) );

		// Basic-auth / anonymous requests are exempt.
		$this->assertFalse( Session_Auth::requires_csrf( 'POST', $ns . '/tasks', false ) );

		// Credential-carrying login routes are exempt even with a cookie.
		$this->assertFalse( Session_Auth::requires_csrf( 'POST', $ns . '/auth/session/login', true ) );
		$this->assertFalse( Session_Auth::requires_csrf( 'POST', $ns . '/auth/login', true ) );
	}

	public function test_verify_csrf_is_constant_time_equality_with_empty_guard() {
		$this->assertTrue( Session_Auth::verify_csrf( 'abc', 'abc' ) );
		$this->assertFalse( Session_Auth::verify_csrf( 'abc', 'abd' ) );
		$this->assertFalse( Session_Auth::verify_csrf( '', '' ) );
		$this->assertFalse( Session_Auth::verify_csrf( 'abc', '' ) );
		$this->assertFalse( Session_Auth::verify_csrf( '', 'abc' ) );
	}

	// ---- enforce_csrf (rest_pre_dispatch) ---------------------------------------

	public function test_state_changing_request_without_header_is_blocked() {
		$this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$result = Session_Auth::enforce_csrf( null, null, new Csrf_Fake_Request( 'POST', '/g2a/v1/tasks' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_csrf_failed', $result->code );
		$this->assertSame( 403, $result->data['status'] );
	}

	public function test_state_changing_request_with_wrong_header_is_blocked() {
		$this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$req    = new Csrf_Fake_Request( 'POST', '/g2a/v1/tasks', array( 'x-g2a-csrf' => str_repeat( 'e', 64 ) ) );
		$result = Session_Auth::enforce_csrf( null, null, $req );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'g2aba_csrf_failed', $result->code );
	}

	public function test_state_changing_request_with_matching_header_passes() {
		$made = $this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$req = new Csrf_Fake_Request( 'POST', '/g2a/v1/tasks', array( 'x-g2a-csrf' => $made['csrf'] ) );
		$this->assertNull( Session_Auth::enforce_csrf( null, null, $req ) );
	}

	public function test_get_requests_never_need_the_header() {
		$this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$this->assertNull( Session_Auth::enforce_csrf( null, null, new Csrf_Fake_Request( 'GET', '/g2a/v1/tasks' ) ) );
	}

	public function test_basic_auth_requests_bypass_csrf() {
		// No cookie session bound — e.g. Basic auth resolved the user.
		$this->assertNull( Session_Auth::enforce_csrf( null, null, new Csrf_Fake_Request( 'POST', '/g2a/v1/tasks' ) ) );
	}

	public function test_routes_outside_the_namespace_are_untouched() {
		$this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$this->assertNull( Session_Auth::enforce_csrf( null, null, new Csrf_Fake_Request( 'POST', '/wp/v2/posts' ) ) );
	}

	public function test_login_routes_are_exempt_even_with_a_stale_cookie_session() {
		$this->present_live_cookie();
		Session_Auth::determine_current_user( 0 );

		$this->assertNull( Session_Auth::enforce_csrf( null, null, new Csrf_Fake_Request( 'POST', '/g2a/v1/auth/session/login' ) ) );
		$this->assertNull( Session_Auth::enforce_csrf( null, null, new Csrf_Fake_Request( 'POST', '/g2a/v1/auth/login' ) ) );
	}
}

/**
 * The bootstrap's WP_REST_Request shim knows nothing about routes, methods
 * or headers; this subclass adds the three accessors enforce_csrf() uses.
 */
class Csrf_Fake_Request extends \WP_REST_Request {
	private string $fake_method;
	private string $fake_route;
	private array  $fake_headers;

	public function __construct( string $method, string $route, array $headers = array() ) {
		parent::__construct();
		$this->fake_method  = $method;
		$this->fake_route   = $route;
		$this->fake_headers = $headers;
	}

	public function get_method(): string {
		return $this->fake_method;
	}

	public function get_route(): string {
		return $this->fake_route;
	}

	public function get_header( $key ) {
		return $this->fake_headers[ strtolower( (string) $key ) ] ?? null;
	}
}
