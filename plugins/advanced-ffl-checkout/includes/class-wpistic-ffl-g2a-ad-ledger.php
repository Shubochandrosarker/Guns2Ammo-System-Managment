<?php
/**
 * G2A — Acquisition & Disposition (bound book) ledger.
 *
 * Every FFL is legally required (18 U.S.C. § 923(g), 27 CFR 478.125/478.125a)
 * to keep a serial-level record of every firearm that crosses its threshold:
 * when and from whom it was acquired, and when and to whom it was disposed.
 * This plugin already models the transfer lifecycle in detail but never
 * touched that record — dealers using it still needed a separate bound-book
 * system.
 *
 * Scope: this ledger models the RECEIVING dealer's bound-book obligation —
 * the FFL that takes physical custody of the firearm and hands it to the
 * end buyer, which is the dealer this plugin actually manages end to end.
 * If the store operating this plugin is itself also an FFL/distributor with
 * its own separate disposition-out record to that receiving dealer, that is
 * a distinct legal record this table does not model.
 *
 * Auto-populated (never requires a separate data-entry step for the normal
 * path):
 *   - Acquisition side fills in when a transfer reaches `received_by_dealer`
 *     (the receiving FFL took physical custody).
 *   - Disposition side fills in when a transfer reaches `transferred` (the
 *     firearm left the FFL's inventory to the buyer, after NICS + 4473).
 *
 * A serial number is often not known until the dealer physically receives
 * the item, so rows can and do start with a blank serial — the admin page's
 * "Needs Serial Number" queue surfaces those for a quick inline fix, and the
 * ATF-format export flags any row still missing one.
 *
 * Retention: ATF requires bound-book records to be kept for 20 years (and
 * indefinitely for records tied to abandoned/lost records requests), so
 * this table is deliberately NOT covered by the GDPR eraser — the same
 * "kept for ATF compliance" rationale this codebase already applies to the
 * `transfers` and `signatures` tables.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Ad_Ledger {

	public function __construct() {
		add_action( 'wpistic_ffl_transfer_status_changed', [ __CLASS__, 'on_status_changed' ], 10, 3 );
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 42 );
		add_action( 'admin_init', [ __CLASS__, 'maybe_handle_export' ] );

		add_action( 'wp_ajax_wpistic_ffl_ad_ledger_update_row', [ __CLASS__, 'ajax_update_row' ] );
	}

	// ── Auto-population ──────────────────────────────────────────

	public static function on_status_changed( int $transfer_id, string $old_status, string $new_status ): void {
		if ( 'received_by_dealer' === $new_status ) {
			self::record_acquisition( $transfer_id );
		} elseif ( 'transferred' === $new_status ) {
			self::record_disposition( $transfer_id );
		}
	}

	private static function record_acquisition( int $transfer_id ): void {
		global $wpdb;
		$existing = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'ad_ledger' ) . ' WHERE transfer_id = %d', $transfer_id
		) );
		if ( $existing ) {
			return; // Already have an acquisition entry for this transfer.
		}

		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d', $transfer_id
		) );
		if ( ! $t ) {
			return;
		}

		$acquired_from = get_bloginfo( 'name' ) ?: 'Online store';
		$theme         = class_exists( '\WpisticFFL\Theming' ) ? Theming::settings() : [];
		if ( ! empty( $theme['business_name'] ) ) {
			$acquired_from = $theme['business_name'];
		}

		$wpdb->insert( DB::table( 'ad_ledger' ), [ // phpcs:ignore WordPress.DB
			'transfer_id'      => $transfer_id,
			'dealer_id'        => (int) $t->dealer_id,
			'manufacturer'     => (string) $t->item_make,
			'model'            => (string) $t->item_model,
			'serial_number'    => (string) $t->item_serial,
			'item_type'        => (string) ( $t->item_type ?: 'handgun' ),
			'caliber_gauge'    => (string) $t->item_caliber,
			'acquisition_date' => $t->dealer_received_date ?: current_time( 'Y-m-d' ),
			'acquired_from'    => $acquired_from,
			'acquisition_type' => 'transfer_in',
			'status'           => 'in_inventory',
		] );

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'ad_ledger_acquired',
			'notes'       => 'Bound-book acquisition entry recorded.' . ( $t->item_serial ? '' : ' Serial number not yet on file — needs staff entry.' ),
			'actor'       => 'system',
			'actor_ip'    => '',
		] );
	}

	private static function record_disposition( int $transfer_id ): void {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d', $transfer_id
		) );
		if ( ! $t ) {
			return;
		}

		$ledger = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'ad_ledger' ) . ' WHERE transfer_id = %d', $transfer_id
		) );

		$data = [
			'serial_number'    => (string) $t->item_serial ?: null, // Keep whatever was already on file if this update has none.
			'disposition_date' => $t->transfer_date ?: current_time( 'Y-m-d' ),
			'disposed_to_name' => (string) $t->customer_name,
			'disposed_to_type' => 'individual_over_counter',
			'status'           => 'disposed',
			'updated_at'       => current_time( 'mysql' ),
		];
		if ( empty( $t->item_serial ) ) {
			unset( $data['serial_number'] ); // Don't overwrite a real serial with an empty one.
		}

		if ( $ledger ) {
			$wpdb->update( DB::table( 'ad_ledger' ), $data, [ 'id' => (int) $ledger->id ] ); // phpcs:ignore WordPress.DB
		} else {
			// Disposition without a prior acquisition row (e.g. transfer skipped
			// received_by_dealer, or was created before this feature shipped).
			// Back-fill a complete row rather than silently dropping the record.
			$acquired_from = get_bloginfo( 'name' ) ?: 'Online store';
			$wpdb->insert( DB::table( 'ad_ledger' ), array_merge( [ // phpcs:ignore WordPress.DB
				'transfer_id'      => $transfer_id,
				'dealer_id'        => (int) $t->dealer_id,
				'manufacturer'     => (string) $t->item_make,
				'model'            => (string) $t->item_model,
				'serial_number'    => (string) $t->item_serial,
				'item_type'        => (string) ( $t->item_type ?: 'handgun' ),
				'caliber_gauge'    => (string) $t->item_caliber,
				'acquisition_date' => $t->dealer_received_date ?: $t->transfer_date ?: current_time( 'Y-m-d' ),
				'acquired_from'    => $acquired_from,
				'acquisition_type' => 'transfer_in',
			], $data ) );
		}

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => $transfer_id,
			'event_type'  => 'ad_ledger_disposed',
			'notes'       => 'Bound-book disposition entry recorded.',
			'actor'       => 'system',
			'actor_ip'    => '',
		] );
	}

	// ── Admin page ────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Bound Book', 'advanced-ffl-checkout' ),
			__( '📕 Bound Book', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-ad-ledger',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$table = DB::table( 'ad_ledger' );
		$nonce = wp_create_nonce( 'wpistic_ffl_admin_nonce' );

		$needs_serial = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT l.*, t.transfer_ref FROM {$table} l
			 LEFT JOIN " . DB::table( 'transfers' ) . " t ON t.id = l.transfer_id
			 WHERE l.serial_number = '' ORDER BY l.created_at DESC LIMIT 50"
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT l.*, t.transfer_ref, d.business_name AS dealer_name FROM {$table} l
			 LEFT JOIN " . DB::table( 'transfers' ) . " t ON t.id = l.transfer_id
			 LEFT JOIN " . DB::table( 'dealers' ) . " d ON d.id = l.dealer_id
			 ORDER BY l.created_at DESC LIMIT 200"
		);

		$export_url = wp_nonce_url(
			admin_url( 'admin.php?page=wpistic-ffl-ad-ledger&action=export' ),
			'wpistic_ffl_ad_ledger_export'
		);
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">📕</span>
				<?php esc_html_e( 'Acquisition & Disposition (Bound Book)', 'advanced-ffl-checkout' ); ?>
				<a href="<?php echo esc_url( $export_url ); ?>" class="button button-primary" style="margin-left:auto;">⬇ <?php esc_html_e( 'Export ATF-Format CSV', 'advanced-ffl-checkout' ); ?></a>
			</h1>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Serial-level acquisition/disposition record for every firearm received and disposed of through this store\'s FFL transfer flow. Rows are created automatically when a transfer is marked received by the dealer, and completed when it is marked transferred. This is a working record, not legal advice — verify against your official bound book before an ATF inspection.', 'advanced-ffl-checkout' ); ?>
			</p>

			<?php if ( $needs_serial ) : ?>
				<div style="background:#fff;padding:20px;border:1px solid #DC2626;border-radius:10px;margin-top:16px;">
					<h2 style="margin-top:0;color:#DC2626;">⚠️ <?php esc_html_e( 'Needs Serial Number', 'advanced-ffl-checkout' ); ?> (<?php echo count( $needs_serial ); ?>)</h2>
					<p class="description"><?php esc_html_e( 'These items were received before a serial number was on file. Enter it as soon as the item is physically in hand.', 'advanced-ffl-checkout' ); ?></p>
					<table class="widefat striped" style="margin-top:10px;">
						<thead><tr>
							<th><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Make / Model', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Serial Number', 'advanced-ffl-checkout' ); ?></th>
							<th></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $needs_serial as $row ) : ?>
							<tr data-ledger-id="<?php echo esc_attr( $row->id ); ?>">
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . (int) $row->transfer_id ) ); ?>">#<?php echo esc_html( $row->transfer_ref ?: $row->transfer_id ); ?></a></td>
								<td><?php echo esc_html( trim( $row->manufacturer . ' ' . $row->model ) ?: '—' ); ?></td>
								<td><input type="text" class="wpistic-ffl-ledger-serial" value="" placeholder="Enter serial…" style="width:180px;"></td>
								<td><button type="button" class="button wpistic-ffl-ledger-save"><?php esc_html_e( 'Save', 'advanced-ffl-checkout' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="margin-top:20px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Manufacturer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Model', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Serial', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Type', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Acquired', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Disposed', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><em><?php esc_html_e( 'No bound-book entries yet — rows appear as transfers are received and completed.', 'advanced-ffl-checkout' ); ?></em></td></tr>
				<?php else : foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row->dealer_name ?: '—' ); ?></td>
						<td><?php echo esc_html( $row->manufacturer ?: '—' ); ?></td>
						<td><?php echo esc_html( $row->model ?: '—' ); ?></td>
						<td><code><?php echo esc_html( $row->serial_number ?: '⚠ missing' ); ?></code></td>
						<td><?php echo esc_html( ucfirst( $row->item_type ) ); ?></td>
						<td><?php echo esc_html( $row->acquisition_date ?: '—' ); ?> <span style="color:#666;font-size:11px;"><?php echo esc_html( $row->acquired_from ); ?></span></td>
						<td><?php echo esc_html( $row->disposition_date ?: '—' ); ?> <?php if ( $row->disposed_to_name ) : ?><span style="color:#666;font-size:11px;"><?php echo esc_html( $row->disposed_to_name ); ?></span><?php endif; ?></td>
						<td><span class="wpistic-ffl-status"><?php echo esc_html( 'disposed' === $row->status ? 'Disposed' : 'In Inventory' ); ?></span></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.querySelectorAll('.wpistic-ffl-ledger-save').forEach(function(btn){
				btn.addEventListener('click', function(){
					var tr = btn.closest('tr');
					var id = tr.getAttribute('data-ledger-id');
					var serial = tr.querySelector('.wpistic-ffl-ledger-serial').value.trim();
					if (! serial) { return; }
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_ad_ledger_update_row');
					fd.append('nonce', nonce);
					fd.append('ledger_id', id);
					fd.append('serial_number', serial);
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(j){
							if (j && j.success) {
								tr.style.opacity = '0.4';
								btn.textContent = '✓ Saved';
							} else {
								btn.disabled = false;
								alert(j && j.data && j.data.message ? j.data.message : 'Save failed');
							}
						});
				});
			});
		})();
		</script>
		<?php
	}

	public static function ajax_update_row(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$id     = (int) ( $_POST['ledger_id'] ?? 0 );
		$serial = sanitize_text_field( wp_unslash( $_POST['serial_number'] ?? '' ) );
		// phpcs:enable
		if ( ! $id || '' === $serial ) {
			wp_send_json_error( [ 'message' => 'Missing ledger row or serial number.' ], 400 );
		}
		global $wpdb;
		$updated = $wpdb->update( DB::table( 'ad_ledger' ), [ // phpcs:ignore WordPress.DB
			'serial_number' => $serial,
			'updated_at'    => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		if ( false === $updated ) {
			wp_send_json_error( [ 'message' => 'Could not save.' ], 500 );
		}
		$transfer_id = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT transfer_id FROM ' . DB::table( 'ad_ledger' ) . ' WHERE id = %d', $id
		) );
		// Keep the transfer's own item_serial in sync so the 4473 worksheet and CSV export agree.
		if ( $transfer_id ) {
			$wpdb->update( DB::table( 'transfers' ), [ 'item_serial' => $serial ], [ 'id' => $transfer_id ] ); // phpcs:ignore WordPress.DB
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => $transfer_id,
				'event_type'  => 'ad_ledger_serial_entered',
				'notes'       => 'Serial number entered on the bound-book ledger: ' . $serial,
				'actor'       => wp_get_current_user()->user_login ?: 'staff',
				'actor_ip'    => Token::client_ip(),
			] );
		}
		wp_send_json_success( [ 'serial_number' => $serial ] );
	}

	// ── ATF-format CSV export ────────────────────────────────

	public static function maybe_handle_export(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['page'], $_GET['action'] ) || 'wpistic-ffl-ad-ledger' !== sanitize_key( $_GET['page'] ) || 'export' !== sanitize_key( $_GET['action'] ) ) {
			return;
		}
		// phpcs:enable
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to export.', 'advanced-ffl-checkout' ), 403 );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpistic_ffl_ad_ledger_export' ) ) {
			wp_die( esc_html__( 'Invalid or missing export nonce.', 'advanced-ffl-checkout' ), 400 );
		}

		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT l.*, d.business_name AS dealer_name, d.license_number AS dealer_license FROM ' . DB::table( 'ad_ledger' ) . ' l
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = l.dealer_id
			 ORDER BY l.acquisition_date ASC, l.id ASC'
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ffl-bound-book-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		// Column order/labels mirror the ATF-required A&D record fields
		// (27 CFR 478.125): manufacturer/importer, model, serial, type,
		// caliber/gauge, date acquired, manner/from whom acquired, date
		// disposed, manner/to whom disposed.
		fputcsv( $out, [
			'Dealer', 'Dealer FFL License', 'Manufacturer/Importer', 'Model', 'Serial Number',
			'Type', 'Caliber/Gauge', 'Date Acquired', 'Acquired From', 'Date Disposed',
			'Disposed To', 'Disposition Type', 'Status',
		] );
		foreach ( (array) $rows as $r ) {
			fputcsv( $out, array_map( [ __CLASS__, 'csv_cell' ], [
				$r->dealer_name ?? '', $r->dealer_license ?? '', $r->manufacturer, $r->model,
				$r->serial_number ?: 'MISSING — ENTER BEFORE FILING', $r->item_type, $r->caliber_gauge,
				$r->acquisition_date, $r->acquired_from, $r->disposition_date,
				$r->disposed_to_name, $r->disposed_to_type, $r->status,
			] ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Neutralize spreadsheet formula injection — same pattern as
	 * Api::csv_cell(). `disposed_to_name`/`acquired_from` trace back to
	 * customer-supplied checkout names, so a buyer could otherwise plant a
	 * `=HYPERLINK(...)`/DDE payload that executes when staff open this
	 * export in Excel/Sheets.
	 */
	private static function csv_cell( $value ): string {
		$value = (string) $value;
		if ( '' !== $value && false !== strpbrk( $value[0], "=+-@\t\r" ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
