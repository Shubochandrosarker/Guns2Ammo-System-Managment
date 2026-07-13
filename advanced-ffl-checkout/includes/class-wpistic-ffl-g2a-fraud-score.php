<?php
/**
 * G2A — Buyer-side fraud / straw-purchase risk scoring (gap #13).
 *
 * There's no fraud-detection vendor wired in here — no credentials to test
 * one against, same honesty rationale as the NICS and ID-verification
 * provider registries elsewhere in this plugin. Instead this is a small,
 * transparent, rules-based scorer built entirely from data this plugin
 * already has: order/customer velocity, dealer-state mismatch (an
 * ATF-documented straw-purchase indicator — an out-of-state dealer
 * selection), disposable-email domains, first-time-buyer + high-value
 * orders, rapid dealer-switching, and correlation with the existing
 * Form 3310.4 multi-sale watcher.
 *
 * Every weight and threshold is filterable so a store can tune it to their
 * own risk tolerance. This NEVER blocks, cancels, or holds a transfer —
 * same "recommendation for a logged staff decision" rule as every other
 * compliance check in this plugin (see STATUS.md §7). A high score just
 * puts the transfer in a review queue and (above the review threshold)
 * emails the admin once.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Fraud_Score {

	/** Well-known disposable/temporary email domains. Filterable — not exhaustive by design. */
	const DEFAULT_DISPOSABLE_DOMAINS = [
		'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', '10minutemail.com',
		'tempmail.com', 'temp-mail.org', 'throwawaymail.com', 'yopmail.com', 'trashmail.com',
		'getnada.com', 'sharklasers.com', 'dispostable.com', 'fakeinbox.com', 'mailnesia.com',
	];

	public function __construct() {
		// Priority 25: after the multi-sale watcher (10) and rate-shop (20),
		// so a multi-sale flag just created in this same request is already
		// visible to the correlation signal below.
		add_action( 'wpistic_ffl_transfer_created', [ __CLASS__, 'score_transfer' ], 25, 2 );
		add_action( 'admin_menu', [ $this, 'register_admin_page' ], 48 );
		add_action( 'wp_ajax_wpistic_ffl_fraud_review', [ __CLASS__, 'ajax_review' ] );
	}

	// ── Scoring ───────────────────────────────────────────────────────────

	public static function score_transfer( int $transfer_id, int $order_id ): void {
		global $wpdb;
		$t = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d', $transfer_id
		) );
		if ( ! $t ) {
			return;
		}

		$weights = apply_filters( 'wpistic_ffl_fraud_score_weights', [
			'email_velocity'    => 30,
			'ip_velocity'       => 25,
			'first_time_high_value' => 20,
			'dealer_state_mismatch' => 15,
			'disposable_email'  => 25,
			'multi_sale_flag'   => 30,
			'dealer_switching'  => 20,
		] );

		$signals = [];

		// ── Velocity: 2+ transfers from the same email within 24h ──────────
		$since_24h = gmdate( 'Y-m-d H:i:s', strtotime( $t->created_at ) - DAY_IN_SECONDS );
		if ( $t->customer_email ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . '
				 WHERE customer_email = %s AND id != %d AND created_at >= %s',
				$t->customer_email, $transfer_id, $since_24h
			) );
			if ( $count > 0 ) {
				$signals['email_velocity'] = sprintf( '%d other transfer(s) from this email in the last 24h.', $count );
			}
		}

		// ── Velocity: 2+ transfers from the same IP (different email) within 24h ──
		if ( $t->customer_ip ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . '
				 WHERE customer_ip = %s AND customer_email != %s AND id != %d AND created_at >= %s',
				$t->customer_ip, $t->customer_email, $transfer_id, $since_24h
			) );
			if ( $count > 0 ) {
				$signals['ip_velocity'] = sprintf( '%d other transfer(s) from the same IP under a different email in the last 24h.', $count );
			}
		}

		// ── First-time buyer + high-value order ─────────────────────────────
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( $order ) {
			$threshold = (float) apply_filters( 'wpistic_ffl_fraud_high_value_threshold', 1500.0 );
			$prior_orders = $t->customer_id
				? (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . ' WHERE customer_id = %d AND id != %d',
					(int) $t->customer_id, $transfer_id
				) )
				: 0;
			if ( 0 === $prior_orders && (float) $order->get_total() >= $threshold ) {
				$signals['first_time_high_value'] = sprintf( 'First transfer for this customer, order total $%s.', number_format( (float) $order->get_total(), 2 ) );
			}
		}

		// ── Dealer/buyer state mismatch (ATF-documented straw-purchase indicator) ──
		if ( $t->dealer_id && $order ) {
			$dealer_state = (string) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT state FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', (int) $t->dealer_id
			) );
			$buyer_state = method_exists( $order, 'get_billing_state' ) ? strtoupper( (string) $order->get_billing_state() ) : '';
			if ( $dealer_state && $buyer_state && strtoupper( $dealer_state ) !== $buyer_state ) {
				$signals['dealer_state_mismatch'] = sprintf( 'Dealer state %s does not match buyer billing state %s.', strtoupper( $dealer_state ), $buyer_state );
			}
		}

		// ── Disposable email domain ──────────────────────────────────────────
		$domain = $t->customer_email && false !== strpos( $t->customer_email, '@' )
			? strtolower( substr( $t->customer_email, strpos( $t->customer_email, '@' ) + 1 ) )
			: '';
		$disposable = (array) apply_filters( 'wpistic_ffl_fraud_disposable_email_domains', self::DEFAULT_DISPOSABLE_DOMAINS );
		if ( $domain && in_array( $domain, array_map( 'strtolower', $disposable ), true ) ) {
			$signals['disposable_email'] = sprintf( 'Email domain "%s" is a known disposable/temporary provider.', $domain );
		}

		// ── Correlation: buyer already has an open Form 3310.4 multi-sale flag ──
		if ( $t->customer_email ) {
			$flag = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT id FROM ' . DB::table( 'multi_sale_flags' ) . "
				 WHERE customer_email = %s AND status != 'filed' AND created_at >= %s
				 ORDER BY created_at DESC LIMIT 1",
				$t->customer_email, gmdate( 'Y-m-d H:i:s', strtotime( $t->created_at ) - 30 * DAY_IN_SECONDS )
			) );
			if ( $flag ) {
				$signals['multi_sale_flag'] = 'Buyer already has an open Form 3310.4 multiple-sale flag within the last 30 days.';
			}
		}

		// ── Rapid dealer-switching: 2+ distinct dealers for this buyer within 14 days ──
		if ( $t->customer_email ) {
			$distinct_dealers = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'SELECT COUNT(DISTINCT dealer_id) FROM ' . DB::table( 'transfers' ) . '
				 WHERE customer_email = %s AND dealer_id > 0 AND created_at >= %s',
				$t->customer_email, gmdate( 'Y-m-d H:i:s', strtotime( $t->created_at ) - 14 * DAY_IN_SECONDS )
			) );
			if ( $distinct_dealers >= 2 ) {
				$signals['dealer_switching'] = sprintf( 'Buyer used %d different dealers within the last 14 days.', $distinct_dealers );
			}
		}

		$score = 0;
		foreach ( array_keys( $signals ) as $key ) {
			$score += (int) ( $weights[ $key ] ?? 0 );
		}
		$score = (int) apply_filters( 'wpistic_ffl_fraud_score_final', $score, $signals, $transfer_id, $order_id );

		$review_threshold = (int) apply_filters( 'wpistic_ffl_fraud_score_threshold_review', 50 );
		$medium_threshold  = (int) apply_filters( 'wpistic_ffl_fraud_score_threshold_medium', 25 );
		$risk_level = $score >= $review_threshold ? 'high' : ( $score >= $medium_threshold ? 'medium' : 'low' );

		$wpdb->insert( DB::table( 'fraud_scores' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id'    => $transfer_id,
			'order_id'       => $order_id,
			'customer_email' => (string) $t->customer_email,
			'score'          => $score,
			'risk_level'     => $risk_level,
			'signals_json'   => wp_json_encode( $signals ),
			'status'         => 'open',
		] );

		if ( $signals ) {
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'transfer_id' => $transfer_id,
				'event_type'  => 'fraud_score_computed',
				'notes'       => sprintf( 'Fraud risk score %d (%s) — %s', $score, $risk_level, implode( ' ', $signals ) ),
				'actor'       => 'system',
				'actor_ip'    => '',
			] );
		}

		if ( $score >= $review_threshold ) {
			self::notify_admin( $t, $score, $signals );
		}
	}

	private static function notify_admin( object $t, int $score, array $signals ): void {
		$settings = get_option( 'wpistic_ffl_settings', [] );
		if ( ! empty( $settings['disable_emails'] ) ) {
			return;
		}
		$admin_email = $settings['notification_email'] ?? get_option( 'admin_email' );

		$subject = sprintf( '[G2A] 🚩 Fraud risk review — Transfer #%s (score %d)', $t->transfer_ref, $score );
		$body    = '<p>This transfer scored high enough on the buyer-risk model to warrant a manual look before it ships. This is a recommendation only — nothing has been held or cancelled.</p>'
			. '<p><strong>Transfer:</strong> #' . esc_html( $t->transfer_ref ) . '<br>'
			. '<strong>Customer:</strong> ' . esc_html( $t->customer_name ) . ' (' . esc_html( $t->customer_email ) . ')<br>'
			. '<strong>Score:</strong> ' . (int) $score . '</p>'
			. '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $signals ) ) . '</li></ul>'
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-fraud-review' ) ) . '">Open the Fraud Review queue</a></p>';

		wp_mail( $admin_email, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );
	}

	// ── Admin review queue ────────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Fraud Review', 'advanced-ffl-checkout' ),
			__( '🚩 Fraud Review', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-fraud-review',
			[ $this, 'render_admin_page' ]
		);
	}

	public static function ajax_review(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$id     = (int) ( $_POST['id'] ?? 0 );
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) );
		$notes  = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
		// phpcs:enable
		if ( ! in_array( $status, [ 'cleared', 'confirmed_fraud', 'escalated' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid status.' ], 400 );
		}

		global $wpdb;
		$user = wp_get_current_user();
		$wpdb->update( DB::table( 'fraud_scores' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'status'       => $status,
			'reviewed_by'  => $user->ID ?: null,
			'reviewed_at'  => current_time( 'mysql' ),
			'review_notes' => $notes,
		], [ 'id' => $id ] );

		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT transfer_id FROM ' . DB::table( 'fraud_scores' ) . ' WHERE id = %d', $id
		) );
		if ( $row ) {
			$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'transfer_id' => (int) $row->transfer_id,
				'event_type'  => 'fraud_score_reviewed',
				'notes'       => 'Fraud review marked "' . $status . '" by staff.' . ( $notes ? ' Notes: ' . $notes : '' ),
				'actor'       => $user->user_login ?: 'staff',
				'actor_ip'    => Token::client_ip(),
			] );
		}

		wp_send_json_success();
	}

	public function render_admin_page(): void {
		global $wpdb;
		$nonce  = wp_create_nonce( 'wpistic_ffl_admin_nonce' );
		$status_filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$per_page = 25;

		$where  = 'WHERE 1=1';
		$params = [];
		if ( 'all' !== $status_filter ) {
			$where   .= ' AND f.status = %s';
			$params[] = $status_filter;
		}

		$sql_base = 'FROM ' . DB::table( 'fraud_scores' ) . ' f ' . $where;
		$total    = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) ' . $sql_base, $params ) ) : $wpdb->get_var( 'SELECT COUNT(*) ' . $sql_base ) ); // phpcs:ignore WordPress.DB
		$offset   = ( $paged - 1 ) * $per_page;
		$rows     = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT f.*, t.transfer_ref, t.customer_name, t.dealer_id
			 ' . $sql_base . ' ORDER BY f.score DESC, f.created_at DESC LIMIT %d OFFSET %d',
			array_merge( $params, [ $per_page, $offset ] )
		) );

		?>
		<div class="wrap wpistic-ffl-admin">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🚩</span>
				<?php esc_html_e( 'Fraud / Straw-Purchase Risk Review', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description" style="max-width:760px;">
				<?php esc_html_e( 'A rules-based risk score computed from this plugin\'s own data (velocity, dealer-state mismatch, disposable email, dealer-switching, correlation with the multiple-sale watcher) — not a fraud vendor. Every row here is a recommendation for staff review; nothing is ever held, cancelled, or denied automatically.', 'advanced-ffl-checkout' ); ?>
			</p>

			<p style="margin-top:12px;">
				<?php foreach ( [ 'open' => 'Open', 'all' => 'All', 'cleared' => 'Cleared', 'confirmed_fraud' => 'Confirmed Fraud', 'escalated' => 'Escalated' ] as $slug => $label ) : ?>
					<a class="button <?php echo $status_filter === $slug ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( [ 'status' => $slug, 'paged' => 1 ] ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>

			<table class="widefat striped" style="margin-top:12px;">
				<thead><tr>
					<th><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Score', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Signals', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Status', 'advanced-ffl-checkout' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'advanced-ffl-checkout' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) :
					$signals = json_decode( (string) $r->signals_json, true ) ?: [];
					$badge   = [ 'high' => '#DC2626', 'medium' => '#D97706', 'low' => '#6B7280' ][ $r->risk_level ] ?? '#6B7280';
					?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&action=view&id=' . (int) $r->transfer_id ) ); ?>">#<?php echo esc_html( $r->transfer_ref ); ?></a></td>
						<td><?php echo esc_html( $r->customer_name ); ?><br><small><?php echo esc_html( $r->customer_email ); ?></small></td>
						<td><span style="color:<?php echo esc_attr( $badge ); ?>;font-weight:700;"><?php echo (int) $r->score; ?> (<?php echo esc_html( $r->risk_level ); ?>)</span></td>
						<td><?php echo esc_html( implode( ' · ', $signals ) ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $r->status ) ) ); ?></td>
						<td>
							<?php if ( 'open' === $r->status ) : ?>
								<button type="button" class="button button-small wpistic-ffl-fraud-act" data-id="<?php echo (int) $r->id; ?>" data-status="cleared"><?php esc_html_e( 'Clear', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button button-small wpistic-ffl-fraud-act" data-id="<?php echo (int) $r->id; ?>" data-status="confirmed_fraud"><?php esc_html_e( 'Confirm Fraud', 'advanced-ffl-checkout' ); ?></button>
								<button type="button" class="button button-small wpistic-ffl-fraud-act" data-id="<?php echo (int) $r->id; ?>" data-status="escalated"><?php esc_html_e( 'Escalate', 'advanced-ffl-checkout' ); ?></button>
							<?php else : ?>
								<em><?php echo esc_html( $r->review_notes ); ?></em>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'Nothing here.', 'advanced-ffl-checkout' ); ?></td></tr>
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
						'base'     => add_query_arg( 'paged', '%#%' ),
						'format'   => '',
						'current'  => $paged,
						'total'    => $total_pages,
					] ) );
					?>
				</div>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var pageNonce = <?php echo wp_json_encode( $nonce ); ?>;
			document.querySelectorAll('.wpistic-ffl-fraud-act').forEach(function(btn){
				btn.addEventListener('click', function(){
					var notes = '';
					if ('cleared' !== btn.dataset.status) {
						notes = window.prompt('Notes (optional):', '') || '';
					}
					btn.disabled = true;
					var fd = new FormData();
					fd.append('action', 'wpistic_ffl_fraud_review');
					fd.append('nonce', pageNonce);
					fd.append('id', btn.dataset.id);
					fd.append('status', btn.dataset.status);
					fd.append('notes', notes);
					fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
						.then(function(r){ return r.json(); })
						.then(function(j){
							if (j.success) { location.reload(); }
							else { btn.disabled = false; alert((j.data && j.data.message) || 'Failed'); }
						});
				});
			});
		})();
		</script>
		<?php
	}
}
