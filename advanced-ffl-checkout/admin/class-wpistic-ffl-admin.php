<?php
/**
 * WP Admin panel controller.
 *
 * Registers the top-level admin menu under the G2A brass shield icon,
 * handles AJAX actions, and dispatches to view files.
 *
 * Menu structure:
 *   ├─ Advanced FFL (top-level, purple W icon)
 *   │   ├─ Dashboard
 *   │   ├─ Transfers
 *   │   ├─ Dealers
 *   │   └─ Settings
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Admin {

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_notices',         [ $this, 'maybe_show_import_notice' ] );

		// AJAX handlers
		add_action( 'wp_ajax_wpistic_ffl_start_zip_import',    [ $this, 'ajax_start_zip_import' ] );
		add_action( 'wp_ajax_wpistic_ffl_start_atf_sync',      [ $this, 'ajax_start_atf_sync' ] );
		add_action( 'wp_ajax_wpistic_ffl_get_sync_progress',   [ $this, 'ajax_get_sync_progress' ] );
		add_action( 'wp_ajax_wpistic_ffl_save_settings',       [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_import_csv_chunk',    [ $this, 'ajax_import_csv_chunk' ] );
		add_action( 'wp_ajax_wpistic_ffl_import_csv_reset',    [ $this, 'ajax_import_csv_reset' ] );
		add_action( 'wp_ajax_wpistic_ffl_get_dealer_count',    [ $this, 'ajax_get_dealer_count' ] );

		// v1.1.1 — Recommended dealer toggle + admin CSV export
		add_action( 'wp_ajax_wpistic_ffl_toggle_preferred',    [ $this, 'ajax_toggle_preferred' ] );
		add_action( 'wp_ajax_wpistic_ffl_update_dealer_email', [ $this, 'ajax_update_dealer_email' ] );
		add_action( 'wp_ajax_wpistic_ffl_geocode_dealer',      [ $this, 'ajax_geocode_dealer' ] );
		add_action( 'admin_init',                              [ $this, 'maybe_handle_export' ] );

		// v1.1.3 — Diagnostics actions
		add_action( 'wp_ajax_wpistic_ffl_clear_mail_log',      [ $this, 'ajax_clear_mail_log' ] );
		add_action( 'wp_ajax_wpistic_ffl_reset_defaults',      [ $this, 'ajax_reset_defaults' ] );
	}

	// ── Menu registration ─────────────────────────────────────────────────────

	public function register_menus(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( self::menu_icon_svg() );

		add_menu_page(
			__( 'Advanced FFL', 'advanced-ffl-checkout' ),
			__( 'Advanced FFL', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl',
			[ $this, 'render_dashboard' ],
			$icon,
			58
		);

		add_submenu_page(
			'wpistic-ffl',
			__( 'FFL Dashboard', 'advanced-ffl-checkout' ),
			__( 'Dashboard', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl',
			[ $this, 'render_dashboard' ]
		);

		add_submenu_page(
			'wpistic-ffl',
			__( 'FFL Transfers', 'advanced-ffl-checkout' ),
			__( 'Transfers', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-transfers',
			[ $this, 'render_transfers' ]
		);

		add_submenu_page(
			'wpistic-ffl',
			__( 'FFL Dealers', 'advanced-ffl-checkout' ),
			__( 'Dealers', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-dealers',
			[ $this, 'render_dealers' ]
		);

		add_submenu_page(
			'wpistic-ffl',
			__( 'Dealer Portal', 'advanced-ffl-checkout' ),
			__( 'Dealer Portal', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-portal',
			[ $this, 'render_portal_dashboard' ]
		);

		add_submenu_page(
			'wpistic-ffl',
			__( 'FFL Diagnostics', 'advanced-ffl-checkout' ),
			__( '🩺 Diagnostics', 'advanced-ffl-checkout' ),
			'manage_options',
			'wpistic-ffl-diagnostics',
			[ $this, 'render_diagnostics' ]
		);

		add_submenu_page(
			'wpistic-ffl',
			__( 'FFL Settings', 'advanced-ffl-checkout' ),
			__( 'Settings', 'advanced-ffl-checkout' ),
			'manage_options',
			'wpistic-ffl-settings',
			[ $this, 'render_settings' ]
		);
	}

	// ── Asset loading ─────────────────────────────────────────────────────────

	public function enqueue_admin_assets( string $hook ): void {
		$our_pages = [
			'toplevel_page_wpistic-ffl',
			'advanced-ffl_page_wpistic-ffl-transfers',
			'advanced-ffl_page_wpistic-ffl-dealers',
			'advanced-ffl_page_wpistic-ffl-portal',
			'advanced-ffl_page_wpistic-ffl-diagnostics',
			'advanced-ffl_page_wpistic-ffl-settings',
		];

		if ( ! in_array( $hook, $our_pages, true ) ) {
			return;
		}

		wp_enqueue_style(
			'wpistic-ffl-admin',
			WPISTIC_FFL_URL . 'assets/css/admin.css',
			[],
			WPISTIC_FFL_VERSION
		);

		wp_enqueue_script(
			'wpistic-ffl-admin',
			WPISTIC_FFL_URL . 'assets/js/admin.js',
			[],
			WPISTIC_FFL_VERSION,
			true
		);

		wp_localize_script( 'wpistic-ffl-admin', 'wpistic_ffl_admin', [
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'wpistic_ffl_admin_nonce' ),
			'api_base'   => rest_url( WPISTIC_FFL_REST_NS ),
			'rest_nonce' => wp_create_nonce( 'wp_rest' ),
			'i18n'       => [
				'confirm_sync'   => __( 'Start a full ATF database sync? This will download ~80k dealers in the background.', 'advanced-ffl-checkout' ),
				'confirm_zip'    => __( 'Start ZIP code import? This will download ~43k ZIP centroids from GeoNames.', 'advanced-ffl-checkout' ),
				'sync_started'   => __( 'Sync started! Progress will update every 30 seconds.', 'advanced-ffl-checkout' ),
				'saved'          => __( 'Settings saved.', 'advanced-ffl-checkout' ),
			],
		] );
	}

	// ── View renderers ────────────────────────────────────────────────────────

	public function render_dashboard(): void {
		include WPISTIC_FFL_PATH . 'admin/views/dashboard.php';
	}

	public function render_transfers(): void {
		include WPISTIC_FFL_PATH . 'admin/views/transfers.php';
	}

	public function render_dealers(): void {
		include WPISTIC_FFL_PATH . 'admin/views/dealers.php';
	}

	public function render_settings(): void {
		// Settings is now a tabbed page covering: Portal, Theme, Emails, Compliance, License.
		// Falls back to legacy settings view if the new template is missing.
		$tabbed = WPISTIC_FFL_PATH . 'admin/views/settings-tabs.php';
		$legacy = WPISTIC_FFL_PATH . 'admin/views/settings.php';
		if ( file_exists( $tabbed ) ) {
			include $tabbed;
		} elseif ( file_exists( $legacy ) ) {
			include $legacy;
		}
	}

	public function render_portal_dashboard(): void {
		$summary  = \WpisticFFL\Analytics::get_summary( '30d' );
		$slowest  = \WpisticFFL\Analytics::dealer_performance( '90d', 10, 'slowest' );
		$pending  = $summary['pending_tokens'];
		$portal   = get_option( 'wpistic_ffl_portal_settings', [] );
		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<?php esc_html_e( 'Dealer Confirmation Portal', 'advanced-ffl-checkout' ); ?>
				<span style="font-size:12px;background:#e0e7ff;color:#4338ca;padding:3px 8px;border-radius:999px;font-weight:600;">v<?php echo esc_html( WPISTIC_FFL_VERSION ); ?></span>
				<?php if ( \WpisticFFL\License::can( 'extended_analytics' ) ) : ?>
					<span style="font-size:11px;background:#fef3c7;color:#92400e;padding:3px 8px;border-radius:999px;font-weight:600;">PRO UNLOCKED</span>
				<?php endif; ?>
			</h1>
			<p class="description" style="max-width:720px;">
				<?php esc_html_e( 'One-click magic-link portal lets receiving FFL dealers confirm shipments with no login. All confirmations create an audit trail (IP, user agent, timestamp) for ATF compliance.', 'advanced-ffl-checkout' ); ?>
			</p>

			<?php if ( empty( $portal['enabled'] ) ) : ?>
				<div class="notice notice-warning" style="margin-top:16px;">
					<p><strong><?php esc_html_e( 'Portal is disabled.', 'advanced-ffl-checkout' ); ?></strong>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-settings#portal' ) ); ?>"><?php esc_html_e( 'Enable it in Settings →', 'advanced-ffl-checkout' ); ?></a>
					</p>
				</div>
			<?php endif; ?>

			<!-- KPI cards -->
			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:24px;">
				<?php
				$kpis = [
					[ 'Tokens Issued',     $summary['totals']['token_issued'],      '#3b82f6' ],
					[ 'Emails Sent',       $summary['totals']['email_sent'],        '#0ea5e9' ],
					[ 'Confirmations',     $summary['totals']['confirmed'],         '#16a34a' ],
					[ 'Pending Tokens',    $pending,                                '#f59e0b' ],
					[ 'Confirm Rate',      $summary['confirmation_rate'] . '%',     '#8b5cf6' ],
					[ 'Avg Confirm Time',  $summary['avg_confirm_hours'] . ' hrs',  '#ec4899' ],
				];
				foreach ( $kpis as $kpi ) :
				?>
					<div style="background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 20px;">
						<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;font-weight:600;"><?php echo esc_html( $kpi[0] ); ?></div>
						<div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( $kpi[2] ); ?>;margin-top:6px;line-height:1.1;"><?php echo esc_html( (string) $kpi[1] ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Slowest dealers table -->
			<h2 style="margin-top:36px;"><?php esc_html_e( 'Slowest Confirming Dealers (90 days)', 'advanced-ffl-checkout' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Dealers with the longest average time between shipment and confirmation. Consider reaching out for the chronically slow ones.', 'advanced-ffl-checkout' ); ?></p>

			<table class="wp-list-table widefat fixed striped" style="margin-top:12px;">
				<thead><tr>
					<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Location', 'advanced-ffl-checkout' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Sent', 'advanced-ffl-checkout' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Confirmed', 'advanced-ffl-checkout' ); ?></th>
					<th style="width:80px;"><?php esc_html_e( 'Issues', 'advanced-ffl-checkout' ); ?></th>
					<th style="width:90px;"><?php esc_html_e( 'Rate', 'advanced-ffl-checkout' ); ?></th>
					<th style="width:120px;"><?php esc_html_e( 'Avg Time', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $slowest ) ) : ?>
					<tr><td colspan="7" style="text-align:center;padding:24px;color:#6b7280;"><?php esc_html_e( 'No dealer activity yet. Data populates as dealers receive and confirm shipments.', 'advanced-ffl-checkout' ); ?></td></tr>
				<?php else : foreach ( $slowest as $d ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $d['dealer_name'] ); ?></strong></td>
						<td><?php echo esc_html( trim( $d['city'] . ', ' . $d['state'] ) ); ?></td>
						<td><?php echo esc_html( (string) $d['sent'] ); ?></td>
						<td><?php echo esc_html( (string) $d['confirmed'] ); ?></td>
						<td><?php echo esc_html( (string) $d['issues'] ); ?></td>
						<td><?php echo esc_html( $d['rate'] . '%' ); ?></td>
						<td>
							<?php if ( $d['avg_hours'] > 168 ) : ?>
								<span style="color:#dc2626;font-weight:600;"><?php echo esc_html( $d['avg_hours'] . ' hrs' ); ?></span>
							<?php else : ?>
								<?php echo esc_html( $d['avg_hours'] . ' hrs' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>

			<p style="margin-top:24px;">
				<a href="<?php echo esc_url( rest_url( WPISTIC_FFL_REST_NS . '/analytics/portal' ) ); ?>" class="button" target="_blank">
					<?php esc_html_e( 'View Raw API Data', 'advanced-ffl-checkout' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-settings#portal' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Portal Settings', 'advanced-ffl-checkout' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// ── Admin notices ─────────────────────────────────────────────────────────

	public function maybe_show_import_notice(): void {
		$zip_status = get_option( 'wpistic_ffl_zip_import_status', 'idle' );
		$atf_status = get_option( 'wpistic_ffl_atf_sync_status', 'idle' );

//		if ( in_array( $zip_status, [ 'pending', 'running' ], true ) ) {
//			echo '<div class="notice notice-info is-dismissible">';
//			echo '<p><strong>' . esc_html__( 'Advanced FFL:', 'advanced-ffl-checkout' ) . '</strong> ';
//			echo esc_html__( 'ZIP code import is in progress. The FFL dealer search will be available after import completes.', 'advanced-ffl-checkout' );
//			echo '</p></div>';
//		}

//		if ( 'running' === $atf_status ) {
//			echo '<div class="notice notice-info is-dismissible">';
//			echo '<p><strong>' . esc_html__( 'Advanced FFL:', 'advanced-ffl-checkout' ) . '</strong> ';
//			echo esc_html__( 'ATF dealer database sync is in progress. This runs in the background — dealers will appear as they are imported.', 'advanced-ffl-checkout' );
//			echo '</p></div>';
//		}



//		if ( 'error_no_url' === $atf_status || 'error_download' === $atf_status ) {
//			$msg = get_option( 'wpistic_ffl_atf_sync_message', '' );
//			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Advanced FFL Sync Error:', 'advanced-ffl-checkout' ) . '</strong> ';
//			echo esc_html( $msg );
//			echo '</p></div>';
//		}


	}

	
	
	
	// ── AJAX handlers ─────────────────────────────────────────────────────────

	public function ajax_start_zip_import(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		$result = Zip_Import::start();
		wp_send_json( $result );
	}

	public function ajax_start_atf_sync(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		Sync::start_full_sync();
		wp_send_json_success( 'ATF sync initiated.' );
	}

	public function ajax_get_sync_progress(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		wp_send_json_success( [
			'zip' => Zip_Import::get_status(),
			'atf' => Sync::get_status(),
		] );
	}

	public function ajax_save_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$settings = [
			'default_radius'        => absint( $_POST['default_radius'] ?? 50 ),
			'notification_email'    => sanitize_email( $_POST['notification_email'] ?? '' ),
			'from_name'             => sanitize_text_field( $_POST['from_name'] ?? get_bloginfo( 'name' ) ),
			'customer_emails'       => ! empty( $_POST['customer_emails'] ),
			'admin_emails'          => ! empty( $_POST['admin_emails'] ),
			'disable_emails'        => ! empty( $_POST['disable_emails'] ),
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
			'google_maps_key'       => sanitize_text_field( $_POST['google_maps_key'] ?? '' ),
		];
		// phpcs:enable

		update_option( 'wpistic_ffl_settings', $settings );
		wp_send_json_success( 'Settings saved.' );
	}

	/**
	 * Import a chunk of ATF CSV rows sent from the browser via AJAX.
	 *
	 * Expects POST fields:
	 *   rows[]   — array of raw CSV line strings (one element per dealer row)
	 *   total    — total rows in the file (for progress calculation)
	 *   imported — running count before this chunk
	 */
	public function ajax_import_csv_chunk(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification
		$raw_rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? $_POST['rows'] : [];
		$total    = absint( $_POST['total']    ?? 0 );
		$imported = absint( $_POST['imported'] ?? 0 );
		// phpcs:enable

		if ( empty( $raw_rows ) ) {
			wp_send_json_success( [ 'inserted' => 0, 'skipped' => 0, 'imported' => $imported ] );
		}

		global $wpdb;
		$table    = DB::table( 'dealers' );
		$inserted = 0;
		$skipped  = 0;
		$dealers  = [];

		foreach ( $raw_rows as $line ) {
			$line = sanitize_text_field( wp_unslash( $line ) );
			if ( '' === $line ) {
				continue;
			}

			// Parse CSV line (handles quoted fields)
			$cols = str_getcsv( $line );
			if ( count( $cols ) < 17 ) {
				++$skipped;
				continue;
			}

			// ATF CSV columns:
			// 0=LIC_REGN, 1=LIC_DIST, 2=LIC_CNTY, 3=LIC_TYPE, 4=LIC_XPRDTE,
			// 5=LIC_SEQN, 6=LICENSE_NAME, 7=BUSINESS_NAME, 8=PREMISE_STREET,
			// 9=PREMISE_CITY, 10=PREMISE_STATE, 11=PREMISE_ZIP_CODE,
			// 12=MAIL_STREET, 13=MAIL_CITY, 14=MAIL_STATE, 15=MAIL_ZIP_CODE, 16=VOICE_PHONE

			$regn    = trim( $cols[0] );
			$dist    = trim( $cols[1] );
			$cnty    = trim( $cols[2] );
			$type    = trim( $cols[3] );
			$xprdte  = trim( $cols[4] );
			$seqn    = trim( $cols[5] );
			$state   = strtoupper( trim( $cols[10] ) );
			$zip_raw = trim( $cols[11] );

			if ( ! $regn || ! $type || ! $seqn ) {
				++$skipped;
				continue;
			}

			// Normalise ZIP: keep only digits, take first 5
			$zip = substr( preg_replace( '/\D/', '', $zip_raw ), 0, 5 );
			if ( strlen( $zip ) < 5 ) {
				$zip = str_pad( $zip, 5, '0', STR_PAD_LEFT );
			}

			$license_number = "{$regn}-{$dist}-{$cnty}-{$type}-{$seqn}";

			$dealers[] = [
				'license_number' => $license_number,
				'lic_type'       => $type,
				'lic_regn'       => $regn,
				'lic_dist'       => $dist,
				'lic_cnty'       => $cnty,
				'lic_xprdte'     => self::decode_atf_date( $xprdte ),
				'business_name'  => trim( $cols[7] ) ?: trim( $cols[6] ),
				'premise_street' => trim( $cols[8] ),
				'premise_city'   => trim( $cols[9] ),
				'premise_state'  => $state,
				'premise_zip'    => $zip,
				'mail_street'    => trim( $cols[12] ),
				'mail_city'      => trim( $cols[13] ),
				'mail_state'     => strtoupper( trim( $cols[14] ) ),
				'mail_zip'       => substr( preg_replace( '/\D/', '', trim( $cols[15] ) ), 0, 5 ),
				'phone'          => preg_replace( '/[^0-9]/', '', trim( $cols[16] ) ),
				'is_active'      => 1,
				'last_synced'    => current_time( 'mysql' ),
			];
		}

		// Bulk upsert in one query
		if ( ! empty( $dealers ) ) {
			$placeholders = [];
			$values       = [];

			foreach ( $dealers as $d ) {
				$placeholders[] = '(%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%d,%s)';
				array_push( $values,
					$d['license_number'], $d['lic_type'], $d['lic_regn'], $d['lic_dist'],
					$d['lic_cnty'], $d['lic_xprdte'], $d['business_name'], $d['premise_street'],
					$d['premise_city'], $d['premise_state'], $d['premise_zip'],
					$d['mail_street'], $d['mail_city'], $d['mail_state'], $d['mail_zip'],
					$d['phone'], $d['is_active'], $d['last_synced']
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
					phone          = VALUES(phone),
					is_active      = VALUES(is_active),
					last_synced    = VALUES(last_synced)',
				$values
			);

			$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			$inserted = count( $dealers );

			// Fill lat/lng for newly inserted dealers from cached zip_coords
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
				"UPDATE {$table} d
				 INNER JOIN " . DB::table( 'zip_coords' ) . " z ON z.zip = d.premise_zip
				 SET d.lat = z.lat, d.lng = z.lng
				 WHERE d.lat IS NULL OR d.lat = 0"
			);
		}

		$new_imported = $imported + $inserted + $skipped;

		wp_send_json_success( [
			'inserted' => $inserted,
			'skipped'  => $skipped,
			'imported' => $new_imported,
			'total'    => $total,
			'pct'      => $total > 0 ? min( 100, round( ( $new_imported / $total ) * 100 ) ) : 0,
		] );
	}

	/**
	 * Reset dealer table before fresh import.
	 */
	public function ajax_import_csv_reset(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . DB::table( 'dealers' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
		wp_send_json_success( 'Dealers table cleared.' );
	}

	/**
	 * Return current dealer + ZIP counts.
	 */
	public function ajax_get_dealer_count(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
		global $wpdb;
		wp_send_json_success( [
			'dealers' => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'dealers' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'zips'    => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'zip_coords' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		] );
	}

	/**
	 * Decode ATF expiry date code (e.g. "7F" → "2027-06-01").
	 * Year char: 0-9 → 2020-2029, A-Z → 2030-2055.
	 * Month char: A-L → Jan-Dec.
	 */
	private static function decode_atf_date( string $code ): string {
		if ( strlen( $code ) !== 2 ) {
			return '2027-01-01'; // Default if unknown format
		}
		$year_char  = strtoupper( $code[0] );
		$month_char = strtoupper( $code[1] );

		// Year
		if ( is_numeric( $year_char ) ) {
			$year = 2020 + (int) $year_char;
		} else {
			$year = 2030 + ( ord( $year_char ) - ord( 'A' ) );
		}

		// Month: A=1, B=2, ... L=12 (skip I to keep 12 months)
		$month_map = [ 'A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5,'F'=>6,'G'=>7,'H'=>8,'I'=>9,'J'=>10,'K'=>11,'L'=>12,'M'=>1 ];
		$month     = $month_map[ $month_char ] ?? 1;

		return sprintf( '%04d-%02d-01', $year, $month );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// v1.1.1 — Admin CSV export + dealer-toggle handlers
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Stream the transfers CSV directly when admin clicks the Export button.
	 * Hooks early on admin_init so headers are clean.
	 */
	public function maybe_handle_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['action'] ) ) {
			return;
		}
		if ( 'wpistic-ffl-transfers' !== sanitize_key( $_GET['page'] ) ) {
			return;
		}
		if ( 'export' !== sanitize_key( $_GET['action'] ) ) {
			return;
		}
		// phpcs:enable

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'advanced-ffl-checkout' ), 403 );
		}

		// Optional status / date filters
		$status = isset( $_GET['status_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['status_filter'] ) ) : '';

		global $wpdb;
		$where  = '';
		$params = [];
		if ( $status ) {
			$where    = 'WHERE t.status = %s';
			$params[] = $status;
		}

		$sql = 'SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license
		        FROM ' . DB::table( 'transfers' ) . ' t
		        LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
		        ' . $where . '
		        ORDER BY t.created_at DESC';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$transfers = $params ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) : $wpdb->get_results( $sql );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ffl-transfers-' . date( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, [
			'Transfer Ref','Order ID','Customer Name','Customer Email','Customer Phone',
			'Dealer Name','Dealer License','Status','Item Description','Item SKU','Item Make',
			'Item Model','Item Caliber','Item Type','Item Serial','Shipped Date',
			'Dealer Received','NICS Check Date','NICS NTN','NICS Delay Expires',
			'Transfer Date','Form 4473 Ref','Created At',
		] );

		foreach ( (array) $transfers as $t ) {
			fputcsv( $out, [
				$t->transfer_ref, $t->order_id, $t->customer_name, $t->customer_email,
				$t->customer_phone, $t->dealer_name ?? '', $t->dealer_license ?? '',
				$t->status, $t->item_description, $t->item_sku, $t->item_make, $t->item_model,
				$t->item_caliber, $t->item_type, $t->item_serial, $t->shipped_date,
				$t->dealer_received_date, $t->nics_check_date, $t->nics_transaction_number,
				$t->nics_delay_expires, $t->transfer_date, $t->form_4473_ref, $t->created_at,
			] );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * AJAX — toggle the recommended (is_preferred) flag on a dealer.
	 */
	public function ajax_toggle_preferred(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$dealer_id = isset( $_POST['dealer_id'] ) ? absint( wp_unslash( $_POST['dealer_id'] ) ) : 0;
		$value     = isset( $_POST['value'] )     ? (int) (bool) wp_unslash( $_POST['value'] ) : 0;

		if ( $dealer_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Missing dealer_id' ], 400 );
		}

		global $wpdb;
		$updated = $wpdb->update( DB::table( 'dealers' ), [ 'is_preferred' => $value ], [ 'id' => $dealer_id ], [ '%d' ], [ '%d' ] ); // phpcs:ignore WordPress.DB
		wp_send_json_success( [ 'dealer_id' => $dealer_id, 'is_preferred' => $value, 'updated' => (bool) $updated ] );
	}

	/**
	 * AJAX — set or clear the dealer email address (v1.2.0).
	 * Used by the inline editor in the dealers admin list so an admin can wire
	 * up a real contact email for portal notifications without round-tripping
	 * through the REST API.
	 */
	public function ajax_update_dealer_email(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$dealer_id = isset( $_POST['dealer_id'] ) ? absint( wp_unslash( $_POST['dealer_id'] ) ) : 0;
		$raw_email = isset( $_POST['email'] ) ? trim( (string) wp_unslash( $_POST['email'] ) ) : '';

		if ( $dealer_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Missing dealer_id' ], 400 );
		}

		// Empty value = clear; non-empty must be a valid email
		$email = '';
		if ( '' !== $raw_email ) {
			$email = sanitize_email( $raw_email );
			if ( ! is_email( $email ) ) {
				wp_send_json_error( [ 'message' => 'Invalid email address' ], 400 );
			}
		}

		global $wpdb;
		$updated = $wpdb->update( DB::table( 'dealers' ), [ 'email' => $email ], [ 'id' => $dealer_id ], [ '%s' ], [ '%d' ] ); // phpcs:ignore WordPress.DB
		wp_send_json_success( [ 'dealer_id' => $dealer_id, 'email' => $email, 'updated' => (bool) $updated ] );
	}

	/**
	 * AJAX — geocode a single dealer using the zip_coords table or Google Maps if a key is set.
	 * Backfills lat/lng so the dealer appears in radius searches.
	 */
	public function ajax_geocode_dealer(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}
		$dealer_id = isset( $_POST['dealer_id'] ) ? absint( wp_unslash( $_POST['dealer_id'] ) ) : 0;
		if ( $dealer_id <= 0 ) {
			wp_send_json_error( [ 'message' => 'Missing dealer_id' ], 400 );
		}

		global $wpdb;
		$dealer = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $dealer_id ) ); // phpcs:ignore WordPress.DB
		if ( ! $dealer ) {
			wp_send_json_error( [ 'message' => 'Dealer not found' ], 404 );
		}

		$zip = preg_replace( '/[^0-9]/', '', (string) $dealer->premise_zip );
		if ( strlen( $zip ) >= 5 ) {
			$zip = substr( $zip, 0, 5 );
			$coords = Zip_Import::get_coords( $zip );
			if ( $coords ) {
				$wpdb->update( DB::table( 'dealers' ), [ 'lat' => $coords['lat'], 'lng' => $coords['lng'] ], [ 'id' => $dealer_id ], [ '%f', '%f' ], [ '%d' ] ); // phpcs:ignore WordPress.DB
				wp_send_json_success( [ 'lat' => $coords['lat'], 'lng' => $coords['lng'], 'source' => 'zip_centroid' ] );
			}
		}

		wp_send_json_error( [ 'message' => 'Could not geocode — no ZIP centroid found.' ] );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// v1.1.3 — Diagnostics page
	// One-click answer to "why aren't my emails sending?"
	// ──────────────────────────────────────────────────────────────────────────

	public function render_diagnostics(): void {
		global $wpdb;

		$portal_settings = get_option( 'wpistic_ffl_portal_settings', [] );
		$general         = get_option( 'wpistic_ffl_settings', [] );
		$email_settings  = get_option( 'wpistic_ffl_email_settings', [] );
		$mail_log        = get_option( 'wpistic_ffl_mail_log', [] );
		$db_version      = get_option( 'wpistic_ffl_db_version', '?' );

		$recent_transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT id, transfer_ref, order_id, customer_email, status, created_at
			 FROM ' . DB::table( 'transfers' ) . '
			 ORDER BY created_at DESC LIMIT 10'
		);

		$recent_tokens = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT id, transfer_id, status, created_at, used_at, used_action
			 FROM ' . DB::table( 'dealer_tokens' ) . '
			 ORDER BY created_at DESC LIMIT 10'
		);

		// SMTP plugin detection
		$smtp_plugins = [];
		if ( class_exists( 'WPMailSMTP\Core' ) )                      $smtp_plugins[] = 'WP Mail SMTP';
		if ( class_exists( 'FluentMail\App\Plugin' ) )                $smtp_plugins[] = 'Fluent SMTP';
		if ( class_exists( 'PostSMTP' ) || function_exists( 'postman_get_logs' ) ) $smtp_plugins[] = 'Post SMTP';
		if ( defined( 'ESM_VERSION' ) || class_exists( 'Elementor_Site_Mailer' ) ) $smtp_plugins[] = 'Elementor Site Mailer';
		if ( class_exists( 'WP_SMTP' ) )                              $smtp_plugins[] = 'WP-SMTP';

		$wp_mail_overridden = function_exists( 'wp_mail' ) && ( new \ReflectionFunction( 'wp_mail' ) )->getFileName() !== ABSPATH . WPINC . '/pluggable.php';

		$portal_url = home_url( '/' . ( $portal_settings['portal_slug'] ?? 'ffl-confirm' ) . '/PREVIEW/' );

		?>
		<div class="wrap">
			<h1>🩺 <?php esc_html_e( 'FFL Diagnostics', 'advanced-ffl-checkout' ); ?></h1>
			<p class="description"><?php esc_html_e( 'One-page health check. Use this when "emails not sending" or "portal not working" complaints come in.', 'advanced-ffl-checkout' ); ?></p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px;margin-top:20px;">

				<!-- Plugin status -->
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;">
					<h2 style="margin-top:0;font-size:15px;">📦 Plugin Status</h2>
					<?php $row = static fn( $k, $v, $ok = true ) => printf(
						'<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #f3f4f6;font-size:13px;"><span>%s</span><span style="font-weight:600;color:%s;">%s</span></div>',
						esc_html( $k ), $ok ? '#16a34a' : '#dc2626', esc_html( (string) $v )
					); ?>
					<?php $row( 'Version',                  WPISTIC_FFL_VERSION ); ?>
					<?php $row( 'DB schema version',        $db_version, $db_version === DB::SCHEMA_VERSION ); ?>
					<?php $row( 'WooCommerce active',       class_exists( 'WooCommerce' ) ? 'Yes' : 'NO', class_exists( 'WooCommerce' ) ); ?>
					<?php $row( 'Token secret set',         get_option( 'wpistic_ffl_token_secret' ) ? 'Yes' : 'NO', (bool) get_option( 'wpistic_ffl_token_secret' ) ); ?>
				</div>

				<!-- Portal settings -->
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;">
					<h2 style="margin-top:0;font-size:15px;">🔗 Dealer Portal Config</h2>
					<?php
					$portal_enabled  = ! empty( $portal_settings['enabled'] );
					$portal_autosend = ! empty( $portal_settings['auto_send_on_ship'] );
					$row( 'Portal enabled',           $portal_enabled  ? 'YES' : 'NO ⚠', $portal_enabled );
					$row( 'Auto-send on ship',        $portal_autosend ? 'YES' : 'NO ⚠', $portal_autosend );
					$row( 'Token expiry (days)',      (int) ( $portal_settings['token_expiry_days'] ?? 0 ) );
					$row( 'Two-factor method',        (string) ( $portal_settings['two_factor_method'] ?? '?' ) );
					$row( 'Portal slug',              (string) ( $portal_settings['portal_slug'] ?? '?' ) );
					?>
					<p style="margin-top:10px;"><a href="<?php echo esc_url( $portal_url ); ?>" target="_blank" class="button button-small">👁 Open Preview Portal</a></p>
				</div>

				<!-- Mailer config -->
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;">
					<h2 style="margin-top:0;font-size:15px;">📧 Email Config</h2>
					<?php
					$disable          = ! empty( $general['disable_emails'] );
					$customer_emails  = ! isset( $general['customer_emails'] ) || ! empty( $general['customer_emails'] );
					$admin_emails     = ! isset( $general['admin_emails'] )    || ! empty( $general['admin_emails'] );
					$row( 'Disable all emails',      $disable ? '⚠ YES (BLOCKING)' : 'No',  ! $disable );
					$row( 'Customer emails enabled', $customer_emails ? 'YES' : 'NO ⚠',     $customer_emails );
					$row( 'Admin emails enabled',    $admin_emails ? 'YES' : 'NO ⚠',        $admin_emails );
					$row( 'From name',               (string) ( $general['from_name'] ?? get_bloginfo( 'name' ) ) );
					$row( 'Notification email',      (string) ( $general['notification_email'] ?? get_option( 'admin_email' ) ) );
					$row( 'wp_mail() overridden',    $wp_mail_overridden ? 'Yes (SMTP intercepting)' : 'No (PHP mail)' );
					$row( 'SMTP plugins detected',   $smtp_plugins ? implode( ', ', $smtp_plugins ) : 'None' );
					?>
				</div>

				<!-- Recent transfers -->
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;grid-column:1/-1;">
					<h2 style="margin-top:0;font-size:15px;">📋 Last 10 Transfers</h2>
					<?php if ( $recent_transfers ) : ?>
						<table class="widefat striped" style="font-size:12px;">
							<thead><tr><th>Ref</th><th>Order</th><th>Customer</th><th>Status</th><th>Created</th></tr></thead>
							<tbody>
								<?php foreach ( $recent_transfers as $t ) : ?>
									<tr>
										<td><code><?php echo esc_html( $t->transfer_ref ); ?></code></td>
										<td>#<?php echo esc_html( (string) $t->order_id ); ?></td>
										<td><?php echo esc_html( $t->customer_email ); ?></td>
										<td><span style="background:#f3f4f6;padding:2px 8px;border-radius:4px;"><?php echo esc_html( $t->status ); ?></span></td>
										<td><?php echo esc_html( $t->created_at ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p style="color:#dc2626;"><strong>⚠ No transfers in the database.</strong> If a customer placed an order with an FFL product but no transfer was created, the order may have been missing the dealer selection.</p>
					<?php endif; ?>
				</div>

				<!-- Recent tokens -->
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;grid-column:1/-1;">
					<h2 style="margin-top:0;font-size:15px;">🎫 Last 10 Dealer Portal Tokens</h2>
					<?php if ( $recent_tokens ) : ?>
						<table class="widefat striped" style="font-size:12px;">
							<thead><tr><th>Transfer</th><th>Status</th><th>Created</th><th>Used At</th><th>Used Action</th></tr></thead>
							<tbody>
								<?php foreach ( $recent_tokens as $tk ) : ?>
									<tr>
										<td>#<?php echo esc_html( (string) $tk->transfer_id ); ?></td>
										<td><?php echo esc_html( $tk->status ); ?></td>
										<td><?php echo esc_html( $tk->created_at ); ?></td>
										<td><?php echo esc_html( (string) ( $tk->used_at ?: '—' ) ); ?></td>
										<td><?php echo esc_html( (string) ( $tk->used_action ?: '—' ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p style="color:#dc2626;"><strong>⚠ No tokens issued yet.</strong> A token gets created when a transfer enters "shipped_to_dealer". If you've shipped transfers but no tokens exist, the auto-send hook isn't firing — check Portal Config above.</p>
					<?php endif; ?>
				</div>

				<!-- Mail log -->
				<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;grid-column:1/-1;">
					<h2 style="margin-top:0;font-size:15px;">📜 Mail Log (last 100 events — newest first)</h2>
					<?php if ( $mail_log ) : ?>
						<div style="max-height:340px;overflow-y:auto;font-family:ui-monospace,SFMono-Regular,monospace;font-size:11px;background:#0f172a;color:#e2e8f0;padding:12px;border-radius:6px;">
							<?php foreach ( $mail_log as $entry ) : ?>
								<div style="padding:3px 0;border-bottom:1px solid #1e293b;">
									<span style="color:#94a3b8;"><?php echo esc_html( $entry['ts'] ?? '' ); ?></span>
									<span style="color:#e2e8f0;"> · <?php echo esc_html( $entry['msg'] ?? '' ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p style="color:#6b7280;">No mail events logged yet. Trigger an order or status change to populate.</p>
					<?php endif; ?>
				</div>
			</div>

			<!-- Quick actions -->
			<div style="margin-top:24px;display:flex;gap:10px;flex-wrap:wrap;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-settings&tab=emails' ) ); ?>" class="button button-primary">📧 Send Test Email</a>
				<button type="button" class="button" id="wpistic-ffl-clear-mail-log">🗑 Clear Mail Log</button>
				<button type="button" class="button" id="wpistic-ffl-reset-defaults">🔄 Reset Settings to Defaults</button>
			</div>

			<script>
			document.addEventListener('DOMContentLoaded', function() {
				const nonce = (window.wpistic_ffl_admin || {}).nonce || '';
				const ajax  = (window.wpistic_ffl_admin || {}).ajax_url || window.ajaxurl;

				const clearBtn = document.getElementById('wpistic-ffl-clear-mail-log');
				if (clearBtn) clearBtn.addEventListener('click', function() {
					if (!confirm('Clear all mail log entries?')) return;
					const data = new FormData();
					data.append('action', 'wpistic_ffl_clear_mail_log');
					data.append('nonce', nonce);
					fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
						.then(r => r.json()).then(j => { if (j.success) location.reload(); });
				});

				const resetBtn = document.getElementById('wpistic-ffl-reset-defaults');
				if (resetBtn) resetBtn.addEventListener('click', function() {
					if (!confirm('Reset Portal + Email settings to defaults? Your theme + integration keys are NOT affected.')) return;
					const data = new FormData();
					data.append('action', 'wpistic_ffl_reset_defaults');
					data.append('nonce', nonce);
					fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
						.then(r => r.json()).then(j => { if (j.success) { alert('Reset complete. Reloading.'); location.reload(); } });
				});
			});
			</script>
		</div>
		<?php
	}

	public function ajax_clear_mail_log(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
		delete_option( 'wpistic_ffl_mail_log' );
		wp_send_json_success();
	}

	public function ajax_reset_defaults(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
		delete_option( 'wpistic_ffl_portal_settings' );
		delete_option( 'wpistic_ffl_settings' );
		delete_option( 'wpistic_ffl_email_settings' );
		// Re-create with defaults
		if ( function_exists( 'wpistic_ffl_ensure_default_options' ) ) {
			wpistic_ffl_ensure_default_options();
		}
		wp_send_json_success();
	}

	// ── SVG icon ──────────────────────────────────────────────────────────────

	private static function menu_icon_svg(): string {
		// G2A brass shield — matches the FFL-required iconography used on checkout.
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#DCB45F">
			<path d="M12 1l9 4v6c0 5.55-3.84 10.74-9 12-5.16-1.26-9-6.45-9-12V5l9-4z"/>
		</svg>';
	}
}
