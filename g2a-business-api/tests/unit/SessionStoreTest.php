<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Auth\Session_Store;

require_once __DIR__ . '/SessionsWpdbFake.php';

class SessionStoreTest extends TestCase {
	private Sessions_Fake_WPDB $wpdb;

	protected function setUp(): void {
		$this->wpdb      = new Sessions_Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	private static function live_row( array $overrides = array() ): array {
		$now = time();
		return array_merge(
			array(
				'id'           => 1,
				'user_id'      => 5,
				'token_hash'   => str_repeat( 'a', 64 ),
				'csrf_token'   => str_repeat( 'b', 64 ),
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now - 60 ),
				'last_seen_at' => gmdate( 'Y-m-d H:i:s', $now - 60 ),
				'expires_at'   => gmdate( 'Y-m-d H:i:s', $now + 3600 ),
				'ip'           => '203.0.113.9',
				'user_agent'   => 'phpunit',
				'revoked_at'   => null,
			),
			$overrides
		);
	}

	// ---- pure token helpers -------------------------------------------------

	public function test_generate_token_is_64_hex_and_unique() {
		$a = Session_Store::generate_token();
		$b = Session_Store::generate_token();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $a );
		$this->assertNotSame( $a, $b );
	}

	public function test_hash_token_is_sha256() {
		$this->assertSame( hash( 'sha256', 'abc' ), Session_Store::hash_token( 'abc' ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Session_Store::hash_token( 'abc' ) );
	}

	// ---- expiry math ---------------------------------------------------------

	public function test_fresh_row_is_live() {
		$this->assertTrue( Session_Store::is_live( self::live_row() ) );
	}

	public function test_revoked_row_is_dead() {
		$row = self::live_row( array( 'revoked_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertFalse( Session_Store::is_live( $row ) );
	}

	public function test_absolute_expiry_kills_the_session() {
		$row = self::live_row( array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
		$this->assertFalse( Session_Store::is_live( $row ) );
	}

	public function test_idle_timeout_kills_the_session() {
		// Seen 3h ago against the default 2h idle window.
		$row = self::live_row( array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * 3600 ) ) );
		$this->assertFalse( Session_Store::is_live( $row ) );
		// Same row survives with a bigger idle window override.
		$this->assertTrue( Session_Store::is_live( $row, null, 4 * 3600 ) );
	}

	public function test_malformed_datetimes_fail_closed() {
		$this->assertFalse( Session_Store::is_live( self::live_row( array( 'expires_at' => 'garbage' ) ) ) );
		$this->assertSame( 0, Session_Store::to_timestamp( '' ) );
		$this->assertSame( 0, Session_Store::to_timestamp( 'not-a-date' ) );
	}

	// ---- create --------------------------------------------------------------

	public function test_create_persists_hash_never_the_raw_token() {
		$made = ( new Session_Store() )->create( 5, '203.0.113.9', 'phpunit-UA' );

		$this->assertCount( 1, $this->wpdb->inserted );
		$stored = $this->wpdb->inserted[0]['data'];

		$this->assertSame( 'wp_g2aba_sessions', $this->wpdb->inserted[0]['table'] );
		$this->assertSame( 5, $stored['user_id'] );
		$this->assertSame( Session_Store::hash_token( $made['token'] ), $stored['token_hash'] );
		$this->assertNotSame( $made['token'], $stored['token_hash'] );
		// Raw token appears nowhere in the persisted row.
		$this->assertStringNotContainsString( $made['token'], wp_json_encode( $stored ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $made['csrfToken'] );
		$this->assertSame( $made['csrfToken'], $stored['csrf_token'] );
		$this->assertNotSame( $made['token'], $made['csrfToken'] );
	}

	public function test_create_applies_the_absolute_lifetime() {
		$made   = ( new Session_Store() )->create( 5, '203.0.113.9', 'phpunit-UA' );
		$stored = $this->wpdb->inserted[0]['data'];

		$created = Session_Store::to_timestamp( $stored['created_at'] );
		$expires = Session_Store::to_timestamp( $stored['expires_at'] );
		$this->assertSame( Session_Store::DEFAULT_LIFETIME, $expires - $created );
		$this->assertSame( $stored['id'] ?? null, null ); // id comes from insert_id, not the payload.
		$this->assertSame( 1, $made['row']['id'] );
	}

	// ---- lookup --------------------------------------------------------------

	public function test_find_rejects_malformed_tokens_without_touching_the_db() {
		$store = new Session_Store();
		$this->assertNull( $store->find_live_by_token( 'not-a-token' ) );
		$this->assertNull( $store->find_live_by_token( strtoupper( str_repeat( 'a', 64 ) ) ) );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_find_returns_the_live_row() {
		$token            = str_repeat( '1', 64 );
		$this->wpdb->row  = self::live_row( array( 'token_hash' => Session_Store::hash_token( $token ) ) );

		$found = ( new Session_Store() )->find_live_by_token( $token );
		$this->assertIsArray( $found );
		$this->assertSame( 5, (int) $found['user_id'] );
		// Lookup was by hash, not by raw token.
		$this->assertStringContainsString( Session_Store::hash_token( $token ), $this->wpdb->queries[0] );
		$this->assertStringNotContainsString( $token, $this->wpdb->queries[0] );
	}

	public function test_find_returns_null_for_revoked_or_expired_rows() {
		$token           = str_repeat( '1', 64 );
		$this->wpdb->row = self::live_row(
			array(
				'token_hash' => Session_Store::hash_token( $token ),
				'revoked_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->assertNull( ( new Session_Store() )->find_live_by_token( $token ) );

		$this->wpdb->row = self::live_row(
			array(
				'token_hash' => Session_Store::hash_token( $token ),
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 10 ),
			)
		);
		$this->assertNull( ( new Session_Store() )->find_live_by_token( $token ) );
	}

	// ---- sliding window ------------------------------------------------------

	public function test_touch_skips_recently_seen_rows() {
		( new Session_Store() )->touch( self::live_row() ); // seen 60s ago < 5min interval.
		$this->assertSame( array(), $this->wpdb->updates );
	}

	public function test_touch_refreshes_stale_rows() {
		$row = self::live_row( array( 'last_seen_at' => gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
		( new Session_Store() )->touch( $row );

		$this->assertCount( 1, $this->wpdb->updates );
		$this->assertSame( array( 'id' => 1 ), $this->wpdb->updates[0]['where'] );
		$this->assertArrayHasKey( 'last_seen_at', $this->wpdb->updates[0]['data'] );
	}

	// ---- revocation + purge --------------------------------------------------

	public function test_revoke_marks_one_session() {
		( new Session_Store() )->revoke( 42 );
		$this->assertCount( 1, $this->wpdb->queries );
		$this->assertStringContainsString( 'SET revoked_at', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'id = 42', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'revoked_at IS NULL', $this->wpdb->queries[0] );
	}

	public function test_revoke_all_for_user_returns_the_count() {
		$this->wpdb->query_return = 3;
		$count = ( new Session_Store() )->revoke_all_for_user( 5 );
		$this->assertSame( 3, $count );
		$this->assertStringContainsString( 'user_id = 5', $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'revoked_at IS NULL', $this->wpdb->queries[0] );
	}

	public function test_purge_deletes_rows_dead_for_a_week() {
		$this->wpdb->query_return = 7;
		$count = ( new Session_Store() )->purge_stale();
		$this->assertSame( 7, $count );

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( 'DELETE FROM wp_g2aba_sessions', $sql );
		// The cutoff embedded in the SQL is ~7 days ago.
		preg_match( '/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $sql, $m );
		$this->assertNotEmpty( $m );
		$this->assertEqualsWithDelta( time() - 7 * 86400, Session_Store::to_timestamp( $m[1] ), 5 );
	}
}
