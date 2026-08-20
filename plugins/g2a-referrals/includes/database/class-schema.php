<?php
/**
 * Table definitions.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {

	/**
	 * Fully qualified table name for one of this plugin's tables.
	 *
	 * @param string $name Bare table name, e.g. "referrers".
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;

		return $wpdb->prefix . 'g2ar_' . $name;
	}

	/**
	 * dbDelta-compatible CREATE TABLE statements.
	 *
	 * @return string[]
	 */
	public static function statements() {
		global $wpdb;

		$prefix          = $wpdb->prefix . 'g2ar_';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = array();

		$sql[] = "CREATE TABLE {$prefix}referrers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  membership_id BIGINT UNSIGNED NULL,
  code VARCHAR(32) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  total_referred BIGINT UNSIGNED NOT NULL DEFAULT 0,
  total_rewarded BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  UNIQUE KEY user_id (user_id),
  KEY status (status),
  KEY membership_id (membership_id)
) {$charset_collate};";

		// visitor_hash is sha256( ip | user agent | daily salt ). A raw IP is
		// never written to this table.
		$sql[] = "CREATE TABLE {$prefix}visits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id BIGINT UNSIGNED NOT NULL,
  visitor_hash CHAR(64) NOT NULL,
  landing_url VARCHAR(255) NULL,
  referrer_url VARCHAR(255) NULL,
  device VARCHAR(20) NULL,
  created_at DATETIME NOT NULL,
  converted_at DATETIME NULL,
  conversion_id BIGINT UNSIGNED NULL,
  PRIMARY KEY  (id),
  KEY referrer_created (referrer_id, created_at),
  KEY visitor_hash (visitor_hash),
  KEY conversion_id (conversion_id)
) {$charset_collate};";

		// UNIQUE (friend_membership_id) is the idempotency guarantee: one
		// reward per membership, ever, however many times a webhook retries.
		$sql[] = "CREATE TABLE {$prefix}conversions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id BIGINT UNSIGNED NOT NULL,
  visit_id BIGINT UNSIGNED NULL,
  friend_user_id BIGINT UNSIGNED NULL,
  friend_membership_id BIGINT UNSIGNED NOT NULL,
  plan_id BIGINT UNSIGNED NULL,
  billing_cycle VARCHAR(20) NULL,
  amount_paid DECIMAL(10,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  reject_reason VARCHAR(191) NULL,
  qualified_at DATETIME NULL,
  rewarded_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY friend_membership_id (friend_membership_id),
  KEY referrer_status (referrer_id, status),
  KEY friend_user_id (friend_user_id),
  KEY created_at (created_at)
) {$charset_collate};";

		// Append-only ledger. Balance = SUM(amount) grouped by user + type.
		// There is deliberately no mutable balance column anywhere.
		$sql[] = "CREATE TABLE {$prefix}rewards (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  reward_type VARCHAR(30) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0,
  direction VARCHAR(20) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'referral',
  source_id BIGINT UNSIGNED NULL,
  booking_id BIGINT UNSIGNED NULL,
  membership_id BIGINT UNSIGNED NULL,
  expires_at DATETIME NULL,
  note VARCHAR(255) NULL,
  actor_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY user_type_created (user_id, reward_type, created_at),
  KEY source_lookup (source, source_id),
  KEY booking_id (booking_id),
  KEY expires_at (expires_at)
) {$charset_collate};";

		// Derived cache only. Rebuilt from the ledger on every write; never
		// the source of truth for a balance.
		$sql[] = "CREATE TABLE {$prefix}balances (
  user_id BIGINT UNSIGNED NOT NULL,
  reward_type VARCHAR(30) NOT NULL,
  balance DECIMAL(10,2) NOT NULL DEFAULT 0,
  next_expiry DATETIME NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY  (user_id, reward_type),
  KEY next_expiry (next_expiry)
) {$charset_collate};";

		// Append-only, hash-chained audit. Mirrors the tamper-evident pattern
		// already used by wpbx_g2a_audit_logs in g2a-pos-core.
		$sql[] = "CREATE TABLE {$prefix}events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  referrer_id BIGINT UNSIGNED NULL,
  event_type VARCHAR(60) NOT NULL,
  object_type VARCHAR(40) NULL,
  object_id BIGINT UNSIGNED NULL,
  actor_id BIGINT UNSIGNED NULL,
  ip_hash CHAR(64) NULL,
  payload_json LONGTEXT NULL,
  prev_hash CHAR(64) NULL,
  row_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY  (id),
  KEY referrer_id (referrer_id),
  KEY event_type (event_type),
  KEY object_lookup (object_type, object_id),
  KEY created_at (created_at)
) {$charset_collate};";

		return $sql;
	}
}
