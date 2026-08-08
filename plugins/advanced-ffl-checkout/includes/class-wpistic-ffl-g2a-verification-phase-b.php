<?php
/**
 * G2A — FFL Compliance Verification Hub, Phase B.
 *
 * Extends the Phase A hub (class-wpistic-ffl-g2a-ffl-verification.php)
 * with the three pieces named in the v1.9.0 phase plan that don't require
 * any new external integration:
 *   1. Certified-copy expiration reminders at 60/30/7/0 days out, reusing
 *      the shared G2A_Scheduler daily cron.
 *   2. A WP dashboard widget summarizing what's missing/expiring, so staff
 *      see it without visiting the Verification Hub page.
 *   3. CSV + PDF export of the dealer verification directory, for an
 *      auditor or a manager who wants a point-in-time compliance snapshot.
 *
 * Same rule as everywhere else in this hub: nothing here is an approval —
 * it only surfaces what a human still needs to look at.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Verification_Phase_B {

	/** Days-before-expiration thresholds, ascending (most urgent first). */
	const THRESHOLDS = [ 0, 7, 30, 60 ];

	public function __construct() {
		add_action( 'wpistic_ffl_daily_portal_runner', [ __CLASS__, 'check_expirations' ], 25 );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'register_dashboard_widget' ] );
		add_action( 'admin_init', [ __CLASS__, 'maybe_handle_export' ] );
	}

	// ── Expiration reminders ─────────────────────────────────────────────

	/**
	 * For the latest active certified-copy document per dealer, fires the
	 * tightest un-sent threshold email and marks it (and every looser/larger
	 * threshold) sent in the same pass — a dealer 5 days from expiring
	 * shouldn't also get a stale "60 days left" email if the cron missed a
	 * run, and a threshold once crossed never fires again for that document.
	 */
	public static function check_expirations(): void {
		global $wpdb;
		$table = DB::table( 'ffl_verification_documents' );
		$docs  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT d1.* FROM {$table} d1
			 INNER JOIN (
			   SELECT dealer_id, MAX(created_at) AS max_created
			   FROM {$table}
			   WHERE document_type = 'certified_copy' AND status = 'active'
			   GROUP BY dealer_id
			 ) latest ON latest.dealer_id = d1.dealer_id AND latest.max_created = d1.created_at
			 WHERE d1.document_type = 'certified_copy' AND d1.status = 'active' AND d1.expires_at IS NOT NULL"
		);
		if ( ! $docs ) {
			return;
		}

		$today = current_time( 'Y-m-d' );
		foreach ( $docs as $doc ) {
			$days_left = (int) floor( ( strtotime( (string) $doc->expires_at ) - strtotime( $today ) ) / DAY_IN_SECONDS );

			$sent = [];
			foreach ( self::THRESHOLDS as $t ) {
				$col          = "reminder_{$t}_sent_at";
				$sent[ $t ] = ! empty( $doc->$col );
			}

			$threshold = self::pick_threshold( $days_left, $sent );
			if ( null === $threshold ) {
				continue;
			}

			self::send_reminder( $doc, $threshold, $days_left );

			$updates = [];
			foreach ( self::THRESHOLDS as $t2 ) {
				if ( $t2 >= $threshold ) {
					$updates[ "reminder_{$t2}_sent_at" ] = current_time( 'mysql' );
				}
			}
			$wpdb->update( $table, $updates, [ 'id' => $doc->id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Pure decision logic split out of check_expirations() for testability:
	 * given how many days remain and which thresholds already have a
	 * reminder sent, which threshold (if any) should fire now? Ascending
	 * order means the tightest/most urgent applicable threshold wins, so a
	 * document 5 days from expiring gets the "7 days" email, not "60 days."
	 *
	 * @param array<int,bool> $sent Threshold => already sent?
	 */
	public static function pick_threshold( int $days_left, array $sent ): ?int {
		foreach ( self::THRESHOLDS as $threshold ) {
			if ( $days_left <= $threshold && empty( $sent[ $threshold ] ) ) {
				return $threshold;
			}
		}
		return null;
	}

	private static function send_reminder( object $doc, int $threshold, int $days_left ): void {
		$settings = get_option( 'wpistic_ffl_settings', [] );
		if ( ! empty( $settings['disable_emails'] ) ) {
			return;
		}

		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT business_name, license_number FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', (int) $doc->dealer_id
		) );
		$dealer_name = $dealer->business_name ?? ( 'Dealer #' . (int) $doc->dealer_id );

		$admin_email = $settings['notification_email'] ?? get_option( 'admin_email' );
		$when        = $days_left < 0
			? sprintf( 'expired %d day(s) ago', abs( $days_left ) )
			: ( 0 === $days_left ? 'expires today' : sprintf( 'expires in %d day(s)', $days_left ) );

		$subject = sprintf( '[G2A] ⏳ Certified FFL copy %s — %s', $when, $dealer_name );
		$body    = '<p>The certified FFL license copy on file for the following dealer ' . esc_html( $when ) . '.</p>'
			. '<p><strong>' . esc_html( $dealer_name ) . '</strong><br>'
			. 'FFL #: ' . esc_html( $dealer->license_number ?? '' ) . '<br>'
			. 'Expiration on file: ' . esc_html( (string) $doc->expires_at ) . '</p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-verification&s=' . rawurlencode( (string) $dealer_name ) ) ) . '">Open in Verification Hub</a> and upload a fresh certified copy.</p>';

		wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => 0,
			'event_type'  => 'verification_expiration_reminder',
			'notes'       => 'Certified copy for dealer #' . (int) $doc->dealer_id . ' ' . $when . ' (' . $threshold . '-day threshold).',
			'actor'       => 'system',
			'actor_ip'    => '',
		] );
	}

	// ── Dashboard widget ──────────────────────────────────────────────────

	public static function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'wpistic_ffl_verification_widget',
			__( '🛡️ FFL Verification Status', 'advanced-ffl-checkout' ),
			[ __CLASS__, 'render_dashboard_widget' ]
		);
	}

	public static function render_dashboard_widget(): void {
		$summary = self::directory_summary();

		echo '<ul style="margin:0;">';
		printf(
			'<li>%s <strong>%d</strong></li>',
			esc_html__( 'Dealers with transfers:', 'advanced-ffl-checkout' ),
			(int) $summary['total']
		);
		printf(
			'<li style="color:%s;">%s <strong>%d</strong></li>',
			$summary['missing_doc'] > 0 ? '#DC2626' : '#16A34A',
			esc_html__( 'Missing certified copy:', 'advanced-ffl-checkout' ),
			(int) $summary['missing_doc']
		);
		printf(
			'<li style="color:%s;">%s <strong>%d</strong></li>',
			$summary['expiring_30'] > 0 ? '#D97706' : '#16A34A',
			esc_html__( 'Certified copy expiring ≤ 30 days:', 'advanced-ffl-checkout' ),
			(int) $summary['expiring_30']
		);
		printf(
			'<li style="color:%s;">%s <strong>%d</strong></li>',
			$summary['needs_review'] > 0 ? '#DC2626' : '#16A34A',
			esc_html__( 'Needs review:', 'advanced-ffl-checkout' ),
			(int) $summary['needs_review']
		);
		echo '</ul>';
		printf(
			'<p style="margin-top:10px;"><a href="%s" class="button button-small">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=wpistic-ffl-verification' ) ),
			esc_html__( 'Open Verification Hub', 'advanced-ffl-checkout' )
		);
	}

	/**
	 * @return array{total:int, missing_doc:int, expiring_30:int, needs_review:int}
	 */
	private static function directory_summary(): array {
		global $wpdb;
		$dealer_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT DISTINCT dealer_id FROM ' . DB::table( 'transfers' ) . ' WHERE dealer_id > 0'
		);

		$missing_doc  = 0;
		$expiring_30  = 0;
		$needs_review = 0;
		$today        = current_time( 'Y-m-d' );
		$soon         = gmdate( 'Y-m-d', strtotime( $today . ' +30 days' ) );

		foreach ( $dealer_ids as $dealer_id ) {
			$dealer_id = (int) $dealer_id;
			$doc       = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT expires_at FROM ' . DB::table( 'ffl_verification_documents' ) . "
				 WHERE dealer_id = %d AND document_type = 'certified_copy' AND status = 'active'
				 ORDER BY created_at DESC LIMIT 1",
				$dealer_id
			) );
			if ( ! $doc ) {
				++$missing_doc;
			} elseif ( $doc->expires_at && $doc->expires_at <= $soon ) {
				++$expiring_30;
			}
			if ( class_exists( __NAMESPACE__ . '\\G2A_Ffl_Verification' ) && 'needs_review' === G2A_Ffl_Verification::compute_status( $dealer_id )['status'] ) {
				++$needs_review;
			}
		}

		return [
			'total'        => count( $dealer_ids ),
			'missing_doc'  => $missing_doc,
			'expiring_30'  => $expiring_30,
			'needs_review' => $needs_review,
		];
	}

	// ── CSV / PDF export ──────────────────────────────────────────────────

	/**
	 * @return object[] One row per dealer this store has shipped to, with a
	 *                  computed compliance status attached.
	 */
	private static function directory_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT d.* FROM ' . DB::table( 'dealers' ) . ' d
			 INNER JOIN ( SELECT DISTINCT dealer_id FROM ' . DB::table( 'transfers' ) . ' WHERE dealer_id > 0 ) t ON t.dealer_id = d.id
			 ORDER BY d.business_name ASC'
		);
		foreach ( $rows as $d ) {
			$status         = class_exists( __NAMESPACE__ . '\\G2A_Ffl_Verification' ) ? G2A_Ffl_Verification::compute_status( (int) $d->id ) : [ 'status' => 'unknown', 'reasons' => [] ];
			$d->calc_status  = $status['status'];
			$d->calc_reasons = implode( '; ', $status['reasons'] );

			$doc         = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT expires_at FROM ' . DB::table( 'ffl_verification_documents' ) . "
				 WHERE dealer_id = %d AND document_type = 'certified_copy' AND status = 'active'
				 ORDER BY created_at DESC LIMIT 1",
				(int) $d->id
			) );
			$d->doc_expires = $doc->expires_at ?? '';

			$log         = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT result_status, created_at FROM ' . DB::table( 'ffl_verifications' ) . "
				 WHERE dealer_id = %d AND method = 'manual_ezcheck_log'
				 ORDER BY created_at DESC LIMIT 1",
				(int) $d->id
			) );
			$d->last_ezcheck_status = $log->result_status ?? '';
			$d->last_ezcheck_date   = $log->created_at ?? '';
		}
		return (array) $rows;
	}

	public static function maybe_handle_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['action'] ) || 'wpistic-ffl-verification' !== sanitize_key( $_GET['page'] ) ) {
			return;
		}
		$action = sanitize_key( $_GET['action'] );
		if ( ! in_array( $action, [ 'export_csv', 'export_pdf' ], true ) ) {
			return;
		}
		// phpcs:enable
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'advanced-ffl-checkout' ), 403 );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, 'wpistic_ffl_verification_export' ) ) {
			wp_die( esc_html__( 'Invalid or missing export nonce.', 'advanced-ffl-checkout' ), 400 );
		}

		if ( 'export_csv' === $action ) {
			self::export_csv();
		} else {
			self::export_pdf();
		}
	}

	private static function export_csv(): void {
		$rows = self::directory_rows();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ffl-verification-audit-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, [
			'Dealer', 'FFL License', 'Status', 'Reasons', 'Certified Copy Expires',
			'Last eZ Check Outcome', 'Last eZ Check Date', 'ATF Last Synced',
		] );
		foreach ( $rows as $r ) {
			fputcsv( $out, array_map( [ __CLASS__, 'csv_cell' ], [
				$r->business_name, $r->license_number, $r->calc_status, $r->calc_reasons,
				$r->doc_expires, $r->last_ezcheck_status, $r->last_ezcheck_date, $r->last_synced,
			] ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Neutralize spreadsheet formula injection — same pattern as G2A_Ad_Ledger::csv_cell(). */
	private static function csv_cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && false !== strpbrk( $value[0], "=+-@\t\r" ) ) {
			return "'" . $value;
		}
		return $value;
	}

	private static function export_pdf(): void {
		if ( ! class_exists( 'FPDF' ) ) {
			require_once WPISTIC_FFL_PATH . 'includes/lib/fpdf/fpdf.php';
		}
		$rows = self::directory_rows();

		$pdf = new \FPDF( 'L', 'mm', 'Letter' );
		$pdf->SetMargins( 12, 12, 12 );
		$pdf->SetAutoPageBreak( true, 12 );
		$pdf->AddPage();

		$pdf->SetFont( 'Helvetica', 'B', 14 );
		$pdf->Cell( 0, 8, self::pdf_text( 'FFL Verification Audit -- ' . gmdate( 'F j, Y' ) ), 0, 1 );
		$pdf->SetFont( 'Helvetica', '', 8 );
		$pdf->SetTextColor( 102, 102, 102 );
		$pdf->Cell( 0, 5, 'Dealer verification status snapshot. Reasons reflect the Verification Hub compute at export time -- not a legal determination.', 0, 1 );
		$pdf->SetTextColor( 17, 17, 17 );
		$pdf->Ln( 3 );

		$cols = [
			[ 'Dealer', 55 ], [ 'FFL #', 30 ], [ 'Status', 20 ], [ 'Cert. Copy Exp.', 22 ],
			[ 'Last eZ Check', 25 ], [ 'ATF Synced', 22 ], [ 'Reasons', 95 ],
		];

		$pdf->SetFont( 'Helvetica', 'B', 8 );
		$pdf->SetFillColor( 0xDC, 0xB4, 0x5F );
		foreach ( $cols as $c ) {
			$pdf->Cell( $c[1], 6, $c[0], 1, 0, 'C', true );
		}
		$pdf->Ln();

		$pdf->SetFont( 'Helvetica', '', 7 );
		foreach ( $rows as $r ) {
			$vals = [
				$r->business_name, $r->license_number, $r->calc_status, $r->doc_expires ?: '-',
				$r->last_ezcheck_status ?: '-', $r->last_synced ?: 'never', $r->calc_reasons ?: '-',
			];
			foreach ( $vals as $i => $v ) {
				$pdf->Cell( $cols[ $i ][1], 6, self::pdf_text( wp_html_excerpt( (string) $v, 90, '…' ) ), 1 );
			}
			$pdf->Ln();
		}

		nocache_headers();
		$pdf->Output( 'D', 'ffl-verification-audit-' . gmdate( 'Y-m-d' ) . '.pdf' );
		exit;
	}

	/** FPDF's core fonts are Latin-1 (cp1252), not UTF-8. */
	private static function pdf_text( string $text ): string {
		return iconv( 'UTF-8', 'CP1252//TRANSLIT//IGNORE', $text ) ?: $text;
	}
}
