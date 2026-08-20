<?php
/**
 * Referrer rows — one per member, holding their code.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

use WordPressistic\G2AReferrals\Codes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Referrers_Repository {

	/**
	 * How many times to retry on a code collision before giving up. With a
	 * 32^6 keyspace (~1.07bn) and a few hundred members, one retry is already
	 * paranoid; five means a genuine failure is a real fault, not bad luck.
	 */
	private const MAX_CODE_ATTEMPTS = 5;

	/**
	 * @return string
	 */
	public static function table() {
		return Schema::table( 'referrers' );
	}

	/**
	 * Fetch by row id.
	 *
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
	 * Fetch by WP user id.
	 *
	 * @param int $user_id WP user id.
	 * @return array|null
	 */
	public static function get_by_user( $user_id ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Fetch by referral code. Input is normalised first, so a code typed in
	 * lower case or with I/O substitutions still resolves.
	 *
	 * @param string $code Raw or canonical code.
	 * @return array|null
	 */
	public static function get_by_code( $code ) {
		global $wpdb;

		$code = Codes::normalize( $code );
		if ( '' === $code ) {
			return null;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE code = %s", $code ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get this user's referrer row, creating it with a fresh code if they do
	 * not have one yet.
	 *
	 * @param int $user_id       WP user id.
	 * @param int $membership_id Membership row id, when known.
	 * @return array|null
	 */
	public static function ensure_for_user( $user_id, $membership_id = 0 ) {
		global $wpdb;

		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return null;
		}

		$existing = self::get_by_user( $user_id );
		if ( $existing ) {
			// Backfill the membership id if we learned it since.
			if ( $membership_id > 0 && empty( $existing['membership_id'] ) ) {
				$wpdb->update(
					self::table(),
					array(
						'membership_id' => (int) $membership_id,
						'updated_at'    => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $existing['id'] ),
					array( '%d', '%s' ),
					array( '%d' )
				);
				$existing['membership_id'] = (int) $membership_id;
			}

			return $existing;
		}

		for ( $attempt = 0; $attempt < self::MAX_CODE_ATTEMPTS; $attempt++ ) {
			$inserted = $wpdb->insert(
				self::table(),
				array(
					'user_id'       => $user_id,
					'membership_id' => $membership_id > 0 ? (int) $membership_id : null,
					'code'          => Codes::generate(),
					'status'        => 'active',
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				return self::get( (int) $wpdb->insert_id );
			}

			// A UNIQUE(user_id) violation means another request created the
			// row first — take theirs rather than looping on a code retry.
			$raced = self::get_by_user( $user_id );
			if ( $raced ) {
				return $raced;
			}
		}

		return null;
	}

	/**
	 * Set a referrer's status.
	 *
	 * @param int    $id     Row id.
	 * @param string $status active|suspended.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;

		$status = in_array( $status, array( 'active', 'suspended' ), true ) ? $status : 'active';

		return (bool) $wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Recalculate the denormalised counters from the conversions table.
	 *
	 * These are display counters only — never a source of truth for money.
	 *
	 * @param int $id Referrer row id.
	 * @return void
	 */
	public static function refresh_counters( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( $id <= 0 ) {
			return;
		}

		$conversions = Schema::table( 'conversions' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$counts = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					SUM( CASE WHEN status IN ('qualified','rewarded') THEN 1 ELSE 0 END ) AS referred,
					SUM( CASE WHEN status = 'rewarded' THEN 1 ELSE 0 END ) AS rewarded
				 FROM {$conversions} WHERE referrer_id = %d",
				$id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$wpdb->update(
			self::table(),
			array(
				'total_referred' => (int) ( $counts['referred'] ?? 0 ),
				'total_rewarded' => (int) ( $counts['rewarded'] ?? 0 ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Search referrers for the admin list and the front-desk lookup.
	 *
	 * @param array $args search, status, limit, offset.
	 * @return array[]
	 */
	public static function search( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'search' => '',
				'status' => '',
				'limit'  => 50,
				'offset' => 0,
			)
		);

		$table = self::table();
		$users = $wpdb->users;
		$where = array( '1=1' );
		$params = array();

		if ( '' !== (string) $args['search'] ) {
			$code = Codes::normalize( $args['search'] );
			if ( '' !== $code ) {
				$where[]  = 'r.code = %s';
				$params[] = $code;
			} else {
				$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
				$where[]  = '( u.user_email LIKE %s OR u.display_name LIKE %s OR r.code LIKE %s )';
				$params[] = $like;
				$params[] = $like;
				$params[] = $like;
			}
		}

		if ( in_array( $args['status'], array( 'active', 'suspended' ), true ) ) {
			$where[]  = 'r.status = %s';
			$params[] = $args['status'];
		}

		$params[] = max( 1, min( 200, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$clause = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb; placeholders are prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.*, u.display_name, u.user_email
				 FROM {$table} r
				 LEFT JOIN {$users} u ON u.ID = r.user_id
				 WHERE {$clause}
				 ORDER BY r.total_rewarded DESC, r.id DESC
				 LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}
}
