<?php
/**
 * G2A — FFL Compliance Verification Hub (Phase A).
 *
 * Built after ATF withdrew the "Licensee eZ Check Verification for
 * Transfers" direct final rule (2026-07-06) — certified-copy collection
 * remains the required default, so this hub organizes that workflow
 * instead of assuming eZ Check can replace it.
 *
 * Scope is deliberately narrow to dealers this store has actually shipped
 * to (i.e. appear in `transfers`), not the full ~80k-row ATF universe the
 * `dealers` table holds — verification is a relationship with dealers we
 * use, not a audit of the entire national FFL list.
 *
 * Three genuinely-automatable pieces, and nothing more:
 *   1. Certified-copy upload + expiration tracking (private storage).
 *   2. Manual FFL eZ Check log entry — staff paste in what the ATF web
 *      tool told them. This is supporting evidence, never the sole
 *      approval, and there is deliberately no code path that calls
 *      fflezcheck.atf.gov automatically — ATF publishes no API for it.
 *   3. An "ATF sync validity" check against data this plugin already
 *      owns (the monthly-synced `dealers` row: is_active, lic_xprdte,
 *      last_synced staleness) — zero new external calls.
 *
 * Final compliance approval always stays a logged, manual staff action.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Ffl_Verification {

	const OPTION_KEY = 'wpistic_ffl_verification_settings';

	const POLICY_MODES = [ 'certified_copy_required', 'certified_copy_plus_ezcheck_log', 'custom' ];

	/**
	 * Allowed certified-copy uploads, in the extension-regex => mime shape
	 * `wp_check_filetype()` expects (NOT mime => extension).
	 */
	const ALLOWED_EXT_MIMES = [
		'pdf'          => 'application/pdf',
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
	];

	const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5MB

	/** After this many days without a fresh ATF sync, flag the dealer's data as stale. */
	const STALE_SYNC_DAYS = 45;

	const EZCHECK_OUTCOMES = [ 'matched', 'partial_match', 'address_mismatch', 'expired', 'not_found', 'manual_review_required' ];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 45 );
		add_action( 'wp_ajax_wpistic_ffl_verification_save_policy',    [ __CLASS__, 'ajax_save_policy' ] );
		add_action( 'wp_ajax_wpistic_ffl_verification_upload_document', [ __CLASS__, 'ajax_upload_document' ] );
		add_action( 'wp_ajax_wpistic_ffl_verification_log_ezcheck',     [ __CLASS__, 'ajax_log_ezcheck' ] );
		add_action( 'wp_ajax_wpistic_ffl_verification_run_check',       [ __CLASS__, 'ajax_run_check' ] );
		add_action( 'wp_ajax_wpistic_ffl_verification_view_document',   [ __CLASS__, 'ajax_view_document' ] );
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Verification Hub', 'advanced-ffl-checkout' ),
			__( '🛡️ Verification Hub', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-verification',
			[ $this, 'render_admin_page' ]
		);
	}

	// ── Settings ──────────────────────────────────────────────────────────

	public static function settings(): array {
		$defaults = [ 'policy_mode' => 'certified_copy_required' ];
		return wp_parse_args( (array) get_option( self::OPTION_KEY, [] ), $defaults );
	}

	public static function policy_mode(): string {
		return self::settings()['policy_mode'];
	}

	public static function ajax_save_policy(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$mode = sanitize_key( wp_unslash( $_POST['policy_mode'] ?? '' ) );
		// phpcs:enable
		if ( ! in_array( $mode, self::POLICY_MODES, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid policy mode.' ], 400 );
		}
		update_option( self::OPTION_KEY, [ 'policy_mode' => $mode ] );
		global $wpdb;
		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'verification_policy_changed',
			'notes'       => 'FFL verification policy mode set to ' . $mode,
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );
		wp_send_json_success( [ 'policy_mode' => $mode ] );
	}

	// ── Status computation ───────────────────────────────────────────────

	/**
	 * Combines the dealer's own ATF-synced record with certified-copy
	 * coverage and the latest manual verification log into one status.
	 * Never auto-approves — "approved" here means "nothing outstanding
	 * for staff to act on", not a legal sign-off.
	 */
	public static function compute_status( int $dealer_id ): array {
		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $dealer_id
		) );
		if ( ! $dealer ) {
			return [ 'status' => 'unknown_dealer', 'reasons' => [ 'Dealer not found.' ] ];
		}

		$reasons = [];
		$today   = current_time( 'Y-m-d' );

		if ( ! (int) $dealer->is_active ) {
			$reasons[] = 'Dealer is marked inactive in the ATF-synced record.';
		}
		if ( $dealer->lic_xprdte && $dealer->lic_xprdte < $today ) {
			$reasons[] = 'ATF-synced license expiration (' . $dealer->lic_xprdte . ') has passed.';
		}
		$stale = ! $dealer->last_synced || ( strtotime( $dealer->last_synced ) < strtotime( '-' . self::STALE_SYNC_DAYS . ' days' ) );
		if ( $stale ) {
			$reasons[] = 'ATF sync data for this dealer is more than ' . self::STALE_SYNC_DAYS . ' days old — consider a manual eZ Check before relying on it.';
		}

		$policy = self::policy_mode();
		$doc    = self::latest_active_document( $dealer_id );
		if ( 'custom' !== $policy && ! $doc ) {
			$reasons[] = 'No certified FFL license copy on file.';
		} elseif ( $doc && $doc->expires_at && $doc->expires_at < $today ) {
			$reasons[] = 'Certified copy on file expired ' . $doc->expires_at . '.';
		}

		$log = self::latest_verification( $dealer_id, 'manual_ezcheck_log' );
		if ( 'certified_copy_plus_ezcheck_log' === $policy && ! $log ) {
			$reasons[] = 'Policy requires a logged eZ Check result and none exists yet.';
		}
		if ( $log && 0 === (int) $log->address_match ) {
			$reasons[] = 'Most recent eZ Check log flagged an address mismatch.';
		}

		if ( empty( $reasons ) ) {
			return [ 'status' => 'clear', 'reasons' => [] ];
		}
		return [ 'status' => 'needs_review', 'reasons' => $reasons ];
	}

	private static function latest_active_document( int $dealer_id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT * FROM " . DB::table( 'ffl_verification_documents' ) . "
			 WHERE dealer_id = %d AND document_type = 'certified_copy' AND status = 'active'
			 ORDER BY created_at DESC LIMIT 1",
			$dealer_id
		) );
	}

	private static function latest_verification( int $dealer_id, string $method ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'ffl_verifications' ) . '
			 WHERE dealer_id = %d AND method = %s
			 ORDER BY created_at DESC LIMIT 1',
			$dealer_id, $method
		) );
	}

	// ── Private file storage ─────────────────────────────────────────────

	private static function private_dir(): string {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpistic-ffl-private/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Best-effort Apache guard. Nginx hosts must add their own deny rule
		// for this path — we don't rely on this alone, every read goes
		// through the capability-gated view endpoint below instead of a
		// direct URL.
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			@file_put_contents( $dir . '.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . 'index.php' ) ) {
			@file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}

	/** Verify the uploaded bytes actually are the claimed type, not just the filename. */
	private static function file_content_matches_mime( string $tmp_path, string $mime ): bool {
		if ( 'application/pdf' === $mime ) {
			$head = file_get_contents( $tmp_path, false, null, 0, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '%PDF-' === $head;
		}
		if ( in_array( $mime, [ 'image/jpeg', 'image/png' ], true ) ) {
			$info = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return is_array( $info ) && ( $info['mime'] ?? '' ) === $mime;
		}
		return false;
	}

	// ── Certified copy upload ────────────────────────────────────────────

	public static function ajax_upload_document(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$dealer_id  = (int) ( $_POST['dealer_id'] ?? 0 );
		$expires_at = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );
		// phpcs:enable

		if ( ! $dealer_id ) {
			wp_send_json_error( [ 'message' => 'Missing dealer.' ], 400 );
		}
		if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( [ 'message' => 'No file uploaded.' ], 400 );
		}
		if ( (int) $_FILES['file']['size'] > self::MAX_FILE_BYTES ) {
			wp_send_json_error( [ 'message' => 'File is larger than 5MB.' ], 400 );
		}
		if ( $expires_at && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expires_at ) ) {
			wp_send_json_error( [ 'message' => 'Invalid expiration date.' ], 400 );
		}

		$check = wp_check_filetype( $_FILES['file']['name'], self::ALLOWED_EXT_MIMES );
		$mime  = $check['type'] ?: '';
		$ext   = $check['ext'] ?: '';
		if ( ! $mime || ! $ext ) {
			wp_send_json_error( [ 'message' => 'Only PDF, JPG, or PNG files are accepted.' ], 400 );
		}
		// The extension check above only looks at the filename — confirm the
		// bytes actually match before this gets served back with an inline
		// Content-Type, so a mislabeled upload can't be used to smuggle
		// HTML/script content past the extension allowlist.
		if ( ! self::file_content_matches_mime( $_FILES['file']['tmp_name'], $mime ) ) {
			wp_send_json_error( [ 'message' => 'File content does not match a PDF, JPG, or PNG.' ], 400 );
		}

		$dealer_dir = self::private_dir() . $dealer_id . '/';
		wp_mkdir_p( $dealer_dir );
		$rel_name = wp_generate_password( 24, false, false ) . '.' . $ext;
		$dest     = $dealer_dir . $rel_name;

		if ( ! move_uploaded_file( $_FILES['file']['tmp_name'], $dest ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			wp_send_json_error( [ 'message' => 'Could not save the uploaded file.' ], 500 );
		}

		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->insert( DB::table( 'ffl_verification_documents' ), [ // phpcs:ignore WordPress.DB
			'dealer_id'       => $dealer_id,
			'document_type'   => 'certified_copy',
			'file_path'       => $dealer_id . '/' . $rel_name,
			'file_name'       => sanitize_file_name( $_FILES['file']['name'] ),
			'mime_type'       => $mime,
			'uploaded_by'     => $user->ID ?: null,
			'received_method' => 'staff_upload',
			'expires_at'      => $expires_at ?: null,
			'status'          => 'active',
		] );
		$doc_id = (int) $wpdb->insert_id;

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'verification_document_uploaded',
			'notes'       => 'Certified FFL copy uploaded for dealer #' . $dealer_id,
			'actor'       => $user->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success( [
			'doc_id' => $doc_id,
			'status' => self::compute_status( $dealer_id ),
		] );
	}

	public static function ajax_view_document(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Forbidden', 403 );
		}
		$doc_id = (int) ( $_GET['doc_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		global $wpdb;
		$doc = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'ffl_verification_documents' ) . ' WHERE id = %d', $doc_id
		) );
		if ( ! $doc ) {
			wp_die( 'Document not found.', 404 );
		}
		$path = self::private_dir() . $doc->file_path;
		if ( ! file_exists( $path ) ) {
			wp_die( 'File missing from storage.', 404 );
		}

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'verification_document_viewed',
			'notes'       => 'Certified copy #' . $doc_id . ' viewed for dealer #' . (int) $doc->dealer_id,
			'actor'       => wp_get_current_user()->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $doc->mime_type );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $doc->file_name ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// ── Manual eZ Check log ──────────────────────────────────────────────

	public static function ajax_log_ezcheck(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$dealer_id    = (int) ( $_POST['dealer_id'] ?? 0 );
		$ffl_checked  = sanitize_text_field( wp_unslash( $_POST['ffl_number_checked'] ?? '' ) );
		$legal_name   = sanitize_text_field( wp_unslash( $_POST['result_legal_name'] ?? '' ) );
		$trade_name   = sanitize_text_field( wp_unslash( $_POST['result_trade_name'] ?? '' ) );
		$address      = sanitize_textarea_field( wp_unslash( $_POST['result_premises_address'] ?? '' ) );
		$expiration   = sanitize_text_field( wp_unslash( $_POST['result_expiration_date'] ?? '' ) );
		$notes        = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		$outcome      = sanitize_key( wp_unslash( $_POST['outcome'] ?? '' ) );
		// phpcs:enable

		if ( ! $dealer_id ) {
			wp_send_json_error( [ 'message' => 'Missing dealer.' ], 400 );
		}
		if ( ! in_array( $outcome, self::EZCHECK_OUTCOMES, true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid outcome.' ], 400 );
		}
		if ( $expiration && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expiration ) ) {
			wp_send_json_error( [ 'message' => 'Invalid expiration date.' ], 400 );
		}

		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $dealer_id
		) );
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'Dealer not found.' ], 404 );
		}

		$address_match    = self::names_and_address_match( $dealer, $legal_name, $trade_name, $address );
		$expiration_valid = $expiration ? ( $expiration >= current_time( 'Y-m-d' ) ) : null;

		$user = wp_get_current_user();
		$wpdb->insert( DB::table( 'ffl_verifications' ), [ // phpcs:ignore WordPress.DB
			'dealer_id'               => $dealer_id,
			'method'                  => 'manual_ezcheck_log',
			'result_status'           => $outcome,
			'checked_ffl_number'      => $ffl_checked,
			'result_legal_name'       => $legal_name,
			'result_trade_name'       => $trade_name,
			'result_premises_address' => $address,
			'result_expiration_date'  => $expiration ?: null,
			'address_match'           => null === $address_match ? null : ( $address_match ? 1 : 0 ),
			'expiration_valid'        => null === $expiration_valid ? null : ( $expiration_valid ? 1 : 0 ),
			'notes'                   => $notes,
			'verified_by'             => $user->ID ?: null,
		] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'verification_ezcheck_logged',
			'notes'       => 'Manual eZ Check result (' . $outcome . ') logged for dealer #' . $dealer_id,
			'actor'       => $user->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success( [ 'status' => self::compute_status( $dealer_id ) ] );
	}

	/**
	 * Loose match: normalizes whitespace/case and checks the reported name
	 * is a substring match either way (staff may enter legal or trade name)
	 * and that premises address shares enough tokens to look like the same
	 * place. This is intentionally forgiving — false mismatches from ATF
	 * formatting quirks are exactly what the brief warns against, so this
	 * only flags a real outlier for staff review, it never auto-rejects.
	 */
	private static function names_and_address_match( object $dealer, string $legal_name, string $trade_name, string $address ): ?bool {
		if ( ! $legal_name && ! $trade_name && ! $address ) {
			return null; // Nothing reported to compare against.
		}
		$norm = static fn( string $s ): string => preg_replace( '/[^a-z0-9 ]/', '', strtolower( trim( $s ) ) );

		$name_ok = true;
		if ( $legal_name || $trade_name ) {
			$reported = $norm( $legal_name ) . ' ' . $norm( $trade_name );
			$stored   = $norm( (string) $dealer->business_name );
			$name_ok  = $stored && ( false !== strpos( $reported, $stored ) || false !== strpos( $stored, $norm( $legal_name ) ) || false !== strpos( $stored, $norm( $trade_name ) ) );
		}

		$addr_ok = true;
		if ( $address ) {
			$reported_zip = preg_match( '/\b(\d{5})\b/', $address, $m ) ? $m[1] : '';
			$addr_ok      = ! $reported_zip || ! $dealer->premise_zip || substr( (string) $dealer->premise_zip, 0, 5 ) === $reported_zip;
		}

		return $name_ok && $addr_ok;
	}

	// ── Automated ATF-sync validity check ────────────────────────────────

	public static function ajax_run_check(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$dealer_id = (int) ( $_POST['dealer_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $dealer_id ) {
			wp_send_json_error( [ 'message' => 'Missing dealer.' ], 400 );
		}

		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $dealer_id
		) );
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'Dealer not found.' ], 404 );
		}

		$today            = current_time( 'Y-m-d' );
		$expiration_valid = ! $dealer->lic_xprdte || $dealer->lic_xprdte >= $today;
		$active           = (bool) (int) $dealer->is_active;
		$result_status    = ! $active ? 'inactive' : ( ! $expiration_valid ? 'expired' : 'matched' );

		$user = wp_get_current_user();
		$wpdb->insert( DB::table( 'ffl_verifications' ), [ // phpcs:ignore WordPress.DB
			'dealer_id'              => $dealer_id,
			'method'                 => 'address_match',
			'result_status'          => $result_status,
			'checked_ffl_number'     => $dealer->license_number,
			'result_legal_name'      => $dealer->business_name,
			'result_premises_address' => trim( $dealer->premise_street . ', ' . $dealer->premise_city . ', ' . $dealer->premise_state . ' ' . $dealer->premise_zip ),
			'result_expiration_date' => $dealer->lic_xprdte ?: null,
			'expiration_valid'       => $expiration_valid ? 1 : 0,
			'notes'                  => 'Automated check against last ATF sync (' . ( $dealer->last_synced ?: 'never' ) . ').',
			'verified_by'            => $user->ID ?: null,
		] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'verification_check_run',
			'notes'       => 'ATF sync validity check (' . $result_status . ') run for dealer #' . $dealer_id,
			'actor'       => $user->user_login ?: 'staff',
			'actor_ip'    => Token::client_ip(),
		] );

		wp_send_json_success( [ 'status' => self::compute_status( $dealer_id ) ] );
	}

	// ── Admin page ────────────────────────────────────────────────────────

	public function render_admin_page(): void {
		global $wpdb;
		$nonce   = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$policy  = self::policy_mode();
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$paged   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page = 20;

		$where  = 'WHERE 1=1';
		$params = [];
		if ( $search ) {
			$where   .= ' AND (d.business_name LIKE %s OR d.license_number LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		// Only dealers this store has actually shipped to — not the full
		// ~80k-row ATF universe the `dealers` table holds.
		$sql_base = 'FROM ' . DB::table( 'dealers' ) . ' d
			INNER JOIN ( SELECT DISTINCT dealer_id FROM ' . DB::table( 'transfers' ) . ' WHERE dealer_id > 0 ) t ON t.dealer_id = d.id ' . $where;

		$total   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) ' . $sql_base, $params ) ); // phpcs:ignore WordPress.DB
		$offset  = ( $paged - 1 ) * $per_page;
		$dealers = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT d.* ' . $sql_base . ' ORDER BY d.business_name ASC LIMIT %d OFFSET %d',
			array_merge( $params, [ $per_page, $offset ] )
		) );

		$review_queue = [];
		foreach ( $dealers as $d ) {
			$s = self::compute_status( (int) $d->id );
			if ( 'needs_review' === $s['status'] ) {
				$review_queue[] = [ 'dealer' => $d, 'status' => $s ];
			}
		}

		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🛡️</span>
				<?php esc_html_e( 'FFL Compliance Verification Hub', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Certified-copy tracking and dealer verification for the FFLs this store actually ships to. ATF withdrew the eZ Check-as-alternative rule on 2026-07-06, so certified-copy collection stays the required baseline here — eZ Check results are logged as supporting evidence, not a replacement.', 'advanced-ffl-checkout' ); ?>
			</p>

			<!-- Policy mode -->
			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚙️ <?php esc_html_e( 'Verification Policy Mode', 'advanced-ffl-checkout' ); ?></h2>
				<form id="wpistic-ffl-policy-form" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<select name="policy_mode">
						<option value="certified_copy_required" <?php selected( $policy, 'certified_copy_required' ); ?>><?php esc_html_e( 'Certified Copy Required (default)', 'advanced-ffl-checkout' ); ?></option>
						<option value="certified_copy_plus_ezcheck_log" <?php selected( $policy, 'certified_copy_plus_ezcheck_log' ); ?>><?php esc_html_e( 'Certified Copy + eZ Check Log (recommended enhanced mode)', 'advanced-ffl-checkout' ); ?></option>
						<option value="custom" <?php selected( $policy, 'custom' ); ?>><?php esc_html_e( 'Custom Policy (admin-defined, no automatic document requirement)', 'advanced-ffl-checkout' ); ?></option>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Policy', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-policy-result" style="font-weight:600;"></span>
				</form>
				<p class="description" style="margin-top:10px;">
					<?php esc_html_e( 'There is no "eZ Check Alternative Allowed" mode here — ATF withdrew that rule. This setting will gain that option only if a future rule is finalized.', 'advanced-ffl-checkout' ); ?>
				</p>
			</div>

			<!-- Manager review queue -->
			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">🔎 <?php esc_html_e( 'Needs Review', 'advanced-ffl-checkout' ); ?></h2>
				<?php if ( empty( $review_queue ) ) : ?>
					<p style="margin-top:12px;color:#16A34A;font-weight:600;">✓ <?php esc_html_e( 'Nothing outstanding on this page of dealers.', 'advanced-ffl-checkout' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="margin-top:12px;">
						<thead><tr>
							<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Reasons', 'advanced-ffl-checkout' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $review_queue as $r ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $r['dealer']->business_name ); ?></strong><br><code><?php echo esc_html( $r['dealer']->license_number ); ?></code></td>
								<td><?php echo esc_html( implode( ' · ', $r['status']['reasons'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Dealer directory -->
			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📁 <?php esc_html_e( 'Dealer Verification Directory', 'advanced-ffl-checkout' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Only dealers this store has actually shipped a transfer to — not the full ATF database.', 'advanced-ffl-checkout' ); ?></p>

				<form method="get" style="margin:12px 0;">
					<input type="hidden" name="page" value="wpistic-ffl-verification">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search business name or license #', 'advanced-ffl-checkout' ); ?>" style="width:280px;">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'advanced-ffl-checkout' ); ?></button>
				</form>

				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Certified Copy', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Last eZ Check Log', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'advanced-ffl-checkout' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $dealers as $d ) :
						$status = self::compute_status( (int) $d->id );
						$doc    = self::latest_active_document( (int) $d->id );
						$log    = self::latest_verification( (int) $d->id, 'manual_ezcheck_log' );
						$badge  = 'clear' === $status['status'] ? [ '#16A34A', '✓ Clear' ] : [ '#DC2626', '⚠ Needs Review' ];
						?>
						<tr>
							<td><strong><?php echo esc_html( $d->business_name ); ?></strong><br><code><?php echo esc_html( $d->license_number ); ?></code></td>
							<td><span style="color:<?php echo esc_attr( $badge[0] ); ?>;font-weight:700;"><?php echo esc_html( $badge[1] ); ?></span></td>
							<td>
								<?php if ( $doc ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=wpistic_ffl_verification_view_document&doc_id=' . (int) $doc->id . '&nonce=' . $nonce ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'advanced-ffl-checkout' ); ?></a>
									<?php if ( $doc->expires_at ) : ?><br><small><?php echo esc_html( 'Expires ' . $doc->expires_at ); ?></small><?php endif; ?>
								<?php else : ?>
									<span style="color:#9ca3af;"><?php esc_html_e( 'None on file', 'advanced-ffl-checkout' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $log ) : ?>
									<?php echo esc_html( ucwords( str_replace( '_', ' ', $log->result_status ) ) ); ?><br><small><?php echo esc_html( $log->created_at ); ?></small>
								<?php else : ?>
									<span style="color:#9ca3af;"><?php esc_html_e( 'Never logged', 'advanced-ffl-checkout' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<button type="button" class="button button-small wpistic-ffl-open-upload" data-dealer="<?php echo (int) $d->id; ?>"><?php esc_html_e( 'Upload Copy', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button button-small wpistic-ffl-open-ezcheck" data-dealer="<?php echo (int) $d->id; ?>"><?php esc_html_e( 'Log eZ Check', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button button-small wpistic-ffl-run-check" data-dealer="<?php echo (int) $d->id; ?>"><?php esc_html_e( 'Run Check', 'advanced-ffl-checkout' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $dealers ) ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'No dealers with transfers yet.', 'advanced-ffl-checkout' ); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>

				<?php
				$total_pages = (int) ceil( $total / $per_page );
				if ( $total_pages > 1 ) :
					?>
					<div style="margin-top:14px;">
						<?php
						echo wp_kses_post( paginate_links( [
							'base'      => add_query_arg( 'paged', '%#%' ),
							'format'    => '',
							'current'   => $paged,
							'total'     => $total_pages,
							'add_args'  => array_filter( [ 's' => $search ] ),
						] ) );
						?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Upload modal -->
			<div id="wpistic-ffl-upload-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
				<div style="background:#fff;border-radius:10px;padding:24px;width:420px;max-width:92vw;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Upload Certified FFL Copy', 'advanced-ffl-checkout' ); ?></h3>
					<form id="wpistic-ffl-upload-form" enctype="multipart/form-data">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<input type="hidden" name="dealer_id" value="">
						<p><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required></p>
						<p>
							<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Expires (optional)', 'advanced-ffl-checkout' ); ?></label>
							<input type="date" name="expires_at">
						</p>
						<p style="display:flex;gap:8px;justify-content:flex-end;">
							<button type="button" class="button wpistic-ffl-modal-cancel"><?php esc_html_e( 'Cancel', 'advanced-ffl-checkout' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Upload', 'advanced-ffl-checkout' ); ?></button>
						</p>
						<p class="wpistic-ffl-modal-msg" style="font-weight:600;"></p>
					</form>
				</div>
			</div>

			<!-- eZ Check log modal -->
			<div id="wpistic-ffl-ezcheck-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
				<div style="background:#fff;border-radius:10px;padding:24px;width:480px;max-width:92vw;max-height:88vh;overflow-y:auto;">
					<h3 style="margin-top:0;"><?php esc_html_e( 'Log FFL eZ Check Result', 'advanced-ffl-checkout' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Enter what fflezcheck.atf.gov returned. This is logged as supporting evidence — it does not replace the certified copy requirement.', 'advanced-ffl-checkout' ); ?></p>
					<form id="wpistic-ffl-ezcheck-form">
						<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
						<input type="hidden" name="dealer_id" value="">
						<p><label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'FFL number checked', 'advanced-ffl-checkout' ); ?></label><input type="text" name="ffl_number_checked" style="width:100%;"></p>
						<p><label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Result: legal name', 'advanced-ffl-checkout' ); ?></label><input type="text" name="result_legal_name" style="width:100%;"></p>
						<p><label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Result: trade name (DBA)', 'advanced-ffl-checkout' ); ?></label><input type="text" name="result_trade_name" style="width:100%;"></p>
						<p><label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Result: premises address', 'advanced-ffl-checkout' ); ?></label><textarea name="result_premises_address" style="width:100%;" rows="2"></textarea></p>
						<p><label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Result: expiration date', 'advanced-ffl-checkout' ); ?></label><input type="date" name="result_expiration_date"></p>
						<p>
							<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Outcome', 'advanced-ffl-checkout' ); ?></label>
							<select name="outcome" style="width:100%;">
								<option value="matched"><?php esc_html_e( 'Matched', 'advanced-ffl-checkout' ); ?></option>
								<option value="partial_match"><?php esc_html_e( 'Partial Match', 'advanced-ffl-checkout' ); ?></option>
								<option value="address_mismatch"><?php esc_html_e( 'Address Mismatch', 'advanced-ffl-checkout' ); ?></option>
								<option value="expired"><?php esc_html_e( 'Expired', 'advanced-ffl-checkout' ); ?></option>
								<option value="not_found"><?php esc_html_e( 'Not Found', 'advanced-ffl-checkout' ); ?></option>
								<option value="manual_review_required"><?php esc_html_e( 'Manual Review Required', 'advanced-ffl-checkout' ); ?></option>
							</select>
						</p>
						<p><label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#666;"><?php esc_html_e( 'Notes', 'advanced-ffl-checkout' ); ?></label><textarea name="notes" style="width:100%;" rows="2"></textarea></p>
						<p style="display:flex;gap:8px;justify-content:flex-end;">
							<button type="button" class="button wpistic-ffl-modal-cancel"><?php esc_html_e( 'Cancel', 'advanced-ffl-checkout' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Log Entry', 'advanced-ffl-checkout' ); ?></button>
						</p>
						<p class="wpistic-ffl-modal-msg" style="font-weight:600;"></p>
					</form>
				</div>
			</div>
		</div>

		<script>
		(function(){
			var pageNonce = <?php echo wp_json_encode( $nonce ); ?>;
			function postJson(body) {
				if (!body.has('nonce')) { body.append('nonce', pageNonce); }
				return fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' }).then(r => r.json());
			}

			document.getElementById('wpistic-ffl-policy-form').addEventListener('submit', function(e){
				e.preventDefault();
				var fd = new FormData(e.target);
				fd.append('action', 'wpistic_ffl_verification_save_policy');
				var res = document.getElementById('wpistic-ffl-policy-result');
				res.textContent = 'Saving…'; res.style.color = '#666';
				postJson(fd).then(function(j){
					res.textContent = j.success ? '✓ Saved' : '✗ ' + (j.data && j.data.message || 'Failed');
					res.style.color = j.success ? '#16A34A' : '#DC2626';
				});
			});

			function openModal(id, dealerId) {
				var modal = document.getElementById(id);
				modal.querySelector('input[name="dealer_id"]').value = dealerId;
				modal.querySelector('.wpistic-ffl-modal-msg').textContent = '';
				modal.style.display = 'flex';
			}
			document.querySelectorAll('.wpistic-ffl-open-upload').forEach(function(btn){
				btn.addEventListener('click', function(){ openModal('wpistic-ffl-upload-modal', btn.dataset.dealer); });
			});
			document.querySelectorAll('.wpistic-ffl-open-ezcheck').forEach(function(btn){
				btn.addEventListener('click', function(){ openModal('wpistic-ffl-ezcheck-modal', btn.dataset.dealer); });
			});
			document.querySelectorAll('.wpistic-ffl-modal-cancel').forEach(function(btn){
				btn.addEventListener('click', function(){ btn.closest('[id$="-modal"]').style.display = 'none'; });
			});

			document.getElementById('wpistic-ffl-upload-form').addEventListener('submit', function(e){
				e.preventDefault();
				var form = e.target;
				var msg  = form.querySelector('.wpistic-ffl-modal-msg');
				var fd   = new FormData(form);
				fd.append('action', 'wpistic_ffl_verification_upload_document');
				msg.textContent = 'Uploading…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					if (j.success) { msg.style.color = '#16A34A'; msg.textContent = '✓ Uploaded'; setTimeout(function(){ location.reload(); }, 700); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + (j.data && j.data.message || 'Failed'); }
				});
			});

			document.getElementById('wpistic-ffl-ezcheck-form').addEventListener('submit', function(e){
				e.preventDefault();
				var form = e.target;
				var msg  = form.querySelector('.wpistic-ffl-modal-msg');
				var fd   = new FormData(form);
				fd.append('action', 'wpistic_ffl_verification_log_ezcheck');
				msg.textContent = 'Saving…'; msg.style.color = '#666';
				postJson(fd).then(function(j){
					if (j.success) { msg.style.color = '#16A34A'; msg.textContent = '✓ Logged'; setTimeout(function(){ location.reload(); }, 700); }
					else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + (j.data && j.data.message || 'Failed'); }
				});
			});

			document.querySelectorAll('.wpistic-ffl-run-check').forEach(function(btn){
				btn.addEventListener('click', function(){
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_verification_run_check');
					fd.append('dealer_id', btn.dataset.dealer);
					postJson(fd).then(function(j){
						btn.disabled = false;
						if (j.success) { location.reload(); }
						else { alert((j.data && j.data.message) || 'Check failed'); }
					});
				});
			});
		})();
		</script>
		<?php
	}
}
