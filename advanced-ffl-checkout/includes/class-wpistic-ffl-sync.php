<?php
/**
 * ATF FFL dealer database sync engine.
 *
 * Downloads the ATF's monthly FFL licensee list (CSV by state) and imports
 * all ~80 000 dealers into the dealers table in resumable 200-row chunks.
 *
 * ATF source (monthly ZIP, one CSV per state):
 *   https://www.atf.gov/firearms/listing-federal-firearms-licensees
 *
 * CSV column order (ATF standard format):
 *   0  Lic_Regn   1  Lic_Dist   2  Lic_Cnty   3  Lic_Type
 *   4  Lic_Xprdte 5  Lic_Seqn   6  License_Name
 *   7  Business_Name  8  Premise_Street  9  Premise_City
 *   10 Premise_State  11 Premise_Zip_Code
 *   12 Mail_Street    13 Mail_City  14 Mail_State  15 Mail_Zip_Code
 *   16 Voice_Phone
 *
 * License number is constructed as: Lic_Regn-Lic_Dist-Lic_Cnty-Lic_Type-Lic_Seqn
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Sync {

	const CHUNK_SIZE = 200;

	/**
	 * US state + territory two-letter codes used in ATF file naming.
	 */
	private static array $state_codes = [
		'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA',
		'HI','ID','IL','IN','IA','KS','KY','LA','ME','MD',
		'MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ',
		'NM','NY','NC','ND','OH','OK','OR','PA','RI','SC',
		'SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
		'DC','GU','MP','PR','VI',
	];

	// ── Public entry points ───────────────────────────────────────────────────

	/**
	 * Kick off a full ATF sync.
	 * Called after ZIP import completes, and monthly by WP Cron.
	 */
	public static function start_full_sync(): void {
		// Don't start a second sync if one is running
		if ( 'running' === get_option( 'wpistic_ffl_atf_sync_status', 'idle' ) ) {
			return;
		}

		$work_dir = self::work_dir();
		wp_mkdir_p( $work_dir );

		// Download the current ATF ZIP archive
		$zip_url  = self::current_atf_url();
		$zip_path = $work_dir . 'atf-ffl-list.zip';

		if ( ! $zip_url ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_no_url' );
			update_option( 'wpistic_ffl_atf_sync_message', 'Could not determine current ATF download URL.' );
			return;
		}

		$response = wp_remote_get( $zip_url, [
			'timeout'  => 120,
			'stream'   => true,
			'filename' => $zip_path,
		] );

		if ( is_wp_error( $response ) ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_download' );
			update_option( 'wpistic_ffl_atf_sync_message', $response->get_error_message() );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== (int) $code ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_download' );
			update_option( 'wpistic_ffl_atf_sync_message', "ATF download returned HTTP {$code}. URL: {$zip_url}" );
			return;
		}

		// Extract all CSVs
		if ( ! class_exists( 'ZipArchive' ) ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_no_zip' );
			update_option( 'wpistic_ffl_atf_sync_message', 'PHP ZipArchive extension required.' );
			return;
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_zip_open' );
			update_option( 'wpistic_ffl_atf_sync_message', 'Could not open ATF ZIP archive.' );
			return;
		}

		$zip->extractTo( $work_dir );
		$zip->close();
		@unlink( $zip_path ); // phpcs:ignore

		// Build ordered list of CSV files to process
		$csv_files = self::find_csv_files( $work_dir );
		if ( empty( $csv_files ) ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_no_csv' );
			update_option( 'wpistic_ffl_atf_sync_message', 'No CSV files found in ATF download.' );
			return;
		}

		// Store import state. We tag the run with a unique id so we can defer the
		// destructive "mark unseen dealers inactive" step until the import has
		// actually completed — a mid-run failure must NOT leave every dealer
		// inactive (which would empty the checkout selector).
		$run_id = uniqid( 'atfsync_', true );
		update_option( 'wpistic_ffl_atf_sync_status',      'running' );
		update_option( 'wpistic_ffl_atf_sync_message',     '' );
		update_option( 'wpistic_ffl_atf_sync_files',       wp_json_encode( $csv_files ) );
		update_option( 'wpistic_ffl_atf_sync_file_index',  0 );
		update_option( 'wpistic_ffl_atf_sync_file_offset', 0 );
		update_option( 'wpistic_ffl_atf_sync_total_done',  0 );
		update_option( 'wpistic_ffl_atf_sync_started_at',  current_time( 'mysql' ) );
		update_option( 'wpistic_ffl_atf_sync_run_id',      $run_id );

		// Schedule chunked cron if not already running
		if ( ! wp_next_scheduled( 'wpistic_ffl_process_atf_sync' ) ) {
			wp_schedule_event( time() + 5, 'every_minute', 'wpistic_ffl_process_atf_sync' );
		}
	}

	/**
	 * After a successful import, mark dealers that were NOT touched during the
	 * current run as inactive. Compares against last_synced to avoid affecting
	 * rows that were just upserted.
	 */
	public static function mark_unseen_dealers_inactive( string $run_started_at ): void {
		if ( ! $run_started_at ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'UPDATE ' . DB::table( 'dealers' ) . ' SET is_active = 0 WHERE last_synced < %s',
			$run_started_at
		) );
	}

	/**
	 * Process one chunk (CHUNK_SIZE rows). Called by WP Cron every minute.
	 */
	public static function process_chunk(): void {
		$status = get_option( 'wpistic_ffl_atf_sync_status', 'idle' );
		if ( 'running' !== $status ) {
			wp_clear_scheduled_hook( 'wpistic_ffl_process_atf_sync' );
			return;
		}

		$csv_files  = json_decode( (string) get_option( 'wpistic_ffl_atf_sync_files', '[]' ), true );
		$file_index = (int) get_option( 'wpistic_ffl_atf_sync_file_index', 0 );
		$offset     = (int) get_option( 'wpistic_ffl_atf_sync_file_offset', 0 );
		$total_done = (int) get_option( 'wpistic_ffl_atf_sync_total_done', 0 );

		if ( empty( $csv_files ) || $file_index >= count( $csv_files ) ) {
			self::finish_sync( $total_done );
			return;
		}

		$csv_path = $csv_files[ $file_index ];

		if ( ! file_exists( $csv_path ) ) {
			// Skip missing file, move to next
			update_option( 'wpistic_ffl_atf_sync_file_index',  $file_index + 1 );
			update_option( 'wpistic_ffl_atf_sync_file_offset', 0 );
			return;
		}

		$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $handle ) {
			update_option( 'wpistic_ffl_atf_sync_status', 'error_file_open' );
			return;
		}

		// Skip header row + previously processed lines
		$current_line = 0;
		while ( $current_line < $offset && ! feof( $handle ) ) {
			fgets( $handle );
			++$current_line;
		}

		$processed = 0;
		$imported  = 0;
		$rows      = [];

		global $wpdb;

		while ( $processed < self::CHUNK_SIZE && ! feof( $handle ) ) {
			$line = fgets( $handle );
			if ( false === $line ) {
				break;
			}

			// Skip blank lines and header rows
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, 'Lic_Regn' ) || 0 === strpos( $line, '"Lic_Regn"' ) ) {
				++$processed;
				continue;
			}

			// Parse CSV (ATF files may be quoted)
			$cols = str_getcsv( $line );
			if ( count( $cols ) < 17 ) {
				++$processed;
				continue;
			}

			$dealer = self::parse_atf_row( $cols );
			if ( ! $dealer ) {
				++$processed;
				continue;
			}

			$rows[]   = $dealer;
			++$processed;
			++$imported;

			// Flush every 50 rows to keep memory usage low
			if ( count( $rows ) >= 50 ) {
				self::upsert_dealers( $rows );
				$rows = [];
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Flush remaining rows
		if ( ! empty( $rows ) ) {
			self::upsert_dealers( $rows );
		}

		$new_offset  = $offset + $processed;
		$total_done += $imported;

		update_option( 'wpistic_ffl_atf_sync_total_done', $total_done );

		// If we read fewer than CHUNK_SIZE rows, this file is done
		if ( $processed < self::CHUNK_SIZE ) {
			update_option( 'wpistic_ffl_atf_sync_file_index',  $file_index + 1 );
			update_option( 'wpistic_ffl_atf_sync_file_offset', 0 );

			// Check if all files processed
			if ( $file_index + 1 >= count( $csv_files ) ) {
				self::finish_sync( $total_done );
			}
		} else {
			update_option( 'wpistic_ffl_atf_sync_file_offset', $new_offset );
		}
	}

	/**
	 * Get current sync status for admin display.
	 */
	public static function get_status(): array {
		global $wpdb;
		$dealer_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'dealers' ) ); // phpcs:ignore
		$active_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'dealers' ) . ' WHERE is_active = 1' ); // phpcs:ignore

		$csv_files  = json_decode( (string) get_option( 'wpistic_ffl_atf_sync_files', '[]' ), true ) ?: [];
		$file_index = (int) get_option( 'wpistic_ffl_atf_sync_file_index', 0 );
		$total_files = count( $csv_files );

		return [
			'status'       => get_option( 'wpistic_ffl_atf_sync_status', 'idle' ),
			'message'      => get_option( 'wpistic_ffl_atf_sync_message', '' ),
			'total_done'   => (int) get_option( 'wpistic_ffl_atf_sync_total_done', 0 ),
			'file_index'   => $file_index,
			'total_files'  => $total_files ?: 55,
			'percentage'   => $total_files > 0 ? min( 100, round( ( $file_index / $total_files ) * 100 ) ) : 0,
			'dealer_count' => $dealer_count,
			'active_count' => $active_count,
			'started_at'   => get_option( 'wpistic_ffl_atf_sync_started_at', '' ),
			'completed_at' => get_option( 'wpistic_ffl_atf_sync_completed_at', '' ),
		];
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Derive the ATF download URL for the current or recent month.
	 * ATF URL pattern: https://www.atf.gov/firearms/docs/data/[MMYYYY]ffllistcsv.zip
	 */
	private static function current_atf_url(): ?string {
		// Try current month then roll back up to 3 months to find latest published file
		for ( $i = 0; $i <= 3; $i++ ) {
			$ts  = strtotime( "-{$i} months" );
			$url = 'https://www.atf.gov/firearms/docs/data/' . date( 'mY', $ts ) . 'ffllistcsv.zip';

			// Quick HEAD request to verify the URL exists
			$resp = wp_remote_head( $url, [ 'timeout' => 15, 'redirection' => 3 ] );
			if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
				return $url;
			}
		}

		return null;
	}

	/**
	 * Find all CSV files in the extracted work directory.
	 * Returns sorted array of file paths.
	 */
	private static function find_csv_files( string $dir ): array {
		$files = [];

		if ( ! is_dir( $dir ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && strtolower( $file->getExtension() ) === 'csv' ) {
				$files[] = $file->getPathname();
			}
		}

		sort( $files );
		return $files;
	}

	/**
	 * Parse a single ATF CSV row into a dealer array.
	 * Returns null if the row is malformed or contains invalid data.
	 *
	 * @param array<string> $cols
	 * @return array<string,mixed>|null
	 */
	private static function parse_atf_row( array $cols ): ?array {
		// Build license number: Regn-Dist-Cnty-Type-Seqn
		$regn  = trim( $cols[0] ?? '' );
		$dist  = trim( $cols[1] ?? '' );
		$cnty  = trim( $cols[2] ?? '' );
		$type  = trim( $cols[3] ?? '' );
		$xprdt = trim( $cols[4] ?? '' );
		$seqn  = trim( $cols[5] ?? '' );

		if ( ! $regn || ! $type || ! $seqn ) {
			return null;
		}

		$license_number = "{$regn}-{$dist}-{$cnty}-{$type}-{$seqn}";

		// Parse expiry date (ATF format: MM/DD/YYYY or MMDDYYYY)
		$expiry = self::parse_atf_date( $xprdt );
		if ( ! $expiry ) {
			$expiry = date( 'Y-m-d', strtotime( '+1 year' ) );
		}

		$zip = preg_replace( '/[^0-9\-]/', '', trim( $cols[11] ?? '' ) );
		// Keep only 5-digit ZIP (strip ZIP+4)
		if ( strlen( $zip ) > 5 ) {
			$zip = substr( $zip, 0, 5 );
		}

		$state = strtoupper( trim( $cols[10] ?? '' ) );
		if ( ! in_array( $state, self::$state_codes, true ) ) {
			return null;
		}

		return [
			'license_number' => sanitize_text_field( $license_number ),
			'lic_type'       => sanitize_text_field( $type ),
			'lic_regn'       => sanitize_text_field( $regn ),
			'lic_dist'       => sanitize_text_field( $dist ),
			'lic_cnty'       => sanitize_text_field( $cnty ),
			'lic_xprdte'     => $expiry,
			'business_name'  => sanitize_text_field( trim( $cols[7] ?? '' ) ),
			'premise_street' => sanitize_text_field( trim( $cols[8] ?? '' ) ),
			'premise_city'   => sanitize_text_field( trim( $cols[9] ?? '' ) ),
			'premise_state'  => $state,
			'premise_zip'    => sanitize_text_field( $zip ),
			'mail_street'    => sanitize_text_field( trim( $cols[12] ?? '' ) ),
			'mail_city'      => sanitize_text_field( trim( $cols[13] ?? '' ) ),
			'mail_state'     => sanitize_text_field( strtoupper( trim( $cols[14] ?? '' ) ) ),
			'mail_zip'       => sanitize_text_field( trim( $cols[15] ?? '' ) ),
			'phone'          => sanitize_text_field( trim( $cols[16] ?? '' ) ),
			'is_active'      => 1,
			'last_synced'    => current_time( 'mysql' ),
		];
	}

	/**
	 * Parse ATF date formats (MM/DD/YYYY, MMDDYYYY, or YYYY-MM-DD) to MySQL date.
	 */
	private static function parse_atf_date( string $raw ): ?string {
		if ( ! $raw ) {
			return null;
		}

		// MM/DD/YYYY
		if ( preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m ) ) {
			return "{$m[3]}-{$m[1]}-{$m[2]}";
		}

		// MMDDYYYY
		if ( preg_match( '/^(\d{2})(\d{2})(\d{4})$/', $raw, $m ) ) {
			return "{$m[3]}-{$m[1]}-{$m[2]}";
		}

		// Already YYYY-MM-DD
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return $raw;
		}

		return null;
	}

	/**
	 * Bulk UPSERT a batch of dealer rows.
	 *
	 * After upsert, attempts to fill lat/lng from zip_coords table
	 * for any dealers that don't yet have coordinates.
	 *
	 * @param array<array<string,mixed>> $dealers
	 */
	private static function upsert_dealers( array $dealers ): void {
		global $wpdb;
		$table = DB::table( 'dealers' );

		$placeholders = [];
		$values       = [];

		foreach ( $dealers as $d ) {
			$placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s)';
			array_push(
				$values,
				$d['license_number'],
				$d['lic_type'],
				$d['lic_regn'],
				$d['lic_dist'],
				$d['lic_cnty'],
				$d['lic_xprdte'],
				$d['business_name'],
				$d['premise_street'],
				$d['premise_city'],
				$d['premise_state'],
				$d['premise_zip'],
				$d['mail_street'],
				$d['mail_city'],
				$d['mail_state'],
				$d['mail_zip'],
				$d['phone'],
				$d['is_active'],
				$d['last_synced']
			);
		}

		$sql = $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'INSERT INTO ' . $table . '
				(license_number,lic_type,lic_regn,lic_dist,lic_cnty,lic_xprdte,
				 business_name,premise_street,premise_city,premise_state,premise_zip,
				 mail_street,mail_city,mail_state,mail_zip,phone,is_active,last_synced)
			VALUES ' . implode( ',', $placeholders ) . '
			ON DUPLICATE KEY UPDATE
				lic_type       = VALUES(lic_type),
				lic_xprdte     = VALUES(lic_xprdte),
				business_name  = VALUES(business_name),
				premise_street = VALUES(premise_street),
				premise_city   = VALUES(premise_city),
				premise_state  = VALUES(premise_state),
				premise_zip    = VALUES(premise_zip),
				mail_street    = VALUES(mail_street),
				mail_city      = VALUES(mail_city),
				mail_state     = VALUES(mail_state),
				mail_zip       = VALUES(mail_zip),
				phone          = VALUES(phone),
				is_active      = VALUES(is_active),
				last_synced    = VALUES(last_synced)',
			...$values
		);

		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		// Fill lat/lng from ZIP centroids for newly inserted rows
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			"UPDATE {$table} d
			 INNER JOIN " . DB::table( 'zip_coords' ) . " z ON z.zip = LEFT(d.premise_zip, 5)
			 SET d.lat = z.lat, d.lng = z.lng
			 WHERE d.lat IS NULL OR d.lat = 0"
		);
	}

	/**
	 * Mark sync complete, clean up temp files, record timestamp.
	 */
	private static function finish_sync( int $total_done ): void {
		// Only collapse the active set once we know the import actually finished
		// AND produced rows. A zero-row run almost certainly means the upstream
		// CSV was empty/corrupt — keep the previous active set in that case.
		if ( $total_done > 0 ) {
			$run_started_at = (string) get_option( 'wpistic_ffl_atf_sync_started_at', '' );
			self::mark_unseen_dealers_inactive( $run_started_at );
		}

		update_option( 'wpistic_ffl_atf_sync_status',       'complete' );
		update_option( 'wpistic_ffl_atf_sync_total_done',   $total_done );
		update_option( 'wpistic_ffl_atf_sync_completed_at', current_time( 'mysql' ) );
		wp_clear_scheduled_hook( 'wpistic_ffl_process_atf_sync' );

		// Clean up extracted CSV files
		self::cleanup_work_dir();

		// Fire action so other modules can react
		do_action( 'wpistic_ffl_sync_complete', $total_done );
	}

	/**
	 * Remove all files in the work directory.
	 */
	private static function cleanup_work_dir(): void {
		$dir = self::work_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $files as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				@unlink( $file->getPathname() ); // phpcs:ignore
			}
		}
	}

	private static function work_dir(): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['basedir'] ) . 'wpistic-ffl/atf-sync/';
	}
}
