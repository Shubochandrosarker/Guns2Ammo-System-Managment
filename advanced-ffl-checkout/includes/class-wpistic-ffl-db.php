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
	const SCHEMA_VERSION = '1.11.0';

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
		// wp_user_id (v1.13.0, gap #12): links a dealer row to a real WP user
		// account for persistent self-service login, an alternative to the
		// single-use magic-link portal (Portal class) — never a replacement,
		// dealers without a linked account still use the magic-link flow.
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
  wp_user_id BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_license (license_number),
  UNIQUE KEY uidx_wp_user (wp_user_id),
  KEY idx_zip (premise_zip),
  KEY idx_state (premise_state),
  KEY idx_type (lic_type),
  KEY idx_active_preferred (is_active, is_preferred),
  KEY idx_coords (lat, lng),
  KEY idx_expiry (lic_xprdte)
) $charset;
" );

		// ── 2. Transfers table ─────────────────────────────────────────────────
		// order_item_id (v1.12.0): the WC_Order_Item id a transfer was created
		// from. Checkout now creates one row per FFL line item (and per unit of
		// quantity on that item), so a single order can own several transfers —
		// order_id alone is no longer assumed unique here.
		// customer_ip (v1.13.0, gap #13): the buyer's IP at checkout, stored
		// packed (inet_pton) same as dealer_tokens.created_ip — feeds the
		// fraud-score velocity signal (G2A_Fraud_Score) and general abuse
		// investigation; not shown to dealers.
		// order_item_unit (v1.14.0): 1-based index of a physical unit within
		// its order_item_id's quantity. Backports a real fix independently
		// made in the guns2ammo repo: Checkout::create_transfer_on_payment()
		// is hooked on two WC events that can fire close together for the
		// same order, so the previous COUNT-then-insert idempotency check was
		// check-then-act, not atomic — two near-simultaneous calls could both
		// pass the count check and double-insert. The old (pre-v1.12.0)
		// single-transfer model fixed this with `UNIQUE KEY (order_id)`;
		// order_item_unit + `uidx_order_item_unit` below is the same fix
		// adapted to the multi-unit model: the DB itself now rejects a
		// duplicate insert for the same physical unit instead of relying on
		// a PHP-side pre-check.
		dbDelta( "
CREATE TABLE {$p}transfers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_ref VARCHAR(20) NOT NULL DEFAULT '',
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  order_item_id BIGINT UNSIGNED DEFAULT NULL,
  order_item_unit INT UNSIGNED NOT NULL DEFAULT 1,
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  customer_name VARCHAR(200) NOT NULL DEFAULT '',
  customer_email VARCHAR(200) NOT NULL DEFAULT '',
  customer_phone VARCHAR(20) NOT NULL DEFAULT '',
  customer_ip VARBINARY(16) DEFAULT NULL,
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
  nics_response VARCHAR(20) NOT NULL DEFAULT '',
  nics_source VARCHAR(40) NOT NULL DEFAULT 'manual',
  easypost_shipment_id VARCHAR(60) NOT NULL DEFAULT '',
  shipping_rates_json LONGTEXT DEFAULT NULL,
  shipping_label_url VARCHAR(500) NOT NULL DEFAULT '',
  booking_appointment_id VARCHAR(60) NOT NULL DEFAULT '',
  booking_slot_start DATETIME DEFAULT NULL,
  staff_notes TEXT DEFAULT NULL,
  customer_notified TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_ref (transfer_ref),
  UNIQUE KEY uidx_order_item_unit (order_id, order_item_id, order_item_unit),
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
		// `source` + `rule_version` (v1.10.0) distinguish a built-in seeded row
		// from one an admin hand-edited or a future external data feed pushed,
		// so re-seeding never clobbers a manual edit and the admin UI can show
		// provenance instead of an opaque, un-editable PHP string literal.
		dbDelta( "
CREATE TABLE {$p}state_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  state_code VARCHAR(2) NOT NULL,
  rule_type VARCHAR(50) NOT NULL,
  description TEXT NOT NULL,
  item_types VARCHAR(200) NOT NULL DEFAULT '',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  source VARCHAR(20) NOT NULL DEFAULT 'builtin',
  rule_version VARCHAR(20) NOT NULL DEFAULT '1.0',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
		// reminder_60/30/7/0_sent_at (v1.12.0, Phase B): one-way markers so the
		// daily expiration-reminder sweep never re-emails the same threshold.
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
  reminder_60_sent_at DATETIME DEFAULT NULL,
  reminder_30_sent_at DATETIME DEFAULT NULL,
  reminder_7_sent_at DATETIME DEFAULT NULL,
  reminder_0_sent_at DATETIME DEFAULT NULL,
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

		// ── 10. Acquisition & Disposition (bound book) ledger (v1.10.0) ────────
		// One row per firearm that physically crosses the receiving FFL's
		// threshold. Auto-populated on receipt (acquisition side) and on
		// completed transfer (disposition side) by G2A_Ad_Ledger. This models
		// the RECEIVING dealer's bound-book obligation only — if the store
		// operating this plugin is itself also an FFL/distributor with its own
		// separate disposition-out record to that dealer, that is a distinct
		// legal record this table does not model.
		dbDelta( "
CREATE TABLE {$p}ad_ledger (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  dealer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  manufacturer VARCHAR(100) NOT NULL DEFAULT '',
  model VARCHAR(100) NOT NULL DEFAULT '',
  serial_number VARCHAR(100) NOT NULL DEFAULT '',
  item_type VARCHAR(50) NOT NULL DEFAULT 'handgun',
  caliber_gauge VARCHAR(50) NOT NULL DEFAULT '',
  acquisition_date DATE DEFAULT NULL,
  acquired_from VARCHAR(255) NOT NULL DEFAULT '',
  acquisition_type VARCHAR(40) NOT NULL DEFAULT 'transfer_in',
  disposition_date DATE DEFAULT NULL,
  disposed_to_name VARCHAR(200) NOT NULL DEFAULT '',
  disposed_to_type VARCHAR(40) NOT NULL DEFAULT 'individual_over_counter',
  status VARCHAR(20) NOT NULL DEFAULT 'in_inventory',
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_transfer (transfer_id),
  KEY idx_dealer (dealer_id),
  KEY idx_status (status),
  KEY idx_serial (serial_number)
) $charset;
" );

		// ── 11. Multiple-sale (Form 3310.4) watch (v1.10.0) ─────────────────────
		// One row per detected group of 2+ handgun transfers to the same buyer
		// within a rolling 5-business-day window. Never auto-files anything —
		// ATF Form 3310.4 filing stays a logged, manual staff action; this is
		// the detection + review-queue layer only.
		dbDelta( "
CREATE TABLE {$p}multi_sale_flags (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_email VARCHAR(200) NOT NULL DEFAULT '',
  customer_id BIGINT UNSIGNED DEFAULT NULL,
  transfer_ids VARCHAR(255) NOT NULL DEFAULT '',
  window_start DATE DEFAULT NULL,
  window_end DATE DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'pending_review',
  filed_at DATETIME DEFAULT NULL,
  filed_by BIGINT UNSIGNED DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_email (customer_email),
  KEY idx_customer (customer_id),
  KEY idx_status (status)
) $charset;
" );

		// ── 12. ID / age verification (v1.11.0, Exhibit 10) ─────────────────────
		// Append-only, same audit philosophy as `signatures`/`events` — a
		// re-check adds a new row. Never the sole gate: a "verified" result is
		// evidence for the existing compliance flow, not an auto-approval.
		dbDelta( "
CREATE TABLE {$p}id_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  order_id BIGINT UNSIGNED DEFAULT NULL,
  provider VARCHAR(40) NOT NULL DEFAULT 'manual',
  provider_reference VARCHAR(120) NOT NULL DEFAULT '',
  ship_to_state VARCHAR(2) NOT NULL DEFAULT '',
  result_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  result_age_over_21 TINYINT(1) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_order (order_id),
  KEY idx_status (result_status)
) $charset;
" );

		// ── 13. Regulatory watch (v1.12.0, Verification Hub Phase B) ────────────
		// One row per Federal Register document the nightly sweep matched
		// against ATF/FFL-licensee terms. Alert-only — nothing here ever
		// changes `wpistic_ffl_verification_settings.policy_mode` automatically.
		dbDelta( "
CREATE TABLE {$p}regulatory_watch (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  document_number VARCHAR(50) NOT NULL DEFAULT '',
  title VARCHAR(500) NOT NULL DEFAULT '',
  agency VARCHAR(200) NOT NULL DEFAULT '',
  document_type VARCHAR(60) NOT NULL DEFAULT '',
  matched_term VARCHAR(100) NOT NULL DEFAULT '',
  publication_date DATE DEFAULT NULL,
  html_url VARCHAR(500) NOT NULL DEFAULT '',
  notified_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_document (document_number),
  KEY idx_publication (publication_date)
) $charset;
" );

		// ── 14. Fraud / straw-purchase risk scoring (v1.13.0, gap #13) ──────────
		// One row per transfer, computed once at creation time by
		// G2A_Fraud_Score from signals already available in this plugin (no
		// external fraud-vendor API — see the class docblock). Purely a
		// recommendation for staff review: nothing here ever cancels,
		// blocks, or holds a transfer automatically.
		dbDelta( "
CREATE TABLE {$p}fraud_scores (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  transfer_id BIGINT UNSIGNED NOT NULL,
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  customer_email VARCHAR(200) NOT NULL DEFAULT '',
  score INT NOT NULL DEFAULT 0,
  risk_level VARCHAR(20) NOT NULL DEFAULT 'low',
  signals_json LONGTEXT DEFAULT NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'open',
  reviewed_by BIGINT UNSIGNED DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  review_notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_transfer (transfer_id),
  KEY idx_score (score),
  KEY idx_status (status),
  KEY idx_email (customer_email)
) $charset;
" );

		// ── 15. Distributor catalog + orders (v1.13.0, gap #11) ─────────────────
		// Targets Lipsey's real, documented dealer API (api.lipseys.com) —
		// see G2A_Lipseys class docblock. `distributor` is already a column
		// (not a fixed 'lipseys' enum) so a second distributor could reuse
		// these same two tables later without a schema change.
		dbDelta( "
CREATE TABLE {$p}distributor_products (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  distributor VARCHAR(30) NOT NULL DEFAULT 'lipseys',
  item_no VARCHAR(50) NOT NULL DEFAULT '',
  description VARCHAR(500) NOT NULL DEFAULT '',
  manufacturer VARCHAR(150) NOT NULL DEFAULT '',
  model VARCHAR(150) NOT NULL DEFAULT '',
  upc VARCHAR(50) NOT NULL DEFAULT '',
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  quantity INT NOT NULL DEFAULT 0,
  is_firearm TINYINT(1) NOT NULL DEFAULT 0,
  last_synced DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uidx_distributor_item (distributor, item_no),
  KEY idx_upc (upc)
) $charset;
" );

		dbDelta( "
CREATE TABLE {$p}distributor_orders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  distributor VARCHAR(30) NOT NULL DEFAULT 'lipseys',
  transfer_id BIGINT UNSIGNED DEFAULT NULL,
  po_number VARCHAR(60) NOT NULL DEFAULT '',
  item_no VARCHAR(50) NOT NULL DEFAULT '',
  quantity INT NOT NULL DEFAULT 1,
  ship_to_ffl VARCHAR(20) NOT NULL DEFAULT '',
  status VARCHAR(30) NOT NULL DEFAULT 'submitted',
  distributor_order_ref VARCHAR(100) NOT NULL DEFAULT '',
  response_json LONGTEXT DEFAULT NULL,
  submitted_by BIGINT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY idx_transfer (transfer_id),
  KEY idx_status (status)
) $charset;
" );

		// Seed state compliance rules if empty
		self::seed_state_rules();

		// Store schema version
		update_option( 'wpistic_ffl_db_version', self::SCHEMA_VERSION );
	}

	/**
	 * Seed basic state compliance rules.
	 *
	 * The literal array below is only the DEFAULT feed — v1.10.0 adds the
	 * `wpistic_ffl_state_rules_seed` filter so an admin's own functions.php,
	 * or a future maintained legal-data-feed integration, can replace or
	 * extend it without touching plugin source. Rows are still stored (and
	 * editable) in the `state_rules` table, not re-read from PHP on every
	 * request.
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

		/**
		 * Filter the default state-rules seed before it's written to the DB.
		 * Return additional/replacement [ state, rule_type, description, item_types ] rows.
		 */
		$rules = (array) apply_filters( 'wpistic_ffl_state_rules_seed', $rules );

		foreach ( $rules as $rule ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				[
					'state_code'   => $rule[0],
					'rule_type'    => $rule[1],
					'description'  => $rule[2],
					'item_types'   => $rule[3],
					'is_active'    => 1,
					'source'       => $rule[4] ?? 'builtin',
					'rule_version' => (string) get_option( 'wpistic_ffl_state_rules_version', '1.0' ),
				],
				[ '%s', '%s', '%s', '%s', '%d', '%s', '%s' ]
			);
		}
	}

	/**
	 * Drop all plugin tables. Called from uninstall.php.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$p = $wpdb->prefix . WPISTIC_FFL_DB_PREFIX;

		$tables = [ 'distributor_orders', 'distributor_products', 'fraud_scores', 'regulatory_watch', 'id_verifications', 'multi_sale_flags', 'ad_ledger', 'ffl_verifications', 'ffl_verification_documents', 'signatures', 'analytics_events', 'dealer_tokens', 'events', 'transfers', 'dealers', 'zip_coords', 'state_rules' ];
		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Remove all plugin options
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpistic_ffl_%'" ); // phpcs:ignore

		// Deregister the custom role (v1.13.0). Deliberately does NOT delete
		// the WP user accounts themselves — those may have other uses on
		// this install; only the plugin-specific role/capability goes away.
		remove_role( 'ffl_dealer' );

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
