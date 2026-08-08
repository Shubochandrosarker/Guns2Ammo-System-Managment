<?php
/**
 * Schema installer for the `g2aba_sessions` table.
 *
 * Same pattern as Leads_Installer: `Activator::activate()` only fires on
 * plugin activation, never on a plugin *update*, so the version-option
 * comparison in `maybe_install()` runs from both `Activator::activate()`
 * and `Plugin::run()` and only pays for `dbDelta()` when the stored
 * `g2aba_sessions_db_version` is behind SESSIONS_DB_VERSION.
 *
 * The table backs the dashboard's HttpOnly cookie sessions. Only the
 * SHA-256 of the cookie token is stored — never the raw token — so a
 * database leak cannot be replayed as a session.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Auth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Session_Installer {
	public const SESSIONS_DB_VERSION = '1.0.0';
	private const VERSION_OPTION     = 'g2aba_sessions_db_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'g2aba_sessions';
	}

	/**
	 * Run dbDelta() only when the stored schema version is behind the
	 * current SESSIONS_DB_VERSION. No-ops otherwise.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::SESSIONS_DB_VERSION ) {
			return;
		}

		self::install();
		update_option( self::VERSION_OPTION, self::SESSIONS_DB_VERSION, false );
	}

	private static function install(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		// dbDelta needs two spaces before PRIMARY KEY and each column/index on
		// its own line to reliably diff against the existing schema.
		$sql = "CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
user_id BIGINT UNSIGNED NOT NULL,
token_hash CHAR(64) NOT NULL,
csrf_token CHAR(64) NOT NULL,
created_at DATETIME NOT NULL,
last_seen_at DATETIME NOT NULL,
expires_at DATETIME NOT NULL,
ip VARCHAR(45) DEFAULT NULL,
user_agent VARCHAR(255) DEFAULT NULL,
revoked_at DATETIME DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY token_hash (token_hash),
KEY idx_user (user_id)
) {$collate};";

		dbDelta( $sql );
	}
}
