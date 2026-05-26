<?php
/**
 * Database layer — table creation and submission/reply storage.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the two plugin tables: submissions and replies.
 */
class WPISTIC_CF_Database {

	/** Submissions table name (without prefix). */
	const SUBMISSIONS = 'WPISTIC_CF_submissions';

	/** Replies table name (without prefix). */
	const REPLIES = 'WPISTIC_CF_replies';

	/** Attachments table name (without prefix). */
	const ATTACHMENTS = 'WPISTIC_CF_attachments';
	/** Notes table name (without prefix). */
	const NOTES = 'WPISTIC_CF_notes';
	/** Impressions table name (without prefix). */
	const IMPRESSIONS = 'WPISTIC_CF_impressions';
	/** AI metadata table name (without prefix). */
	const AI_META = 'wpistic_cf_ai_meta';

	/** Fully-qualified submissions table name. */
	public static function submissions_table() {
		global $wpdb;
		return $wpdb->prefix . self::SUBMISSIONS;
	}

	/** Fully-qualified replies table name. */
	public static function replies_table() {
		global $wpdb;
		return $wpdb->prefix . self::REPLIES;
	}

	/** Fully-qualified attachments table name. */
	public static function attachments_table() {
		global $wpdb;
		return $wpdb->prefix . self::ATTACHMENTS;
	}
	/** Fully-qualified notes table name. */
	public static function notes_table() {
		global $wpdb;
		return $wpdb->prefix . self::NOTES;
	}
	/** Fully-qualified impressions table name. */
	public static function impressions_table() {
		global $wpdb;
		return $wpdb->prefix . self::IMPRESSIONS;
	}
	/** Fully-qualified AI metadata table name. */
	public static function ai_meta_table() {
		global $wpdb;
		return $wpdb->prefix . self::AI_META;
	}

	/**
	 * Create/upgrade the database tables. Runs on activation.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset     = $wpdb->get_charset_collate();
		$subs        = self::submissions_table();
		$replies     = self::replies_table();
		$attachments = self::attachments_table();
		$notes       = self::notes_table();
		$impressions = self::impressions_table();
		$ai_meta     = self::ai_meta_table();

		$sql_subs = "CREATE TABLE {$subs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_name VARCHAR(191) NOT NULL DEFAULT '',
			sender_name VARCHAR(191) NOT NULL DEFAULT '',
			sender_email VARCHAR(191) NOT NULL DEFAULT '',
			sender_phone VARCHAR(64) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			message LONGTEXT NULL,
			fields LONGTEXT NULL,
			source_url VARCHAR(255) NOT NULL DEFAULT '',
			ip_address VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY form_name (form_name),
			KEY created_at (created_at)
		) {$charset};";

		$sql_replies = "CREATE TABLE {$replies} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL,
			reply_subject VARCHAR(255) NOT NULL DEFAULT '',
			reply_body LONGTEXT NULL,
			sent_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id)
		) {$charset};";

		$sql_attachments = "CREATE TABLE {$attachments} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL,
			original_name VARCHAR(255) NOT NULL DEFAULT '',
			stored_name VARCHAR(255) NOT NULL DEFAULT '',
			mime_type VARCHAR(100) NOT NULL DEFAULT '',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(20) NOT NULL DEFAULT 'local',
			external_url VARCHAR(500) NOT NULL DEFAULT '',
			uploaded_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id)
		) {$charset};";
		$sql_notes = "CREATE TABLE {$notes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			submission_id BIGINT UNSIGNED NOT NULL,
			note_body LONGTEXT NULL,
			tags VARCHAR(500) NOT NULL DEFAULT '',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY submission_id (submission_id),
			KEY created_at (created_at)
		) {$charset};";
		$sql_impressions = "CREATE TABLE {$impressions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_name VARCHAR(191) NOT NULL DEFAULT '',
			source_url VARCHAR(255) NOT NULL DEFAULT '',
			referer_url VARCHAR(255) NOT NULL DEFAULT '',
			rendered_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY form_name (form_name),
			KEY rendered_at (rendered_at)
		) {$charset};";
		$sql_ai_meta = "CREATE TABLE {$ai_meta} (
			submission_id BIGINT UNSIGNED NOT NULL,
			spam_score INT NOT NULL DEFAULT 0,
			ai_tags VARCHAR(500) NOT NULL DEFAULT '',
			ai_reply LONGTEXT NULL,
			source_provider VARCHAR(100) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (submission_id),
			KEY updated_at (updated_at)
		) {$charset};";

		dbDelta( $sql_subs );
		dbDelta( $sql_replies );
		dbDelta( $sql_attachments );
		dbDelta( $sql_notes );
		dbDelta( $sql_impressions );
		dbDelta( $sql_ai_meta );

		update_option( 'WPISTIC_CF_db_version', WPISTIC_CF_DB_VERSION );
	}

	/**
	 * Ensure the schema is current — cheap guard on admin_init.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'WPISTIC_CF_db_version' ) !== WPISTIC_CF_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Insert a submission record.
	 *
	 * @param array $data Associative record data.
	 * @return int New submission ID, or 0 on failure.
	 */
	public static function insert_submission( array $data ) {
		global $wpdb;

		$row = [
			'form_name'    => substr( (string) ( $data['form_name'] ?? '' ), 0, 191 ),
			'sender_name'  => substr( (string) ( $data['sender_name'] ?? '' ), 0, 191 ),
			'sender_email' => substr( (string) ( $data['sender_email'] ?? '' ), 0, 191 ),
			'sender_phone' => substr( (string) ( $data['sender_phone'] ?? '' ), 0, 64 ),
			'subject'      => substr( (string) ( $data['subject'] ?? '' ), 0, 255 ),
			'message'      => (string) ( $data['message'] ?? '' ),
			'fields'       => wp_json_encode( $data['fields'] ?? [] ),
			'source_url'   => substr( (string) ( $data['source_url'] ?? '' ), 0, 255 ),
			'ip_address'   => substr( (string) ( $data['ip_address'] ?? '' ), 0, 64 ),
			'status'       => 'new',
			'created_at'   => current_time( 'mysql' ),
		];

		$ok = $wpdb->insert( self::submissions_table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch a single submission by ID.
	 *
	 * @param int $id Submission ID.
	 * @return object|null
	 */
	public static function get_submission( $id ) {
		global $wpdb;
		$table = self::submissions_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id )
		);
	}

	/**
	 * Query submissions with optional filters + pagination.
	 *
	 * @param array $args search, form, status, paged, per_page.
	 * @return array { items: object[], total: int }
	 */
	public static function query_submissions( array $args = [] ) {
		global $wpdb;
		$table = self::submissions_table();

		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$params = [];

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['form'] ) ) {
			$where   .= ' AND form_name = %s';
			$params[] = $args['form'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (sender_name LIKE %s OR sender_email LIKE %s OR subject LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$count_sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$total     = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: $wpdb->get_var( $count_sql ) );

		$list_sql      = "SELECT * FROM {$table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$list_params   = array_merge( $params, [ $per_page, $offset ] );
		$items         = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) );

		return [ 'items' => $items ?: [], 'total' => $total ];
	}

	/**
	 * Distinct form names — powers the admin filter dropdown.
	 *
	 * @return string[]
	 */
	public static function form_names() {
		global $wpdb;
		$table = self::submissions_table();
		$names = $wpdb->get_col( "SELECT DISTINCT form_name FROM {$table} ORDER BY form_name ASC" );
		return array_values( array_filter( (array) $names ) );
	}

	/**
	 * Count submissions per status — powers dashboard stat cards.
	 *
	 * @return array
	 */
	public static function status_counts() {
		global $wpdb;
		$table = self::submissions_table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );

		$out = [ 'total' => 0, 'new' => 0, 'read' => 0, 'replied' => 0 ];
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
			$out['total']       += (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Update a submission status.
	 *
	 * @param int    $id     Submission ID.
	 * @param string $status new|read|replied.
	 * @return bool
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$allowed = [ 'new', 'read', 'replied' ];
		if ( ! in_array( $status, $allowed, true ) ) {
			return false;
		}
		$result = $wpdb->update(
			self::submissions_table(),
			[ 'status' => $status ],
			[ 'id' => (int) $id ]
		);
		return false !== $result;
	}

	/**
	 * Delete a submission, its replies, and all attached files.
	 *
	 * @param int $id Submission ID.
	 * @return bool
	 */
	public static function delete_submission( $id ) {
		global $wpdb;
		$id = (int) $id;

		// Delete on-disk files for local attachments before dropping rows.
		$attachments = self::get_attachments( $id );
		if ( class_exists( 'WPISTIC_CF_Attachments' ) ) {
			foreach ( $attachments as $att ) {
				if ( 'local' === $att->source ) {
					WPISTIC_CF_Attachments::delete_file( $id, $att->stored_name );
				}
			}
		}

		$wpdb->delete( self::attachments_table(), [ 'submission_id' => $id ] );
		$wpdb->delete( self::notes_table(), [ 'submission_id' => $id ] );
		$wpdb->delete( self::ai_meta_table(), [ 'submission_id' => $id ] );
		$wpdb->delete( self::replies_table(), [ 'submission_id' => $id ] );
		return (bool) $wpdb->delete( self::submissions_table(), [ 'id' => $id ] );
	}

	/**
	 * List submissions for one sender email, newest first.
	 *
	 * @param string $email Sender email.
	 * @return object[]
	 */
	public static function submissions_by_sender_email( $email ) {
		global $wpdb;
		$table = self::submissions_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE sender_email = %s ORDER BY created_at DESC", (string) $email )
		) ?: [];
	}

	/**
	 * Sender-level activity view across submissions + reply counts.
	 *
	 * @param string $email Sender email.
	 * @return object[]
	 */
	public static function sender_activity( $email ) {
		global $wpdb;
		$subs    = self::submissions_table();
		$replies = self::replies_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, COUNT(r.id) AS reply_count, MAX(r.sent_at) AS last_reply_at
				   FROM {$subs} s
				   LEFT JOIN {$replies} r ON r.submission_id = s.id
				  WHERE s.sender_email = %s
				  GROUP BY s.id
				  ORDER BY s.created_at DESC",
				(string) $email
			)
		) ?: [];
	}

	/**
	 * Thread list grouped by sender email.
	 *
	 * @param array $args search, paged, per_page.
	 * @return array{items:object[],total:int}
	 */
	public static function query_threads( array $args = [] ) {
		global $wpdb;
		$table = self::submissions_table();
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged    = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset   = ( $paged - 1 ) * $per_page;
		$where    = "WHERE sender_email <> ''";
		$params   = [];
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (sender_email LIKE %s OR sender_name LIKE %s OR form_name LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$count_sql = "SELECT COUNT(DISTINCT sender_email) FROM {$table} {$where}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$list_sql = "SELECT sender_email, MAX(sender_name) AS sender_name, COUNT(*) AS submissions_count,
		                    MAX(created_at) AS last_at,
		                    SUM(CASE WHEN status='new' THEN 1 ELSE 0 END) AS new_count,
		                    SUM(CASE WHEN status='read' THEN 1 ELSE 0 END) AS read_count,
		                    SUM(CASE WHEN status='replied' THEN 1 ELSE 0 END) AS replied_count
		               FROM {$table}
		               {$where}
		              GROUP BY sender_email
		              ORDER BY last_at DESC
		              LIMIT %d OFFSET %d";
		$list_params = array_merge( $params, [ $per_page, $offset ] );
		$items       = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ) ?: [];
		return [ 'items' => $items, 'total' => $total ];
	}

	/**
	 * Add an internal note for a submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $note_body     Note text.
	 * @param string $tags          Comma-separated tags.
	 * @return int
	 */
	public static function insert_note( $submission_id, $note_body, $tags = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::notes_table(),
			[
				'submission_id' => (int) $submission_id,
				'note_body'     => (string) $note_body,
				'tags'          => substr( (string) $tags, 0, 500 ),
				'created_by'    => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Note list for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return object[]
	 */
	public static function get_notes( $submission_id ) {
		global $wpdb;
		$notes = self::notes_table();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.display_name
				   FROM {$notes} n
				   LEFT JOIN {$wpdb->users} u ON u.ID = n.created_by
				  WHERE n.submission_id = %d
				  ORDER BY n.created_at DESC",
				(int) $submission_id
			)
		) ?: [];
	}

	/**
	 * Log a frontend form impression.
	 *
	 * @param string $form_name Form name.
	 */
	public static function log_impression( $form_name ) {
		global $wpdb;
		$wpdb->insert(
			self::impressions_table(),
			[
				'form_name'   => substr( (string) $form_name, 0, 191 ),
				'source_url'  => substr( (string) home_url( add_query_arg( [] ) ), 0, 255 ),
				'referer_url' => substr( (string) wp_get_referer(), 0, 255 ),
				'rendered_at' => current_time( 'mysql' ),
			]
		);
	}

	/**
	 * Store an outgoing reply.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $subject       Reply subject.
	 * @param string $body          Reply body.
	 * @return int Reply ID.
	 */
	public static function insert_reply( $submission_id, $subject, $body ) {
		global $wpdb;
		$wpdb->insert(
			self::replies_table(),
			[
				'submission_id' => (int) $submission_id,
				'reply_subject' => substr( (string) $subject, 0, 255 ),
				'reply_body'    => (string) $body,
				'sent_by'       => get_current_user_id(),
				'sent_at'       => current_time( 'mysql' ),
			]
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * All replies for a submission, oldest first.
	 *
	 * @param int $submission_id Submission ID.
	 * @return object[]
	 */
	public static function get_replies( $submission_id ) {
		global $wpdb;
		$table = self::replies_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d ORDER BY sent_at ASC", (int) $submission_id )
		) ?: [];
	}

	/**
	 * Insert an attachment row.
	 *
	 * @param array $data submission_id, original_name, stored_name, mime_type, size_bytes, source, external_url.
	 * @return int Attachment ID, or 0 on failure.
	 */
	public static function insert_attachment( array $data ) {
		global $wpdb;
		$row = [
			'submission_id' => (int) ( $data['submission_id'] ?? 0 ),
			'original_name' => substr( (string) ( $data['original_name'] ?? '' ), 0, 255 ),
			'stored_name'   => substr( (string) ( $data['stored_name'] ?? '' ), 0, 255 ),
			'mime_type'     => substr( (string) ( $data['mime_type'] ?? '' ), 0, 100 ),
			'size_bytes'    => (int) ( $data['size_bytes'] ?? 0 ),
			'source'        => in_array( $data['source'] ?? 'local', [ 'local', 'external' ], true ) ? $data['source'] : 'local',
			'external_url'  => substr( (string) ( $data['external_url'] ?? '' ), 0, 500 ),
			'uploaded_at'   => current_time( 'mysql' ),
		];
		$ok = $wpdb->insert( self::attachments_table(), $row );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Fetch attachments for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return object[]
	 */
	public static function get_attachments( $submission_id ) {
		global $wpdb;
		$table = self::attachments_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d ORDER BY id ASC", (int) $submission_id )
		) ?: [];
	}

	/**
	 * Fetch a single attachment by ID.
	 *
	 * @param int $id Attachment ID.
	 * @return object|null
	 */
	public static function get_attachment( $id ) {
		global $wpdb;
		$table = self::attachments_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id )
		);
	}

	/* ==================================================================
	 * Bulk helpers (v1.2)
	 * ================================================================== */

	/**
	 * Update status on a set of submission IDs.
	 *
	 * @param int[]  $ids    Submission IDs.
	 * @param string $status new|read|replied.
	 * @return int Number of rows updated.
	 */
	public static function bulk_set_status( array $ids, $status ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		if ( ! in_array( $status, [ 'new', 'read', 'replied' ], true ) ) {
			return 0;
		}
		$table = self::submissions_table();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql   = "UPDATE {$table} SET status = %s WHERE id IN ({$ph})";
		$args  = array_merge( [ $status ], $ids );
		return (int) $wpdb->query( $wpdb->prepare( $sql, $args ) );
	}

	/**
	 * Bulk delete (cascading replies + attachments via delete_submission).
	 *
	 * @param int[] $ids Submission IDs.
	 * @return int Number of submissions deleted.
	 */
	public static function bulk_delete( array $ids ) {
		$n = 0;
		foreach ( array_filter( array_map( 'intval', $ids ) ) as $id ) {
			if ( self::delete_submission( $id ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Stream every submission matching the given filters (no LIMIT).
	 * Used by the export pipeline so memory stays flat on large datasets.
	 *
	 * @param array    $args     search, form, status, ids.
	 * @param callable $callback Called once per row with the row object.
	 */
	public static function each_submission( array $args, $callback ) {
		global $wpdb;
		$table  = self::submissions_table();
		$where  = 'WHERE 1=1';
		$params = [];

		if ( ! empty( $args['status'] ) ) {
			$where   .= ' AND status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['form'] ) ) {
			$where   .= ' AND form_name = %s';
			$params[] = $args['form'];
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where   .= ' AND (sender_name LIKE %s OR sender_email LIKE %s OR subject LIKE %s OR message LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids = array_filter( array_map( 'intval', (array) $args['ids'] ) );
			if ( $ids ) {
				$ph     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$where .= " AND id IN ({$ph})";
				$params = array_merge( $params, $ids );
			}
		}

		// Page through in chunks of 200 to keep peak memory low.
		$chunk  = 200;
		$offset = 0;
		do {
			$sql      = "SELECT * FROM {$table} {$where} ORDER BY created_at ASC LIMIT %d OFFSET %d";
			$args_sql = array_merge( $params, [ $chunk, $offset ] );
			$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $args_sql ) );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				call_user_func( $callback, $row );
			}
			$offset += $chunk;
		} while ( count( $rows ) === $chunk );
	}

	/**
	 * Count attachments per submission for a set of IDs.
	 *
	 * @param int[] $ids Submission IDs.
	 * @return array<int,int> id => count
	 */
	public static function attachment_counts( array $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return [];
		}
		$table = self::attachments_table();
		$ph    = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql   = "SELECT submission_id, COUNT(*) AS n FROM {$table} WHERE submission_id IN ({$ph}) GROUP BY submission_id";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $ids ), ARRAY_A );
		$out   = [];
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['submission_id'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Delete every submission older than $days (uses delete_submission so
	 * replies + on-disk files are cascaded).
	 *
	 * @param int $days Cutoff in days.
	 * @return int Number of submissions deleted.
	 */
	public static function purge_older_than( $days ) {
		global $wpdb;
		$days = max( 1, (int) $days );
		$table = self::submissions_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE created_at < DATE_SUB( NOW(), INTERVAL %d DAY )",
				$days
			)
		);
		return self::bulk_delete( (array) $ids );
	}

	/**
	 * Find submission IDs by sender email (used by GDPR exporter/eraser).
	 *
	 * @param string $email Sender email address.
	 * @return int[]
	 */
	public static function ids_by_email( $email ) {
		global $wpdb;
		$table = self::submissions_table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE sender_email = %s ORDER BY id ASC", $email )
		);
		return array_map( 'intval', (array) $ids );
	}

	/* ==================================================================
	 * Analytics (v1.3)
	 * ================================================================== */

	/**
	 * Submission counts grouped by day, for the last N days.
	 * Days with zero submissions are returned as 0.
	 *
	 * @param int $days Number of days back (default 30).
	 * @return array<string,int> 'YYYY-MM-DD' => count
	 */
	public static function submissions_by_day( $days = 30 ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$table = self::submissions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) AS d, COUNT(*) AS n
				   FROM {$table}
				  WHERE created_at >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
				  GROUP BY DATE(created_at)",
				$days - 1
			),
			ARRAY_A
		);
		$map = [];
		foreach ( (array) $rows as $r ) {
			$map[ (string) $r['d'] ] = (int) $r['n'];
		}
		// Fill missing days with zeros so the chart has a continuous x-axis.
		$out = [];
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d         = wp_date( 'Y-m-d', strtotime( "-{$i} days" ) );
			$out[ $d ] = $map[ $d ] ?? 0;
		}
		return $out;
	}

	/**
	 * Submissions received today (server local time).
	 *
	 * @return int
	 */
	public static function today_count() {
		global $wpdb;
		$table = self::submissions_table();
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE DATE(created_at) = CURDATE()"
		);
	}

	/**
	 * Top N forms by total submission volume (over all time).
	 *
	 * @param int $limit Number of rows.
	 * @return array<array{form_name:string,n:int}>
	 */
	public static function top_forms( $limit = 5 ) {
		global $wpdb;
		$limit = max( 1, (int) $limit );
		$table = self::submissions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_name, COUNT(*) AS n
				   FROM {$table}
				  GROUP BY form_name
				  ORDER BY n DESC
				  LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [ 'form_name' => (string) $r['form_name'], 'n' => (int) $r['n'] ];
		}
		return $out;
	}

	/**
	 * Average time-to-first-reply, in seconds, computed across submissions
	 * that have at least one reply.
	 *
	 * @return int 0 if no replies exist.
	 */
	public static function avg_reply_time_seconds() {
		global $wpdb;
		$subs    = self::submissions_table();
		$replies = self::replies_table();
		$avg     = $wpdb->get_var(
			"SELECT AVG( TIMESTAMPDIFF( SECOND, s.created_at, r.first_sent ) )
			   FROM {$subs} s
			   JOIN (
			          SELECT submission_id, MIN(sent_at) AS first_sent
			            FROM {$replies}
			           GROUP BY submission_id
			        ) r
			     ON r.submission_id = s.id"
		);
		return $avg === null ? 0 : (int) round( (float) $avg );
	}

	/**
	 * Percentage of submissions whose status is 'replied'.
	 *
	 * @return float 0..100 (1 decimal).
	 */
	public static function replied_rate() {
		$counts = self::status_counts();
		$total  = max( 1, (int) $counts['total'] );
		return round( ( (int) $counts['replied'] / $total ) * 100, 1 );
	}

	/**
	 * Impressions received today.
	 *
	 * @return int
	 */
	public static function impressions_today_count() {
		global $wpdb;
		$table = self::impressions_table();
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE DATE(rendered_at) = CURDATE()"
		);
	}

	/**
	 * Per-form impressions in date window.
	 *
	 * @param int $days Lookback days.
	 * @return array<string,int>
	 */
	public static function impressions_by_form( $days = 30 ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$table = self::impressions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_name, COUNT(*) AS n
				   FROM {$table}
				  WHERE rendered_at >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
				  GROUP BY form_name",
				$days - 1
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['form_name'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Per-form submissions in date window.
	 *
	 * @param int $days Lookback days.
	 * @return array<string,int>
	 */
	public static function submissions_by_form_window( $days = 30 ) {
		global $wpdb;
		$days  = max( 1, (int) $days );
		$table = self::submissions_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_name, COUNT(*) AS n
				   FROM {$table}
				  WHERE created_at >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
				  GROUP BY form_name",
				$days - 1
			),
			ARRAY_A
		);
		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['form_name'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Conversion overview per form for the last N days.
	 *
	 * @param int $days Lookback days.
	 * @return array<int,array{form_name:string,impressions:int,submissions:int,conversion:float}>
	 */
	public static function conversion_by_form( $days = 30 ) {
		$impressions = self::impressions_by_form( $days );
		$submissions = self::submissions_by_form_window( $days );
		$forms       = array_unique( array_merge( array_keys( $impressions ), array_keys( $submissions ) ) );
		$out = [];
		foreach ( $forms as $form_name ) {
			$imp = (int) ( $impressions[ $form_name ] ?? 0 );
			$sub = (int) ( $submissions[ $form_name ] ?? 0 );
			$conv = $imp > 0 ? round( ( $sub / $imp ) * 100, 2 ) : 0.0;
			$out[] = [
				'form_name'    => (string) $form_name,
				'impressions'  => $imp,
				'submissions'  => $sub,
				'conversion'   => $conv,
			];
		}
		usort(
			$out,
			function ( $a, $b ) {
				return $b['conversion'] <=> $a['conversion'];
			}
		);
		return $out;
	}

	/**
	 * Count overdue submissions by SLA threshold.
	 *
	 * @param int $hours SLA threshold in hours.
	 * @return int
	 */
	public static function overdue_submissions_count( $hours = 24 ) {
		global $wpdb;
		$hours = max( 1, (int) $hours );
		$table = self::submissions_table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				  WHERE status <> 'replied'
				    AND created_at < DATE_SUB( NOW(), INTERVAL %d HOUR )",
				$hours
			)
		);
	}

	/**
	 * Median (P50) reply time in seconds.
	 *
	 * @return int
	 */
	public static function p50_reply_time_seconds() {
		global $wpdb;
		$subs    = self::submissions_table();
		$replies = self::replies_table();
		$rows = $wpdb->get_col(
			"SELECT TIMESTAMPDIFF( SECOND, s.created_at, r.first_sent ) AS t
			   FROM {$subs} s
			   JOIN (
			          SELECT submission_id, MIN(sent_at) AS first_sent
			            FROM {$replies}
			           GROUP BY submission_id
			        ) r
			     ON r.submission_id = s.id
			  ORDER BY t ASC"
		);
		$count = count( (array) $rows );
		if ( 0 === $count ) {
			return 0;
		}
		$mid = (int) floor( ( $count - 1 ) / 2 );
		return (int) $rows[ $mid ];
	}

	/**
	 * Upsert AI metadata for a submission.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param int    $spam_score    0-100.
	 * @param string $ai_tags       Comma-separated tags.
	 * @param string $ai_reply      Generated reply draft.
	 * @param string $provider      Provider key.
	 * @return bool
	 */
	public static function upsert_ai_meta( $submission_id, $spam_score, $ai_tags, $ai_reply, $provider ) {
		global $wpdb;
		$table = self::ai_meta_table();
		$data  = [
			'submission_id'   => (int) $submission_id,
			'spam_score'      => max( 0, min( 100, (int) $spam_score ) ),
			'ai_tags'         => substr( (string) $ai_tags, 0, 500 ),
			'ai_reply'        => (string) $ai_reply,
			'source_provider' => substr( (string) $provider, 0, 100 ),
			'updated_at'      => current_time( 'mysql' ),
		];
		$exists = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT submission_id FROM {$table} WHERE submission_id = %d", (int) $submission_id )
		);
		if ( $exists ) {
			return false !== $wpdb->update( $table, $data, [ 'submission_id' => (int) $submission_id ] );
		}
		return (bool) $wpdb->insert( $table, $data );
	}

	/**
	 * Get AI metadata for a submission.
	 *
	 * @param int $submission_id Submission ID.
	 * @return object|null
	 */
	public static function get_ai_meta( $submission_id ) {
		global $wpdb;
		$table = self::ai_meta_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE submission_id = %d", (int) $submission_id )
		);
	}
}
