<?php
/**
 * Schema installer for the `g2aba_leads` table.
 *
 * g2a-business-api has never owned a real database table before this phase
 * (everything else is `wp_options`-backed). `Activator::activate()` only
 * fires on plugin activation, never on a plugin *update* — a prior phase in
 * this project found that gap silently breaks anything scheduled only
 * there. `maybe_install()` guards against that: it compares the stored
 * `g2aba_leads_db_version` option against LEADS_DB_VERSION and only pays for
 * a `dbDelta()` call when the version actually changed. It is safe (and
 * cheap — one `get_option()`) to call on every request, so it is wired from
 * both `Activator::activate()` and `Plugin::run()`.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leads_Installer {
	public const LEADS_DB_VERSION = '1.0.0';
	private const VERSION_OPTION  = 'g2aba_leads_db_version';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'g2aba_leads';
	}

	/**
	 * Run dbDelta() only when the stored schema version is behind the
	 * current LEADS_DB_VERSION. No-ops otherwise.
	 */
	public static function maybe_install(): void {
		if ( get_option( self::VERSION_OPTION ) === self::LEADS_DB_VERSION ) {
			return;
		}

		self::install();
		update_option( self::VERSION_OPTION, self::LEADS_DB_VERSION, false );
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
category VARCHAR(40) NOT NULL,
source VARCHAR(30) NOT NULL,
source_ref_id VARCHAR(64) DEFAULT NULL,
status VARCHAR(20) NOT NULL DEFAULT 'new',
contact_name VARCHAR(190) DEFAULT NULL,
contact_email VARCHAR(190) DEFAULT NULL,
contact_phone VARCHAR(40) DEFAULT NULL,
subject VARCHAR(255) DEFAULT NULL,
excerpt TEXT,
assigned_agent VARCHAR(60) DEFAULT NULL,
meta LONGTEXT,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY source_ref (source, source_ref_id),
KEY idx_category (category),
KEY idx_status (status),
KEY idx_created (created_at)
) {$collate};";

		dbDelta( $sql );
	}
}
