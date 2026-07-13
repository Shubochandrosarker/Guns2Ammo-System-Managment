<?php
/**
 * G2A — Operations toolset (one class, three small admin features).
 *
 *   • Dealer-fee bulk editor (set transfer_fee for every dealer in a state)
 *   • Customer LTV / transfer-history lookup
 *   • Dealer health alerts (nightly flag for high issue_reported rate)
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Ops_Tools {

	const HEALTH_CRON = 'wpistic_ffl_dealer_health_check';
	/** Issue-rate threshold above which a dealer is flagged. */
	const HEALTH_ISSUE_RATIO = 0.25; // 25%+ of recent transfers reported an issue
	const HEALTH_MIN_TRANSFERS = 5;

	public function __construct() {
		add_action( 'admin_menu',         [ $this, 'register_admin_page' ], 40 );
		add_action( 'wp_ajax_wpistic_ffl_bulk_fees',     [ __CLASS__, 'ajax_bulk_fees' ] );
		add_action( 'wp_ajax_wpistic_ffl_customer_ltv',  [ __CLASS__, 'ajax_customer_ltv' ] );
		add_action( self::HEALTH_CRON,    [ __CLASS__, 'run_health_check' ] );
		add_action( 'init', function (): void {
			G2A_Scheduler::recurring( DAY_IN_SECONDS, self::HEALTH_CRON, [], strtotime( 'tomorrow 06:00' ) );
		}, 25 );

		// REST: LTV lookup for the dashboard app
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Ops Tools', 'advanced-ffl-checkout' ),
			__( '🛠️ Ops Tools', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-ops',
			[ $this, 'render_admin_page' ]
		);
	}

	// ── Page render ───────────────────────────────────────────────

	public function render_admin_page(): void {
		$nonce = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$flags = (array) get_option( 'wpistic_ffl_dealer_health_flags', [] );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;"><span style="font-size:24px;">🛠️</span><?php esc_html_e( 'Operations Tools', 'advanced-ffl-checkout' ); ?></h1>

			<!-- Bulk fees -->
			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">💰 <?php esc_html_e( 'Bulk-set dealer transfer fee by state', 'advanced-ffl-checkout' ); ?></h2>
				<p class="description"><?php esc_html_e( "Set the same transfer fee for every active dealer in a given state. Useful when expanding into a new market and you don't want to edit dealers one at a time.", 'advanced-ffl-checkout' ); ?></p>
				<form id="wpistic-ffl-bulk-fees" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div>
						<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#666;"><?php esc_html_e( 'State', 'advanced-ffl-checkout' ); ?></label>
						<select name="state" required>
							<option value=""><?php esc_html_e( '— Select state —', 'advanced-ffl-checkout' ); ?></option>
							<?php foreach ( self::us_states() as $abbr => $full ) : ?>
								<option value="<?php echo esc_attr( $abbr ); ?>"><?php echo esc_html( $abbr . ' — ' . $full ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#666;"><?php esc_html_e( 'New fee (USD)', 'advanced-ffl-checkout' ); ?></label>
						<input type="number" name="fee" min="0" step="0.01" placeholder="25.00" required>
					</div>
					<div>
						<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#666;"><?php esc_html_e( 'Only if currently', 'advanced-ffl-checkout' ); ?></label>
						<select name="only_if">
							<option value="any"><?php esc_html_e( 'any value', 'advanced-ffl-checkout' ); ?></option>
							<option value="zero"><?php esc_html_e( '0.00 (not yet set)', 'advanced-ffl-checkout' ); ?></option>
						</select>
					</div>
					<button type="submit" class="button button-primary">🔁 <?php esc_html_e( 'Apply', 'advanced-ffl-checkout' ); ?></button>
					<span id="wpistic-ffl-bulk-fees-result" style="font-weight:600;"></span>
				</form>
			</div>

			<!-- Customer LTV -->
			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">📊 <?php esc_html_e( 'Customer lifetime value lookup', 'advanced-ffl-checkout' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Find every FFL transfer a customer has had with us — count, lifetime value, average days from order to pickup, last dealer used. Search by email.', 'advanced-ffl-checkout' ); ?></p>
				<form id="wpistic-ffl-ltv" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:12px;">
					<input type="hidden" name="nonce" value="<?php echo esc_attr( $nonce ); ?>">
					<div style="flex:1;min-width:280px;">
						<label style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#666;"><?php esc_html_e( 'Customer email', 'advanced-ffl-checkout' ); ?></label>
						<input type="email" name="email" placeholder="customer@example.com" required style="width:100%;">
					</div>
					<button type="submit" class="button button-primary">🔎 <?php esc_html_e( 'Lookup', 'advanced-ffl-checkout' ); ?></button>
				</form>
				<div id="wpistic-ffl-ltv-result" style="margin-top:16px;"></div>
			</div>

			<!-- Dealer health -->
			<div style="background:#fff;padding:20px;border:1px solid #ccd0d4;border-radius:10px;margin-top:16px;">
				<h2 style="margin-top:0;border-bottom:2px solid #DCB45F;padding-bottom:8px;">⚠️ <?php esc_html_e( 'Dealer health alerts', 'advanced-ffl-checkout' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: 1 = pct, 2 = count */
						esc_html__( 'Dealers with a recent issue-reported rate above %1$d%% (minimum %2$d recent transfers) are flagged here. Re-checked nightly.', 'advanced-ffl-checkout' ),
						(int) ( self::HEALTH_ISSUE_RATIO * 100 ),
						self::HEALTH_MIN_TRANSFERS
					);
					?>
				</p>
				<?php if ( empty( $flags ) ) : ?>
					<p style="margin-top:12px;color:#16A34A;font-weight:600;">✓ <?php esc_html_e( 'No dealers currently flagged.', 'advanced-ffl-checkout' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="margin-top:12px;">
						<thead><tr>
							<th><?php esc_html_e( 'Dealer', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'License #', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Recent transfers', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Issue rate', 'advanced-ffl-checkout' ); ?></th>
							<th><?php esc_html_e( 'Flagged at', 'advanced-ffl-checkout' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $flags as $f ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-dealers&id=' . (int) ( $f['dealer_id'] ?? 0 ) ) ); ?>"><?php echo esc_html( $f['business_name'] ?? '?' ); ?></a></td>
								<td><code><?php echo esc_html( $f['license_number'] ?? '' ); ?></code></td>
								<td><?php echo (int) ( $f['total'] ?? 0 ); ?></td>
								<td style="color:#DC2626;font-weight:700;"><?php echo number_format( ( $f['ratio'] ?? 0 ) * 100, 1 ); ?>%</td>
								<td><?php echo esc_html( $f['at'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function(){
			function postJson(form, action) {
				var fd = new FormData(form);
				fd.append('action', action);
				return fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(r => r.json());
			}
			document.getElementById('wpistic-ffl-bulk-fees').addEventListener('submit', function(e){
				e.preventDefault();
				var res = document.getElementById('wpistic-ffl-bulk-fees-result');
				res.textContent = 'Working…'; res.style.color = '#666';
				postJson(e.target, 'wpistic_ffl_bulk_fees').then(function(j){
					if (j && j.success) {
						res.textContent = '✓ Updated ' + j.data.affected + ' dealer(s)';
						res.style.color = '#16A34A';
					} else {
						res.textContent = '✗ ' + (j && j.data && j.data.message ? j.data.message : 'Failed');
						res.style.color = '#DC2626';
					}
				});
			});
			document.getElementById('wpistic-ffl-ltv').addEventListener('submit', function(e){
				e.preventDefault();
				var out = document.getElementById('wpistic-ffl-ltv-result');
				out.innerHTML = '<em>Looking up…</em>';
				postJson(e.target, 'wpistic_ffl_customer_ltv').then(function(j){
					if (!(j && j.success && j.data && j.data.found)) {
						out.innerHTML = '<p style="color:#666;">No transfers found for that email.</p>';
						return;
					}
					var d = j.data;
					out.innerHTML =
						'<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;">' +
							kpi(d.total_transfers, 'Transfers') +
							kpi('$'+Number(d.lifetime_value).toFixed(0), 'Lifetime value') +
							kpi(d.avg_pickup_days || '—', 'Avg days to pickup') +
							kpi(d.last_dealer || '—', 'Last dealer used') +
						'</div>' +
						'<table class="widefat"><thead><tr><th>Ref</th><th>Status</th><th>Item</th><th>Dealer</th><th>Order</th><th>Created</th></tr></thead><tbody>' +
						d.transfers.map(function(t){
							return '<tr><td><code>' + t.transfer_ref + '</code></td><td>' + t.status + '</td>' +
								'<td>' + (t.item_description || (t.item_make + ' ' + t.item_model).trim() || '—') + '</td>' +
								'<td>' + (t.dealer_name || '—') + '</td>' +
								'<td><a href="' + (t.order_url || '#') + '">#' + t.order_id + '</a></td>' +
								'<td>' + t.created_at + '</td></tr>';
						}).join('') +
						'</tbody></table>';
				});
				function kpi(v,l){return '<div style="padding:14px;background:#1A191E;color:#DCB45F;border-radius:8px;text-align:center;"><div style="font-size:22px;font-weight:800;">'+v+'</div><div style="font-size:10px;letter-spacing:.08em;text-transform:uppercase;color:#A7A6AE;margin-top:2px;">'+l+'</div></div>';}
			});
		})();
		</script>
		<?php
	}

	// ── Bulk fees ─────────────────────────────────────────────────

	public static function ajax_bulk_fees(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$state   = strtoupper( substr( sanitize_text_field( wp_unslash( $_POST['state'] ?? '' ) ), 0, 2 ) );
		$fee     = (float) ( $_POST['fee'] ?? 0 );
		$only_if = sanitize_key( wp_unslash( $_POST['only_if'] ?? 'any' ) );
		// phpcs:enable

		if ( 2 !== strlen( $state ) || $fee < 0 ) {
			wp_send_json_error( [ 'message' => 'Invalid state or fee.' ], 400 );
		}

		global $wpdb;
		$where_fee = ( 'zero' === $only_if ) ? 'AND transfer_fee = 0.00' : '';
		$affected  = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . DB::table( 'dealers' ) . " SET transfer_fee = %f WHERE premise_state = %s AND is_active = 1 {$where_fee}",
			$fee, $state
		) );
		wp_send_json_success( [ 'affected' => (int) $affected ] );
	}

	// ── Customer LTV ─────────────────────────────────────────────

	public static function ajax_customer_ltv(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		// phpcs:enable
		if ( ! $email ) {
			wp_send_json_error( [ 'message' => 'Invalid email.' ], 400 );
		}
		wp_send_json_success( self::compute_ltv( $email ) );
	}

	public static function compute_ltv( string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.id, t.transfer_ref, t.status, t.order_id, t.created_at, t.dealer_received_date,
			        t.item_description, t.item_make, t.item_model,
			        d.business_name AS dealer_name
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.customer_email = %s
			 ORDER BY t.created_at DESC LIMIT 50',
			$email
		), ARRAY_A );

		if ( empty( $rows ) ) {
			return [ 'found' => false ];
		}

		// Lifetime value — sum order totals for the related WC orders. An
		// order can now own several transfer rows (one per FFL unit, see
		// Checkout::get_ffl_items()), so each order_id is counted at most
		// once here or a multi-firearm order would inflate the total.
		$ltv            = 0.0;
		$pickup_days    = [];
		$counted_orders = [];
		foreach ( $rows as &$r ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $r['order_id'] ) : null;
			if ( $order && ! isset( $counted_orders[ $r['order_id'] ] ) ) {
				$counted_orders[ $r['order_id'] ] = true;
				$ltv += (float) $order->get_total();
			}
			if ( ! empty( $r['dealer_received_date'] ) && ! empty( $r['created_at'] ) ) {
				$days = ( strtotime( $r['dealer_received_date'] ) - strtotime( $r['created_at'] ) ) / DAY_IN_SECONDS;
				if ( $days >= 0 ) {
					$pickup_days[] = $days;
				}
			}
			$r['order_url'] = $order ? $order->get_edit_order_url() : '';
		}
		unset( $r );

		return [
			'found'             => true,
			'total_transfers'   => count( $rows ),
			'lifetime_value'    => round( $ltv, 2 ),
			'avg_pickup_days'   => $pickup_days ? round( array_sum( $pickup_days ) / count( $pickup_days ), 1 ) : null,
			'last_dealer'       => $rows[0]['dealer_name'] ?? null,
			'transfers'         => $rows,
		];
	}

	// ── REST ──────────────────────────────────────────────────

	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/customer/ltv', [
			'methods'             => 'GET',
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_woocommerce' );
			},
			'callback' => function ( \WP_REST_Request $r ): \WP_REST_Response {
				return rest_ensure_response( self::compute_ltv( sanitize_email( (string) $r->get_param( 'email' ) ) ) );
			},
			'args' => [ 'email' => [ 'required' => true, 'sanitize_callback' => 'sanitize_email' ] ],
		] );
	}

	// ── Dealer health nightly sweep ─────────────────────────────

	public static function run_health_check(): void {
		global $wpdb;
		// Last 90 days of transfers grouped by dealer.
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 90 * DAY_IN_SECONDS ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT t.dealer_id,
			        COUNT(*) AS total,
			        SUM( CASE WHEN t.status = 'issue_reported' THEN 1 ELSE 0 END ) AS issues
			 FROM " . DB::table( 'transfers' ) . " t
			 WHERE t.dealer_id > 0 AND t.created_at >= %s
			 GROUP BY t.dealer_id
			 HAVING total >= %d",
			$cutoff, self::HEALTH_MIN_TRANSFERS
		) );

		$flags = [];
		foreach ( (array) $rows as $row ) {
			$ratio = $row->total > 0 ? ( (int) $row->issues / (int) $row->total ) : 0;
			if ( $ratio < self::HEALTH_ISSUE_RATIO ) {
				continue;
			}
			$dealer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT business_name, license_number FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d',
				(int) $row->dealer_id
			) );
			$flags[] = [
				'dealer_id'      => (int) $row->dealer_id,
				'business_name'  => $dealer->business_name ?? '?',
				'license_number' => $dealer->license_number ?? '',
				'total'          => (int) $row->total,
				'issues'         => (int) $row->issues,
				'ratio'          => round( $ratio, 4 ),
				'at'             => current_time( 'mysql' ),
			];
		}
		update_option( 'wpistic_ffl_dealer_health_flags', $flags, false );

		// Email admin if there are any new flags
		$prev_count = (int) get_option( 'wpistic_ffl_dealer_health_count', 0 );
		if ( count( $flags ) > $prev_count && ! empty( $flags ) ) {
			$admin = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );
			$body  = '<p>The following dealers have an elevated issue-reported rate over the last 90 days:</p><ul>';
			foreach ( $flags as $f ) {
				$body .= '<li><strong>' . esc_html( $f['business_name'] ) . '</strong> · ' . esc_html( $f['license_number'] )
					. ' — ' . (int) $f['issues'] . ' issues / ' . (int) $f['total'] . ' transfers ('
					. number_format( $f['ratio'] * 100, 1 ) . '%)</li>';
			}
			$body .= '</ul><p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-ops' ) ) . '">Open Ops Tools</a></p>';
			wp_mail( $admin, '[G2A] ⚠ Dealer health alert', $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
		}
		update_option( 'wpistic_ffl_dealer_health_count', count( $flags ), false );
	}

	// ── Helpers ───────────────────────────────────────────────────

	private static function us_states(): array {
		return [
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
			'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
			'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
			'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
			'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan',
			'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana',
			'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
			'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota',
			'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
			'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
			'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia',
			'WI' => 'Wisconsin', 'WY' => 'Wyoming',
		];
	}
}
