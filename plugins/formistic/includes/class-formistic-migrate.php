<?php
/**
 * Migration — one-time importer from the legacy "WPistic Contact Form"
 * plugin tables (WPISTIC_CF_*) into the Formistic tables.
 *
 * Idempotent: imported submission IDs are tracked in a mapping option and
 * every table keeps a high-water mark, so the importer can be run repeatedly
 * (or resumed after a timeout) without creating duplicates. Rows are copied
 * in batches of 500. Local attachment files are copied from the old
 * protected uploads directory into Formistic's.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Import from Wpistic Contact Form" admin action.
 */
class Wpistic_Formistic_Migrate {

	/** admin-post action for the settings-screen button. */
	const ACTION = 'wpistic_formistic_import_wpcf';

	/** Capability required to run the import. */
	const CAP = 'manage_options';

	/** Option holding the old-submission-id => new-submission-id map. */
	const MAP_OPTION = 'wpistic_formistic_wpcf_id_map';

	/** Option holding the per-table import high-water marks. */
	const PROGRESS_OPTION = 'wpistic_formistic_wpcf_import_progress';

	/** Transient holding the last run's counts for the settings notice. */
	const REPORT_TRANSIENT = 'wpistic_formistic_wpcf_import_report';

	/** Rows copied per query loop. */
	const BATCH = 500;

	/** Old plugin's protected uploads subdirectory. */
	const OLD_UPLOAD_SUBDIR = 'wpistic-contact-form';

	/**
	 * Register the admin-triggered import action.
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle_import' ] );
	}

	/**
	 * Fully-qualified legacy table name.
	 *
	 * @param string $name Table name without prefix (case matters on Linux).
	 * @return string
	 */
	public static function old_table( $name ) {
		global $wpdb;
		return $wpdb->prefix . $name;
	}

	/**
	 * Does a table exist in this database?
	 *
	 * @param string $table Fully-qualified table name.
	 * @return bool
	 */
	protected static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}

	/**
	 * Are the legacy plugin tables present? Controls whether the import
	 * button is shown at all.
	 *
	 * @return bool
	 */
	public static function old_tables_exist() {
		return self::table_exists( self::old_table( 'WPISTIC_CF_submissions' ) );
	}

	/**
	 * Admin-post handler for the "Import from Wpistic Contact Form" button.
	 */
	public function handle_import() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$notice = 'wpcf_import';
		if ( self::old_tables_exist() ) {
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$report = self::run();
			set_transient( self::REPORT_TRANSIENT, $report, 10 * MINUTE_IN_SECONDS );
		} else {
			$notice = 'wpcf_import_missing';
		}

		$back = add_query_arg( [
			'page'                     => Wpistic_Formistic_Settings::PAGE,
			'tab'                      => 'general',
			'wpistic_formistic_notice' => $notice,
		], admin_url( 'admin.php' ) );
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Run the full import. Safe to call repeatedly — already-imported rows
	 * are skipped via the ID map + per-table high-water marks.
	 *
	 * @return array<string,int> Imported counts per record type.
	 */
	public static function run() {
		$report = [
			'submissions' => 0,
			'replies'     => 0,
			'attachments' => 0,
			'notes'       => 0,
			'impressions' => 0,
			'subscribers' => 0,
			'skipped'     => 0,
		];

		$map = get_option( self::MAP_OPTION, [] );
		$map = is_array( $map ) ? $map : [];
		$progress = get_option( self::PROGRESS_OPTION, [] );
		$progress = is_array( $progress ) ? $progress : [];

		self::import_submissions( $map, $progress, $report );

		self::import_children( 'WPISTIC_CF_replies', Wpistic_Formistic_Database::replies_table(), 'replies', $map, $progress, $report );
		self::import_attachments( $map, $progress, $report );
		self::import_children( 'WPISTIC_CF_notes', Wpistic_Formistic_Database::notes_table(), 'notes', $map, $progress, $report );
		self::import_impressions( $progress, $report );
		self::import_subscribers( $progress, $report );

		update_option( self::MAP_OPTION, $map, false );
		update_option( self::PROGRESS_OPTION, $progress, false );

		return $report;
	}

	/**
	 * Copy submissions, preserving every column (including status and
	 * created_at) and recording old-id => new-id in the map.
	 *
	 * @param array $map      Old => new submission ID map (by reference).
	 * @param array $progress Per-table high-water marks (by reference).
	 * @param array $report   Counts (by reference).
	 */
	protected static function import_submissions( array &$map, array &$progress, array &$report ) {
		global $wpdb;
		$old = self::old_table( 'WPISTIC_CF_submissions' );
		if ( ! self::table_exists( $old ) ) {
			return;
		}
		$new   = Wpistic_Formistic_Database::submissions_table();
		$since = (int) ( $progress['submissions'] ?? 0 );

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$old} WHERE id > %d ORDER BY id ASC LIMIT %d", $since, self::BATCH ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$old_id = (int) $row['id'];
				$since  = $old_id;
				if ( isset( $map[ $old_id ] ) ) {
					continue; // Already imported on a previous run.
				}
				unset( $row['id'] );
				if ( $wpdb->insert( $new, $row ) ) {
					$map[ $old_id ] = (int) $wpdb->insert_id;
					$report['submissions']++;
				} else {
					$report['skipped']++;
				}
			}
			$progress['submissions'] = $since;
			// Persist per batch so a fatal mid-run never re-imports.
			update_option( self::MAP_OPTION, $map, false );
			update_option( self::PROGRESS_OPTION, $progress, false );
		} while ( $rows && count( $rows ) === self::BATCH );
	}

	/**
	 * Copy a child table (replies / notes) whose rows reference a submission
	 * via submission_id. Rows pointing at an unmapped submission are skipped.
	 *
	 * @param string $old_name Old table name without prefix.
	 * @param string $new      Fully-qualified new table name.
	 * @param string $key      Report/progress key.
	 * @param array  $map      Old => new submission ID map.
	 * @param array  $progress Per-table high-water marks (by reference).
	 * @param array  $report   Counts (by reference).
	 */
	protected static function import_children( $old_name, $new, $key, array $map, array &$progress, array &$report ) {
		global $wpdb;
		$old = self::old_table( $old_name );
		if ( ! self::table_exists( $old ) ) {
			return;
		}
		$since = (int) ( $progress[ $key ] ?? 0 );

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$old} WHERE id > %d ORDER BY id ASC LIMIT %d", $since, self::BATCH ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$since  = (int) $row['id'];
				$old_sub = (int) $row['submission_id'];
				if ( ! isset( $map[ $old_sub ] ) ) {
					$report['skipped']++;
					continue; // Orphan or unmapped parent.
				}
				unset( $row['id'] );
				$row['submission_id'] = (int) $map[ $old_sub ];
				if ( $wpdb->insert( $new, $row ) ) {
					$report[ $key ]++;
				} else {
					$report['skipped']++;
				}
			}
			$progress[ $key ] = $since;
			update_option( self::PROGRESS_OPTION, $progress, false );
		} while ( $rows && count( $rows ) === self::BATCH );
	}

	/**
	 * Copy attachment rows AND their on-disk files (for source=local rows)
	 * from the old protected directory into Formistic's per-submission dir.
	 *
	 * @param array $map      Old => new submission ID map.
	 * @param array $progress Per-table high-water marks (by reference).
	 * @param array $report   Counts (by reference).
	 */
	protected static function import_attachments( array $map, array &$progress, array &$report ) {
		global $wpdb;
		$old = self::old_table( 'WPISTIC_CF_attachments' );
		if ( ! self::table_exists( $old ) ) {
			return;
		}
		$new      = Wpistic_Formistic_Database::attachments_table();
		$since    = (int) ( $progress['attachments'] ?? 0 );
		$uploads  = wp_get_upload_dir();
		$old_root = trailingslashit( $uploads['basedir'] ) . self::OLD_UPLOAD_SUBDIR;

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$old} WHERE id > %d ORDER BY id ASC LIMIT %d", $since, self::BATCH ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$since   = (int) $row['id'];
				$old_sub = (int) $row['submission_id'];
				if ( ! isset( $map[ $old_sub ] ) ) {
					$report['skipped']++;
					continue;
				}
				$new_sub = (int) $map[ $old_sub ];
				unset( $row['id'] );
				$row['submission_id'] = $new_sub;
				if ( ! $wpdb->insert( $new, $row ) ) {
					$report['skipped']++;
					continue;
				}
				$report['attachments']++;

				// Copy the physical file for local attachments.
				if ( 'local' === ( $row['source'] ?? '' ) && '' !== (string) $row['stored_name'] && class_exists( 'Wpistic_Formistic_Attachments' ) ) {
					$src = $old_root . '/' . $old_sub . '/' . basename( (string) $row['stored_name'] );
					if ( is_file( $src ) ) {
						$dest_dir = Wpistic_Formistic_Attachments::submission_dir( $new_sub );
						$dest     = trailingslashit( $dest_dir ) . basename( (string) $row['stored_name'] );
						if ( ! file_exists( $dest ) && copy( $src, $dest ) ) {
							chmod( $dest, 0640 );
						}
					}
				}
			}
			$progress['attachments'] = $since;
			update_option( self::PROGRESS_OPTION, $progress, false );
		} while ( $rows && count( $rows ) === self::BATCH );
	}

	/**
	 * Copy impressions (no foreign key — straight batched copy).
	 *
	 * @param array $progress Per-table high-water marks (by reference).
	 * @param array $report   Counts (by reference).
	 */
	protected static function import_impressions( array &$progress, array &$report ) {
		global $wpdb;
		$old = self::old_table( 'WPISTIC_CF_impressions' );
		if ( ! self::table_exists( $old ) ) {
			return;
		}
		$new   = Wpistic_Formistic_Database::impressions_table();
		$since = (int) ( $progress['impressions'] ?? 0 );

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$old} WHERE id > %d ORDER BY id ASC LIMIT %d", $since, self::BATCH ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$since = (int) $row['id'];
				unset( $row['id'] );
				if ( $wpdb->insert( $new, $row ) ) {
					$report['impressions']++;
				} else {
					$report['skipped']++;
				}
			}
			$progress['impressions'] = $since;
			update_option( self::PROGRESS_OPTION, $progress, false );
		} while ( $rows && count( $rows ) === self::BATCH );
	}

	/**
	 * Copy newsletter subscribers. The new table has a UNIQUE key on email,
	 * so idempotency here is by email match: an address that already exists
	 * in the Formistic list is skipped (its newer state wins).
	 *
	 * @param array $progress Per-table high-water marks (by reference).
	 * @param array $report   Counts (by reference).
	 */
	protected static function import_subscribers( array &$progress, array &$report ) {
		global $wpdb;
		$old = self::old_table( 'WPISTIC_CF_subscribers' );
		if ( ! self::table_exists( $old ) || ! class_exists( 'Wpistic_Formistic_Newsletter' ) ) {
			return;
		}
		$new   = Wpistic_Formistic_Newsletter::table();
		$since = (int) ( $progress['subscribers'] ?? 0 );

		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$old} WHERE id > %d ORDER BY id ASC LIMIT %d", $since, self::BATCH ),
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$since = (int) $row['id'];
				$email = (string) $row['email'];
				if ( '' === $email ) {
					$report['skipped']++;
					continue;
				}
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT id FROM {$new} WHERE email = %s", $email )
				);
				if ( $exists ) {
					$report['skipped']++;
					continue;
				}
				unset( $row['id'] );
				if ( $wpdb->insert( $new, $row ) ) {
					$report['subscribers']++;
				} else {
					$report['skipped']++;
				}
			}
			$progress['subscribers'] = $since;
			update_option( self::PROGRESS_OPTION, $progress, false );
		} while ( $rows && count( $rows ) === self::BATCH );
	}
}
