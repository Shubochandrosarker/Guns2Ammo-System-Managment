<?php
/**
 * Conversions — one row per referred membership.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Conversions_Repository {

	public const STATUSES = array( 'pending', 'qualified', 'rewarded', 'rejected', 'reversed' );

	/**
	 * @return string
	 */
	public static function table() {
		return Schema::table( 'conversions' );
	}

	/**
	 * Insert a conversion.
	 *
	 * Relies on UNIQUE (friend_membership_id) for idempotency: a webhook
	 * retry hits the constraint and returns the existing row instead of
	 * creating a second reward. This is the only thing standing between a
	 * Stripe retry storm and double-rewarding every referral, so the
	 * duplicate path returns the existing row rather than an error.
	 *
	 * @param array $data Conversion fields.
	 * @return array{row:array|null,created:bool}
	 */
	public static function create( array $data ) {
		global $wpdb;

		$membership_id = (int) ( $data['friend_membership_id'] ?? 0 );
		$referrer_id   = (int) ( $data['referrer_id'] ?? 0 );

		if ( $membership_id <= 0 || $referrer_id <= 0 ) {
			return array(
				'row'     => null,
				'created' => false,
			);
		}

		$existing = self::get_by_membership( $membership_id );
		if ( $existing ) {
			return array(
				'row'     => $existing,
				'created' => false,
			);
		}

		$status = (string) ( $data['status'] ?? 'pending' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'pending';
		}

		$ok = $wpdb->insert(
			self::table(),
			array(
				'referrer_id'          => $referrer_id,
				'visit_id'             => (int) ( $data['visit_id'] ?? 0 ) ?: null,
				'friend_user_id'       => (int) ( $data['friend_user_id'] ?? 0 ) ?: null,
				'friend_membership_id' => $membership_id,
				'plan_id'              => (int) ( $data['plan_id'] ?? 0 ) ?: null,
				'billing_cycle'        => mb_substr( (string) ( $data['billing_cycle'] ?? '' ), 0, 20 ),
				'amount_paid'          => isset( $data['amount_paid'] ) ? round( (float) $data['amount_paid'], 2 ) : null,
				'status'               => $status,
				'reject_reason'        => isset( $data['reject_reason'] ) ? mb_substr( (string) $data['reject_reason'], 0, 191 ) : null,
				'created_at'           => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			// Lost a race on the unique key — the winner's row is the truth.
			$raced = self::get_by_membership( $membership_id );

			return array(
				'row'     => $raced,
				'created' => false,
			);
		}

		return array(
			'row'     => self::get( (int) $wpdb->insert_id ),
			'created' => true,
		);
	}

	/**
	 * @param int $id Row id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int $membership_id Friend's membership row id.
	 * @return array|null
	 */
	public static function get_by_membership( $membership_id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE friend_membership_id = %d", (int) $membership_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Move a conversion to a new status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status New status.
	 * @param array  $extra  Additional columns to write.
	 * @return bool
	 */
	public static function set_status( $id, $status, array $extra = array() ) {
		global $wpdb;

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}

		$data    = array( 'status' => $status );
		$formats = array( '%s' );

		if ( 'qualified' === $status ) {
			$data['qualified_at'] = current_time( 'mysql', true );
			$formats[]            = '%s';
		}

		if ( 'rewarded' === $status ) {
			$data['rewarded_at'] = current_time( 'mysql', true );
			$formats[]           = '%s';
		}

		if ( isset( $extra['reject_reason'] ) ) {
			$data['reject_reason'] = mb_substr( (string) $extra['reject_reason'], 0, 191 );
			$formats[]             = '%s';
		}

		return (bool) $wpdb->update( self::table(), $data, array( 'id' => (int) $id ), $formats, array( '%d' ) );
	}

	/**
	 * Conversions for one referrer, newest first.
	 *
	 * @param int $referrer_id Referrer row id.
	 * @param int $limit       Max rows.
	 * @return array[]
	 */
	public static function for_referrer( $referrer_id, $limit = 50 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE referrer_id = %d ORDER BY id DESC LIMIT %d",
				(int) $referrer_id,
				max( 1, min( 200, (int) $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rewarded conversions a referrer has banked inside a UTC month.
	 * Enforces the per-member monthly cap.
	 *
	 * @param int    $referrer_id Referrer row id.
	 * @param string $month       Y-m, defaults to the current UTC month.
	 * @return int
	 */
	public static function count_rewarded_in_month( $referrer_id, $month = '' ) {
		global $wpdb;

		$month = $month ?: gmdate( 'Y-m' );
		$table = self::table();

		// Counts anything that reached a rewarded state this month, including
		// rows later reversed: a reversal must not hand back cap headroom
		// that a refund cycle could be used to farm.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				 WHERE referrer_id = %d
				   AND rewarded_at IS NOT NULL
				   AND DATE_FORMAT( rewarded_at, '%%Y-%%m' ) = %s",
				(int) $referrer_id,
				$month
			)
		);
	}

	/**
	 * Conversions created by a referrer since a timestamp. Feeds the
	 * >10/day fraud review flag.
	 *
	 * @param int    $referrer_id Referrer row id.
	 * @param string $since       MySQL UTC datetime.
	 * @return int
	 */
	public static function count_since( $referrer_id, $since ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE referrer_id = %d AND created_at >= %s",
				(int) $referrer_id,
				(string) $since
			)
		);
	}

	/**
	 * Admin list with optional status filter.
	 *
	 * @param array $args status, limit, offset.
	 * @return array[]
	 */
	public static function search( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status' => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$table  = self::table();
		$where  = '1=1';
		$params = array();

		if ( in_array( $args['status'], self::STATUSES, true ) ) {
			$where    = 'status = %s';
			$params[] = $args['status'];
		}

		$params[] = max( 1, min( 200, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; placeholders prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Status counts for the admin overview.
	 *
	 * @return array<string,int>
	 */
	public static function counts_by_status() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS total FROM {$table} GROUP BY status", ARRAY_A );

		$out = array_fill_keys( self::STATUSES, 0 );

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['total'];
		}

		return $out;
	}
}
