<?php
/**
 * G2A — Activity log surface for the admin dashboard.
 *
 * Reads from the existing append-only `events` and `analytics_events` tables
 * — both already populated by Checkout, Portal, Status_Bridge, NICS, Mailer
 * — and presents them as a unified feed. Also exposes REST JSON endpoints
 * so the standalone G2A dashboard app (app.guns2ammo.com) can subscribe in
 * real time.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Activity {

	public function __construct() {
		add_action( 'admin_menu',     [ $this, 'register_admin_page' ], 20 );
		add_action( 'rest_api_init',  [ $this, 'register_routes' ] );
	}

	// ── Canonical labels reused across email, portal, account, tracking ─────

	public static function status_labels(): array {
		return [
			'payment_confirmed'  => __( 'Payment Confirmed', 'advanced-ffl-checkout' ),
			'dealer_selected'    => __( 'Dealer Selected', 'advanced-ffl-checkout' ),
			'shipped_to_dealer'  => __( 'Shipped to Dealer', 'advanced-ffl-checkout' ),
			'shipped'            => __( 'Shipped to Dealer', 'advanced-ffl-checkout' ),
			'received_by_dealer' => __( 'Received by Dealer', 'advanced-ffl-checkout' ),
			'dealer_received'    => __( 'Received by Dealer', 'advanced-ffl-checkout' ),
			'background_check'   => __( 'Background Check', 'advanced-ffl-checkout' ),
			'nics_pending'       => __( 'Background Check', 'advanced-ffl-checkout' ),
			'delayed'            => __( 'NICS Delayed', 'advanced-ffl-checkout' ),
			'nics_delayed'       => __( 'NICS Delayed', 'advanced-ffl-checkout' ),
			'approved'           => __( 'NICS Approved', 'advanced-ffl-checkout' ),
			'denied'             => __( 'NICS Denied', 'advanced-ffl-checkout' ),
			'nics_denied'        => __( 'NICS Denied', 'advanced-ffl-checkout' ),
			'transferred'        => __( 'Transfer Complete', 'advanced-ffl-checkout' ),
			'cancelled'          => __( 'Cancelled', 'advanced-ffl-checkout' ),
			'issue_reported'     => __( 'Issue Reported', 'advanced-ffl-checkout' ),
		];
	}

	// ── Admin menu page ─────────────────────────────────────────────────────

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Activity Log', 'advanced-ffl-checkout' ),
			__( '📋 Activity Log', 'advanced-ffl-checkout' ),
			'manage_woocommerce',
			'wpistic-ffl-activity',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		$activity = self::feed( [ 'per_page' => 100 ] );
		$labels   = self::status_labels();
		?>
		<div class="wrap wpistic-ffl-activity">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">📋</span>
				<?php esc_html_e( 'FFL Activity Log', 'advanced-ffl-checkout' ); ?>
				<span style="margin-left:auto;font-size:12px;color:#666;font-weight:400;">
					<?php
					printf(
						/* translators: %d = count */
						esc_html__( 'Showing %d most recent events', 'advanced-ffl-checkout' ),
						count( $activity['items'] )
					);
					?>
				</span>
			</h1>

			<p class="description">
				<?php esc_html_e( 'Every status change, dealer confirmation, NICS update, and customer email is logged here. The same data is exposed at the JSON endpoints below for the G2A dashboard app.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 20px;">
				<a class="button" href="<?php echo esc_url( rest_url( WPISTIC_FFL_REST_NS . '/activity' ) ); ?>" target="_blank">
					<?php esc_html_e( '/activity (JSON)', 'advanced-ffl-checkout' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( rest_url( WPISTIC_FFL_REST_NS . '/activity/summary' ) ); ?>" target="_blank">
					<?php esc_html_e( '/activity/summary (JSON)', 'advanced-ffl-checkout' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers' ) ); ?>">
					<?php esc_html_e( 'Open Transfers', 'advanced-ffl-checkout' ); ?>
				</a>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:170px;"><?php esc_html_e( 'When', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Event', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Transfer', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'advanced-ffl-checkout' ); ?></th>
						<th><?php esc_html_e( 'Notes', 'advanced-ffl-checkout' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $activity['items'] ) ) : ?>
					<tr><td colspan="5"><em><?php esc_html_e( 'No activity yet — events will appear here as orders and statuses move.', 'advanced-ffl-checkout' ); ?></em></td></tr>
				<?php else : foreach ( $activity['items'] as $item ) : ?>
					<tr>
						<td>
							<strong><?php echo esc_html( human_time_diff( strtotime( $item['created_at'] ), current_time( 'timestamp' ) ) ); ?> ago</strong><br>
							<span style="color:#666;font-size:11px;"><?php echo esc_html( $item['created_at'] ); ?></span>
						</td>
						<td>
							<strong><?php echo esc_html( self::event_label( $item['event_type'], $item['new_status'] ?? '' ) ); ?></strong>
							<?php if ( ! empty( $item['old_status'] ) || ! empty( $item['new_status'] ) ) : ?>
								<br>
								<span style="color:#666;font-size:11px;">
									<?php
									if ( ! empty( $item['old_status'] ) ) {
										echo esc_html( $labels[ $item['old_status'] ] ?? $item['old_status'] );
										echo ' → ';
									}
									echo esc_html( $labels[ $item['new_status'] ?? '' ] ?? ( $item['new_status'] ?? '' ) );
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( ! empty( $item['transfer_ref'] ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . (int) $item['transfer_id'] ) ); ?>" style="font-family:ui-monospace,monospace;">
									#<?php echo esc_html( $item['transfer_ref'] ); ?>
								</a>
							<?php elseif ( ! empty( $item['transfer_id'] ) ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . (int) $item['transfer_id'] ) ); ?>">
									#<?php echo esc_html( (string) $item['transfer_id'] ); ?>
								</a>
							<?php else : ?>
								<span style="color:#999;">—</span>
							<?php endif; ?>
						</td>
						<td><code style="font-size:11px;"><?php echo esc_html( $item['actor'] ?? 'system' ); ?></code></td>
						<td style="font-size:12px;color:#444;">
							<?php echo esc_html( wp_trim_words( (string) ( $item['notes'] ?? '' ), 14 ) ); ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ── Data: shared between admin page + REST endpoints ────────────────────

	/**
	 * Pull a merged activity feed from `events` (status changes, by transfer)
	 * and `analytics_events` (portal funnel, by token). Items are normalized
	 * into a uniform shape so the admin table and the JSON endpoint render
	 * identically.
	 *
	 * @param array $args ['per_page' => int, 'event_type' => string|null]
	 * @return array{items: array<int,array>, total: int, generated_at: string}
	 */
	public static function feed( array $args = [] ): array {
		global $wpdb;
		$per_page    = max( 1, min( 500, (int) ( $args['per_page'] ?? 50 ) ) );
		$transfer_id = isset( $args['transfer_id'] ) ? (int) $args['transfer_id'] : 0;
		$event_type  = isset( $args['event_type'] ) ? sanitize_key( (string) $args['event_type'] ) : '';

		// Use UNION ALL so a single ORDER BY can sort cross-table. SELECTs are
		// kept column-aligned to keep the union shape stable.
		$transfers_tbl = DB::table( 'transfers' );
		$events_tbl    = DB::table( 'events' );
		$analytics_tbl = DB::table( 'analytics_events' );

		$where_events    = $transfer_id ? $wpdb->prepare( 'WHERE e.transfer_id = %d', $transfer_id ) : '';
		$where_analytics = $transfer_id ? $wpdb->prepare( 'WHERE a.transfer_id = %d', $transfer_id ) : '';
		if ( $event_type ) {
			$where_events    .= ( $where_events ? ' AND ' : 'WHERE ' ) . $wpdb->prepare( 'e.event_type = %s', $event_type );
			$where_analytics .= ( $where_analytics ? ' AND ' : 'WHERE ' ) . $wpdb->prepare( 'a.event_type = %s', $event_type );
		}

		// phpcs:disable WordPress.DB.PreparedSQL,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare(
			"(
				SELECT e.id, e.transfer_id, t.transfer_ref, 'events' AS src,
				       e.event_type, e.old_status, e.new_status, e.notes,
				       e.actor, e.actor_ip, e.created_at
				FROM {$events_tbl} e
				LEFT JOIN {$transfers_tbl} t ON t.id = e.transfer_id
				{$where_events}
			)
			UNION ALL
			(
				SELECT a.id, a.transfer_id, t.transfer_ref, 'analytics' AS src,
				       a.event_type, NULL AS old_status, NULL AS new_status, a.metadata AS notes,
				       'portal' AS actor, NULL AS actor_ip, a.created_at
				FROM {$analytics_tbl} a
				LEFT JOIN {$transfers_tbl} t ON t.id = a.transfer_id
				{$where_analytics}
			)
			ORDER BY created_at DESC LIMIT %d",
			$per_page
		), ARRAY_A );
		// phpcs:enable

		return [
			'items'        => (array) $rows,
			'total'        => count( (array) $rows ),
			'generated_at' => gmdate( 'c' ),
		];
	}

	/**
	 * Aggregate counts per status + event_type for a dashboard sparkline.
	 */
	public static function summary( int $days = 30 ): array {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );

		$events_tbl    = DB::table( 'events' );
		$analytics_tbl = DB::table( 'analytics_events' );
		$transfers_tbl = DB::table( 'transfers' );

		$by_status = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT status, COUNT(*) AS cnt FROM {$transfers_tbl}
			 WHERE created_at >= %s
			 GROUP BY status",
			$since
		), ARRAY_A );

		$by_event_type = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT event_type, COUNT(*) AS cnt FROM {$events_tbl}
			 WHERE created_at >= %s
			 GROUP BY event_type",
			$since
		), ARRAY_A );

		$analytics_funnel = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT event_type, COUNT(*) AS cnt FROM {$analytics_tbl}
			 WHERE created_at >= %s
			 GROUP BY event_type",
			$since
		), ARRAY_A );

		$daily = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DATE(created_at) AS d, COUNT(*) AS cnt FROM {$transfers_tbl}
			 WHERE created_at >= %s
			 GROUP BY DATE(created_at)
			 ORDER BY d ASC",
			$since
		), ARRAY_A );

		return [
			'period_days'      => $days,
			'since'            => $since,
			'by_status'        => $by_status,
			'by_event_type'    => $by_event_type,
			'analytics_funnel' => $analytics_funnel,
			'daily'            => $daily,
			'generated_at'     => gmdate( 'c' ),
		];
	}

	// ── REST endpoints ──────────────────────────────────────────────────────

	public function register_routes(): void {
		$ns = WPISTIC_FFL_REST_NS;

		register_rest_route( $ns, '/activity', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'require_staff' ],
			'callback'            => [ $this, 'rest_feed' ],
			'args'                => [
				'per_page'    => [ 'default' => 50, 'sanitize_callback' => 'absint' ],
				'transfer_id' => [ 'default' => 0,  'sanitize_callback' => 'absint' ],
				'event_type'  => [ 'default' => '', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( $ns, '/activity/summary', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'require_staff' ],
			'callback'            => [ $this, 'rest_summary' ],
			'args'                => [
				'days' => [ 'default' => 30, 'sanitize_callback' => 'absint' ],
			],
		] );

		// Detailed JSON for a single transfer including its full event timeline.
		register_rest_route( $ns, '/transfers/(?P<id>\d+)/details', [
			'methods'             => 'GET',
			'permission_callback' => [ $this, 'require_staff' ],
			'callback'            => [ $this, 'rest_transfer_details' ],
		] );
	}

	public function require_staff(): bool {
		return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
	}

	public function rest_feed( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( self::feed( [
			'per_page'    => (int) $req->get_param( 'per_page' ),
			'transfer_id' => (int) $req->get_param( 'transfer_id' ),
			'event_type'  => (string) $req->get_param( 'event_type' ),
		] ) );
	}

	public function rest_summary( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( self::summary( max( 1, (int) $req->get_param( 'days' ) ) ) );
	}

	public function rest_transfer_details( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;
		$id = (int) $req->get_param( 'id' );

		$transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license,
			        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip,
			        d.phone AS dealer_phone, d.email AS dealer_email
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d',
			$id
		), ARRAY_A );

		if ( ! $transfer ) {
			return new \WP_Error( 'not_found', 'Transfer not found.', [ 'status' => 404 ] );
		}

		$feed = self::feed( [ 'transfer_id' => $id, 'per_page' => 200 ] );

		return rest_ensure_response( [
			'transfer'   => $transfer,
			'activity'   => $feed['items'],
			'tracking_url' => G2A_Customer_Tracking::url_for( (string) $transfer['transfer_ref'] ),
			'generated_at' => gmdate( 'c' ),
		] );
	}

	// ── Display helpers ─────────────────────────────────────────────────────

	private static function event_label( string $event_type, string $new_status = '' ): string {
		$map = [
			'created'             => __( 'Transfer Created', 'advanced-ffl-checkout' ),
			'status_change'       => __( 'Status Changed', 'advanced-ffl-checkout' ),
			'nics_3day_reached'   => __( 'NICS 3-Day Window Reached', 'advanced-ffl-checkout' ),
			'token_issued'        => __( 'Dealer Token Issued', 'advanced-ffl-checkout' ),
			'email_sent'          => __( 'Email Sent to Dealer', 'advanced-ffl-checkout' ),
			'reminder_sent'       => __( 'Reminder Sent', 'advanced-ffl-checkout' ),
			'portal_viewed'       => __( 'Dealer Opened Portal', 'advanced-ffl-checkout' ),
			'confirmed'           => __( 'Dealer Confirmed Receipt', 'advanced-ffl-checkout' ),
			'report_issue'        => __( 'Dealer Reported Issue', 'advanced-ffl-checkout' ),
			'not_my_shipment'     => __( 'Dealer Rejected Shipment', 'advanced-ffl-checkout' ),
			'two_factor_failed'   => __( 'Dealer 2FA Failed', 'advanced-ffl-checkout' ),
			'token_expired'       => __( 'Tokens Expired (cleanup)', 'advanced-ffl-checkout' ),
			'public_track_viewed' => __( 'Customer Viewed Tracking', 'advanced-ffl-checkout' ),
		];
		return $map[ $event_type ] ?? ucwords( str_replace( '_', ' ', $event_type ) );
	}
}
