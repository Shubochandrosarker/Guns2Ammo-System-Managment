<?php
/**
 * G2A — Federal Register regulatory watcher (Verification Hub Phase B).
 *
 * ATF's "Licensee eZ Check Verification for Transfers" direct final rule
 * was withdrawn on 2026-07-06 (see G2A_Ffl_Verification docblock), but a
 * similar rule could be proposed or finalized later. The Federal Register
 * API (federalregister.gov/developers/documentation/api/v1) is real,
 * public, and requires no key — unlike ATF's own FFL eZ Check tool, which
 * has no published API at all. This class polls it nightly for ATF
 * documents matching FFL-licensee-verification terms and alerts staff.
 *
 * This is alert-only. Nothing here ever changes
 * `wpistic_ffl_verification_settings.policy_mode` automatically — a human
 * reads the alert, decides whether the policy needs to change, and updates
 * it by hand on the Verification Hub page, same as every other compliance
 * decision this plugin surfaces.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Regulatory_Watch {

	const API_BASE    = 'https://www.federalregister.gov/api/v1/documents.json';
	const AGENCY_SLUG = 'bureau-of-alcohol-tobacco-firearms-and-explosives';
	const CRON_HOOK   = 'wpistic_ffl_regulatory_watch_check';

	/** Curated search terms — kept narrow so this stays signal, not noise. */
	const TERMS = [
		'firearms licensee verification',
		'eZ Check',
		'FFL transfer verification',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 46 );
		add_action( 'wp_ajax_wpistic_ffl_regwatch_run_now', [ __CLASS__, 'ajax_run_now' ] );
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_check' ] );

		G2A_Scheduler::recurring( DAY_IN_SECONDS, self::CRON_HOOK );
	}

	// ── The check itself ─────────────────────────────────────────────────

	/**
	 * Queries each term against the Federal Register API, scoped to ATF.
	 * New document_numbers (the API's own dedupe key) get one DB row and
	 * one admin email; documents already seen are silently skipped — the
	 * UNIQUE KEY on document_number is the source of truth for "already
	 * seen," not a timestamp cursor, since a single query naturally returns
	 * old matches again on every run.
	 */
	public static function run_check(): void {
		foreach ( self::TERMS as $term ) {
			self::query_term( $term );
		}
		update_option( 'wpistic_ffl_regwatch_last_run', current_time( 'mysql' ), false );
	}

	private static function query_term( string $term ): void {
		$url = add_query_arg(
			[
				'conditions' => [
					'term'     => $term,
					'agencies' => [ self::AGENCY_SLUG ],
				],
				'order'      => 'newest',
				'per_page'   => 20,
			],
			self::API_BASE
		);

		$resp = wp_remote_get( $url, [ 'timeout' => 15 ] );
		if ( is_wp_error( $resp ) ) {
			return;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			return;
		}
		$payload = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $payload ) || empty( $payload['results'] ) || ! is_array( $payload['results'] ) ) {
			return;
		}

		global $wpdb;
		foreach ( $payload['results'] as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}
			$doc_number = sanitize_text_field( (string) ( $doc['document_number'] ?? '' ) );
			if ( '' === $doc_number ) {
				continue;
			}
			$exists = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT id FROM ' . DB::table( 'regulatory_watch' ) . ' WHERE document_number = %s',
				$doc_number
			) );
			if ( $exists ) {
				continue;
			}

			$agency_names = [];
			foreach ( (array) ( $doc['agencies'] ?? [] ) as $agency ) {
				if ( is_array( $agency ) && ! empty( $agency['name'] ) ) {
					$agency_names[] = sanitize_text_field( (string) $agency['name'] );
				}
			}

			$pub_date = sanitize_text_field( (string) ( $doc['publication_date'] ?? '' ) );

			$wpdb->insert( DB::table( 'regulatory_watch' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'document_number'  => $doc_number,
				'title'            => sanitize_text_field( (string) ( $doc['title'] ?? '' ) ),
				'agency'           => implode( ', ', $agency_names ),
				'document_type'    => sanitize_text_field( (string) ( $doc['type'] ?? '' ) ),
				'matched_term'     => $term,
				'publication_date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $pub_date ) ? $pub_date : null,
				'html_url'         => esc_url_raw( (string) ( $doc['html_url'] ?? '' ) ),
			] );

			$id = (int) $wpdb->insert_id;
			if ( $id ) {
				self::notify_admin( $id );
			}
		}
	}

	private static function notify_admin( int $id ): void {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'regulatory_watch' ) . ' WHERE id = %d', $id
		) );
		if ( ! $row ) {
			return;
		}

		$settings = get_option( 'wpistic_ffl_settings', [] );
		if ( ! empty( $settings['disable_emails'] ) ) {
			return;
		}
		$admin_email = $settings['notification_email'] ?? get_option( 'admin_email' );

		$subject = '[G2A] 📅 New Federal Register document matched FFL/eZ Check watch';
		$body    = '<p>The nightly regulatory watch found a new ATF Federal Register document matching "<strong>' . esc_html( $row->matched_term ) . '</strong>".</p>'
			. '<p><strong>' . esc_html( $row->title ) . '</strong><br>'
			. 'Type: ' . esc_html( $row->document_type ) . '<br>'
			. 'Published: ' . esc_html( (string) $row->publication_date ) . '<br>'
			. 'Document #: ' . esc_html( $row->document_number ) . '</p>'
			. '<p><a href="' . esc_url( $row->html_url ) . '">Read on federalregister.gov</a></p>'
			. '<p>This is informational only — nothing has been changed in the Verification Hub policy setting. Review the document and update the policy mode by hand if it changes what\'s legally required.</p>';

		wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			DB::table( 'regulatory_watch' ),
			[ 'notified_at' => current_time( 'mysql' ) ],
			[ 'id' => $id ]
		);

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => 0,
			'event_type'  => 'regulatory_watch_match',
			'notes'       => 'Federal Register document #' . $row->document_number . ' matched regulatory watch term "' . $row->matched_term . '".',
			'actor'       => 'system',
			'actor_ip'    => '',
		] );
	}

	// ── Manual "check now" ───────────────────────────────────────────────

	public static function ajax_run_now(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		self::run_check();
		wp_send_json_success();
	}

	// ── Admin page ───────────────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Regulatory Watch', 'advanced-ffl-checkout' ),
			__( '📅 Regulatory Watch', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-regulatory-watch',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce   = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$last_run = get_option( 'wpistic_ffl_regwatch_last_run', '' );
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'regulatory_watch' ) . ' ORDER BY created_at DESC LIMIT 100'
		);
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">📅</span>
				<?php esc_html_e( 'ATF Regulatory Watch', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'Nightly sweep of the Federal Register API for ATF documents matching FFL-licensee-verification terms (e.g. a revived "eZ Check" rule). Alert-only — this never changes the Verification Hub policy setting on its own.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:20px;">
				<p>
					<strong><?php esc_html_e( 'Last run:', 'advanced-ffl-checkout' ); ?></strong>
					<?php echo esc_html( $last_run ?: __( 'Never', 'advanced-ffl-checkout' ) ); ?>
					&nbsp;·&nbsp;
					<button type="button" class="button" id="wpistic-ffl-regwatch-run"><?php esc_html_e( 'Check Now', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-regwatch-msg" style="font-weight:600;"></span>
				</p>
			</div>

			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;"><?php esc_html_e( 'Matched Documents', 'advanced-ffl-checkout' ); ?></h2>
				<?php if ( empty( $rows ) ) : ?>
					<p style="margin-top:12px;color:#666;"><?php esc_html_e( 'No matches yet.', 'advanced-ffl-checkout' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="margin-top:12px;">
						<thead><tr>
							<th><?php esc_html_e( 'Title', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Type', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Published', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Matched term', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Notified', 'advanced-ffl-checkout' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $rows as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( $r->html_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $r->title ); ?></a></td>
								<td><?php echo esc_html( $r->document_type ); ?></td>
								<td><?php echo esc_html( (string) $r->publication_date ); ?></td>
								<td><?php echo esc_html( $r->matched_term ); ?></td>
								<td><?php echo esc_html( $r->notified_at ?: '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function(){
			document.getElementById('wpistic-ffl-regwatch-run').addEventListener('click', function(){
				var btn = this, msg = document.getElementById('wpistic-ffl-regwatch-msg');
				btn.disabled = true;
				msg.textContent = 'Checking…'; msg.style.color = '#666';
				var fd = new FormData();
				fd.append('action', 'wpistic_ffl_regwatch_run_now');
				fd.append('nonce', <?php echo wp_json_encode( $nonce ); ?>);
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.then(function(r){ return r.json(); })
					.then(function(j){
						btn.disabled = false;
						if (j.success) { location.reload(); }
						else { msg.style.color = '#DC2626'; msg.textContent = '✗ ' + ((j.data && j.data.message) || 'Failed'); }
					});
			});
		})();
		</script>
		<?php
	}
}
