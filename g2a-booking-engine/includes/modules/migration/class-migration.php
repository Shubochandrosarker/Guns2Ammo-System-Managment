<?php
/**
 * Migration Module — main controller.
 *
 * Owns:
 *   - Schema additions (external_ref columns, g2ab_migration_runs table)
 *   - Adapter registry
 *   - Boot wiring (admin UI, AJAX, Action Scheduler hooks)
 *
 * @package G2AB\Modules\Migration
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Migration {

	private static $instance = null;

	/** @var array<string, G2AB_Migration_Adapter_Base> */
	private $adapters = array();

	private $admin = null;
	private $runner = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// Schema additions — extend existing g2ab tables with external_ref columns + add migration_runs table.
		add_filter( 'g2ab_install_schemas', array( $this, 'register_schema_additions' ), 10, 1 );
		add_action( 'g2ab_run_migrations', array( $this, 'run_db_migrations' ), 10, 2 );

		// Register built-in adapters immediately. The module loader can boot after
		// init on real installs, so waiting for init here makes the UI look empty.
		$this->register_default_adapters();

		// Bootstrap admin UI.
		if ( is_admin() ) {
			$this->admin = new G2AB_Migration_Admin( $this );
		}

		// Bootstrap runner (Action Scheduler / cron handlers run in all contexts).
		$this->runner = new G2AB_Migration_Runner( $this );
	}

	// -----------------------------------------------------------------
	// Adapter registry
	// -----------------------------------------------------------------

	public function register_default_adapters() {
		$this->register_adapter( new G2AB_Migration_Adapter_Amelia() );
		$this->register_adapter( new G2AB_Migration_Adapter_CSV() );
		do_action( 'g2ab_migration_register_adapters', $this );
	}

	public function register_adapter( G2AB_Migration_Adapter_Base $adapter ) {
		$this->adapters[ $adapter->id() ] = $adapter;
	}

	/**
	 * @return array<string, G2AB_Migration_Adapter_Base>
	 */
	public function adapters() {
		return $this->adapters;
	}

	public function adapter( $id ) {
		return isset( $this->adapters[ $id ] ) ? $this->adapters[ $id ] : null;
	}

	/**
	 * Adapters whose source plugin is detected on this site.
	 *
	 * @return array<string, G2AB_Migration_Adapter_Base>
	 */
	public function available_adapters() {
		$out = array();
		foreach ( $this->adapters as $id => $a ) {
			try {
				if ( $a->is_available() ) $out[ $id ] = $a;
			} catch ( \Throwable $e ) {
				G2AB_Migration_Logger::log( 'adapter_detect_failed', array( 'adapter' => $id, 'error' => $e->getMessage() ) );
			}
		}
		// CSV always available.
		if ( isset( $this->adapters['csv'] ) ) $out['csv'] = $this->adapters['csv'];
		return $out;
	}

	public function runner() { return $this->runner; }
	public function admin()  { return $this->admin; }

	// -----------------------------------------------------------------
	// Schema additions
	// -----------------------------------------------------------------

	/**
	 * Add external_ref columns + g2ab_migration_runs table to schema registry.
	 * dbDelta will detect new columns and ALTER existing tables in place.
	 */
	public function register_schema_additions( $schemas ) {
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$collate = $wpdb->get_charset_collate();

		// Inject external_ref column into existing schemas (dbDelta will ALTER).
		$inject = array( 'bookings', 'resources', 'booking_types', 'payments' );
		foreach ( $inject as $name ) {
			if ( ! isset( $schemas[ $name ] ) ) continue;
			// Insert "external_ref" + KEY before the closing ") {$collate};".
			// dbDelta is line-based; safest is to inject AFTER the final non-PRIMARY KEY column.
			$schemas[ $name ] = $this->inject_external_ref_column( $schemas[ $name ] );
		}

		// New table: migration runs (audit log + rollback handles).
		$schemas['migration_runs'] = "CREATE TABLE {$prefix}g2ab_migration_runs (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
adapter VARCHAR(64) NOT NULL,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
mode VARCHAR(20) NOT NULL DEFAULT 'live',
total_records INT UNSIGNED NOT NULL DEFAULT 0,
processed_records INT UNSIGNED NOT NULL DEFAULT 0,
failed_records INT UNSIGNED NOT NULL DEFAULT 0,
skipped_records INT UNSIGNED NOT NULL DEFAULT 0,
field_map LONGTEXT,
status_map LONGTEXT,
options LONGTEXT,
log LONGTEXT,
error_summary TEXT,
started_at DATETIME DEFAULT NULL,
completed_at DATETIME DEFAULT NULL,
user_id BIGINT UNSIGNED DEFAULT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_status (status),
KEY idx_adapter (adapter),
KEY idx_started (started_at)
) {$collate};";

		return $schemas;
	}

	/**
	 * Inject `external_ref VARCHAR(64) NULL` and `KEY idx_extref (external_ref)`
	 * into a CREATE TABLE statement before the final closing paren.
	 *
	 * Safe to run multiple times — dbDelta will no-op if the column/key exists.
	 */
	private function inject_external_ref_column( $sql ) {
		if ( strpos( $sql, 'external_ref' ) !== false ) return $sql; // already present

		// Find the position of the last KEY/PRIMARY KEY/UNIQUE KEY line.
		// We append two new lines just before the closing `) {$collate};`.
		$close_pos = strrpos( $sql, ')' );
		if ( false === $close_pos ) return $sql;

		// Find the last newline before the closing paren so we can insert columns there.
		$insertion_point = strrpos( substr( $sql, 0, $close_pos ), "\n" );
		if ( false === $insertion_point ) return $sql;

		$column = "external_ref VARCHAR(64) DEFAULT NULL,\nKEY idx_extref (external_ref),\n";
		return substr( $sql, 0, $insertion_point + 1 ) . $column . substr( $sql, $insertion_point + 1 );
	}

	/**
	 * Hook for the installer's `g2ab_run_migrations` action.
	 * Runs once when DB version bumps. We don't need imperative SQL here —
	 * dbDelta on the filtered schema (above) handles ALTERs for us.
	 *
	 * Reserved for future schema bumps (e.g., 1.2.0 might add a `mappings` table).
	 */
	public function run_db_migrations( $current, $target ) {
		// no-op for 1.1.0 — schema filter handles everything
		do_action( 'g2ab_migration_after_db_upgrade', $current, $target );
	}
}
