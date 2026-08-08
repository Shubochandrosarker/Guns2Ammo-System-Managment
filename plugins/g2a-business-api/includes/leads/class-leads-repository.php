<?php
/**
 * Persistent repository for the `g2aba_leads` table.
 *
 * Every write is idempotent on (source, source_ref_id): re-firing hooks
 * (e.g. a booking's status changing after it was created) updates the same
 * row instead of inserting a duplicate. All queries go through
 * `$wpdb->prepare()` — no raw interpolation of caller-controlled values.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Leads;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leads_Repository {
	public const STATUSES = array( 'new', 'in_progress', 'contacted', 'won', 'lost', 'spam' );

	private const MAX_PER_PAGE = 100;

	/**
	 * Insert a lead, or update the existing row for the same
	 * (source, source_ref_id) pair in place.
	 *
	 * @param array<string, mixed> $fields Any of: category, status,
	 *                                     contact_name, contact_email,
	 *                                     contact_phone, subject, excerpt,
	 *                                     assigned_agent, meta (array,
	 *                                     JSON-encoded on write).
	 */
	public function upsert_by_source( string $source, string $source_ref_id, array $fields ): int {
		global $wpdb;
		$table = Leads_Installer::table_name();
		$now   = current_time( 'mysql' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source = %s AND source_ref_id = %s",
				$source,
				$source_ref_id
			)
		);

		$data = self::shape_write_fields( $fields );

		if ( $existing_id ) {
			$data['updated_at'] = $now;
			// Every column here is textual (VARCHAR/TEXT/DATETIME/JSON-as-
			// LONGTEXT) so a flat '%s' per field is always correct and
			// keeps $format's length trivially in sync with $data's,
			// avoiding the classic off-by-one when fields are conditionally
			// added (wpdb::process_field_formats() matches $format to
			// $data positionally, not by key).
			$wpdb->update(
				$table,
				$data,
				array( 'id' => (int) $existing_id ),
				self::all_string_formats( $data ),
				array( '%d' )
			);
			return (int) $existing_id;
		}

		$data['source']       = $source;
		$data['source_ref_id'] = $source_ref_id;
		$data['status']       = $data['status'] ?? 'new';
		$data['created_at']   = $now;
		$data['updated_at']   = $now;

		$wpdb->insert( $table, $data, self::all_string_formats( $data ) );
		return (int) $wpdb->insert_id;
	}

	public function update_status( int $id, string $status ): bool {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		global $wpdb;
		$table  = Leads_Installer::table_name();
		$result = $wpdb->update(
			$table,
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function assign( int $id, ?string $agent_slug ): bool {
		global $wpdb;
		$table  = Leads_Installer::table_name();
		$result = $wpdb->update(
			$table,
			array(
				'assigned_agent' => $agent_slug,
				'updated_at'     => current_time( 'mysql' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		return false !== $result;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$table = Leads_Installer::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return is_array( $row ) ? self::shape_row( $row ) : null;
	}

	/**
	 * Look up an existing lead's id by its originating (source,
	 * source_ref_id) pair without creating one — used by hooks that only
	 * ever update an already-ingested lead (e.g. a booking status change),
	 * and must never manufacture a new row.
	 */
	public function find_id_by_source( string $source, string $source_ref_id ): ?int {
		global $wpdb;
		$table = Leads_Installer::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE source = %s AND source_ref_id = %s",
				$source,
				$source_ref_id
			)
		);
		return $id ? (int) $id : null;
	}

	/**
	 * Filtered, paginated lead listing.
	 *
	 * @param array<string, mixed> $args category, status, source, search,
	 *                                   date_from, date_to, page, per_page.
	 * @return array{items: array<int, array>, total: int}
	 */
	public function query( array $args = array() ): array {
		global $wpdb;
		$table = Leads_Installer::table_name();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['category'] ) ) {
			$where[]  = 'category = %s';
			$params[] = (string) $args['category'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = (string) $args['source'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$where[]  = '(contact_name LIKE %s OR contact_email LIKE %s OR subject LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = (string) $args['date_from'];
		}
		if ( ! empty( $args['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = (string) $args['date_to'];
		}

		$where_sql = implode( ' AND ', $where );

		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		if ( $per_page <= 0 ) {
			$per_page = 20;
		}
		$per_page = min( self::MAX_PER_PAGE, $per_page );

		$page = isset( $args['page'] ) ? (int) $args['page'] : 1;
		if ( $page <= 0 ) {
			$page = 1;
		}
		$offset = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built from %s/%d placeholders only.
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			empty( $params ) ? $count_sql : $wpdb->prepare( $count_sql, $params )
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $where_sql built from %s/%d placeholders only.
		$list_sql      = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$list_params   = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = self::shape_row( (array) $row );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * @return array{byCategory: array<string,int>, byStatus: array<string,int>, today: int, last7d: int, last30d: int}
	 */
	public function stats(): array {
		global $wpdb;
		$table = Leads_Installer::table_name();

		$by_category = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$category_rows = $wpdb->get_results( "SELECT category, COUNT(*) AS n FROM {$table} GROUP BY category", ARRAY_A );
		foreach ( (array) $category_rows as $row ) {
			$by_category[ (string) ( $row['category'] ?? '' ) ] = (int) ( $row['n'] ?? 0 );
		}

		$by_status = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		foreach ( (array) $status_rows as $row ) {
			$by_status[ (string) ( $row['status'] ?? '' ) ] = (int) ( $row['n'] ?? 0 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$today = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_7d = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_30d = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)" );

		return array(
			'byCategory' => $by_category,
			'byStatus'   => $by_status,
			'today'      => $today,
			'last7d'     => $last_7d,
			'last30d'    => $last_30d,
		);
	}

	/**
	 * Whitelist + shape the fields accepted on insert/update. `meta` is
	 * JSON-encoded here so callers can pass a plain array.
	 *
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function shape_write_fields( array $fields ): array {
		$data = array();

		foreach ( array( 'category', 'status', 'contact_name', 'contact_email', 'contact_phone', 'subject', 'excerpt', 'assigned_agent' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = null === $fields[ $key ] ? null : (string) $fields[ $key ];
			}
		}

		if ( array_key_exists( 'meta', $fields ) ) {
			$data['meta'] = is_array( $fields['meta'] ) ? wp_json_encode( $fields['meta'] ) : (string) $fields['meta'];
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<int, string>
	 */
	private static function all_string_formats( array $data ): array {
		return array_fill( 0, count( $data ), '%s' );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private static function shape_row( array $row ): array {
		$meta = $row['meta'] ?? null;
		if ( is_string( $meta ) && '' !== $meta ) {
			$decoded = json_decode( $meta, true );
			$meta    = is_array( $decoded ) ? $decoded : null;
		} elseif ( '' === $meta ) {
			$meta = null;
		}

		return array(
			'id'            => (int) ( $row['id'] ?? 0 ),
			'category'      => (string) ( $row['category'] ?? '' ),
			'source'        => (string) ( $row['source'] ?? '' ),
			'sourceRefId'   => $row['source_ref_id'] ?? null,
			'status'        => (string) ( $row['status'] ?? 'new' ),
			'contactName'   => $row['contact_name'] ?? null,
			'contactEmail'  => $row['contact_email'] ?? null,
			'contactPhone'  => $row['contact_phone'] ?? null,
			'subject'       => $row['subject'] ?? null,
			'excerpt'       => $row['excerpt'] ?? null,
			'assignedAgent' => $row['assigned_agent'] ?? null,
			'meta'          => $meta,
			'createdAt'     => (string) ( $row['created_at'] ?? '' ),
			'updatedAt'     => (string) ( $row['updated_at'] ?? '' ),
		);
	}
}
