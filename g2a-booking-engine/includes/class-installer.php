<?php
/**
 * Schema installer / migrator for G2A Booking Engine.
 * Owns CREATE TABLE for all booking tables + verify/repair tools.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Installer {

	const TABLES = array(
		'bookings'           => 35,
		'resources'          => 12,
		'booking_types'      => 20,
		'forms'              => 9,
		'form_fields'        => 12,
		'availability_rules' => 13,
		'payments'           => 14,
		'logs'               => 10,
		'migration_runs'     => 17,
		'booking_activity'   => 8,
		'checkins'           => 8,
	);

	private $last_result = array();

	public function install() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$this->last_result = array();
		$schemas = $this->get_schemas();

		foreach ( $schemas as $name => $sql ) {
			$this->last_result[ $name ] = array(
				'created' => false,
				'errors'  => array(),
				'dbdelta' => array(),
			);
			try {
				$out = dbDelta( $sql );
				$this->last_result[ $name ]['dbdelta'] = is_array( $out ) ? $out : array();
			} catch ( \Throwable $e ) {
				$this->last_result[ $name ]['errors'][] = $e->getMessage();
				continue;
			}
			$this->last_result[ $name ]['created'] = $this->table_exists( $name );
			if ( ! $this->last_result[ $name ]['created'] ) {
				$this->last_result[ $name ]['errors'][] = sprintf(
					__( 'Table %s did not appear after dbDelta. Check DB user CREATE privileges.', 'g2a-booking' ),
					$this->full_table_name( $name )
				);
			}
		}

		update_option( 'g2ab_last_install_result', array(
			'timestamp'  => current_time( 'mysql' ),
			'db_version' => G2AB_DB_VERSION,
			'tables'     => $this->last_result,
		), false );

		$this->run_migrations();
		do_action( 'g2ab_after_install', $this->last_result );
		return $this->last_result;
	}

	public function force_install() {
		$result = $this->install();
		update_option( 'g2ab_db_version', G2AB_DB_VERSION );
		return $result;
	}

	public function reinstall_table( $name ) {
		if ( ! array_key_exists( $name, self::TABLES ) ) {
			return false;
		}
		global $wpdb;
		$full = $this->full_table_name( $name );
		$wpdb->query( "DROP TABLE IF EXISTS {$full}" );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$schemas = $this->get_schemas();
		if ( isset( $schemas[ $name ] ) ) {
			dbDelta( $schemas[ $name ] );
		}
		return $this->table_exists( $name );
	}

	public function reset_all_data() {
		global $wpdb;
		$drop_order = array( 'migration_runs', 'logs', 'payments', 'availability_rules', 'form_fields', 'forms', 'booking_types', 'resources', 'bookings' );
		foreach ( $drop_order as $name ) {
			$full = $this->full_table_name( $name );
			$wpdb->query( "DROP TABLE IF EXISTS {$full}" );
		}
		$result = $this->install();
		if ( class_exists( 'G2AB_Activator' ) && is_callable( array( 'G2AB_Activator', 'seed_initial_data' ) ) ) {
			G2AB_Activator::seed_initial_data();
		}
		do_action( 'g2ab_after_reset' );
		return $result;
	}

	public function verify_install_detailed() {
		global $wpdb;
		$out = array();
		foreach ( self::TABLES as $name => $expected ) {
			$full = $this->full_table_name( $name );
			$exists = $this->table_exists( $name );
			$cols_actual = 0;
			$rows = 0;
			if ( $exists ) {
				$cols = $wpdb->get_results( "SHOW COLUMNS FROM {$full}" );
				$cols_actual = is_array( $cols ) ? count( $cols ) : 0;
				$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" );
			}
			$out[ $name ] = array(
				'full_name'        => $full,
				'exists'           => $exists,
				'columns_actual'   => $cols_actual,
				'columns_expected' => $expected,
				'columns_ok'       => $cols_actual >= $expected,
				'row_count'        => $rows,
				'healthy'          => $exists && $cols_actual >= $expected,
			);
		}
		return $out;
	}

	public function verify_tables() {
		$out = array();
		foreach ( array_keys( self::TABLES ) as $name ) {
			$out[ $name ] = $this->table_exists( $name );
		}
		return $out;
	}

	public function get_last_install_result() {
		return get_option( 'g2ab_last_install_result', array() );
	}

	public function full_table_name( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'g2ab_' . $name;
	}

	private function table_exists( $name ) {
		global $wpdb;
		$full = $this->full_table_name( $name );
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
	}

	private function run_migrations() {
		$current = get_option( 'g2ab_db_version', '0.0.0' );
		$this->migrate_to_1_2_0( $current );
		$this->migrate_to_1_3_0( $current );
		$this->migrate_to_1_4_0( $current );
		$this->migrate_to_1_5_0( $current );
		$this->migrate_to_1_6_0( $current );
		do_action( 'g2ab_run_migrations', $current, G2AB_DB_VERSION );
	}

	/**
	 * v1.6.0 — compound index on g2ab_logs (event_type, booking_id)
	 * + log-retention pruner.
	 *
	 * The reminder cron + activity-log views both filter logs by
	 * (event_type='reminder_24h_sent' AND booking_id IN (…)) — a
	 * compound index on those two columns turns it from a full
	 * scan into a covering lookup. dbDelta will add the new key
	 * on the next install_tables() pass.
	 *
	 * Also seeds a one-time prune of any logs older than 365 days
	 * on upgrade so existing installs immediately benefit.
	 */
	private function migrate_to_1_6_0( $current ) {
		if ( version_compare( $current, '1.6.0', '>=' ) ) {
			return;
		}
		global $wpdb;
		$logs = $wpdb->prefix . 'g2ab_logs';
		// One-time historical prune.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$logs} WHERE created_at < %s",
			gmdate( 'Y-m-d H:i:s', time() - 365 * DAY_IN_SECONDS )
		) );
	}

	/**
	 * v1.5.0 — payment-row de-duplication.
	 *
	 * Adds UNIQUE KEY (gateway, transaction_id) to g2ab_payments so two
	 * concurrent webhook deliveries (Stripe/Fortis/Authnet/PayPal retries,
	 * or simultaneous payment_intent + checkout.session events) can't
	 * insert duplicate rows. dbDelta will have added the key on fresh
	 * installs; on upgrades we first collapse any existing duplicates so
	 * the index can be added. Strategy: keep the most recent row per
	 * (gateway, transaction_id) and delete the rest.
	 *
	 * Rows where transaction_id IS NULL (pay-in-store, manual payments)
	 * are exempt from the dedupe because they have no provider id; the
	 * UNIQUE KEY treats multiple NULLs as distinct on MySQL.
	 */
	private function migrate_to_1_5_0( $current ) {
		if ( version_compare( $current, '1.5.0', '>=' ) ) {
			return;
		}
		global $wpdb;
		$payments = $wpdb->prefix . 'g2ab_payments';

		// Collapse duplicates: keep the row with the largest id (most
		// recently inserted) per (gateway, transaction_id) and delete
		// the rest. Excludes rows where transaction_id is NULL or empty
		// (in-store payments don't have a provider transaction id).
		$wpdb->query( "DELETE p1 FROM {$payments} p1
			INNER JOIN {$payments} p2
			ON p1.gateway = p2.gateway
			AND p1.transaction_id = p2.transaction_id
			AND p1.id < p2.id
			WHERE p1.transaction_id IS NOT NULL
			AND p1.transaction_id <> ''" );
		// dbDelta will add the new UNIQUE KEY on the next install_tables() pass.
	}

	/**
	 * v1.4.0 — front desk + check-in.
	 *
	 * dbDelta has just added the `checked_in_at` column on bookings and
	 * created `g2ab_checkins`. Nothing else to backfill — check-ins start
	 * fresh from the moment the front desk page goes live.
	 */
	private function migrate_to_1_4_0( $current ) {
		if ( version_compare( $current, '1.4.0', '>=' ) ) {
			return;
		}
		// no-op (column added by dbDelta)
	}

	/**
	 * v1.3.0 status normalization + original_start_at backfill.
	 *
	 * v1.3.0 standardizes the booking status set on:
	 *   pending, reserved, confirmed, paid, completed,
	 *   cancelled, no_show, refunded, expired
	 *
	 * Existing rows already use the first three; the rest are added by the
	 * controllers as they happen. We backfill `original_start_at` so the
	 * activity log has something to compare against on the very first
	 * reschedule of any pre-1.3.0 booking.
	 */
	private function migrate_to_1_3_0( $current ) {
		if ( version_compare( $current, '1.3.0', '>=' ) ) {
			return;
		}
		global $wpdb;
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$wpdb->query(
			"UPDATE {$bookings} SET original_start_at = start_at
			 WHERE original_start_at IS NULL"
		);
	}

	/**
	 * v1.2.4 capacity mode + booking type seeding.
	 *
	 * `capacity_mode` was added to booking_types in 1.2.0 (db). dbDelta will
	 * create the column on upgrade automatically; this migration makes sure
	 * existing rows get a sensible default based on category.
	 *
	 *   class    → party_size  (CCW class capacity counts seats, not bookings)
	 *   anything else → booking_count (1 lane = 1 reservation per slot)
	 */
	private function migrate_to_1_2_0( $current ) {
		if ( version_compare( $current, '1.2.0', '>=' ) ) {
			return;
		}
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_booking_types';
		// dbDelta will have just added the column with default 'booking_count'.
		// Promote class-style booking types to party_size.
		$wpdb->query(
			"UPDATE {$bt} SET capacity_mode = 'party_size'
			 WHERE category = 'class' AND ( capacity_mode IS NULL OR capacity_mode = 'booking_count' )"
		);
	}

	public function get_schemas() {
		global $wpdb;
		$prefix  = $wpdb->prefix;
		$collate = $wpdb->get_charset_collate();
		$schemas = array();

		$schemas['bookings'] = "CREATE TABLE {$prefix}g2ab_bookings (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
uuid CHAR(36) NOT NULL,
booking_type_id BIGINT UNSIGNED NOT NULL,
resource_id BIGINT UNSIGNED DEFAULT NULL,
form_id BIGINT UNSIGNED DEFAULT NULL,
user_id BIGINT UNSIGNED DEFAULT NULL,
customer_name VARCHAR(150) NOT NULL DEFAULT '',
customer_email VARCHAR(150) NOT NULL DEFAULT '',
customer_phone VARCHAR(30) DEFAULT NULL,
start_at DATETIME NOT NULL,
end_at DATETIME NOT NULL,
duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
party_size TINYINT UNSIGNED NOT NULL DEFAULT 1,
status VARCHAR(20) NOT NULL DEFAULT 'pending',
payment_mode VARCHAR(20) NOT NULL DEFAULT 'full',
total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
currency CHAR(3) NOT NULL DEFAULT 'USD',
form_data LONGTEXT,
metadata LONGTEXT,
gateway_intent_id VARCHAR(255) DEFAULT NULL,
notes TEXT,
waiver_signed TINYINT(1) NOT NULL DEFAULT 0,
waiver_id VARCHAR(100) DEFAULT NULL,
source VARCHAR(30) NOT NULL DEFAULT 'web',
external_ref VARCHAR(64) DEFAULT NULL,
created_by BIGINT UNSIGNED DEFAULT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
cancelled_at DATETIME DEFAULT NULL,
cancel_reason VARCHAR(255) DEFAULT NULL,
cancelled_by_user_id BIGINT UNSIGNED DEFAULT NULL,
rescheduled_at DATETIME DEFAULT NULL,
original_start_at DATETIME DEFAULT NULL,
checked_in_at DATETIME DEFAULT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uuid (uuid),
KEY idx_status (status),
KEY idx_resource_time (resource_id, start_at, end_at),
KEY idx_user (user_id),
KEY idx_email (customer_email),
KEY idx_start (start_at),
KEY idx_type (booking_type_id),
KEY idx_gateway_intent (gateway_intent_id),
KEY idx_extref (external_ref)
) ENGINE=InnoDB {$collate};";

		$schemas['checkins'] = "CREATE TABLE {$prefix}g2ab_checkins (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
booking_id BIGINT UNSIGNED NOT NULL,
checked_in_by BIGINT UNSIGNED DEFAULT NULL,
checked_in_at DATETIME NOT NULL,
waiver_verified TINYINT(1) NOT NULL DEFAULT 0,
payment_verified TINYINT(1) NOT NULL DEFAULT 0,
note TEXT,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uniq_booking (booking_id),
KEY idx_checked_in_at (checked_in_at)
) ENGINE=InnoDB {$collate};";

		$schemas['booking_activity'] = "CREATE TABLE {$prefix}g2ab_booking_activity (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
booking_id BIGINT UNSIGNED NOT NULL,
action VARCHAR(50) NOT NULL,
old_value LONGTEXT,
new_value LONGTEXT,
user_id BIGINT UNSIGNED DEFAULT NULL,
note TEXT,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_booking (booking_id),
KEY idx_action (action),
KEY idx_created (created_at)
) ENGINE=InnoDB {$collate};";

		$schemas['resources'] = "CREATE TABLE {$prefix}g2ab_resources (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
name VARCHAR(150) NOT NULL,
slug VARCHAR(150) NOT NULL,
type VARCHAR(30) NOT NULL DEFAULT 'lane',
capacity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
description TEXT,
settings LONGTEXT,
sort_order INT NOT NULL DEFAULT 0,
is_active TINYINT(1) NOT NULL DEFAULT 1,
external_ref VARCHAR(64) DEFAULT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug),
KEY idx_type_active (type, is_active),
KEY idx_extref (external_ref)
) ENGINE=InnoDB {$collate};";

		$schemas['booking_types'] = "CREATE TABLE {$prefix}g2ab_booking_types (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
name VARCHAR(150) NOT NULL,
slug VARCHAR(150) NOT NULL,
category VARCHAR(30) NOT NULL DEFAULT 'lane',
duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
buffer_before SMALLINT UNSIGNED NOT NULL DEFAULT 0,
buffer_after SMALLINT UNSIGNED NOT NULL DEFAULT 0,
base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
payment_modes VARCHAR(100) NOT NULL DEFAULT 'full,deposit,in_store',
form_id BIGINT UNSIGNED DEFAULT NULL,
requires_waiver TINYINT(1) NOT NULL DEFAULT 1,
members_only TINYINT(1) NOT NULL DEFAULT 0,
member_discount DECIMAL(5,2) NOT NULL DEFAULT 0.00,
capacity_mode VARCHAR(20) NOT NULL DEFAULT 'booking_count',
settings LONGTEXT,
is_active TINYINT(1) NOT NULL DEFAULT 1,
external_ref VARCHAR(64) DEFAULT NULL,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug),
KEY idx_category_active (category, is_active),
KEY idx_extref (external_ref)
) ENGINE=InnoDB {$collate};";

		$schemas['forms'] = "CREATE TABLE {$prefix}g2ab_forms (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
name VARCHAR(150) NOT NULL,
slug VARCHAR(150) NOT NULL,
description TEXT,
schema_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
settings LONGTEXT,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL,
updated_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY slug (slug)
) ENGINE=InnoDB {$collate};";

		$schemas['form_fields'] = "CREATE TABLE {$prefix}g2ab_form_fields (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
form_id BIGINT UNSIGNED NOT NULL,
field_key VARCHAR(100) NOT NULL,
field_type VARCHAR(30) NOT NULL DEFAULT 'text',
label VARCHAR(255) NOT NULL,
placeholder VARCHAR(255) DEFAULT NULL,
help_text TEXT,
options LONGTEXT,
validation LONGTEXT,
conditional LONGTEXT,
sort_order INT NOT NULL DEFAULT 0,
is_required TINYINT(1) NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY uniq_form_key (form_id, field_key),
KEY idx_form (form_id, sort_order)
) ENGINE=InnoDB {$collate};";

		$schemas['availability_rules'] = "CREATE TABLE {$prefix}g2ab_availability_rules (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
rule_type VARCHAR(30) NOT NULL DEFAULT 'business_hours',
resource_id BIGINT UNSIGNED DEFAULT NULL,
booking_type_id BIGINT UNSIGNED DEFAULT NULL,
day_of_week TINYINT UNSIGNED DEFAULT NULL,
start_time TIME DEFAULT NULL,
end_time TIME DEFAULT NULL,
start_date DATE DEFAULT NULL,
end_date DATE DEFAULT NULL,
reason VARCHAR(255) DEFAULT NULL,
priority TINYINT UNSIGNED NOT NULL DEFAULT 10,
is_active TINYINT(1) NOT NULL DEFAULT 1,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_lookup (resource_id, rule_type, is_active),
KEY idx_date_range (start_date, end_date)
) ENGINE=InnoDB {$collate};";

		$schemas['payments'] = "CREATE TABLE {$prefix}g2ab_payments (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
booking_id BIGINT UNSIGNED NOT NULL,
gateway VARCHAR(50) NOT NULL DEFAULT 'pay_in_store',
transaction_id VARCHAR(191) DEFAULT NULL,
amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
currency CHAR(3) NOT NULL DEFAULT 'USD',
status VARCHAR(20) NOT NULL DEFAULT 'pending',
payment_method VARCHAR(50) DEFAULT NULL,
refund_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
gateway_response LONGTEXT,
metadata LONGTEXT,
external_ref VARCHAR(64) DEFAULT NULL,
processed_at DATETIME DEFAULT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uniq_gateway_tx (gateway, transaction_id),
KEY idx_booking (booking_id),
KEY idx_status (status),
KEY idx_extref (external_ref)
) ENGINE=InnoDB {$collate};";

		$schemas['logs'] = "CREATE TABLE {$prefix}g2ab_logs (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
booking_id BIGINT UNSIGNED DEFAULT NULL,
user_id BIGINT UNSIGNED DEFAULT NULL,
event_type VARCHAR(50) NOT NULL DEFAULT 'info',
severity VARCHAR(10) NOT NULL DEFAULT 'info',
message TEXT NOT NULL,
context LONGTEXT,
ip_address VARCHAR(45) DEFAULT NULL,
user_agent VARCHAR(255) DEFAULT NULL,
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
KEY idx_booking (booking_id),
KEY idx_event (event_type),
KEY idx_created (created_at),
KEY idx_event_booking (event_type, booking_id)
) ENGINE=InnoDB {$collate};";

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
) ENGINE=InnoDB {$collate};";

		return apply_filters( 'g2ab_install_schemas', $schemas );
	}
}
