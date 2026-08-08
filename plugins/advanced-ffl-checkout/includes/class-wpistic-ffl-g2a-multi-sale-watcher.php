<?php
/**
 * G2A — Multiple-sale (Form 3310.4) watcher.
 *
 * Federal law (18 U.S.C. § 923(g)(3)(A), 27 CFR 478.126a) requires an FFL to
 * report to ATF — and to the local chief law enforcement officer — whenever
 * a non-licensee buys 2 or more pistols and/or revolvers within any 5
 * consecutive business days. Nothing in the checkout or transfer flow
 * previously looked across a buyer's purchase history for this; the
 * multi-sale trigger was invisible to the system and depended entirely on
 * staff memory.
 *
 * This class detects the pattern using data the plugin already stores
 * (customer identity, item type, transfer date) and raises a flag for staff
 * review — it never files anything with ATF itself. Filing Form 3310.4
 * stays a logged, manual staff action, same as every other compliance
 * decision in this plugin.
 *
 * Known limitation (documented, not silently glossed over): a WooCommerce
 * order with two handguns in a single cart currently produces only ONE
 * `transfers` row — Checkout::create_transfer_on_payment() only records the
 * first FFL line item it finds. This watcher correctly catches the common
 * real-world case (repeat purchases across separate orders/visits), but a
 * single order containing 2+ handguns will under-count until the checkout
 * flow is extended to create one transfer per firearm line item, which is a
 * separate, larger change outside this feature's scope.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Multi_Sale_Watcher {

	/** Federal multiple-sale reporting window. */
	const WINDOW_BUSINESS_DAYS = 5;

	public function __construct() {
		add_action( 'wpistic_ffl_transfer_created', [ __CLASS__, 'check_multi_sale' ], 10, 2 );
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 44 );
		add_action( 'wp_ajax_wpistic_ffl_multi_sale_mark_filed', [ __CLASS__, 'ajax_mark_filed' ] );
		add_action( 'wp_ajax_wpistic_ffl_multi_sale_dismiss',    [ __CLASS__, 'ajax_dismiss' ] );
	}

	// ── Detection ─────────────────────────────────────────────────

	public static function check_multi_sale( int $transfer_id, int $order_id ): void {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id, transfer_ref, customer_id, customer_email, customer_name, item_type, created_at
			 FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d',
			$transfer_id
		) );
		if ( ! $t || 'handgun' !== self::canonical_item_type( (string) $t->item_type ) ) {
			return; // Federal Form 3310.4 trigger is pistols/revolvers only, not long guns.
		}
		if ( '' === (string) $t->customer_email && ! $t->customer_id ) {
			return;
		}

		$window_start = self::n_business_days_ago( self::WINDOW_BUSINESS_DAYS, $t->created_at );

		if ( $t->customer_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT id, transfer_ref, created_at FROM " . DB::table( 'transfers' ) . "
				 WHERE item_type = 'handgun' AND customer_id = %d AND created_at >= %s
				 ORDER BY created_at ASC",
				$t->customer_id, $window_start
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT id, transfer_ref, created_at FROM " . DB::table( 'transfers' ) . "
				 WHERE item_type = 'handgun' AND customer_id IS NULL AND customer_email = %s AND created_at >= %s
				 ORDER BY created_at ASC",
				$t->customer_email, $window_start
			) );
		}

		if ( count( $rows ) < 2 ) {
			return; // This is the buyer's only qualifying handgun purchase in the window so far.
		}

		self::raise_flag( $t, $rows );
	}

	private static function raise_flag( object $t, array $rows ): void {
		global $wpdb;
		$ids = wp_list_pluck( $rows, 'id' );
		sort( $ids );
		$ids_key = implode( ',', $ids );

		// Don't re-flag (and re-email) the identical group of transfers twice.
		$existing = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT id FROM ' . DB::table( 'multi_sale_flags' ) . " WHERE transfer_ids = %s AND status != 'dismissed'",
			$ids_key
		) );
		if ( $existing ) {
			return;
		}

		$dates       = wp_list_pluck( $rows, 'created_at' );
		$window_start = date( 'Y-m-d', strtotime( min( $dates ) ) );
		$window_end   = date( 'Y-m-d', strtotime( max( $dates ) ) );

		$wpdb->insert( DB::table( 'multi_sale_flags' ), [ // phpcs:ignore WordPress.DB
			'customer_email' => (string) $t->customer_email,
			'customer_id'    => $t->customer_id ?: null,
			'transfer_ids'   => $ids_key,
			'window_start'   => $window_start,
			'window_end'     => $window_end,
			'status'         => 'pending_review',
		] );

		foreach ( $ids as $tid ) {
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
				'transfer_id' => (int) $tid,
				'event_type'  => 'multi_sale_flagged',
				'notes'       => sprintf( 'Possible federal multiple-sale (Form 3310.4) trigger — %d handgun transfers for this buyer within %d business days.', count( $ids ), self::WINDOW_BUSINESS_DAYS ),
				'actor'       => 'system',
				'actor_ip'    => '',
			] );
		}

		// This has a same-day filing deadline (close of business on the day of
		// the multiple sale) — unlike the NICS 3-day nightly sweep, an
		// immediate alert is the correct urgency here, not a batched one.
		$admin_email = get_option( 'wpistic_ffl_settings', [] )['notification_email'] ?? get_option( 'admin_email' );
		$refs        = wp_list_pluck( $rows, 'transfer_ref' );
		$subject     = sprintf( '[G2A] ⚠️ Possible Multiple-Sale (Form 3310.4) — %s', $t->customer_name );
		$body        = '<p><strong>' . count( $ids ) . '</strong> handgun transfers were detected for the same buyer within a rolling ' . self::WINDOW_BUSINESS_DAYS . '-business-day window.</p>'
			. '<p><strong>Buyer:</strong> ' . esc_html( $t->customer_name ) . ' (' . esc_html( $t->customer_email ) . ')<br>'
			. '<strong>Transfers:</strong> #' . esc_html( implode( ', #', $refs ) ) . '<br>'
			. '<strong>Window:</strong> ' . esc_html( $window_start ) . ' to ' . esc_html( $window_end ) . '</p>'
			. '<p>Federal law (18 U.S.C. § 923(g)(3)(A)) requires ATF Form 3310.4 to be filed with ATF and the local chief law enforcement officer no later than the close of business on the day of the multiple sale. This is a system-detected match for staff review — verify against the physical Form 4473(s) before filing; this plugin does not file anything automatically.</p>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-multi-sale' ) ) . '">Review in Multi-Sale Watch</a></p>';
		wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	/** Map any legacy/loose item_type string to the canonical handgun/long-gun split. */
	public static function canonical_item_type( string $item_type ): string {
		$item_type = strtolower( trim( $item_type ) );
		if ( in_array( $item_type, [ 'handgun', 'pistol', 'revolver' ], true ) ) {
			return 'handgun';
		}
		return 'long_gun';
	}

	/** Walk backward N business days (Mon-Fri, excluding federal holidays) from $from_datetime. Same math as G2A_NICS. */
	private static function n_business_days_ago( int $n, string $from_datetime ): string {
		$ts    = strtotime( $from_datetime ) ?: current_time( 'timestamp' );
		$added = 0;
		while ( $added < $n ) {
			$ts  = strtotime( '-1 day', $ts );
			$dow = (int) date( 'N', $ts );
			if ( $dow < 6 && ! G2A_NICS::is_federal_holiday( date( 'Y-m-d', $ts ) ) ) {
				$added++;
			}
		}
		return date( 'Y-m-d 00:00:00', $ts );
	}

	// ── Admin page ────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Multi-Sale Watch', 'advanced-ffl-checkout' ),
			__( '⚠️ Multi-Sale Watch', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-multi-sale',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$flags = $wpdb->get_results( // phpcs:ignore WordPress.DB
			'SELECT * FROM ' . DB::table( 'multi_sale_flags' ) . " ORDER BY FIELD(status,'pending_review','filed','dismissed'), created_at DESC LIMIT 100"
		);
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">⚠️</span>
				<?php esc_html_e( 'Multiple-Sale (Form 3310.4) Watch', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:820px;">
				<?php esc_html_e( 'Federal law requires reporting to ATF (and local law enforcement) whenever a buyer purchases 2+ handguns within any 5 consecutive business days. This page lists system-detected matches for staff review — nothing here is filed with ATF automatically. Verify against the physical Form 4473(s), file Form 3310.4 through your normal process, then mark it filed here.', 'advanced-ffl-checkout' ); ?>
			</p>

			<table class="widefat striped" style="margin-top:16px;">
				<thead><tr>
					<th><?php esc_html_e( 'Buyer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Transfers', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Window', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Action', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $flags ) ) : ?>
					<tr><td colspan="5"><em><?php esc_html_e( 'No multiple-sale patterns detected.', 'advanced-ffl-checkout' ); ?></em></td></tr>
				<?php else : foreach ( $flags as $f ) :
					$ids = array_filter( array_map( 'intval', explode( ',', $f->transfer_ids ) ) );
					?>
					<tr data-flag-id="<?php echo esc_attr( $f->id ); ?>">
						<td><?php echo esc_html( $f->customer_email ); ?></td>
						<td>
							<?php foreach ( $ids as $i => $tid ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . $tid ) ); ?>">#<?php echo esc_html( (string) $tid ); ?></a><?php echo $i < count( $ids ) - 1 ? ', ' : ''; ?>
							<?php endforeach; ?>
						</td>
						<td><?php echo esc_html( $f->window_start . ' — ' . $f->window_end ); ?></td>
						<td>
							<?php
							$badge = [ 'pending_review' => [ '#F59E0B', 'Needs Review' ], 'filed' => [ '#16A34A', 'Filed' ], 'dismissed' => [ '#888', 'Dismissed' ] ];
							[ $color, $label ] = $badge[ $f->status ] ?? [ '#888', $f->status ];
							?>
							<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?php echo esc_attr( $color ); ?>;color:#fff;font-size:11px;font-weight:700;"><?php echo esc_html( $label ); ?></span>
						</td>
						<td>
							<?php if ( 'pending_review' === $f->status ) : ?>
								<button type="button" class="button button-primary wpistic-ffl-ms-file"><?php esc_html_e( 'Mark Filed', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button wpistic-ffl-ms-dismiss"><?php esc_html_e( 'Dismiss', 'advanced-ffl-checkout' ); ?></button>
							<?php elseif ( 'filed' === $f->status ) : ?>
								<span style="color:#666;font-size:12px;"><?php echo esc_html( $f->filed_at ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<script>
		(function(){
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;
			function post(action, id, cb) {
				var fd = new FormData();
				fd.append('action', action);
				fd.append('nonce', nonce);
				fd.append('flag_id', id);
				fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(cb);
			}
			document.querySelectorAll('.wpistic-ffl-ms-file').forEach(function(btn){
				btn.addEventListener('click', function(){
					var tr = btn.closest('tr');
					post('wpistic_ffl_multi_sale_mark_filed', tr.getAttribute('data-flag-id'), function(j){
						if (j && j.success) { location.reload(); }
					});
				});
			});
			document.querySelectorAll('.wpistic-ffl-ms-dismiss').forEach(function(btn){
				btn.addEventListener('click', function(){
					if (! confirm('<?php echo esc_js( __( 'Dismiss this flag as a false positive?', 'advanced-ffl-checkout' ) ); ?>')) return;
					var tr = btn.closest('tr');
					post('wpistic_ffl_multi_sale_dismiss', tr.getAttribute('data-flag-id'), function(j){
						if (j && j.success) { location.reload(); }
					});
				});
			});
		})();
		</script>
		<?php
	}

	public static function ajax_mark_filed(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$id = (int) ( $_POST['flag_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing flag.' ], 400 );
		}
		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->update( DB::table( 'multi_sale_flags' ), [ // phpcs:ignore WordPress.DB
			'status'     => 'filed',
			'filed_at'   => current_time( 'mysql' ),
			'filed_by'   => $user->ID ?: null,
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		wp_send_json_success();
	}

	public static function ajax_dismiss(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		$id = (int) ( $_POST['flag_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id ) {
			wp_send_json_error( [ 'message' => 'Missing flag.' ], 400 );
		}
		global $wpdb;
		$wpdb->update( DB::table( 'multi_sale_flags' ), [ // phpcs:ignore WordPress.DB
			'status'     => 'dismissed',
			'updated_at' => current_time( 'mysql' ),
		], [ 'id' => $id ] );
		wp_send_json_success();
	}
}
