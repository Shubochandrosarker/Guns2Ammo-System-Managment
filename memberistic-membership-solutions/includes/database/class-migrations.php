<?php
/**
 * Database migration runner.
 *
 * Each migration is keyed by the DB version it advances to. Migrations are
 * idempotent and version-tracked through the `memberistic_db_version` option,
 * so applying twice is a no-op and partial upgrades resume correctly.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Migrations {
	/**
	 * Run any migrations newer than the stored DB version.
	 */
	public static function run() {
		$current = (string) get_option( 'memberistic_db_version', '0.0.0' );
		$target  = MEMBERISTIC_DB_VERSION;

		if ( version_compare( $current, $target, '>=' ) ) {
			return;
		}

		foreach ( self::migrations() as $version => $callback ) {
			if ( version_compare( $current, $version, '<' ) ) {
				$result = call_user_func( $callback );

				if ( false === $result ) {
					return;
				}

				update_option( 'memberistic_db_version', $version, false );
				$current = $version;
			}
		}
	}

	/**
	 * Ordered list of migrations, keyed by the version they advance to.
	 *
	 * @return array<string, callable>
	 */
	private static function migrations() {
		return array(
			'1.1.0' => array( self::class, 'migrate_1_1_0' ),
			'1.2.0' => array( self::class, 'migrate_1_2_0' ),
			'1.3.0' => array( self::class, 'migrate_1_3_0' ),
			'1.4.0' => array( self::class, 'migrate_1_4_0' ),
		);
	}

	/**
	 * 1.4.0 — Waiver signature audit log + member documents tables.
	 *
	 * Re-runs dbDelta() (the CREATE TABLE statements live in Schema) so
	 * existing installs gain the two new tables idempotently.
	 */
	public static function migrate_1_4_0() {
		Schema::create_tables();
		return true;
	}

	/**
	 * 1.3.0 — Add the per-membership billing_amount column.
	 *
	 * Preserves the legacy/grandfathered price each member actually pays
	 * (carried over from a PMPro import) so the account "Next charge" display
	 * matches reality instead of falling back to the plan's standard price.
	 */
	public static function migrate_1_3_0() {
		global $wpdb;

		self::add_column_if_missing(
			$wpdb->prefix . 'memberistic_memberships',
			'billing_amount',
			'DECIMAL(10,2) NULL AFTER payment_source'
		);

		return true;
	}

	/**
	 * 1.1.0 — Backfill indexes on hot Stripe/Woo lookup columns.
	 */
	public static function migrate_1_1_0() {
		global $wpdb;

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$payments    = $wpdb->prefix . 'memberistic_payments';

		self::add_index_if_missing( $memberships, 'stripe_subscription_id', 'stripe_subscription_id' );
		self::add_index_if_missing( $payments, 'gateway_transaction_id', 'gateway_transaction_id' );

		return true;
	}

	/**
	 * 1.2.0 — Add the email_logs + integrations tables introduced in v1.7.0.
	 *
	 * The actual CREATE TABLE statements live in Schema::create_tables(); this
	 * migration simply re-runs dbDelta() so existing installs gain the new
	 * tables idempotently.
	 */
	public static function migrate_1_2_0() {
		Schema::create_tables();
		return true;
	}

	/**
	 * Add an index to a table if it does not already exist.
	 */
	private static function add_index_if_missing( $table, $index_name, $column ) {
		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
				$table,
				$index_name
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		$table      = esc_sql( $table );
		$index_name = esc_sql( $index_name );
		$column     = esc_sql( $column );

		$wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `{$index_name}` (`{$column}`)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Add a column to a table if it does not already exist.
	 *
	 * @param string $table       Fully-qualified table name.
	 * @param string $column      Column name.
	 * @param string $definition  Column definition (type + modifiers).
	 */
	private static function add_column_if_missing( $table, $column, $definition ) {
		global $wpdb;

		$exists = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s',
				$table,
				$column
			)
		);

		if ( $exists > 0 ) {
			return;
		}

		$table      = esc_sql( $table );
		$column     = esc_sql( $column );
		$definition = esc_sql( $definition );

		$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
