<?php
/**
 * The reward ledger.
 *
 * Append-only. Every grant, redemption, expiry and reversal is a row, and a
 * balance is always SUM(amount) over those rows. There is deliberately no
 * mutable balance column: the `balances` table is a derived cache that is
 * rebuilt from this ledger on every write and may be dropped at any time
 * without losing a cent.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewards_Repository {

	public const TYPE_GUEST_PASS     = 'guest_pass';
	public const TYPE_MEMBERSHIP_DAYS = 'membership_days';

	public const DIRECTIONS = array( 'grant', 'redeem', 'expire', 'reverse' );

	/**
	 * @return string
	 */
	public static function table() {
		return Schema::table( 'rewards' );
	}

	/**
	 * @return string
	 */
	public static function balances_table() {
		return Schema::table( 'balances' );
	}

	/**
	 * Append a ledger row.
	 *
	 * The caller passes a positive magnitude; the sign is derived from the
	 * direction so no call site can accidentally write a positive redemption.
	 *
	 * @param array $data user_id, reward_type, amount, direction, source, source_id,
	 *                    booking_id, membership_id, expires_at, note, actor_id.
	 * @return int Row id, 0 on failure.
	 */
	public static function add( array $data ) {
		global $wpdb;

		$user_id   = (int) ( $data['user_id'] ?? 0 );
		$type      = (string) ( $data['reward_type'] ?? '' );
		$direction = (string) ( $data['direction'] ?? '' );
		$magnitude = abs( round( (float) ( $data['amount'] ?? 0 ), 2 ) );

		if ( $user_id <= 0 || '' === $type || ! in_array( $direction, self::DIRECTIONS, true ) ) {
			return 0;
		}

		// Zero is meaningful for exactly one case: the expiry sweep marks a
		// grant it found nothing left to reclaim on, so the nightly job stops
		// rescanning it. Everything else must move a real amount.
		if ( $magnitude <= 0 && 'expire' !== $direction ) {
			return 0;
		}

		// grant adds; redeem, expire and reverse subtract.
		$amount = ( 'grant' === $direction ) ? $magnitude : -$magnitude;

		$ok = $wpdb->insert(
			self::table(),
			array(
				'user_id'       => $user_id,
				'reward_type'   => mb_substr( $type, 0, 30 ),
				'amount'        => $amount,
				'direction'     => $direction,
				'source'        => mb_substr( (string) ( $data['source'] ?? 'referral' ), 0, 20 ),
				'source_id'     => (int) ( $data['source_id'] ?? 0 ) ?: null,
				'booking_id'    => (int) ( $data['booking_id'] ?? 0 ) ?: null,
				'membership_id' => (int) ( $data['membership_id'] ?? 0 ) ?: null,
				'expires_at'    => ! empty( $data['expires_at'] ) ? (string) $data['expires_at'] : null,
				'note'          => isset( $data['note'] ) ? mb_substr( (string) $data['note'], 0, 255 ) : null,
				'actor_id'      => (int) ( $data['actor_id'] ?? 0 ) ?: null,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( ! $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		self::refresh_balance( $user_id, $type );

		/**
		 * Fires after a ledger row is appended.
		 *
		 * @param int   $id   Ledger row id.
		 * @param array $data The row as passed in.
		 */
		do_action( 'g2ar_reward_ledger_written', $id, $data );

		return $id;
	}

	/**
	 * Current balance for a user and reward type, computed from the ledger.
	 *
	 * Expired-but-unswept rows are excluded here as well as by the daily
	 * cron, so a pass is never spendable past its expiry even if cron has not
	 * run — which on a host that 502s under load is a real possibility.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $type    Reward type.
	 * @return float
	 */
	public static function balance( $user_id, $type ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return 0.0;
		}

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( amount ), 0 ) FROM {$table}
				 WHERE user_id = %d
				   AND reward_type = %s
				   AND ( amount < 0 OR expires_at IS NULL OR expires_at > %s )",
				$user_id,
				(string) $type,
				$now
			)
		);

		return round( (float) $sum, 2 );
	}

	/**
	 * Earliest expiry among a user's still-live grants of a type.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $type    Reward type.
	 * @return string|null MySQL UTC datetime.
	 */
	public static function next_expiry( $user_id, $type ) {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN( expires_at ) FROM {$table}
				 WHERE user_id = %d AND reward_type = %s AND direction = 'grant'
				   AND expires_at IS NOT NULL AND expires_at > %s",
				(int) $user_id,
				(string) $type,
				$now
			)
		);

		return $value ? (string) $value : null;
	}

	/**
	 * Rewrite the derived balance cache for one user/type pair.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $type    Reward type.
	 * @return void
	 */
	public static function refresh_balance( $user_id, $type ) {
		global $wpdb;

		$balance = self::balance( $user_id, $type );
		$expiry  = self::next_expiry( $user_id, $type );

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				'INSERT INTO ' . self::balances_table() . ' ( user_id, reward_type, balance, next_expiry, updated_at )
				 VALUES ( %d, %s, %f, %s, %s )
				 ON DUPLICATE KEY UPDATE balance = VALUES(balance), next_expiry = VALUES(next_expiry), updated_at = VALUES(updated_at)',
				(int) $user_id,
				(string) $type,
				$balance,
				$expiry,
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Ledger history for a user, newest first.
	 *
	 * @param int    $user_id WP user id.
	 * @param int    $limit   Max rows.
	 * @param string $type    Optional reward type filter.
	 * @return array[]
	 */
	public static function history( $user_id, $limit = 50, $type = '' ) {
		global $wpdb;

		$table  = self::table();
		$params = array( (int) $user_id );
		$where  = 'user_id = %d';

		if ( '' !== $type ) {
			$where   .= ' AND reward_type = %s';
			$params[] = $type;
		}

		$params[] = max( 1, min( 200, (int) $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; placeholders prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d", $params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * True when a ledger row already exists for this source/direction pair.
	 *
	 * Second line of idempotency defence behind UNIQUE(friend_membership_id):
	 * even a hand-run repair script cannot double-grant for one conversion.
	 *
	 * The user id matters: one conversion writes a row for the referrer AND
	 * a row for the friend, and when the referrer is a Guest Pass plan
	 * holder both are membership_days. Without scoping by user the friend's
	 * grant would look like a duplicate of the referrer's and be dropped.
	 *
	 * @param string $source    Source bucket.
	 * @param int    $source_id Source row id.
	 * @param string $direction Ledger direction.
	 * @param string $type      Reward type.
	 * @param int    $user_id   Whose row to look for. 0 = any.
	 * @return bool
	 */
	public static function exists_for_source( $source, $source_id, $direction, $type = '', $user_id = 0 ) {
		global $wpdb;

		$table  = self::table();
		$params = array( (string) $source, (int) $source_id, (string) $direction );
		$where  = 'source = %s AND source_id = %d AND direction = %s';

		if ( '' !== $type ) {
			$where   .= ' AND reward_type = %s';
			$params[] = $type;
		}

		if ( (int) $user_id > 0 ) {
			$where   .= ' AND user_id = %d';
			$params[] = (int) $user_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; placeholders prepared.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$where} LIMIT 1", $params ) );
	}

	/**
	 * Live grants of a type that have passed their expiry and not yet been
	 * swept. Feeds the daily expiry job.
	 *
	 * @param int $limit Batch size.
	 * @return array[]
	 */
	public static function expired_grants( $limit = 500 ) {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// A grant is swept once; the sweep row carries source='expiry' and
		// source_id = the grant's id, so this LEFT JOIN filters them out.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT g.* FROM {$table} g
				 LEFT JOIN {$table} s ON s.source = 'expiry' AND s.source_id = g.id AND s.direction = 'expire'
				 WHERE g.direction = 'grant'
				   AND g.expires_at IS NOT NULL
				   AND g.expires_at <= %s
				   AND s.id IS NULL
				 ORDER BY g.id ASC
				 LIMIT %d",
				$now,
				max( 1, min( 1000, (int) $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Total outstanding balance across all members for a reward type.
	 * Drives the admin's dollar liability figure.
	 *
	 * @param string $type Reward type.
	 * @return float
	 */
	public static function outstanding_total( $type ) {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( amount ), 0 ) FROM {$table}
				 WHERE reward_type = %s AND ( amount < 0 OR expires_at IS NULL OR expires_at > %s )",
				(string) $type,
				$now
			)
		);

		return max( 0, round( (float) $sum, 2 ) );
	}

	/**
	 * Outstanding balances expiring inside a window, for the admin forecast.
	 *
	 * @param string $type Reward type.
	 * @param int    $days Window in days.
	 * @return float
	 */
	public static function expiring_within( $type, $days = 30 ) {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +' . max( 1, (int) $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$sum = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE( SUM( amount ), 0 ) FROM {$table}
				 WHERE reward_type = %s AND direction = 'grant'
				   AND expires_at IS NOT NULL AND expires_at > %s AND expires_at <= %s",
				(string) $type,
				$now,
				$until
			)
		);

		return max( 0, round( (float) $sum, 2 ) );
	}
}
