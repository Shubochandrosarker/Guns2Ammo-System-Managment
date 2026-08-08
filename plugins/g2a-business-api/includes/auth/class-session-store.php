<?php
/**
 * Server-managed dashboard sessions backing the `g2aba_session` cookie.
 *
 * A session is a row in `{prefix}g2aba_sessions`. The cookie carries a
 * 64-hex random token; the row stores only its SHA-256 (`token_hash`), so
 * a leaked database dump cannot be replayed as a cookie. Each row also
 * holds a per-session CSRF token that the SPA echoes back in the
 * `X-G2A-CSRF` header on unsafe methods (enforced in Session_Auth).
 *
 * Lifetimes:
 *  - absolute: 12h from creation (filter `g2aba_session_lifetime`)
 *  - sliding:  2h of inactivity (filter `g2aba_session_idle_timeout`);
 *    `last_seen_at` is refreshed at most once per 5 minutes so a busy
 *    dashboard doesn't turn every GET into an UPDATE.
 *
 * Expired/revoked rows linger for observability and are purged after 7
 * days by the daily `g2aba_sessions_cleanup` cron event.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session_Store {
	public const DEFAULT_LIFETIME     = 43200; // 12h absolute cap.
	public const DEFAULT_IDLE_TIMEOUT = 7200;  // 2h sliding inactivity window.
	public const TOUCH_INTERVAL       = 300;   // Refresh last_seen_at at most once/5min.
	public const PURGE_AFTER_DAYS     = 7;     // Keep dead rows a week for forensics.
	public const CLEANUP_HOOK         = 'g2aba_sessions_cleanup';

	public static function table_name(): string {
		return Session_Installer::table_name();
	}

	/**
	 * Absolute session lifetime in seconds. Filterable, floor of 60s so a
	 * broken filter can't mint already-expired sessions.
	 */
	public static function lifetime(): int {
		return max( 60, (int) apply_filters( 'g2aba_session_lifetime', self::DEFAULT_LIFETIME ) );
	}

	/**
	 * Sliding inactivity timeout in seconds. Filterable, floor of 60s.
	 */
	public static function idle_timeout(): int {
		return max( 60, (int) apply_filters( 'g2aba_session_idle_timeout', self::DEFAULT_IDLE_TIMEOUT ) );
	}

	/**
	 * 64-hex random token (32 bytes of CSPRNG entropy).
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * What actually lands in the `token_hash` column. Pure so tests can
	 * verify the raw token is never persisted.
	 */
	public static function hash_token( string $raw_token ): string {
		return hash( 'sha256', $raw_token );
	}

	/**
	 * Pure liveness check against a row shape. A session is live when it
	 * has not been revoked, its absolute expiry is in the future, and it
	 * has been seen within the idle window.
	 *
	 * @param array    $row          Session row (DB column names).
	 * @param int|null $now          Unix timestamp override for tests.
	 * @param int|null $idle_timeout Idle window override for tests.
	 */
	public static function is_live( array $row, ?int $now = null, ?int $idle_timeout = null ): bool {
		$now          = $now ?? time();
		$idle_timeout = $idle_timeout ?? self::idle_timeout();

		if ( ! empty( $row['revoked_at'] ) ) {
			return false;
		}
		if ( self::to_timestamp( (string) ( $row['expires_at'] ?? '' ) ) <= $now ) {
			return false;
		}
		if ( self::to_timestamp( (string) ( $row['last_seen_at'] ?? '' ) ) + $idle_timeout <= $now ) {
			return false;
		}
		return true;
	}

	/**
	 * Parse a stored UTC DATETIME into a unix timestamp. 0 for garbage so
	 * malformed rows fail closed (they compare as long-expired).
	 */
	public static function to_timestamp( string $mysql_datetime ): int {
		if ( '' === $mysql_datetime ) {
			return 0;
		}
		$ts = strtotime( $mysql_datetime . ' +00:00' );
		return false === $ts ? 0 : $ts;
	}

	private static function format( int $timestamp ): string {
		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	/**
	 * Mint a fresh session for a user. Always a brand-new token — login
	 * never reuses an existing row (session-fixation resistance).
	 *
	 * @return array{token:string, csrfToken:string, row:array}
	 */
	public function create( int $user_id, string $ip, string $user_agent ): array {
		global $wpdb;

		$token = self::generate_token();
		$csrf  = self::generate_token();
		$now   = time();

		$row = array(
			'user_id'      => $user_id,
			'token_hash'   => self::hash_token( $token ),
			'csrf_token'   => $csrf,
			'created_at'   => self::format( $now ),
			'last_seen_at' => self::format( $now ),
			'expires_at'   => self::format( $now + self::lifetime() ),
			'ip'           => substr( $ip, 0, 45 ),
			'user_agent'   => substr( $user_agent, 0, 255 ),
			'revoked_at'   => null,
		);

		$wpdb->insert( self::table_name(), $row );
		$row['id'] = (int) $wpdb->insert_id;

		return array(
			'token'     => $token,
			'csrfToken' => $csrf,
			'row'       => $row,
		);
	}

	/**
	 * Resolve a raw cookie token to its LIVE session row, or null.
	 *
	 * The lookup is by SHA-256 hash (indexed, unique); liveness (revoked /
	 * absolute expiry / idle window) is then evaluated in PHP so the rules
	 * live in one testable place (`is_live`).
	 */
	public function find_live_by_token( string $raw_token ): ?array {
		global $wpdb;

		if ( ! preg_match( '/^[0-9a-f]{64}$/', $raw_token ) ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", self::hash_token( $raw_token ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			ARRAY_A
		);

		if ( ! is_array( $row ) || ! self::is_live( $row ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * Sliding-window refresh: bump `last_seen_at`, but only when the stored
	 * value is older than TOUCH_INTERVAL, so read-heavy dashboard traffic
	 * doesn't write on every request.
	 */
	public function touch( array $row, ?int $now = null ): void {
		global $wpdb;

		$now  = $now ?? time();
		$seen = self::to_timestamp( (string) ( $row['last_seen_at'] ?? '' ) );
		if ( $seen + self::TOUCH_INTERVAL > $now ) {
			return;
		}

		$wpdb->update(
			self::table_name(),
			array( 'last_seen_at' => self::format( $now ) ),
			array( 'id' => (int) ( $row['id'] ?? 0 ) )
		);
	}

	/**
	 * Revoke one session (logout). Idempotent — revoking a revoked row
	 * keeps the original revocation time.
	 */
	public function revoke( int $session_id ): void {
		global $wpdb;

		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::format( time() ),
				$session_id
			)
		);
	}

	/**
	 * Revoke every live session a user has ("sign out everywhere").
	 *
	 * @return int Number of sessions revoked.
	 */
	public function revoke_all_for_user( int $user_id ): int {
		global $wpdb;

		$table = self::table_name();
		$count = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET revoked_at = %s WHERE user_id = %d AND revoked_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				self::format( time() ),
				$user_id
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Delete rows that have been dead (revoked, or past absolute expiry)
	 * for longer than a week. Runs from the daily cleanup cron.
	 *
	 * @return int Number of rows deleted.
	 */
	public function purge_stale( int $older_than_days = self::PURGE_AFTER_DAYS ): int {
		global $wpdb;

		$cutoff = self::format( time() - max( 1, $older_than_days ) * DAY_IN_SECONDS );
		$table  = self::table_name();
		$count  = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE ( revoked_at IS NOT NULL AND revoked_at < %s ) OR expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$cutoff,
				$cutoff
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Wire the daily purge onto WP-Cron. Called from Plugin::run() on every
	 * request (cheap: one wp_next_scheduled lookup) — same pattern as
	 * Insight_Generator::register_cron().
	 */
	public static function register_cron(): void {
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + 120, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function cleanup(): void {
		( new self() )->purge_stale();
	}
}
