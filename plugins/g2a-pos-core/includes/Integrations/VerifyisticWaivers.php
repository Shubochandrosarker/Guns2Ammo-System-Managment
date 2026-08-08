<?php

namespace G2A\POS\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only waiver/verification sources from sibling WPistic plugins:
 *
 *  - Verifyistic (wp_verifyistic_logs): passed age/ID verification records.
 *  - Memberistic (wp_memberistic_people): per-person waiver_status kept on
 *    membership household members.
 *
 * Rows are normalised to the POS waiver shape (RangeWaiverController::present)
 * with `source` = 'verifyistic' | 'memberistic' so the Waivers view can list
 * and filter them next to OtterWaiver imports. Nothing here writes to the
 * other plugins' tables.
 */
final class VerifyisticWaivers {

	public static function verifyistic_active(): bool {
		return self::table_exists( 'verifyistic_logs' );
	}

	public static function memberistic_active(): bool {
		return self::table_exists( 'memberistic_people' );
	}

	/**
	 * Passed Verifyistic verification records, normalised.
	 *
	 * @param array $params q|from|to|limit supported.
	 */
	public static function verifyistic_records( array $params = array() ): array {
		global $wpdb;
		if ( ! self::verifyistic_active() ) {
			return array();
		}
		$t     = $wpdb->prefix . 'verifyistic_logs';
		$where = array( "status = 'passed'" );
		$args  = array();
		if ( ! empty( $params['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $params['q'] ) . '%';
			$where[] = '(first_name LIKE %s OR last_name LIKE %s)';
			array_push( $args, $like, $like );
		}
		if ( ! empty( $params['from'] ) ) {
			$where[] = 'verified_at >= %s';
			$args[]  = (string) $params['from'] . ' 00:00:00';
		}
		if ( ! empty( $params['to'] ) ) {
			$where[] = 'verified_at <= %s';
			$args[]  = (string) $params['to'] . ' 23:59:59';
		}
		$limit  = max( 1, min( 500, (int) ( $params['limit'] ?? 50 ) ) );
		$sql    = "SELECT id, verify_type, first_name, last_name, dob, age_at_verify, status, verified_at
		           FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY verified_at DESC, id DESC LIMIT %d';
		$args[] = $limit;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();

		return array_map(
			static fn( array $r ): array => array(
				'id'               => 'verifyistic-' . (int) $r['id'],
				'source'           => 'verifyistic',
				'unique_ref'       => 'verifyistic:' . (int) $r['id'],
				'first_name'       => (string) $r['first_name'],
				'last_name'        => (string) $r['last_name'],
				'dob'              => $r['dob'] ?: null,
				'age'              => (int) $r['age_at_verify'],
				'email'            => null,
				'phone'            => null,
				'participant_type' => 'Adult',
				'waiver_date'      => (string) $r['verified_at'],
				'signed_on'        => (string) $r['verified_at'],
				'status'           => (string) $r['status'],
				'check_in_count'   => 0,
				'title'            => 'ID/age verification (' . (string) $r['verify_type'] . ')',
				'read_only'        => true,
			),
			$rows
		);
	}

	/**
	 * Memberistic household members with a waiver on file, normalised.
	 *
	 * @param array $params q|email|limit supported.
	 */
	public static function memberistic_records( array $params = array() ): array {
		global $wpdb;
		if ( ! self::memberistic_active() ) {
			return array();
		}
		$t     = $wpdb->prefix . 'memberistic_people';
		$where = array( "waiver_status <> 'missing'" );
		$args  = array();
		if ( ! empty( $params['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $params['q'] ) . '%';
			$where[] = '(full_name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		if ( ! empty( $params['email'] ) ) {
			$where[] = 'email = %s';
			$args[]  = (string) $params['email'];
		}
		$limit  = max( 1, min( 500, (int) ( $params['limit'] ?? 50 ) ) );
		$sql    = "SELECT id, membership_id, full_name, email, phone, date_of_birth,
		                  waiver_status, waiver_signed_at, waiver_expires_at, status
		           FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY waiver_signed_at DESC, id DESC LIMIT %d';
		$args[] = $limit;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();

		return array_map(
			static function ( array $r ): array {
				$name  = trim( (string) $r['full_name'] );
				$parts = preg_split( '/\s+/', $name, 2 ) ?: array( $name );
				return array(
					'id'               => 'memberistic-' . (int) $r['id'],
					'source'           => 'memberistic',
					'unique_ref'       => 'memberistic:' . (int) $r['id'],
					'first_name'       => (string) ( $parts[0] ?? '' ),
					'last_name'        => (string) ( $parts[1] ?? '' ),
					'dob'              => $r['date_of_birth'] ?: null,
					'age'              => null,
					'email'            => $r['email'] ?: null,
					'phone'            => $r['phone'] ?: null,
					'participant_type' => 'Member',
					'waiver_date'      => $r['waiver_signed_at'] ?: null,
					'signed_on'        => $r['waiver_signed_at'] ?: null,
					'valid_until'      => $r['waiver_expires_at'] ?: null,
					'status'           => (string) $r['waiver_status'],
					'check_in_count'   => 0,
					'title'            => 'Memberistic membership #' . (int) $r['membership_id'],
					'read_only'        => true,
				);
			},
			$rows
		);
	}

	/**
	 * Best waiver evidence for one customer across external sources, used by
	 * the CRM detail panel. Memberistic matches by email; Verifyistic by name.
	 */
	public static function status_for_customer( ?string $email, ?string $display_name = null ): ?array {
		if ( $email ) {
			$rows = self::memberistic_records(
				array(
					'email' => $email,
					'limit' => 1,
				)
			);
			if ( $rows ) {
				return $rows[0];
			}
		}
		if ( $display_name ) {
			$rows = self::verifyistic_records(
				array(
					'q'     => $display_name,
					'limit' => 1,
				)
			);
			if ( $rows ) {
				return $rows[0];
			}
		}
		return null;
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$full = $wpdb->prefix . $table;
		if ( ! array_key_exists( $full, $cache ) ) {
			$cache[ $full ] = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) )
			);
		}
		return $cache[ $full ];
	}
}
