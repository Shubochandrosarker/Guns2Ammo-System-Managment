<?php
/**
 * Plugin Name:       Advanced FFL Checkout Solutions — G2A Edition
 * Plugin URI:        https://wordpressistic.com/products/advanced-ffl-checkout
 * Description:       Federal Firearms License (FFL) dealer management, WooCommerce checkout integration, transfer tracking and one-click dealer confirmation portal — customized for the Guns2Ammo system (HPOS-safe order meta, brass/graphite branding, customer "My FFL Transfers" tab, NICS 3-day automation, SMS via Verifyistic, WC order ↔ transfer status bridge).
 * Version:           1.9.2
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Wordpressistic
 * Author URI:        https://wordpressistic.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       advanced-ffl-checkout
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.x
 *
 * @package WpisticFFL
 */

defined( 'ABSPATH' ) || exit;

// ── Version guard ─────────────────────────────────────────────────────────────
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>Advanced FFL Checkout:</strong> PHP 8.1 or higher is required.</p></div>';
	} );
	return;
}

// ── Constants ─────────────────────────────────────────────────────────────────
define( 'WPISTIC_FFL_VERSION',   '1.9.2' );
define( 'WPISTIC_FFL_FILE',      __FILE__ );
define( 'WPISTIC_FFL_PATH',      plugin_dir_path( __FILE__ ) );
define( 'WPISTIC_FFL_URL',       plugin_dir_url( __FILE__ ) );
define( 'WPISTIC_FFL_BASENAME',  plugin_basename( __FILE__ ) );
define( 'WPISTIC_FFL_DB_PREFIX', 'wpistic_ffl_' );
define( 'WPISTIC_FFL_REST_NS',   'wpistic-ffl/v1' );

// Default portal URL slug — admin can override in settings; constant wins if defined.
if ( ! defined( 'WPISTIC_FFL_PORTAL_SLUG' ) ) {
	define( 'WPISTIC_FFL_PORTAL_SLUG', 'ffl-confirm' );
}

// Premium feature gate. Defining this in wp-config.php unlocks every Pro feature.
// Used now so the Guns2Ammo build runs with full white-label + analytics + theming.
// In v1.2.0 this is replaced with a remote license check against wordpressistic.com.
if ( ! defined( 'WPISTIC_FFL_UNLIMITED' ) ) {
	define( 'WPISTIC_FFL_UNLIMITED', true );
}

// ── Autoloader ────────────────────────────────────────────────────────────────
spl_autoload_register( function ( string $class ): void {
	$ns = 'WpisticFFL\\';
	if ( strpos( $class, $ns ) !== 0 ) {
		return;
	}

	$relative   = substr( $class, strlen( $ns ) );
	$parts      = explode( '\\', $relative );
	$class_name = array_pop( $parts );
	$file_name  = 'class-wpistic-ffl-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	$locations = [
		WPISTIC_FFL_PATH . 'includes/' . $file_name,
		WPISTIC_FFL_PATH . 'admin/' . $file_name,
	];

	foreach ( $locations as $path ) {
		if ( file_exists( $path ) ) {
			require_once $path;
			return;
		}
	}
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, function (): void {
	require_once WPISTIC_FFL_PATH . 'includes/class-wpistic-ffl-db.php';
	require_once WPISTIC_FFL_PATH . 'includes/class-wpistic-ffl-theming.php';
	\WpisticFFL\DB::install();

	// Schedule chunked import processes (run every minute while work remains)
	if ( ! wp_next_scheduled( 'wpistic_ffl_process_zip_import' ) ) {
		wp_schedule_event( time() + 30, 'every_minute', 'wpistic_ffl_process_zip_import' );
	}

	// Schedule monthly ATF sync
	if ( ! wp_next_scheduled( 'wpistic_ffl_monthly_sync' ) ) {
		wp_schedule_event( strtotime( 'first day of next month 03:00:00' ), 'monthly', 'wpistic_ffl_monthly_sync' );
	}

	// Schedule daily reminder + token cleanup runner
	if ( ! wp_next_scheduled( 'wpistic_ffl_daily_portal_runner' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 09:00' ), 'daily', 'wpistic_ffl_daily_portal_runner' );
	}

	// Generate the portal HMAC secret once on activation if not present.
	// Stored in WP options as a fallback; recommended path is to define
	// WPISTIC_FFL_TOKEN_SECRET in wp-config.php for higher security.
	if ( ! get_option( 'wpistic_ffl_token_secret' ) ) {
		update_option( 'wpistic_ffl_token_secret', wp_generate_password( 64, true, true ), false );
	}

	// Default portal settings on first activation only
	if ( ! get_option( 'wpistic_ffl_portal_settings' ) ) {
		add_option( 'wpistic_ffl_portal_settings', [
			'enabled'                => 1,
			'token_expiry_days'      => 30,
			'two_factor_method'      => 'last4_ffl',
			'portal_slug'            => WPISTIC_FFL_PORTAL_SLUG,
			'single_use'             => 1,
			'rate_limit_per_min'     => 5,
			'allow_replay_view'      => 1,
			'auto_send_on_ship'      => 1,
			'notify_dealer_on_order' => 1,
			'reminder_days'          => '3,7,14',
			'track_email_opens'      => 1,
			'require_https'          => 0,
		] );
	}

	if ( ! get_option( 'wpistic_ffl_theme_settings' ) ) {
		add_option( 'wpistic_ffl_theme_settings', \WpisticFFL\Theming::default_theme_settings() );
	}

	// Kick off initial ZIP import
	update_option( 'wpistic_ffl_zip_import_status', 'pending' );
	update_option( 'wpistic_ffl_zip_import_offset', 0 );

	// G2A: register every front-facing rewrite once so it survives activation.
	if ( class_exists( '\WpisticFFL\G2A_Account' ) ) {
		( new \WpisticFFL\G2A_Account() )->register_endpoint();
	} else {
		add_rewrite_endpoint( 'ffl-transfers', EP_ROOT | EP_PAGES );
	}
	if ( class_exists( '\WpisticFFL\G2A_Customer_Tracking' ) ) {
		( new \WpisticFFL\G2A_Customer_Tracking() )->register_rewrites();
	}
	if ( class_exists( '\WpisticFFL\G2A_Form_4473' ) ) {
		( new \WpisticFFL\G2A_Form_4473() )->register_rewrites();
	}
	if ( class_exists( '\WpisticFFL\G2A_Scorecard' ) ) {
		( new \WpisticFFL\G2A_Scorecard() )->register_rewrites();
	}

	flush_rewrite_rules();
} );

register_deactivation_hook( __FILE__, function (): void {
	// Cancel from both engines (Action Scheduler + WP-Cron) — defensive.
	$hooks = [
		'wpistic_ffl_process_zip_import',
		'wpistic_ffl_process_atf_sync',
		'wpistic_ffl_monthly_sync',
		'wpistic_ffl_daily_portal_runner',
		'wpistic_ffl_carrier_poll',
		'wpistic_ffl_async_issue_dealer_token',
		'wpistic_ffl_dealer_health_check',
		'wpistic_ffl_webhook_retry',
	];
	foreach ( $hooks as $hook ) {
		wp_clear_scheduled_hook( $hook );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( $hook, [], 'wpistic-ffl' );
		}
	}
	flush_rewrite_rules();
} );

// ── Custom cron intervals ─────────────────────────────────────────────────────
add_filter( 'cron_schedules', function ( array $schedules ): array {
	if ( ! isset( $schedules['every_minute'] ) ) {
		$schedules['every_minute'] = [
			'interval' => 60,
			'display'  => __( 'Every Minute', 'advanced-ffl-checkout' ),
		];
	}
	if ( ! isset( $schedules['monthly'] ) ) {
		$schedules['monthly'] = [
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => __( 'Monthly', 'advanced-ffl-checkout' ),
		];
	}
	return $schedules;
} );

// ── CORS — allow G2A Dashboard (app.guns2ammo.com) to call WP REST API ───────
// Scoped to plugin REST namespace only — prevents leaking CORS headers across the whole site.
add_action( 'rest_api_init', function (): void {
	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

	add_filter( 'rest_pre_serve_request', function ( $served, $result, $request ) {
		$route = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		if ( strpos( $route, '/' . WPISTIC_FFL_REST_NS . '/' ) !== 0 ) {
			return $served;
		}

		$allowed_origins = array(
			'https://app.guns2ammo.com',
			'http://localhost:3000',
			'http://localhost:5173',
		);
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( in_array( $origin, $allowed_origins, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With' );
			header( 'Access-Control-Max-Age: 600' );
			header( 'Vary: Origin', false );
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 200 );
			exit();
		}

		return $served;
	}, 10, 3 );
}, 15 );

// ── Boot ──────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function (): void {

	load_plugin_textdomain( 'advanced-ffl-checkout', false, dirname( WPISTIC_FFL_BASENAME ) . '/languages' );

	// Run schema upgrade if version changed (handles upgrades without re-activation)
	if ( get_option( 'wpistic_ffl_db_version' ) !== \WpisticFFL\DB::SCHEMA_VERSION ) {
		\WpisticFFL\DB::install();
	}

	// v1.1.3 — force-create default settings when they are missing.
	// Plugin ZIP upgrades do NOT re-run register_activation_hook, so options
	// added in later versions would never exist and the auto-send-on-ship hook
	// would silently skip everything. This closes that gap on every page load.
	wpistic_ffl_ensure_default_options();

	// Guard: WooCommerce required
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function (): void {
			echo '<div class="notice notice-error"><p>'
				. '<strong>Advanced FFL Checkout Solutions:</strong> '
				. esc_html__( 'WooCommerce is required and must be active.', 'advanced-ffl-checkout' )
				. '</p></div>';
		} );
		return;
	}

	// Core modules
	new \WpisticFFL\API();
	new \WpisticFFL\Checkout();
	new \WpisticFFL\JWT_Bridge();
	new \WpisticFFL\Users();
	new \WpisticFFL\Compliance();

	// v1.1.0 — dealer portal stack
	new \WpisticFFL\Settings();
	new \WpisticFFL\Theming();
	new \WpisticFFL\Portal();

	// Admin
	if ( is_admin() ) {
		new \WpisticFFL\Admin();
	}

	// Cron handlers
	add_action( 'wpistic_ffl_process_zip_import',  [ '\WpisticFFL\Zip_Import', 'process_chunk' ] );
	add_action( 'wpistic_ffl_process_atf_sync',    [ '\WpisticFFL\Sync',       'process_chunk' ] );
	add_action( 'wpistic_ffl_monthly_sync',        [ '\WpisticFFL\Sync',       'start_full_sync' ] );
	add_action( 'wpistic_ffl_daily_portal_runner', [ '\WpisticFFL\Portal',     'cron_runner' ] );

	// G2A: async dealer token issuance — invoked via wp_schedule_single_event so
	// a slow SMTP server never stalls WooCommerce checkout.
	add_action( 'wpistic_ffl_async_issue_dealer_token', function ( $transfer_id ): void {
		\WpisticFFL\Portal::issue_and_send( (int) $transfer_id );
	}, 10, 1 );

	// Auto-issue + email a dealer confirmation token when admin marks a transfer shipped.
	add_action( 'wpistic_ffl_transfer_status_changed', function ( $transfer_id, $old_status, $new_status ): void {
		$portal = get_option( 'wpistic_ffl_portal_settings', [] );
		if ( empty( $portal['enabled'] ) || empty( $portal['auto_send_on_ship'] ) ) {
			return;
		}
		if ( 'shipped_to_dealer' === $new_status ) {
			\WpisticFFL\G2A_Scheduler::async( 'wpistic_ffl_async_issue_dealer_token', [ (int) $transfer_id ] );
		}
	}, 10, 3 );

	// G2A: WC order status → transfer status bridge (boots its own hooks).
	new \WpisticFFL\G2A_Status_Bridge();

	// G2A: NICS 3-day rule helper — auto-fills expiry + nightly admin alerts.
	new \WpisticFFL\G2A_NICS();
	add_action( 'wpistic_ffl_daily_portal_runner', [ '\WpisticFFL\G2A_NICS', 'maybe_flag_delays' ], 20 );

	// G2A: SMS notifications via Verifyistic (if installed) — sends customer SMS
	// on key status changes. Silently no-ops when Verifyistic is absent or no phone.
	new \WpisticFFL\G2A_SMS();

	// G2A: customer "My FFL Transfers" WC account tab.
	new \WpisticFFL\G2A_Account();

	// G2A: theme bridge — capture Transfer Request form posts as draft transfers.
	new \WpisticFFL\G2A_Bridge();

	// G2A: public per-transfer tracking page (HMAC-protected, no login).
	new \WpisticFFL\G2A_Customer_Tracking();

	// G2A: admin Activity Log page + JSON endpoints for the dashboard.
	new \WpisticFFL\G2A_Activity();

	// G2A: compliance + security audit page + token-secret nag + regen handler.
	new \WpisticFFL\G2A_Compliance_Check();
	add_action( 'admin_init', [ '\WpisticFFL\G2A_Compliance_Check', 'maybe_regenerate_secret' ] );

	// G2A: carrier tracking auto-advance on shipment_tracking entry.
	new \WpisticFFL\G2A_Carrier();

	// G2A: live carrier providers (EasyPost pull + webhook receiver for Shippo/EasyPost/AfterShip/ShipStation).
	new \WpisticFFL\G2A_Carrier_Providers();

	// G2A: per-customer saved-dealer registry — quick-pick at checkout + manage in My Account.
	new \WpisticFFL\G2A_Saved_Dealers();

	// G2A: generic outbound webhook dispatcher (Zapier/Make/n8n/custom CRM).
	new \WpisticFFL\G2A_Webhooks_Out();

	// G2A: ops tools — bulk dealer fees, customer LTV lookup, nightly health alerts.
	new \WpisticFFL\G2A_Ops_Tools();

	// G2A: admin TOTP 2FA (opt-in per user).
	new \WpisticFFL\G2A_Admin_2FA();

	// G2A: WP personal-data exporter / eraser for FFL transfers.
	new \WpisticFFL\G2A_Gdpr();

	// G2A: public [g2a_ffl_dealer_onboard] shortcode for FFLs to apply.
	new \WpisticFFL\G2A_Dealer_Onboarding();

	// G2A: 4473 worksheet draft generator + dealer scorecard PDFs (admin-only URLs).
	new \WpisticFFL\G2A_Form_4473();
	new \WpisticFFL\G2A_Scorecard();

	// G2A: 50-state law engine top-up — ensures every state has a baseline rule.
	new \WpisticFFL\G2A_State_Laws();

	// G2A: FFL Compliance Verification Hub — certified-copy tracking, manual
	// eZ Check logging, and an ATF-sync validity check for dealers this
	// store has actually shipped to.
	new \WpisticFFL\G2A_Ffl_Verification();

}, 20 );

// ── v1.1.3 — Default options bootstrap ────────────────────────────────────────
function wpistic_ffl_ensure_default_options(): void {
	// Portal settings — ALWAYS enabled + auto-send unless user has explicitly turned off
	$portal_defaults = [
		'enabled'                  => 1,
		'token_expiry_days'        => 30,
		'two_factor_method'        => 'last4_ffl',
		'portal_slug'              => WPISTIC_FFL_PORTAL_SLUG,
		'single_use'               => 1,
		'rate_limit_per_min'       => 5,
		'allow_replay_view'        => 1,
		'auto_send_on_ship'        => 1,
		'notify_dealer_on_order'   => 1,
		'reminder_days'            => '3,7,14',
		'track_email_opens'        => 1,
		'analytics_retention_days' => 365,
		'require_https'            => 0,
	];
	$portal_current = get_option( 'wpistic_ffl_portal_settings', false );
	if ( false === $portal_current || ! is_array( $portal_current ) ) {
		update_option( 'wpistic_ffl_portal_settings', $portal_defaults );
	} else {
		// Fill in any missing keys without overwriting existing user choices
		$merged = array_merge( $portal_defaults, $portal_current );
		if ( $merged !== $portal_current ) {
			update_option( 'wpistic_ffl_portal_settings', $merged );
		}
	}

	// Legacy general settings — ensure email toggles default to TRUE (not false from empty POST)
	$general_defaults = [
		'default_radius'           => 50,
		'notification_email'       => get_option( 'admin_email' ),
		'from_name'                => get_bloginfo( 'name' ),
		'customer_emails'          => 1,   // explicit truthy — fixes silent-fail
		'admin_emails'             => 1,
		'disable_emails'           => 0,
		'delete_data_on_uninstall' => 0,
		'google_maps_key'          => '',
	];
	$gen_current = get_option( 'wpistic_ffl_settings', false );
	if ( false === $gen_current || ! is_array( $gen_current ) ) {
		update_option( 'wpistic_ffl_settings', $general_defaults );
	} else {
		// Fill only missing keys — respect existing user toggles
		$merged = array_merge( $general_defaults, $gen_current );
		if ( $merged !== $gen_current ) {
			update_option( 'wpistic_ffl_settings', $merged );
		}
	}

	// Theme settings
	if ( false === get_option( 'wpistic_ffl_theme_settings', false ) && class_exists( '\WpisticFFL\Theming' ) ) {
		update_option( 'wpistic_ffl_theme_settings', \WpisticFFL\Theming::default_theme_settings() );
	}

	// Email templates settings
	if ( false === get_option( 'wpistic_ffl_email_settings', false ) ) {
		update_option( 'wpistic_ffl_email_settings', [
			'from_name'                => get_bloginfo( 'name' ),
			'from_email'               => get_option( 'admin_email' ),
			'reply_to'                 => '',
			'confirmation_subject'     => '',
			'confirmation_intro'       => '',
			'reminder_subject'         => '',
			'reminder_intro'           => '',
			'admin_notify_on_confirm'  => 1,
			'admin_notify_on_issue'    => 1,
		] );
	}

	// Ensure HMAC token secret exists
	if ( ! get_option( 'wpistic_ffl_token_secret' ) ) {
		update_option( 'wpistic_ffl_token_secret', wp_generate_password( 64, true, true ), false );
	}
}

// ── Declare WC HPOS compatibility ─────────────────────────────────────────────
add_action( 'before_woocommerce_init', function (): void {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
	}
} );
