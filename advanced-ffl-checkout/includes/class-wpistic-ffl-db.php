<?php
/**
 * Database installer — creates all plugin tables.
 * Uses dbDelta for safe, idempotent upgrades.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class DB {

	/** Current schema version */
	const SCHEMA_VERSION = '1.4.0';

	/**
	 * Install or upgrade all plugin tables.
	 * Called on plugin activation and version upgrades.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix . WPISTIC_FFL_DB_PREFIX;

		// ── 1. Dealers table (synced from ATF monthly) ────────────────────────
		// NOTE: Avoid ON UPDATE CURRENT_TIMESTAMP — dbDelta does not handle it
		// reliably across all WordPress/MySQL versions. updated_at is set in PHP.
		dbDelta( "
CREATE TABLE {$p}dealers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  license_number VARCHAR(20) NOT NULL DEFAULT '',
  lic_type VARCHAR(2) NOT NULL DEFAULT '01',
  lic_regn VARCHAR(1) NOT NULL DEFAULT '',
  lic_dist VARCHAR(2) NOT NULL DEFAULT '',
  lic_cnty VARCHAR(3) NOT NULL DEFAULT '',
  lic_xprdte DATE NOT NULL DEFAULT '2099-01-01',
  business_name VARCHAR(200) NOT NULL DEFAULT '',
  premise_street VARCHAR(200) NOT NULL DEFAULT '',
  premise_city VARCHAR(100) NOT NULL DEFAULT '',
  premise_state VARCHAR(2) NOT NULL DEFAULT '',
  premise_zip VARCHAR(10) NOT NULL DEFAULT '',
  mail_street VARCHAR(200) NOT NULL DEFAULT '',
  mail_city VARCHAR(100) NOT NULL DEFAULT '',
  mail_state VARCHAR(2) NOT NULL DEFAULT '',
  mail_zip VARCHAR(10) NOT NULL DEFAULT '',
  phone VARCHAR(20) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  lat DECIMAL(10,7) DEFAULT NULL,
  lng DECIMAL(10,7) DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_preferred TINYINT(1) NOT NULL DEFAULT 0,
  transfer_fee DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  notes TEXT DEFAULT NULL,
  last_synced DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_license (license_number),
  KEY idx_zip (premise_zip),
  KEY idx_state (premise_state),
  KEY idx_type (lic_type),
  KEY idx_active_preferred (is_active, is_preferred),
  KEY idx_coords (lat, lng),
  KEY idx_expiry (lic_xprdte)
) $charset;
" );

		// ── 2. Transfers table ─────────────────────────────────────────────────
		dbDelta( "
CREATE TABLE {$p}transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_ref VARCHAR(20) NOT NULL DEFAULT '',
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(200) NOT NULL DEFAULT '',
  customer_email VARCHAR(200) NOT NULL DEFAULT '',
  customer_phone VARCHAR(20) NOT NULL DEFAULT '',
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(30) NOT NULL DEFAULT 'dealer_selected',
  item_description VARCHAR(500) NOT NULL DEFAULT '',
  item_sku VARCHAR(100) NOT NULL DEFAULT '',
  item_serial VARCHAR(100) NOT NULL DEFAULT '',
  item_make VARCHAR(100) NOT NULL DEFAULT '',
  item_model VARCHAR(100) NOT NULL DEFAULT '',
  item_caliber VARCHAR(50) NOT NULL DEFAULT '',
  item_type VARCHAR(50) NOT NULL DEFAULT 'handgun',
  shipment_carrier VARCHAR(20) NOT NULL DEFAULT '',
  shipment_tracking VARCHAR(100) NOT NULL DEFAULT '',
  shipped_date DATE DEFAULT NULL,
  dealer_received_date DATE DEFAULT NULL,
  nics_transaction_number VARCHAR(50) NOT NULL DEFAULT '',
  nics_check_date DATE DEFAULT NULL,
  nics_delay_expires DATE DEFAULT NULL,
  transfer_date DATE DEFAULT NULL,
  form_4473_ref VARCHAR(100) NOT NULL DEFAULT '',
  staff_notes TEXT DEFAULT NULL,
  customer_notified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_ref (transfer_ref),
  KEY idx_order (order_id),
  KEY idx_status (status),
  KEY idx_dealer (dealer_id),
  KEY idx_customer (customer_id),
  KEY idx_created (created_at),
  KEY idx_nics_delay (nics_delay_expires)
) $charset;
" );

		// ── 3. Audit event log (append-only) ──────────────────────────────────
		dbDelta( "
CREATE TABLE {$p}events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  event_type VARCHAR(50) NOT NULL DEFAULT 'status_change',
  old_status VARCHAR(30) DEFAULT NULL,
  new_status VARCHAR(30) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  actor VARCHAR(150) NOT NULL DEFAULT 'system',
  actor_ip VARCHAR(45) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_type (event_type),
  KEY idx_created (created_at)
) $charset;
" );

		// ── 4. ZIP code centroids ──────────────────────────────────────────────
		dbDelta( "
CREATE TABLE {$p}zip_coords (
  zip VARCHAR(5) NOT NULL,
  lat DECIMAL(9,6) NOT NULL,
  lng DECIMAL(9,6) NOT NULL,
  city VARCHAR(100) NOT NULL DEFAULT '',
  state VARCHAR(2) NOT NULL DEFAULT '',
  PRIMARY KEY  (zip),
  KEY idx_state (state)
) $charset;
" );

		// ── 5. State compliance rules ──────────────────────────────────────────
		dbDelta( "
CREATE TABLE {$p}state_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  state_code VARCHAR(2) NOT NULL,
  rule_type VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  item_types VARCHAR(200) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_state (state_code),
  KEY idx_active (is_active)
) $charset;
" );

		// ── 6. Dealer portal tokens (HMAC magic-link confirmations) ────────────
		// Stores the SHA-256 hash of the issued token, never the raw token.
		// Single-use enforced via used_at; expiry enforced via expires_at.
		dbDelta( "
CREATE TABLE {$p}dealer_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  transfer_id BIGINT UNSIGNED NOT NULL,
  dealer_id BIGINT UNSIGNED NOT NULL,
  action VARCHAR(32) NOT NULL DEFAULT 'mark_received',
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  used_action VARCHAR(32) DEFAULT NULL,
  used_ip VARBINARY(16) DEFAULT NULL,
  used_user_agent VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_ip VARBINARY(16) DEFAULT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_reminder_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_token (token_hash),
  KEY idx_transfer (transfer_id),
  KEY idx_dealer (dealer_id),
  KEY idx_status_expires (status, expires_at)
) $charset;
" );

		// ── 7. Self-contained analytics events ─────────────────────────────────
		// Replaces dependency on Insightistic plugin. Tracks portal funnel:
		// email_sent → email_opened → portal_viewed → confirmed | issue_reported
		dbDelta( "
CREATE TABLE {$p}analytics_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(50) NOT NULL DEFAULT '',
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  dealer_id BIGINT UNSIGNED DEFAULT NULL,
  token_id BIGINT UNSIGNED DEFAULT NULL,
  metadata LONGTEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_ip VARBINARY(16) DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY idx_type_date (event_type, created_at),
  KEY idx_transfer (transfer_id),
  KEY idx_dealer (dealer_id),
  KEY idx_token (token_id)
) $charset;
" );

		// ── 8. Form 4473 worksheet signatures (v1.8.0) ─────────────────────────
		// Append-only, same audit philosophy as `events`: a re-signed row adds
		// a new record rather than overwriting the prior capture, so the trail
		// of who signed what, and when, is never lost.
		dbDelta( "
CREATE TABLE {$p}signatures (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'buyer',
  signer_name VARCHAR(200) NOT NULL DEFAULT '',
  signature_data LONGTEXT NOT NULL,
  signed_by BIGINT UNSIGNED DEFAULT NULL,
  signed_ip VARBINARY(16) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer_role (transfer_id, role),
  KEY idx_created (created_at)
) $charset;
" );

		// ── 9. FFL Compliance Verification Hub (v1.9.0) ────────────────────────
		// Certified-copy files never live in a public media URL — file_path is
		// a path under wp-content/uploads/wpistic-ffl-private/, served only
		// through the capability-gated view/download endpoint.
		dbDelta( "
CREATE TABLE {$p}ffl_verification_documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dealer_id BIGINT UNSIGNED NOT NULL,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  document_type VARCHAR(80) NOT NULL DEFAULT 'certified_copy',
  file_path VARCHAR(255) NOT NULL DEFAULT '',
  file_name VARCHAR(255) NOT NULL DEFAULT '',
  mime_type VARCHAR(100) NOT NULL DEFAULT '',
  uploaded_by BIGINT UNSIGNED DEFAULT NULL,
  received_method VARCHAR(80) NOT NULL DEFAULT 'staff_upload',
  expires_at DATE DEFAULT NULL,
  status VARCHAR(50) NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_dealer (dealer_id),
  KEY idx_transfer (transfer_id),
  KEY idx_status (status),
  KEY idx_expires (expires_at)
) $charset;
" );

		// Verification checks: an address/expiration match run against our own
		// ATF-synced `dealers` row, or a manually-logged FFL eZ Check result.
		// Append-only — a re-run adds a new row, same audit philosophy as
		// `signatures` and `events`.
		dbDelta( "
CREATE TABLE {$p}ffl_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dealer_id BIGINT UNSIGNED NOT NULL,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  method VARCHAR(40) NOT NULL DEFAULT 'address_match',
  result_status VARCHAR(40) NOT NULL DEFAULT 'manual_review_required',
  checked_ffl_number VARCHAR(50) NOT NULL DEFAULT '',
  result_legal_name VARCHAR(255) NOT NULL DEFAULT '',
  result_trade_name VARCHAR(255) NOT NULL DEFAULT '',
  result_premises_address TEXT DEFAULT NULL,
  result_expiration_date DATE DEFAULT NULL,
  address_match TINYINT(1) DEFAULT NULL,
  expiration_valid TINYINT(1) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  verified_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_dealer (dealer_id),
  KEY idx_transfer (transfer_id),
  KEY idx_method (method),
  KEY idx_status (result_status)
) $charset;
" );

		// Seed state compliance rules if empty
		self::seed_state_rules();

		// Store schema version
		update_option( 'wpistic_ffl_db_version', self::SCHEMA_VERSION );
	}

	/**
	 * Seed basic state compliance rules.
	 */
	private static function seed_state_rules(): void {
		global $wpdb;
		$table = $wpdb->prefix . WPISTIC_FFL_DB_PREFIX . 'state_rules';

		// Only seed if empty
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
		if ( $count > 0 ) {
			return;
		}

		$rules = [
			[ 'CA', 'handgun_roster',   'California: Only CA DOJ-approved handguns may be sold to civilians (roster requirement).', 'handgun' ],
			[ 'CA', 'assault_weapon',   'California: Assault weapon restrictions apply. Verify compliance with CA AW laws.', 'rifle,pistol' ],
			[ 'CA', 'waiting_period',   'California: 10-day mandatory waiting period for all firearms.', 'handgun,rifle,shotgun' ],
			[ 'NY', 'assault_weapon',   'New York: SAFE Act restrictions on assault weapons and high-capacity magazines.', 'rifle,pistol' ],
			[ 'NY', 'waiting_period',   'New York: Background check required; check local laws for additional requirements.', 'handgun' ],
			[ 'NJ', 'purchase_permit',  'New Jersey: Firearms purchaser ID card required for long guns; handgun purchase permit required.', 'handgun,rifle,shotgun' ],
			[ 'MA', 'license_required', 'Massachusetts: Firearms ID Card or LTC required to purchase. Dealers must verify.', 'handgun,rifle,shotgun' ],
			[ 'MD', 'regulated',        'Maryland: Regulated firearms (handguns, assault pistols) require 77R form and 7-day waiting period.', 'handgun' ],
			[ 'IL', 'foid_required',    'Illinois: Firearm Owner\'s Identification (FOID) card required for all firearm purchases.', 'handgun,rifle,shotgun' ],
			[ 'HI', 'permit_required',  'Hawaii: Permit to purchase required for all firearms. 14-day waiting period.', 'handgun,rifle,shotgun' ],
			[ 'CO', 'background_check', 'Colorado: Background check required at FFL for all firearm transfers.', 'handgun,rifle,shotgun' ],
			[ 'WA', 'waiting_period',   'Washington: 10-day waiting period for all firearms.', 'handgun,rifle,shotgun' ],
		];

		foreach ( $rules as $rule ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[
					'state_code'  => $rule[0],
					'rule_type'   => $rule[1],
					'description' => $rule[2],
					'item_types'  => $rule[3],
					'is_active'   => 1,
				],
				[ '%s', '%s', '%s', '%s', '%d' ]
			);
		}
	}

	/**
	 * Drop all plugin tables. Called from uninstall.php.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$p = $wpdb->prefix . WPISTIC_FFL_DB_PREFIX;

		$tables = [ 'ffl_verifications', 'ffl_verification_documents', 'signatures', 'analytics_events', 'dealer_tokens', 'events', 'transfers', 'dealers', 'zip_coords', 'state_rules' ];
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Remove all plugin options
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpistic_ffl_%'" ); // phpcs:ignore

		// Certified FFL copies live on disk, not in the DB — remove them too
		// so a deleted plugin doesn't leave dealer license documents behind.
		self::delete_private_uploads();
	}

	private static function delete_private_uploads(): void {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpistic-ffl-private/';
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Get the full table name for a given table key.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . WPISTIC_FFL_DB_PREFIX . $name;
	}
}
