<?php
/**
 * Activation, schema migration and capability setup.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Installer {

	public const DB_VERSION_OPTION = 'g2ar_db_version';

	/**
	 * Capability that lets front-desk staff run referral lookups and grants
	 * without handing them full manage_options.
	 */
	public const STAFF_CAP = 'g2ar_manage_referrals';

	/**
	 * Run on activation: tables, caps, cron.
	 *
	 * @return void
	 */
	public static function activate() {
		self::install_tables();
		self::add_capabilities();
		self::schedule_cron();
	}

	/**
	 * Run on deactivation. Tables and ledger rows survive — only scheduled
	 * work stops.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'g2ar_daily_maintenance' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'g2ar_daily_maintenance' );
		}
	}

	/**
	 * Create or migrate tables when the schema version moves.
	 *
	 * @param bool $force Run dbDelta even when the version is unchanged.
	 * @return void
	 */
	public static function install_tables( $force = false ) {
		$installed = get_option( self::DB_VERSION_OPTION, '' );

		if ( ! $force && G2AR_DB_VERSION === $installed ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, G2AR_DB_VERSION, false );
	}

	/**
	 * Run pending migrations on load, so a plugin updated by file copy (the
	 * usual path on this host) still gets its tables.
	 *
	 * @return void
	 */
	public static function maybe_upgrade() {
		if ( G2AR_DB_VERSION !== get_option( self::DB_VERSION_OPTION, '' ) ) {
			self::install_tables();
		}
	}

	/**
	 * Grant the referral capabilities.
	 *
	 * Administrators and shop managers get the front-desk capability;
	 * settings stay behind manage_options.
	 *
	 * @return void
	 */
	public static function add_capabilities() {
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::STAFF_CAP ) ) {
				$role->add_cap( self::STAFF_CAP );
			}
		}
	}

	/**
	 * Daily maintenance: expire stale Guest Passes, rotate the visitor salt.
	 *
	 * @return void
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( 'g2ar_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'g2ar_daily_maintenance' );
		}
	}

	/**
	 * Seed referral codes for every eligible existing member.
	 *
	 * Batched deliberately: this host 502s under load and the relay caps at
	 * 30s, so the caller re-invokes until it reports nothing left to do
	 * rather than trying to do 372 members in one request.
	 *
	 * @param int $batch Rows per batch.
	 * @return array{created:int,remaining:int}
	 */
	public static function backfill_codes( $batch = 500 ) {
		global $wpdb;

		$batch = max( 1, min( 1000, (int) $batch ) );

		if ( ! function_exists( 'memberistic_get_membership_status' ) ) {
			return array(
				'created'   => 0,
				'remaining' => 0,
			);
		}

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$referrers   = Schema::table( 'referrers' );

		// Plan exclusions deliberately do NOT gate code creation: Guest Pass
		// holders may still refer, they just earn a free month instead of a
		// pass. See Rewards_Service::referrer_reward_type().

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.primary_user_id AS user_id, MIN(m.id) AS membership_id
				 FROM {$memberships} m
				 LEFT JOIN {$referrers} r ON r.user_id = m.primary_user_id
				 WHERE m.primary_user_id IS NOT NULL
				   AND m.primary_user_id > 0
				   AND m.status IN ('active','comped')
				   AND r.id IS NULL
				 GROUP BY m.primary_user_id
				 LIMIT %d",
				$batch
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$created = 0;

		foreach ( (array) $rows as $row ) {
			$referrer = Referrers_Repository::ensure_for_user( (int) $row['user_id'], (int) $row['membership_id'] );
			if ( $referrer ) {
				++$created;
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from $wpdb->prefix.
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(DISTINCT m.primary_user_id)
			 FROM {$memberships} m
			 LEFT JOIN {$referrers} r ON r.user_id = m.primary_user_id
			 WHERE m.primary_user_id IS NOT NULL
			   AND m.primary_user_id > 0
			   AND m.status IN ('active','comped')
			   AND r.id IS NULL"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'created'   => $created,
			'remaining' => $remaining,
		);
	}
}
