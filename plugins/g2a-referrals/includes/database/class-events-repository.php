<?php
/**
 * Append-only, hash-chained audit trail.
 *
 * Follows the tamper-evident pattern already in wpbx_g2a_audit_logs
 * (g2a-pos-core): each row's hash covers the row plus the previous row's
 * hash, so removing or editing any row breaks the chain from that point on
 * and verify_chain() reports exactly where.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Events_Repository {

	/**
	 * @return string
	 */
	public static function table() {
		return Schema::table( 'events' );
	}

	/**
	 * Append an audit event.
	 *
	 * @param string $event_type  Machine event name.
	 * @param array  $args        referrer_id, object_type, object_id, payload, actor_id.
	 * @return int Row id, 0 on failure.
	 */
	public static function log( $event_type, array $args = array() ) {
		global $wpdb;

		$event_type = mb_substr( (string) $event_type, 0, 60 );
		if ( '' === $event_type ) {
			return 0;
		}

		$payload = isset( $args['payload'] ) ? (array) $args['payload'] : array();

		$prev = self::last_row_hash();
		$row  = array(
			'referrer_id'  => (int) ( $args['referrer_id'] ?? 0 ) ?: null,
			'event_type'   => $event_type,
			'object_type'  => isset( $args['object_type'] ) ? mb_substr( (string) $args['object_type'], 0, 40 ) : null,
			'object_id'    => (int) ( $args['object_id'] ?? 0 ) ?: null,
			'actor_id'     => array_key_exists( 'actor_id', $args ) ? ( (int) $args['actor_id'] ?: null ) : ( get_current_user_id() ?: null ),
			'ip_hash'      => \WordPressistic\G2AReferrals\Fingerprint::ip_hash(),
			'payload_json' => $payload ? wp_json_encode( self::scrub( $payload ) ) : null,
			'prev_hash'    => $prev,
			'created_at'   => current_time( 'mysql', true ),
		);

		$row['row_hash'] = self::hash_row( $row, $prev );

		$ok = $wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Walk the chain and report any row whose hash no longer matches.
	 *
	 * @param int $limit Rows to check.
	 * @return array{rows_checked:int,broken:int[],ok:bool}
	 */
	public static function verify_chain( $limit = 5000 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id ASC LIMIT %d", max( 1, (int) $limit ) ),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$prev   = null;
		$broken = array();

		foreach ( $rows as $row ) {
			$check = $row;
			unset( $check['row_hash'], $check['id'] );

			$expected = self::hash_row( $check, $prev );

			if ( ! hash_equals( (string) ( $row['row_hash'] ?? '' ), $expected )
				|| (string) ( $row['prev_hash'] ?? '' ) !== (string) ( $prev ?? '' ) ) {
				$broken[] = (int) $row['id'];
			}

			$prev = (string) $row['row_hash'];
		}

		return array(
			'rows_checked' => count( $rows ),
			'broken'       => $broken,
			'ok'           => empty( $broken ),
		);
	}

	/**
	 * Read events for the admin log.
	 *
	 * @param array $args event_type, referrer_id, limit, offset.
	 * @return array[]
	 */
	public static function search( array $args = array() ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'event_type'  => '',
				'referrer_id' => 0,
				'limit'       => 100,
				'offset'      => 0,
			)
		);

		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== (string) $args['event_type'] ) {
			$where[]  = 'event_type = %s';
			$params[] = (string) $args['event_type'];
		}

		if ( (int) $args['referrer_id'] > 0 ) {
			$where[]  = 'referrer_id = %d';
			$params[] = (int) $args['referrer_id'];
		}

		$params[] = max( 1, min( 500, (int) $args['limit'] ) );
		$params[] = max( 0, (int) $args['offset'] );

		$clause = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; placeholders prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d", $params ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The hash of the newest row, or null on an empty chain.
	 *
	 * @return string|null
	 */
	private static function last_row_hash() {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$value = $wpdb->get_var( "SELECT row_hash FROM {$table} ORDER BY id DESC LIMIT 1" );

		return $value ? (string) $value : null;
	}

	/**
	 * Deterministic row hash: sorted fields, keyed by the site's auth salt.
	 *
	 * @param array       $row  Row without its own hash.
	 * @param string|null $prev Previous row hash.
	 * @return string
	 */
	private static function hash_row( array $row, $prev ) {
		$copy = $row;
		unset( $copy['row_hash'], $copy['id'] );
		ksort( $copy );

		$payload = ( $prev ?? '' ) . '|' . wp_json_encode( $copy );

		return hash_hmac( 'sha256', $payload, wp_salt( 'logged_in' ) );
	}

	/**
	 * Strip anything that looks like PII before it reaches the audit payload.
	 *
	 * This is a firearms retailer: an audit row must be able to prove what
	 * happened without recording who a member's friends are.
	 *
	 * @param array $payload Raw payload.
	 * @return array
	 */
	private static function scrub( array $payload ) {
		$blocked = array( 'email', 'user_email', 'phone', 'ip', 'ip_address', 'full_name', 'address', 'card', 'last4' );

		foreach ( $payload as $key => $value ) {
			if ( in_array( strtolower( (string) $key ), $blocked, true ) ) {
				unset( $payload[ $key ] );
				continue;
			}

			if ( is_array( $value ) ) {
				$payload[ $key ] = self::scrub( $value );
			}
		}

		return $payload;
	}
}
