<?php
/**
 * REST API controller — namespace wpistic-ffl/v1
 *
 * Endpoints:
 *   GET  /dealers/search          — ZIP-radius dealer search (Haversine)
 *   GET  /dealers/{id}            — Single dealer detail
 *   GET  /dealers                 — Admin: paginated dealer list
 *   PUT  /dealers/{id}            — Admin: update dealer (fee, preferred, notes)
 *
 *   GET  /transfers               — Admin: paginated transfer list
 *   GET  /transfers/{id}          — Transfer detail with event log
 *   POST /transfers               — Create transfer (called from WC)
 *   PUT  /transfers/{id}/status   — Update transfer status + log event
 *   GET  /transfers/export        — CSV export for ATF audit
 *
 *   GET  /stats/dashboard         — Dashboard KPI summary
 *   GET  /stats/sync              — Import/sync status for admin progress bars
 *
 *   GET  /compliance/{state}      — State compliance rules
 *
 * Auth: accepts both WP cookie nonce AND JWT Bearer token.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class API {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes(): void {
		$ns = WPISTIC_FFL_REST_NS;

		// ── Dealer endpoints ─────────────────────────────────────────────────────
		register_rest_route( $ns, '/dealers/search', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'search_dealers' ],
			'permission_callback' => '__return_true', // Public — needed at checkout
			'args'                => [
				// All filters are optional in v1.1.1 — at least one must be provided.
				'zip'      => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'name'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'license'  => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'phone'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'state'    => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'city'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'radius'   => [ 'default' => 50, 'sanitize_callback' => 'absint' ],
				'limit'    => [ 'default' => 20, 'sanitize_callback' => 'absint' ],
				'type'     => [ 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				'include_no_geo' => [ 'default' => 1, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( $ns, '/dealers/(?P<id>\d+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_dealer' ],
				'permission_callback' => '__return_true',
				'args'                => [ 'id' => [ 'validate_callback' => 'is_numeric' ] ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_dealer' ],
				'permission_callback' => [ $this, 'require_manager' ],
			],
		] );

		register_rest_route( $ns, '/dealers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'list_dealers' ],
			'permission_callback' => [ $this, 'require_manager' ],
			'args'                => [
				'page'     => [ 'default' => 1,   'sanitize_callback' => 'absint' ],
				'per_page' => [ 'default' => 25,  'sanitize_callback' => 'absint' ],
				'search'   => [ 'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
				'state'    => [ 'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
				'type'     => [ 'default' => '',   'sanitize_callback' => 'sanitize_text_field' ],
			],
		] );

		// ── Transfer endpoints ──────────────────────────────────────────────────
		register_rest_route( $ns, '/transfers', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_transfers' ],
				'permission_callback' => [ $this, 'require_staff' ],
				'args'                => [
					'page'     => [ 'default' => 1,    'sanitize_callback' => 'absint' ],
					'per_page' => [ 'default' => 25,   'sanitize_callback' => 'absint' ],
					'status'   => [ 'default' => '',    'sanitize_callback' => 'sanitize_text_field' ],
					'search'   => [ 'default' => '',    'sanitize_callback' => 'sanitize_text_field' ],
					'order_id' => [ 'default' => 0,     'sanitize_callback' => 'absint' ],
				],
			],
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_transfer' ],
				'permission_callback' => [ $this, 'require_staff' ],
			],
		] );

		register_rest_route( $ns, '/transfers/export', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'export_transfers_csv' ],
			'permission_callback' => [ $this, 'require_manager' ],
		] );

		register_rest_route( $ns, '/transfers/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_transfer' ],
			'permission_callback' => [ $this, 'require_staff' ],
		] );

		register_rest_route( $ns, '/transfers/(?P<id>\d+)/status', [
			'methods'             => 'PUT',
			'callback'            => [ $this, 'update_transfer_status' ],
			'permission_callback' => [ $this, 'require_staff' ],
		] );

		// ── Stats endpoints ──────────────────────────────────────────────────
		register_rest_route( $ns, '/stats/dashboard', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_dashboard_stats' ],
			'permission_callback' => [ $this, 'require_staff' ],
		] );

		register_rest_route( $ns, '/stats/sync', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_sync_status' ],
			'permission_callback' => [ $this, 'require_manager' ],
		] );

		// ── Compliance endpoints ───────────────────────────────────────────────
		register_rest_route( $ns, '/compliance/(?P<state>[A-Z]{2})', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_state_compliance' ],
			'permission_callback' => '__return_true',
		] );

		// ── v1.1.0 — Dealer portal endpoints ─────────────────────────
		register_rest_route( $ns, '/portal/issue-token', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'portal_issue_token' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [
				'transfer_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( $ns, '/portal/resend', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'portal_resend' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [
				'transfer_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( $ns, '/portal/revoke', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'portal_revoke' ],
			'permission_callback' => [ $this, 'require_manager' ],
			'args'                => [
				'transfer_id' => [ 'required' => true, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( $ns, '/analytics/portal', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_portal_summary' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [
				'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( $ns, '/analytics/dealers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_dealer_performance' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [
				'period' => [ 'default' => '90d', 'sanitize_callback' => 'sanitize_key' ],
				'order'  => [ 'default' => 'slowest', 'sanitize_callback' => 'sanitize_key' ],
				'limit'  => [ 'default' => 25, 'sanitize_callback' => 'absint' ],
			],
		] );

		register_rest_route( $ns, '/analytics/timeseries', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_time_series' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [
				'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ],
				'event'  => [ 'default' => 'confirmed', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		// ── v1.1.2 — Business analytics endpoints ────────────────────────
		register_rest_route( $ns, '/analytics/woocommerce', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_woocommerce' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [ 'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ] ],
		] );

		register_rest_route( $ns, '/analytics/amelia', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_amelia' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [ 'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ] ],
		] );

		register_rest_route( $ns, '/analytics/chatbotistic', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_chatbotistic' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [ 'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ] ],
		] );

		register_rest_route( $ns, '/analytics/otter', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_otter' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [ 'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ] ],
		] );

		register_rest_route( $ns, '/analytics/leads', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'analytics_leads' ],
			'permission_callback' => [ $this, 'require_staff' ],
			'args'                => [ 'period' => [ 'default' => '30d', 'sanitize_callback' => 'sanitize_key' ] ],
		] );

		register_rest_route( $ns, '/integrations/test', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'integrations_test' ],
			// v1.1.4 — accept WP cookie auth (admin clicking from browser) in addition to JWT Bearer.
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
			},
		] );
	}

	// ── v1.1.0 — Portal handlers ───────────────────────────────────

	public function portal_issue_token( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$transfer_id = (int) $req->get_param( 'transfer_id' );
		$result      = Portal::issue_and_send( $transfer_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'success' => (bool) $result ] );
	}

	public function portal_resend( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		$transfer_id = (int) $req->get_param( 'transfer_id' );
		$result      = Portal::issue_and_send( $transfer_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( [ 'success' => true, 'message' => 'New token issued and email sent.' ] );
	}

	public function portal_revoke( \WP_REST_Request $req ): \WP_REST_Response {
		$transfer_id = (int) $req->get_param( 'transfer_id' );
		$count       = Token::revoke_all_for_transfer( $transfer_id );
		return rest_ensure_response( [ 'success' => true, 'revoked' => $count ] );
	}

	public function analytics_portal_summary( \WP_REST_Request $req ): \WP_REST_Response {
		$period = (string) $req->get_param( 'period' );
		return rest_ensure_response( Analytics::get_summary( $period ) );
	}

	public function analytics_dealer_performance( \WP_REST_Request $req ): \WP_REST_Response {
		$period = (string) $req->get_param( 'period' );
		$order  = (string) $req->get_param( 'order' );
		$limit  = (int) $req->get_param( 'limit' );
		return rest_ensure_response( [
			'period'  => $period,
			'order'   => $order,
			'dealers' => Analytics::dealer_performance( $period, max( 1, min( 100, $limit ) ), $order ),
		] );
	}

	public function analytics_time_series( \WP_REST_Request $req ): \WP_REST_Response {
		$period = (string) $req->get_param( 'period' );
		$event  = (string) $req->get_param( 'event' );
		return rest_ensure_response( [
			'period' => $period,
			'event'  => $event,
			'series' => Analytics::time_series( $period, $event ),
		] );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// v1.1.2 — Business analytics handlers
	// ──────────────────────────────────────────────────────────────────────────

	public function analytics_woocommerce( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;
		$period = (string) $req->get_param( 'period' );
		$days   = Integrations::period_days( $period );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$prev   = gmdate( 'Y-m-d H:i:s', time() - ( $days * 2 * DAY_IN_SECONDS ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			return rest_ensure_response( self::empty_wc_shape( $period ) );
		}

		// Prefer wc_order_stats (WooCommerce Analytics schema) for speed
		$stats_tbl = $wpdb->prefix . 'wc_order_stats';
		$has_stats = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stats_tbl ) ) === $stats_tbl );

		if ( $has_stats ) {
			$cur = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) AS orders,
				        COALESCE(SUM(total_sales),0) AS revenue,
				        COALESCE(AVG(total_sales),0) AS aov,
				        COUNT(DISTINCT customer_id) AS customers
				 FROM {$stats_tbl}
				 WHERE date_created >= %s AND status IN ('wc-processing','wc-completed','wc-on-hold')",
				$since
			) );

			$prev_row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) AS orders, COALESCE(SUM(total_sales),0) AS revenue
				 FROM {$stats_tbl}
				 WHERE date_created >= %s AND date_created < %s AND status IN ('wc-processing','wc-completed','wc-on-hold')",
				$prev, $since
			) );

			$revenue = (float) ( $cur->revenue ?? 0 );
			$orders  = (int) ( $cur->orders ?? 0 );
			$aov     = $orders > 0 ? round( $revenue / $orders, 2 ) : 0;
		} elseif ( self::hpos_enabled() ) {
			// HPOS active and no wc_order_stats — query via the WC orders API so we
			// don't blindly hit empty wp_posts (which under HPOS no longer carries orders).
			[ $revenue, $orders ] = self::wc_orders_revenue_orders_since( $since );
			[ $prev_revenue, $prev_orders ] = self::wc_orders_revenue_orders_since( $prev, $since );
			$aov      = $orders > 0 ? round( $revenue / $orders, 2 ) : 0;
			$prev_row = (object) [ 'orders' => $prev_orders, 'revenue' => $prev_revenue ];
		} else {
			// Fallback: query wp_posts directly (pre-HPOS)
			$revenue = (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COALESCE(SUM(pm.meta_value+0),0)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
				 WHERE p.post_type = 'shop_order'
				   AND p.post_status IN ('wc-processing','wc-completed','wc-on-hold')
				   AND p.post_date >= %s",
				$since
			) );
			$orders = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$wpdb->posts}
				 WHERE post_type = 'shop_order'
				   AND post_status IN ('wc-processing','wc-completed','wc-on-hold')
				   AND post_date >= %s",
				$since
			) );
			$aov      = $orders > 0 ? round( $revenue / $orders, 2 ) : 0;
			$prev_row = (object) [ 'orders' => 0, 'revenue' => 0 ];
		}

		$change_revenue = self::pct_change( $revenue, (float) ( $prev_row->revenue ?? 0 ) );
		$change_orders  = self::pct_change( $orders,  (int)   ( $prev_row->orders  ?? 0 ) );

		// Top selling products (uses order_product_lookup if available, else empty)
		$lookup_tbl    = $wpdb->prefix . 'wc_order_product_lookup';
		$has_lookup    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookup_tbl ) ) === $lookup_tbl );
		$top_products  = [];
		if ( $has_lookup ) {
			$top_products = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT pl.product_id AS id,
				        p.post_title AS name,
				        SUM(pl.product_qty) AS units,
				        SUM(pl.product_net_revenue) AS revenue
				 FROM {$lookup_tbl} pl
				 LEFT JOIN {$wpdb->posts} p ON p.ID = pl.product_id
				 WHERE pl.date_created >= %s
				 GROUP BY pl.product_id
				 ORDER BY revenue DESC
				 LIMIT 10",
				$since
			) );
			$top_products = array_map( static fn( $r ) => [
				'id'      => (int) $r->id,
				'name'    => (string) ( $r->name ?: 'Product #' . $r->id ),
				'units'   => (int) $r->units,
				'revenue' => round( (float) $r->revenue, 2 ),
			], $top_products );
		}

		// Low stock (<= 5 units, stock managed)
		$low_stock = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			"SELECT p.ID AS id, p.post_title AS name,
			        CAST(pm.meta_value AS SIGNED) AS stock,
			        ( SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = p.ID AND meta_key = '_sku' LIMIT 1 ) AS sku
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_stock'
			 INNER JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_manage_stock' AND pm2.meta_value = 'yes'
			 WHERE p.post_type IN ('product','product_variation')
			   AND p.post_status = 'publish'
			   AND CAST(pm.meta_value AS SIGNED) <= 5
			 ORDER BY stock ASC
			 LIMIT 15"
		);
		$low_stock = array_map( static fn( $r ) => [
			'id'    => (int) $r->id,
			'name'  => (string) $r->name,
			'stock' => (int) $r->stock,
			'sku'   => (string) $r->sku,
		], $low_stock );

		// Order status breakdown (last N days)
		$status_map = [];
		if ( self::hpos_enabled() ) {
			$orders_tbl = $wpdb->prefix . 'wc_orders';
			$by_status  = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT status, COUNT(*) AS cnt
				 FROM {$orders_tbl}
				 WHERE type = 'shop_order'
				   AND date_created_gmt >= %s
				 GROUP BY status",
				$since
			) );
		} else {
			$by_status = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT post_status AS status, COUNT(*) AS cnt
				 FROM {$wpdb->posts}
				 WHERE post_type = 'shop_order'
				   AND post_date >= %s
				 GROUP BY post_status",
				$since
			) );
		}
		foreach ( $by_status as $r ) {
			$status_map[ str_replace( 'wc-', '', $r->status ) ] = (int) $r->cnt;
		}

		// New vs returning customers (best-effort)
		$cust_lookup = $wpdb->prefix . 'wc_customer_lookup';
		$has_cust    = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cust_lookup ) ) === $cust_lookup );
		$new_cust = 0; $returning_cust = 0;
		if ( $has_cust && $has_stats ) {
			$new_cust = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(DISTINCT s.customer_id)
				 FROM {$stats_tbl} s
				 INNER JOIN {$cust_lookup} c ON c.customer_id = s.customer_id
				 WHERE s.date_created >= %s
				   AND c.date_registered >= %s",
				$since, $since
			) );
			$returning_cust = max( 0, (int) ( $cur->customers ?? 0 ) - $new_cust );
		}

		// Revenue trend (daily)
		$trend = [];
		if ( $has_stats ) {
			$rows = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT DATE(date_created) AS d, COALESCE(SUM(total_sales),0) AS rev, COUNT(*) AS orders
				 FROM {$stats_tbl}
				 WHERE date_created >= %s AND status IN ('wc-processing','wc-completed','wc-on-hold')
				 GROUP BY DATE(date_created)
				 ORDER BY d ASC",
				$since
			) );
			foreach ( $rows as $r ) {
				$trend[] = [ 'date' => $r->d, 'revenue' => round( (float) $r->rev, 2 ), 'orders' => (int) $r->orders ];
			}
		}

		return rest_ensure_response( [
			'period'    => $period,
			'since'     => $since,
			'revenue'   => [ 'total' => round( $revenue, 2 ), 'change' => $change_revenue, 'trend' => $trend ],
			'orders'    => [ 'total' => $orders, 'avg_value' => $aov, 'change' => $change_orders ],
			'customers' => [
				'new'       => $new_cust,
				'returning' => $returning_cust,
				'total'     => $new_cust + $returning_cust,
			],
			'top_products' => $top_products,
			'low_stock'    => $low_stock,
			'by_status'    => $status_map,
			'ffl_transfers_in_period' => (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . ' WHERE created_at >= %s',
				$since
			) ),
		] );
	}

	public function analytics_amelia( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;
		$period = (string) $req->get_param( 'period' );
		$days   = Integrations::period_days( $period );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$appts_tbl = $wpdb->prefix . 'amelia_appointments';
		$has_amelia = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $appts_tbl ) ) === $appts_tbl );

		if ( ! $has_amelia ) {
			return rest_ensure_response( [
				'period'       => $period,
				'installed'    => false,
				'bookings'     => [ 'total' => 0, 'revenue' => 0, 'change' => 0 ],
				'bookers'      => [ 'new' => 0, 'returning' => 0 ],
				'by_service'   => [],
				'by_day_of_week' => [],
				'message'      => 'Amelia plugin not detected. Install Amelia for booking analytics.',
			] );
		}

		$bookings = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT COUNT(*) FROM {$appts_tbl} WHERE bookingStart >= %s AND status IN ('approved','pending')",
			$since
		) );

		// Revenue (if Amelia payments table exists)
		$pay_tbl = $wpdb->prefix . 'amelia_payments';
		$has_pay = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pay_tbl ) ) === $pay_tbl );
		$revenue = 0;
		if ( $has_pay ) {
			$revenue = (float) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COALESCE(SUM(amount),0) FROM {$pay_tbl} WHERE dateTime >= %s AND status = 'paid'",
				$since
			) );
		}

		// By day of week
		$by_dow = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
			"SELECT DAYNAME(bookingStart) AS day, COUNT(*) AS cnt
			 FROM {$appts_tbl}
			 WHERE bookingStart >= %s AND status IN ('approved','pending')
			 GROUP BY DAYNAME(bookingStart)",
			$since
		) );

		// By service
		$svc_tbl = $wpdb->prefix . 'amelia_services';
		$has_svc = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $svc_tbl ) ) === $svc_tbl );
		$by_service = [];
		if ( $has_svc ) {
			$by_service = (array) $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT s.name AS service, COUNT(a.id) AS bookings, COALESCE(SUM(s.price),0) AS revenue
				 FROM {$appts_tbl} a
				 LEFT JOIN {$svc_tbl} s ON s.id = a.serviceId
				 WHERE a.bookingStart >= %s AND a.status IN ('approved','pending')
				 GROUP BY s.id
				 ORDER BY bookings DESC
				 LIMIT 10",
				$since
			) );
		}

		return rest_ensure_response( [
			'period'    => $period,
			'installed' => true,
			'bookings'  => [ 'total' => $bookings, 'revenue' => round( $revenue, 2 ), 'change' => 0 ],
			'bookers'   => [ 'new' => 0, 'returning' => 0 ],
			'by_service'     => array_map( static fn($r) => [ 'service' => (string)$r->service, 'bookings' => (int)$r->bookings, 'revenue' => round((float)$r->revenue, 2) ], $by_service ),
			'by_day_of_week' => array_map( static fn($r) => [ 'day' => (string)$r->day, 'count' => (int)$r->cnt ], $by_dow ),
		] );
	}

	public function analytics_chatbotistic( \WP_REST_Request $req ): \WP_REST_Response {
		$period = (string) $req->get_param( 'period' );
		return rest_ensure_response( Integrations::chatbotistic_summary( $period ) + [ 'period' => $period ] );
	}

	public function analytics_otter( \WP_REST_Request $req ): \WP_REST_Response {
		$period = (string) $req->get_param( 'period' );
		return rest_ensure_response( Integrations::otter_summary( $period ) + [ 'period' => $period ] );
	}

	public function analytics_leads( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;
		$period = (string) $req->get_param( 'period' );
		$days   = Integrations::period_days( $period );
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// ChatBotistic (3 widget sources)
		$cb = Integrations::chatbotistic_summary( $period );
		$by_source = is_array( $cb['by_widget'] ?? null ) ? $cb['by_widget'] : [];

		// WooCommerce — new customers
		$new_customers = 0;
		$cust_lookup = $wpdb->prefix . 'wc_customer_lookup';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cust_lookup ) ) === $cust_lookup ) {
			$new_customers = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$cust_lookup} WHERE date_registered >= %s",
				$since
			) );
		} else {
			$new_customers = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta}
				 WHERE meta_key = 'wp_capabilities'
				   AND meta_value LIKE '%customer%'
				   AND user_id IN (SELECT ID FROM {$wpdb->users} WHERE user_registered >= %s)",
				$since
			) );
		}
		$by_source[] = [
			'id'     => 'wc_new_customers', 'icon' => '🛒',
			'label'  => 'WooCommerce — New Customers',
			'count'  => $new_customers,
			'source' => 'woocommerce',
		];

		// Amelia bookings
		$amelia_count = 0;
		$appts_tbl = $wpdb->prefix . 'amelia_appointments';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $appts_tbl ) ) === $appts_tbl ) {
			$amelia_count = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
				"SELECT COUNT(*) FROM {$appts_tbl} WHERE bookingStart >= %s AND status IN ('approved','pending')",
				$since
			) );
		}
		$by_source[] = [
			'id'     => 'amelia_bookings', 'icon' => '📅',
			'label'  => 'Range Bookings (Amelia)',
			'count'  => $amelia_count,
			'source' => 'amelia',
		];

		// Direct phone calls — no call-tracking integration is bundled with
		// this plugin, so there's nothing real to report by default. Rather
		// than ship a permanent "coming_soon" placeholder that never
		// resolves, this is a real, filterable extension point: a site that
		// connects its own call-tracking number (Twilio, CallRail, Google
		// Business Profile) can return a real count via the filter, and the
		// row only appears once something actually answers it — same
		// honesty pattern as the NICS/ID-verification provider registries.
		$phone_call_count = apply_filters( 'wpistic_ffl_phone_call_count', null, $since, $period );
		if ( null !== $phone_call_count ) {
			$by_source[] = [
				'id'     => 'phone_calls', 'icon' => '📞',
				'label'  => 'Direct Phone Calls',
				'count'  => (int) $phone_call_count,
				'source' => (string) apply_filters( 'wpistic_ffl_phone_call_source_label', 'connected', $since, $period ),
			];
		}

		$total = 0;
		foreach ( $by_source as $s ) { $total += (int) ( $s['count'] ?? 0 ); }

		return rest_ensure_response( [
			'period'    => $period,
			'total'     => $total,
			'by_source' => $by_source,
			'recent_whatsapp' => $cb['recent'] ?? [],
		] );
	}

	public function integrations_test( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( Integrations::self_test() );
	}

	// Helper — empty WC response shape
	private static function empty_wc_shape( string $period ): array {
		return [
			'period'    => $period,
			'installed' => false,
			'revenue'   => [ 'total' => 0, 'change' => 0, 'trend' => [] ],
			'orders'    => [ 'total' => 0, 'avg_value' => 0, 'change' => 0 ],
			'customers' => [ 'new' => 0, 'returning' => 0, 'total' => 0 ],
			'top_products' => [], 'low_stock' => [], 'by_status' => [],
			'message'   => 'WooCommerce not active.',
		];
	}

	private static function pct_change( float $cur, float $prev ): float {
		if ( $prev <= 0 ) return $cur > 0 ? 1.0 : 0.0;
		return round( ( $cur - $prev ) / $prev, 3 );
	}

	/**
	 * True when WooCommerce HPOS (custom_order_tables) is the authoritative store.
	 * Cached per-request because OrderUtil::custom_orders_table_usage_is_enabled()
	 * touches the options API on every call.
	 */
	private static function hpos_enabled(): bool {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}
		$cached = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		return $cached;
	}

	/**
	 * HPOS-safe revenue + order count for a date range. Uses wc_get_orders() so
	 * we never invent SQL against the rapidly-evolving wc_orders schema.
	 *
	 * @return array{0: float, 1: int}
	 */
	private static function wc_orders_revenue_orders_since( string $since_gmt, ?string $until_gmt = null ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [ 0.0, 0 ];
		}
		$args = [
			'limit'        => -1,
			'status'       => [ 'processing', 'completed', 'on-hold' ],
			'type'         => 'shop_order',
			'date_created' => $until_gmt ? ( $since_gmt . '...' . $until_gmt ) : ( '>=' . $since_gmt ),
			'return'       => 'objects',
			'paginate'     => false,
		];
		$orders = wc_get_orders( $args );
		$rev    = 0.0;
		$cnt    = 0;
		foreach ( $orders as $o ) {
			$rev += (float) $o->get_total();
			++$cnt;
		}
		return [ round( $rev, 2 ), $cnt ];
	}

	/**
	 * Neutralize spreadsheet formula injection: Excel/Sheets execute cells
	 * starting with = + - @ (or a tab/CR prefix), and transfer rows contain
	 * customer-supplied names/emails. Prefix such cells with a single quote.
	 */
	private function csv_cell( $value ): string {
		$value = (string) $value;
		if ( $value !== '' && strpbrk( $value[0], "=+-@\t\r" ) !== false ) {
			return "'" . $value;
		}
		return $value;
	}

	// ── Permission callbacks ─────────────────────────────────────────────────────

	public function require_staff( \WP_REST_Request $req ): bool|\WP_Error {
		$user = $this->get_current_user( $req );
		if ( ! $user ) {
			return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
		}
		// Transfers carry customer PII and analytics expose revenue data, so
		// mere authentication is not enough on a store where every customer
		// has an account — require an assigned G2A staff role or a manager cap.
		$role = get_user_meta( $user->ID, 'wpistic_ffl_g2a_role', true );
		if ( ! in_array( $role, [ 'owner', 'manager', 'staff' ], true )
			&& ! current_user_can( 'manage_options' )
			&& ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'forbidden', 'Staff access required.', [ 'status' => 403 ] );
		}
		return true;
	}

	public function require_manager( \WP_REST_Request $req ): bool|\WP_Error {
		$user = $this->get_current_user( $req );
		if ( ! $user ) {
			return new \WP_Error( 'unauthorized', 'Authentication required.', [ 'status' => 401 ] );
		}
		$role = get_user_meta( $user->ID, 'wpistic_ffl_g2a_role', true );
		if ( ! in_array( $role, [ 'owner', 'manager' ], true ) && ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'forbidden', 'Manager access required.', [ 'status' => 403 ] );
		}
		return true;
	}

	// ── Dealer handlers ─────────────────────────────────────────────────

	/**
	 * v1.1.1 — Advanced multi-filter dealer search.
	 *
	 * Filter combinations (at least one required):
	 *   - zip   → ZIP radius search (Haversine) + exact ZIP matches for non-geocoded dealers
	 *   - name  → business_name LIKE
	 *   - license → license_number LIKE
	 *   - phone → phone LIKE (digits-only fuzzy match)
	 *   - state → premise_state =
	 *   - city  → premise_city LIKE
	 *
	 * Returns geocoded AND non-geocoded dealers (the latter get distance = null).
	 * Results sorted: is_preferred DESC, has_geo DESC, distance/name ASC.
	 */
	public function search_dealers( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		// G2A: rate-limit the public endpoint to stop bulk scraping of the
		// 80k-row ATF database. 30 searches per minute per IP is plenty for a
		// real shopper picking a dealer at checkout.
		$ip    = Token::client_ip();
		$rl_key = 'wpistic_ffl_search_rl_' . wp_hash( $ip );
		$hits   = (int) get_transient( $rl_key );
		$limit_per_min = (int) apply_filters( 'wpistic_ffl_search_rate_limit', 30 );
		if ( $hits >= $limit_per_min ) {
			return new \WP_Error( 'rate_limited', 'Too many searches. Please wait a moment.', [ 'status' => 429 ] );
		}
		set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

		$zip      = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'zip' ) );
		$name     = trim( (string) $req->get_param( 'name' ) );
		$license  = trim( (string) $req->get_param( 'license' ) );
		$phone    = preg_replace( '/[^0-9]/', '', (string) $req->get_param( 'phone' ) );
		$state    = strtoupper( trim( (string) $req->get_param( 'state' ) ) );
		$city     = trim( (string) $req->get_param( 'city' ) );
		$type     = trim( (string) $req->get_param( 'type' ) );
		$radius   = min( 200, max( 1, (int) $req->get_param( 'radius' ) ) );
		$limit    = min( 100, max( 1, (int) $req->get_param( 'limit' ) ) );
		$include_no_geo = (bool) $req->get_param( 'include_no_geo' );

		// At least one filter must be present
		if ( '' === $zip && '' === $name && '' === $license && '' === $phone && '' === $state && '' === $city ) {
			return new \WP_Error( 'missing_filter', 'Please enter a ZIP code, dealer name, license number, phone, or state.', [ 'status' => 400 ] );
		}

		$t_dealers = DB::table( 'dealers' );

		// Geo context — only populated when we have a valid ZIP + centroid
		$origin_lat = null;
		$origin_lng = null;
		$origin_city  = '';
		$origin_state = '';
		$has_geo      = false;

		if ( preg_match( '/^\d{5}$/', $zip ) ) {
			$coords = Zip_Import::get_coords( $zip );
			if ( $coords ) {
				$origin_lat   = (float) $coords['lat'];
				$origin_lng   = (float) $coords['lng'];
				$origin_city  = (string) $coords['city'];
				$origin_state = (string) $coords['state'];
				$has_geo      = true;
			}
		}

		// Build WHERE + args
		$where  = [ 'd.is_active = 1' ];
		$args   = [];

		if ( $name !== '' ) {
			$where[] = '(d.business_name LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $name ) . '%';
		}
		if ( $license !== '' ) {
			$where[] = '(d.license_number LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $license ) . '%';
		}
		if ( $phone !== '' && strlen( $phone ) >= 4 ) {
			$where[] = "(REPLACE(REPLACE(REPLACE(REPLACE(d.phone,'-',''),' ',''),'(',''),')','') LIKE %s)";
			$args[]  = '%' . $wpdb->esc_like( $phone ) . '%';
		}
		if ( $state !== '' ) {
			$where[] = '(d.premise_state = %s)';
			$args[]  = $state;
		}
		if ( $city !== '' ) {
			$where[] = '(d.premise_city LIKE %s)';
			$args[]  = '%' . $wpdb->esc_like( $city ) . '%';
		}
		if ( $type !== '' ) {
			$where[] = '(d.lic_type = %s)';
			$args[]  = $type;
		}

		// ZIP handling — combine radius match with ZIP exact + ZIP+4 prefix matches
		// (fixes the G2A "can't find own dealer" issue + ZIP+4 stored format)
		$zip_clause = '';
		if ( preg_match( '/^\d{5}$/', $zip ) ) {
			if ( $has_geo ) {
				// Radius match OR ZIP equals OR ZIP+4 first 5 = our zip
				// (dealers missing lat/lng AND dealers stored as 12345-6789 both surface)
				$zip_clause = '( LEFT( d.premise_zip, 5 ) = %s OR ( d.lat IS NOT NULL AND d.lat <> 0 AND (
					3959 * acos(
						cos( radians(%f) ) * cos( radians( d.lat ) )
						* cos( radians( d.lng ) - radians(%f) )
						+ sin( radians(%f) ) * sin( radians( d.lat ) )
					)
				) <= %d ) )';
				$args[] = $zip;
				$args[] = $origin_lat;
				$args[] = $origin_lng;
				$args[] = $origin_lat;
				$args[] = $radius;
			} else {
				// No centroid available — ZIP / ZIP+4 prefix match only
				$zip_clause = '( LEFT( d.premise_zip, 5 ) = %s )';
				$args[] = $zip;
			}
			$where[] = $zip_clause;
		}

		$where_sql = implode( ' AND ', $where );

		// Distance column — only real when we have geo context
		$distance_select = $has_geo ?
			'( 3959 * acos(
				cos( radians( %f ) ) * cos( radians( d.lat ) )
				* cos( radians( d.lng ) - radians( %f ) )
				+ sin( radians( %f ) ) * sin( radians( d.lat ) )
			) ) AS distance_miles' :
			'NULL AS distance_miles';

		$select_prefix_args = $has_geo ? [ $origin_lat, $origin_lng, $origin_lat ] : [];

		// Final SQL — order: is_preferred DESC, has_geo DESC (geo first so map doesn't feel empty), distance/name ASC
		$order_sql = $has_geo
			? 'ORDER BY d.is_preferred DESC, ( d.lat IS NULL OR d.lat = 0 ) ASC, distance_miles ASC, d.business_name ASC'
			: 'ORDER BY d.is_preferred DESC, d.business_name ASC';

		$sql = "SELECT
				d.id, d.license_number, d.lic_type, d.business_name,
				d.premise_street, d.premise_city, d.premise_state, d.premise_zip,
				d.phone, d.transfer_fee, d.is_preferred, d.lic_xprdte,
				d.lat, d.lng,
				{$distance_select}
			FROM {$t_dealers} d
			WHERE {$where_sql}
			{$order_sql}
			LIMIT %d";

		$final_args = array_merge( $select_prefix_args, $args, [ $limit ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$dealers = $wpdb->get_results( $wpdb->prepare( $sql, ...$final_args ) );

		// Post-process: ensure numeric types + derive has_geo flag per dealer
		$dealers = array_map( static function ( $d ) {
			$d->lat              = $d->lat !== null ? (float) $d->lat : null;
			$d->lng              = $d->lng !== null ? (float) $d->lng : null;
			$d->has_geo          = ( $d->lat && $d->lng );
			$d->distance_miles   = $d->distance_miles !== null ? round( (float) $d->distance_miles, 1 ) : null;
			$d->is_preferred     = (int) $d->is_preferred;
			$d->transfer_fee     = (float) $d->transfer_fee;
			return $d;
		}, (array) $dealers );

		// Filter out non-geo dealers if explicitly requested
		if ( ! $include_no_geo ) {
			$dealers = array_values( array_filter( $dealers, static fn( $d ) => (bool) $d->has_geo ) );
		}

		return rest_ensure_response( [
			'success' => true,
			'origin'  => $has_geo ? [
				'zip'   => $zip,
				'lat'   => $origin_lat,
				'lng'   => $origin_lng,
				'city'  => $origin_city,
				'state' => $origin_state,
			] : null,
			'filters' => [
				'zip'     => $zip,
				'name'    => $name,
				'license' => $license,
				'phone'   => $phone,
				'state'   => $state,
				'city'    => $city,
				'type'    => $type,
			],
			'radius'  => $radius,
			'count'   => count( $dealers ),
			'dealers' => $dealers,
		] );
	}

	public function get_dealer( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		// G2A: rate-limit this public endpoint the same as /dealers/search — it
		// exposes the same per-record data via a sequential auto-increment ID,
		// so without its own limit it could be used to scrape the entire ~80k
		// row dealer database (bypassing the search endpoint's throttle).
		$ip            = Token::client_ip();
		$rl_key        = 'wpistic_ffl_getdealer_rl_' . wp_hash( $ip );
		$hits          = (int) get_transient( $rl_key );
		$limit_per_min = (int) apply_filters( 'wpistic_ffl_search_rate_limit', 30 );
		if ( $hits >= $limit_per_min ) {
			return new \WP_Error( 'rate_limited', 'Too many requests. Please wait a moment.', [ 'status' => 429 ] );
		}
		set_transient( $rl_key, $hits + 1, MINUTE_IN_SECONDS );

		// Public endpoint: return only the same public-safe column set
		// /dealers/search exposes — never SELECT * (leaks dealer email/import
		// metadata), and never `notes` (internal staff commentary about the
		// dealer, writable only via the manager-gated PUT /dealers/{id}).
		$dealer = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT id, license_number, lic_type, business_name,
				        premise_street, premise_city, premise_state, premise_zip,
				        phone, transfer_fee, is_preferred, lic_xprdte, lat, lng
				 FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d',
				$req['id']
			)
		);
		if ( ! $dealer ) {
			return new \WP_Error( 'not_found', 'Dealer not found.', [ 'status' => 404 ] );
		}
		return rest_ensure_response( $dealer );
	}

	public function list_dealers( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;

		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = min( 100, max( 10, (int) $req->get_param( 'per_page' ) ) );
		$search   = $req->get_param( 'search' );
		$state    = strtoupper( $req->get_param( 'state' ) );
		$type     = $req->get_param( 'type' );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$values = [];

		if ( $search ) {
			$where   .= ' AND (business_name LIKE %s OR license_number LIKE %s OR premise_city LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}
		if ( $state ) {
			$where   .= ' AND premise_state = %s';
			$values[] = $state;
		}
		if ( $type ) {
			$where   .= ' AND lic_type = %s';
			$values[] = $type;
		}

		$table = DB::table( 'dealers' );
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where}", ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		$values[] = $per_page;
		$values[] = $offset;
		$dealers  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} {$where} ORDER BY is_preferred DESC, business_name ASC LIMIT %d OFFSET %d", ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		return rest_ensure_response( [
			'dealers'    => $dealers,
			'total'      => $total,
			'page'       => $page,
			'per_page'   => $per_page,
			'total_pages'=> (int) ceil( $total / $per_page ),
		] );
	}

	public function update_dealer( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;
		$id = (int) $req['id'];

		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . DB::table( 'dealers' ) . ' WHERE id = %d', $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $existing ) {
			return new \WP_Error( 'not_found', 'Dealer not found.', [ 'status' => 404 ] );
		}

		$allowed = [ 'transfer_fee', 'is_preferred', 'notes', 'is_active', 'email' ];
		$data    = [];
		$formats = [];

		foreach ( $allowed as $key ) {
			if ( $req->has_param( $key ) ) {
				$val = $req->get_param( $key );
				switch ( $key ) {
					case 'transfer_fee':
						$data[ $key ] = (float) $val;
						$formats[]    = '%f';
						break;
					case 'is_preferred':
					case 'is_active':
						$data[ $key ] = (int) (bool) $val;
						$formats[]    = '%d';
						break;
					case 'email':
						$data[ $key ] = sanitize_email( (string) $val );
						$formats[]    = '%s';
						break;
					default:
						$data[ $key ] = sanitize_textarea_field( $val );
						$formats[]    = '%s';
				}
			}
		}

		if ( empty( $data ) ) {
			return new \WP_Error( 'no_data', 'No valid fields to update.', [ 'status' => 400 ] );
		}

		$wpdb->update( DB::table( 'dealers' ), $data, [ 'id' => $id ], $formats, [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return rest_ensure_response( [ 'success' => true ] );
	}

	// ── Transfer handlers ──────────────────────────────────────────────

	public function list_transfers( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;

		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = min( 100, max( 10, (int) $req->get_param( 'per_page' ) ) );
		$status   = $req->get_param( 'status' );
		$search   = $req->get_param( 'search' );
		$order_id = (int) $req->get_param( 'order_id' );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = 'WHERE 1=1';
		$values = [];

		if ( $status ) {
			$where   .= ' AND t.status = %s';
			$values[] = $status;
		}
		if ( $order_id ) {
			$where   .= ' AND t.order_id = %d';
			$values[] = $order_id;
		}
		if ( $search ) {
			$where   .= ' AND (t.transfer_ref LIKE %s OR t.customer_name LIKE %s OR t.customer_email LIKE %s OR t.item_sku LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values   = array_merge( $values, [ $like, $like, $like, $like ] );
		}

		$t_table = DB::table( 'transfers' );
		$d_table = DB::table( 'dealers' );

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t_table} t {$where}", ...$values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		$values[] = $per_page;
		$values[] = $offset;

		$transfers = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
			"SELECT t.*, d.business_name AS dealer_name, d.premise_city AS dealer_city, d.premise_state AS dealer_state
			 FROM {$t_table} t
			 LEFT JOIN {$d_table} d ON d.id = t.dealer_id
			 {$where}
			 ORDER BY t.created_at DESC
			 LIMIT %d OFFSET %d",
			...$values
		) );

		return rest_ensure_response( [
			'transfers'   => $transfers,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total / $per_page ),
		] );
	}

	public function get_transfer( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;
		$id = (int) $req['id'];

		$transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT t.*, d.business_name AS dealer_name, d.premise_street AS dealer_street,
			        d.premise_city AS dealer_city, d.premise_state AS dealer_state,
			        d.premise_zip AS dealer_zip, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d',
			$id
		) );

		if ( ! $transfer ) {
			return new \WP_Error( 'not_found', 'Transfer not found.', [ 'status' => 404 ] );
		}

		$events = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT * FROM ' . DB::table( 'events' ) . ' WHERE transfer_id = %d ORDER BY created_at ASC',
			$id
		) );

		return rest_ensure_response( [
			'transfer' => $transfer,
			'events'   => $events,
		] );
	}

	public function create_transfer( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$required = [ 'order_id', 'dealer_id', 'customer_name', 'customer_email' ];
		foreach ( $required as $field ) {
			if ( ! $req->has_param( $field ) || ! $req->get_param( $field ) ) {
				return new \WP_Error( 'missing_field', "Field {$field} is required.", [ 'status' => 400 ] );
			}
		}

		$ref = self::generate_transfer_ref();

		$data = [
			'transfer_ref'      => $ref,
			'order_id'          => (int) $req->get_param( 'order_id' ),
			'customer_id'       => (int) $req->get_param( 'customer_id' ) ?: null,
			'customer_name'     => sanitize_text_field( $req->get_param( 'customer_name' ) ),
			'customer_email'    => sanitize_email( $req->get_param( 'customer_email' ) ),
			'customer_phone'    => sanitize_text_field( $req->get_param( 'customer_phone' ) ?? '' ),
			'dealer_id'         => (int) $req->get_param( 'dealer_id' ),
			'status'            => 'dealer_selected',
			'item_description'  => sanitize_text_field( $req->get_param( 'item_description' ) ?? '' ),
			'item_sku'          => sanitize_text_field( $req->get_param( 'item_sku' ) ?? '' ),
			'item_make'         => sanitize_text_field( $req->get_param( 'item_make' ) ?? '' ),
			'item_model'        => sanitize_text_field( $req->get_param( 'item_model' ) ?? '' ),
			'item_caliber'      => sanitize_text_field( $req->get_param( 'item_caliber' ) ?? '' ),
			'item_type'         => sanitize_text_field( $req->get_param( 'item_type' ) ?? 'handgun' ),
		];

		$result = $wpdb->insert( DB::table( 'transfers' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $result ) {
			return new \WP_Error( 'db_error', 'Could not create transfer record.', [ 'status' => 500 ] );
		}

		$transfer_id = $wpdb->insert_id;

		// Log creation event
		$this->log_event( $transfer_id, 'created', null, 'dealer_selected', 'Transfer created.' );

		// Send confirmation email
		Mailer::send_status_update( $transfer_id, 'dealer_selected' );

		return rest_ensure_response( [
			'success'     => true,
			'transfer_id' => $transfer_id,
			'transfer_ref'=> $ref,
		] );
	}

	public function update_transfer_status( \WP_REST_Request $req ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$id         = (int) $req['id'];
		$new_status = sanitize_text_field( $req->get_param( 'status' ) ?? '' );
		$notes      = sanitize_textarea_field( $req->get_param( 'notes' ) ?? '' );

		// Status names must match the TypeScript TransferStatus union in fflData.ts
		$valid_statuses = [
			'pending_selection', 'dealer_selected', 'payment_confirmed',
			'shipped_to_dealer', 'received_by_dealer',
			'background_check', 'approved', 'delayed',
			'denied', 'transferred', 'cancelled',
		];

		if ( ! in_array( $new_status, $valid_statuses, true ) ) {
			return new \WP_Error( 'invalid_status', 'Invalid transfer status.', [ 'status' => 400 ] );
		}

		$transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT id, status FROM ' . DB::table( 'transfers' ) . ' WHERE id = %d', $id
		) );

		if ( ! $transfer ) {
			return new \WP_Error( 'not_found', 'Transfer not found.', [ 'status' => 404 ] );
		}

		$update = [ 'status' => $new_status ];

		// Set date fields automatically based on new status
		$date_today = current_time( 'Y-m-d' );
		switch ( $new_status ) {
			case 'shipped_to_dealer':
				$update['shipped_date']        = $date_today;
				$update['shipment_carrier']    = sanitize_text_field( $req->get_param( 'carrier' ) ?? '' );
				$update['shipment_tracking']   = sanitize_text_field( $req->get_param( 'tracking' ) ?? '' );
				break;
			case 'received_by_dealer':
				$update['dealer_received_date'] = $date_today;
				break;
			case 'background_check':
				$update['nics_check_date'] = $date_today;
				break;
			case 'delayed':
				// NICS delay expires in 3 business days
				$update['nics_delay_expires'] = date( 'Y-m-d', strtotime( '+3 days' ) );
				$update['nics_response']      = 'delayed';
				$update['nics_source']        = 'manual';
				break;
			case 'approved':
				$update['nics_response'] = 'proceed';
				$update['nics_source']   = 'manual';
				break;
			case 'denied':
				$update['nics_response'] = 'denied';
				$update['nics_source']   = 'manual';
				break;
			case 'transferred':
				$update['transfer_date']           = $date_today;
				$update['nics_transaction_number'] = sanitize_text_field( $req->get_param( 'nics_ntn' ) ?? '' );
				$update['form_4473_ref']           = sanitize_text_field( $req->get_param( 'form_4473_ref' ) ?? '' );
				$update['item_serial']             = sanitize_text_field( $req->get_param( 'serial' ) ?? '' );
				break;
		}

		// Manual override — lets staff record/correct the NICS outcome on any
		// status transition, not just the ones above (e.g. re-confirming after
		// a provider-reported result). Uses the same nics_response/nics_source
		// columns a connected provider's webhook writes to.
		$manual_response = sanitize_key( (string) ( $req->get_param( 'nics_response' ) ?? '' ) );
		if ( in_array( $manual_response, G2A_Background_Check_Providers::RESPONSES, true ) ) {
			$update['nics_response'] = $manual_response;
			$update['nics_source']   = 'manual';
		}

		$wpdb->update( DB::table( 'transfers' ), $update, [ 'id' => $id ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Append audit event
		$this->log_event( $id, 'status_change', $transfer->status, $new_status, $notes );

		// Send email notification
		Mailer::send_status_update( $id, $new_status );

		// G2A: fire both actions — _status_changed for email/SMS/bridge, _updated
		// for side-effects keyed off arbitrary column changes (e.g. carrier auto-advance).
		do_action( 'wpistic_ffl_transfer_status_changed', $id, $transfer->status, $new_status );
		do_action( 'wpistic_ffl_transfer_updated', $id, $update );

		return rest_ensure_response( [ 'success' => true, 'status' => $new_status ] );
	}

	public function export_transfers_csv( \WP_REST_Request $req ): void {
		global $wpdb;

		$transfers = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT t.*, d.business_name AS dealer_name, d.license_number AS dealer_license
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 ORDER BY t.created_at DESC'
		);

		// Output CSV directly
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ffl-transfers-' . date( 'Y-m-d' ) . '.csv"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, [
			'Transfer Ref','Order ID','Customer Name','Customer Email','Customer Phone',
			'Dealer Name','Dealer License','Status','Item Description','Item SKU','Item Make',
			'Item Model','Item Caliber','Item Type','Item Serial','Shipped Date',
			'Dealer Received','NICS Check Date','NICS NTN','NICS Delay Expires',
			'Transfer Date','Form 4473 Ref','Created At',
		] );

		foreach ( $transfers as $t ) {
			fputcsv( $out, array_map( [ $this, 'csv_cell' ], [
				$t->transfer_ref, $t->order_id, $t->customer_name, $t->customer_email,
				$t->customer_phone, $t->dealer_name ?? '', $t->dealer_license ?? '',
				$t->status, $t->item_description, $t->item_sku, $t->item_make, $t->item_model,
				$t->item_caliber, $t->item_type, $t->item_serial, $t->shipped_date,
				$t->dealer_received_date, $t->nics_check_date, $t->nics_transaction_number,
				$t->nics_delay_expires, $t->transfer_date, $t->form_4473_ref, $t->created_at,
			] ) );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	// ── Stats handlers ─────────────────────────────────────────────────

	public function get_dashboard_stats( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;

		$t_table = DB::table( 'transfers' );
		$d_table = DB::table( 'dealers' );

		// Total counts by status
		$by_status = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$t_table} GROUP BY status" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
		$status_map = [];
		foreach ( (array) $by_status as $row ) {
			$status_map[ $row->status ] = (int) $row->cnt;
		}

		// Transfers this month
		$this_month = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$t_table} WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())"
		);

		// NICS delayed (awaiting decision) — status name matches TS 'delayed'
		$nics_delayed = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$t_table} WHERE status = 'delayed'"
		);

		// Expiring licenses (within 30 days)
		$expiring = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT COUNT(*) FROM {$d_table} WHERE is_active = 1 AND lic_xprdte BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"
		);

		// Active dealers
		$active_dealers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$d_table} WHERE is_active = 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return rest_ensure_response( [
			'by_status'       => $status_map,
			'total_transfers' => array_sum( $status_map ),
			'this_month'      => $this_month,
			'nics_delayed'    => $nics_delayed,
			'expiring_licenses' => $expiring,
			'active_dealers'  => $active_dealers,
		] );
	}

	public function get_sync_status( \WP_REST_Request $req ): \WP_REST_Response {
		return rest_ensure_response( [
			'zip_import' => Zip_Import::get_status(),
			'atf_sync'   => Sync::get_status(),
		] );
	}

	// ── Compliance handler ───────────────────────────────────────────────

	public function get_state_compliance( \WP_REST_Request $req ): \WP_REST_Response {
		global $wpdb;
		$state = strtoupper( sanitize_text_field( $req['state'] ) );

		$rules = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT rule_type, description, item_types
			 FROM ' . DB::table( 'state_rules' ) . '
			 WHERE state_code = %s AND is_active = 1
			 ORDER BY rule_type ASC',
			$state
		) );

		return rest_ensure_response( [
			'state' => $state,
			'rules' => $rules,
			'count' => count( $rules ),
		] );
	}

	// ── Helpers ─────────────────────────────────────────────────────

	/**
	 * Resolve the current WP user from cookie session or JWT Bearer header.
	 */
	private function get_current_user( \WP_REST_Request $req ): ?\WP_User {
		// JWT auth plugin sets current user when Bearer header is present
		$user = wp_get_current_user();
		if ( $user && $user->ID > 0 ) {
			return $user;
		}
		return null;
	}

	private function log_event( int $transfer_id, string $type, ?string $old, ?string $new, string $notes ): void {
		global $wpdb;
		$user = wp_get_current_user();

		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'transfer_id' => $transfer_id,
			'event_type'  => $type,
			'old_status'  => $old,
			'new_status'  => $new,
			'notes'       => $notes,
			'actor'       => $user && $user->ID ? $user->user_login : 'system',
			'actor_ip'    => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		] );
	}

	private static function generate_transfer_ref(): string {
		return 'G2A-' . strtoupper( substr( uniqid( '', true ), -8 ) );
	}
}
