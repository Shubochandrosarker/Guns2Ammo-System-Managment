<?php
/**
 * G2A — FFL Compliance & Security self-check.
 *
 * One-page audit that turns "is everything wired correctly?" into a list of
 * green/amber/red checks the admin can act on. Same data exposed at
 * /wp-json/wpistic-ffl/v1/compliance/audit for the G2A dashboard.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Compliance_Check {

	public function __construct() {
		add_action( 'admin_menu',    [ $this, 'register_admin_page' ], 25 );
		add_action( 'admin_notices', [ $this, 'maybe_token_secret_notice' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_admin_page(): void {
		add_submenu_page(
			'wpistic-ffl',
			__( 'Compliance & Security', 'advanced-ffl-checkout' ),
			__( '🛡️ Compliance', 'advanced-ffl-checkout' ),
			'manage_options',
			'wpistic-ffl-compliance',
			[ $this, 'render_admin_page' ]
		);
	}

	public function render_admin_page(): void {
		$audit = self::run_audit();
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:12px;">
				<span style="font-size:24px;">🛡️</span>
				<?php esc_html_e( 'FFL Compliance & Security Audit', 'advanced-ffl-checkout' ); ?>
			</h1>
			<p class="description">
				<?php esc_html_e( 'Real-time check of every piece the FFL workflow depends on — secrets, schema, settings, hooks, integration with the G2A theme + Verifyistic + Memberistic. Re-run any time by refreshing this page.', 'advanced-ffl-checkout' ); ?>
			</p>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:20px 0;">
				<?php foreach ( $audit['summary'] as $key => $count ) :
					$color = 'pass' === $key ? '#16A34A' : ( 'warn' === $key ? '#F59E0B' : ( 'fail' === $key ? '#DC2626' : '#3B82F6' ) ); ?>
					<div style="padding:16px;border-radius:10px;background:<?php echo esc_attr( $color ); ?>;color:#fff;text-align:center;">
						<div style="font-size:28px;font-weight:800;"><?php echo esc_html( (string) $count ); ?></div>
						<div style="font-size:11px;text-transform:uppercase;letter-spacing:.1em;opacity:.85;"><?php echo esc_html( ucfirst( $key ) ); ?></div>
					</div>
				<?php endforeach; ?>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th style="width:80px;">Status</th>
						<th>Check</th>
						<th>Details</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $audit['checks'] as $check ) :
					$colors = [ 'pass' => '#16A34A', 'warn' => '#F59E0B', 'fail' => '#DC2626', 'info' => '#3B82F6' ];
					$bg = $colors[ $check['status'] ] ?? '#888';
					?>
					<tr>
						<td><span style="display:inline-block;padding:3px 10px;border-radius:999px;background:<?php echo esc_attr( $bg ); ?>;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;"><?php echo esc_html( $check['status'] ); ?></span></td>
						<td><strong><?php echo esc_html( $check['name'] ); ?></strong><br><span style="color:#666;font-size:12px;"><?php echo esc_html( $check['description'] ); ?></span></td>
						<td style="font-family:ui-monospace,monospace;font-size:12px;color:#444;"><?php echo wp_kses_post( $check['detail'] ?? '' ); ?></td>
						<td><?php echo wp_kses_post( $check['action'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2 style="margin-top:32px;"><?php esc_html_e( 'Quick actions', 'advanced-ffl-checkout' ); ?></h2>
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'wpistic_ffl_security_actions' ); ?>
				<input type="hidden" name="wpistic_ffl_security_action" value="regenerate_token_secret">
				<button type="submit" class="button button-secondary"
				        onclick="return confirm('<?php esc_attr_e( "Regenerate the HMAC secret? Every live dealer-portal token will be invalidated immediately. Dealers with open emails will need new links — admin can resend from each transfer.", 'advanced-ffl-checkout' ); ?>');">
					🔄 <?php esc_html_e( 'Regenerate HMAC Token Secret (revokes all live tokens)', 'advanced-ffl-checkout' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	// ── Audit checks ────────────────────────────────────────────────────────

	public static function run_audit(): array {
		global $wpdb;
		$checks = [];

		// 1. WooCommerce present + HPOS compat
		$checks[] = [
			'name'        => 'WooCommerce active',
			'description' => 'Plugin requires WooCommerce 7.0+ for checkout integration.',
			'status'      => class_exists( 'WooCommerce' ) ? 'pass' : 'fail',
			'detail'      => class_exists( 'WooCommerce' ) ? 'Loaded' : 'Not loaded',
			'action'      => class_exists( 'WooCommerce' ) ? '' : '<a href="' . esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ) . '">Install WooCommerce</a>',
		];

		// 2. HPOS compatibility declared
		$hpos_declared = class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' );
		$checks[] = [
			'name'        => 'HPOS / Cart Blocks compatibility',
			'description' => 'Declared so WooCommerce loads us under modern order storage.',
			'status'      => $hpos_declared ? 'pass' : 'warn',
			'detail'      => $hpos_declared ? 'before_woocommerce_init declared' : 'WC not loaded — check on next page load',
			'action'      => '',
		];

		// 3. Token secret in wp-config
		$secret_in_config = defined( 'WPISTIC_FFL_TOKEN_SECRET' ) && WPISTIC_FFL_TOKEN_SECRET;
		$checks[] = [
			'name'        => 'HMAC token secret in wp-config.php',
			'description' => 'Define WPISTIC_FFL_TOKEN_SECRET in wp-config.php for the strongest portal token security. Database-stored fallback is supported but lower-grade.',
			'status'      => $secret_in_config ? 'pass' : 'warn',
			'detail'      => $secret_in_config ? 'wp-config constant detected' : 'Using DB-stored auto-secret',
			'action'      => $secret_in_config ? '' : '<code>define( \'WPISTIC_FFL_TOKEN_SECRET\', \'' . esc_html( substr( wp_generate_password( 64, true, true ), 0, 32 ) ) . '...\' );</code>',
		];

		// 4. Trusted proxies if behind LB
		$trusted = (array) apply_filters( 'wpistic_ffl_trusted_proxies', [] );
		$cf_header = ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] );
		$xff       = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$proxy_sane = empty( $trusted ) && ( $cf_header || $xff );
		$checks[] = [
			'name'        => 'Trusted-proxy CIDR list',
			'description' => 'If you sit behind Cloudflare or a load balancer, set the wpistic_ffl_trusted_proxies filter so client IPs in the audit log are accurate.',
			'status'      => $proxy_sane ? 'warn' : 'pass',
			'detail'      => $proxy_sane ? 'CF/XFF headers seen but no proxies trusted' : ( empty( $trusted ) ? 'No proxy headers seen' : count( $trusted ) . ' CIDR(s) trusted' ),
			'action'      => $proxy_sane ? 'Hook <code>wpistic_ffl_trusted_proxies</code> in functions.php' : '',
		];

		// 5. JWT bridge / "JWT Authentication for WP REST API" plugin
		$jwt_loaded = defined( 'JWT_AUTH_PLUGIN_VERSION' ) || class_exists( 'Jwt_Auth_Public' );
		$checks[] = [
			'name'        => 'JWT Authentication plugin',
			'description' => 'Required for the G2A dashboard app to authenticate against this WP install. Not needed for checkout to work.',
			'status'      => $jwt_loaded ? 'pass' : 'info',
			'detail'      => $jwt_loaded ? 'Detected' : 'Not installed (optional)',
			'action'      => $jwt_loaded ? '' : '<a href="' . esc_url( admin_url( 'plugin-install.php?s=JWT+Authentication&tab=search&type=term' ) ) . '">Install JWT Auth</a>',
		];

		// 6. Verifyistic for SMS dispatch
		$verifyistic_loaded = class_exists( '\Verifyistic_Webhooks' );
		$checks[] = [
			'name'        => 'Verifyistic webhook dispatcher',
			'description' => 'Used to forward customer SMS events to your provider (Twilio, OtterText, etc.). Silent no-op when absent.',
			'status'      => $verifyistic_loaded ? 'pass' : 'info',
			'detail'      => $verifyistic_loaded ? 'Loaded' : 'Not installed — SMS notifications disabled',
			'action'      => '',
		];

		// 7. Cron events all scheduled.
		// Each entry: [ hook, label, done_check ] where done_check is an optional
		// closure that returns true when this cron has legitimately finished its
		// work — in which case "not scheduled" is correct behavior, not a problem.
		$expected_crons = [
			[
				'wpistic_ffl_process_zip_import',
				function () {
					$status = (string) get_option( 'wpistic_ffl_zip_import_status', '' );
					return in_array( $status, [ 'complete', 'completed', 'done', 'finished' ], true );
				},
			],
			[ 'wpistic_ffl_monthly_sync', null ],
			[ 'wpistic_ffl_daily_portal_runner', null ],
		];
		foreach ( $expected_crons as $entry ) {
			[ $hook, $done_check ] = $entry;
			$next      = wp_next_scheduled( $hook );
			// Also check Action Scheduler — v1.6 migrated async paths to it,
			// and AS-scheduled actions don't appear in wp_next_scheduled().
			$as_pending = function_exists( 'as_next_scheduled_action' )
				&& \as_next_scheduled_action( $hook, [], 'wpistic-ffl' );
			$is_done   = is_callable( $done_check ) ? (bool) $done_check() : false;

			if ( $next || $as_pending ) {
				$status = 'pass';
				$detail = $next
					? 'Next run: ' . esc_html( date_i18n( 'Y-m-d H:i', $next ) )
					: 'Pending via Action Scheduler';
			} elseif ( $is_done ) {
				$status = 'pass';
				$detail = 'Work complete — cron correctly unscheduled';
			} else {
				$status = 'warn';
				$detail = 'Not scheduled — deactivate/reactivate the plugin to fix';
			}

			$checks[] = [
				'name'        => 'Cron: ' . $hook,
				'description' => 'Scheduled task that drives background processing.',
				'status'      => $status,
				'detail'      => $detail,
				'action'      => '',
			];
		}

		// 8. ZIP / ATF data populated
		$zip_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'zip_coords' ) );
		$checks[] = [
			'name'        => 'ZIP centroid data',
			'description' => 'Needed for distance-based dealer search.',
			'status'      => $zip_count >= 30000 ? 'pass' : ( $zip_count > 0 ? 'warn' : 'fail' ),
			'detail'      => $zip_count > 0 ? number_format( $zip_count ) . ' rows' : 'Empty — import not yet run',
			'action'      => $zip_count > 0 ? '' : '<a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl' ) ) . '">Start ZIP import</a>',
		];
		$dealer_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'dealers' ) );
		$checks[] = [
			'name'        => 'ATF dealer database',
			'description' => 'Federal FFL licensee list. Refreshes monthly from atf.gov.',
			'status'      => $dealer_count >= 50000 ? 'pass' : ( $dealer_count > 0 ? 'warn' : 'fail' ),
			'detail'      => $dealer_count > 0 ? number_format( $dealer_count ) . ' rows' : 'Empty — sync not yet run',
			'action'      => $dealer_count > 0 ? '' : '<a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl' ) ) . '">Run sync</a>',
		];

		// 9. Receiving FFL license configured
		$theme        = Theming::settings();
		$has_license  = ! empty( $theme['ffl_license'] );
		$checks[] = [
			'name'        => 'Store FFL license number',
			'description' => 'Your receiving FFL — surfaced in customer emails and the dealer-confirmation portal.',
			'status'      => $has_license ? 'pass' : 'warn',
			'detail'      => $has_license ? esc_html( $theme['ffl_license'] ) : 'Not configured',
			'action'      => $has_license ? '' : '<a href="' . esc_url( admin_url( 'customize.php?autofocus[section]=g2a_business' ) ) . '">Set in Customizer → G2A Business Info</a>',
		];

		// 10. Portal slug + email defaults present
		$portal = get_option( 'wpistic_ffl_portal_settings', [] );
		$checks[] = [
			'name'        => 'Portal settings present',
			'description' => 'Auto-bootstrapped on every load (defends against ZIP-upgrade-without-reactivation).',
			'status'      => ( is_array( $portal ) && ! empty( $portal ) ) ? 'pass' : 'fail',
			'detail'      => is_array( $portal ) ? count( $portal ) . ' keys' : 'Missing',
			'action'      => '',
		];

		// 11. State compliance seed data
		$rules_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'state_rules' ) );
		$checks[] = [
			'name'        => 'State compliance rules',
			'description' => 'Customer-facing checkout notices for high-regulation states (CA, NY, NJ, MA, MD, IL, HI, CO, WA).',
			'status'      => $rules_count >= 12 ? 'pass' : ( $rules_count > 0 ? 'warn' : 'fail' ),
			'detail'      => $rules_count . ' rules seeded',
			'action'      => '',
		];

		// 12. Pending NICS 3-day flags
		$pending_nics = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT COUNT(*) FROM ' . DB::table( 'transfers' ) . " WHERE status IN ('delayed','nics_delayed') AND nics_delay_expires <= %s",
			current_time( 'Y-m-d' )
		) );
		$checks[] = [
			'name'        => 'NICS 3-day rule attention queue',
			'description' => 'Federal 3-business-day window — once elapsed the dealer may proceed at their discretion.',
			'status'      => $pending_nics > 0 ? 'warn' : 'pass',
			'detail'      => $pending_nics > 0 ? $pending_nics . ' transfer(s) at or past the 3-day window' : 'No transfers waiting',
			'action'      => $pending_nics > 0 ? '<a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers' ) ) . '">Review transfers</a>' : '',
		];

		// 13. Dealers with no email set
		$dealers_with_email = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . DB::table( 'dealers' ) . " WHERE email != ''" );
		$checks[] = [
			'name'        => 'Dealers reachable by email',
			'description' => 'A dealer without an email falls back to your admin inbox for the confirmation portal link.',
			'status'      => $dealers_with_email > 0 ? 'pass' : 'info',
			'detail'      => $dealers_with_email > 0 ? number_format( $dealers_with_email ) . ' dealer(s) have an email' : 'No dealer emails set yet',
			'action'      => '',
		];

		// 14. Active dealer tokens count
		$active_tokens = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . DB::table( 'dealer_tokens' ) . " WHERE status = 'active'" );
		$checks[] = [
			'name'        => 'Active portal tokens',
			'description' => 'Single-use, HMAC-signed, expiring magic links currently outstanding.',
			'status'      => 'info',
			'detail'      => number_format( $active_tokens ) . ' active tokens',
			'action'      => '',
		];

		// 15. Carrier provider configured
		if ( class_exists( '\WpisticFFL\G2A_Carrier_Providers' ) ) {
			$carrier = G2A_Carrier_Providers::settings();
			$provider_set = ! empty( $carrier['provider'] ) && 'none' !== $carrier['provider'];
			$has_key      = 'easypost' === $carrier['provider'] && ! empty( $carrier['easypost_api_key'] );
			$webhook_set  = ! empty( $carrier['webhook_secret'] );
			$status = 'info';
			$detail = 'Manual + webhook only';
			$action = '';
			if ( $has_key ) {
				$status = 'pass';
				$detail = 'EasyPost configured — daily pull active';
			} elseif ( $provider_set && ! $has_key ) {
				$status = 'warn';
				$detail = 'Provider selected but API key missing';
				$action = '<a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-carriers' ) ) . '">Add key</a>';
			} elseif ( $webhook_set ) {
				$status = 'pass';
				$detail = 'Webhook receiver ready';
			}
			$checks[] = [
				'name'        => 'Carrier auto-advance',
				'description' => 'Live tracking source so delivered parcels auto-advance to received_by_dealer without manual portal confirmation.',
				'status'      => $status,
				'detail'      => $detail,
				'action'      => $action,
			];
		}

		// 16. Memberistic JWT secret sharing (informational)
		$mem_loaded = class_exists( 'Memberistic_Membership_Solutions' ) || defined( 'MEMBERISTIC_VERSION' );
		$checks[] = [
			'name'        => 'Memberistic detected',
			'description' => 'Membership plugin sibling — shares user identity with the G2A dashboard.',
			'status'      => $mem_loaded ? 'pass' : 'info',
			'detail'      => $mem_loaded ? 'Loaded' : 'Not installed',
			'action'      => '',
		];

		// Summarize
		$summary = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 ];
		foreach ( $checks as $c ) {
			$summary[ $c['status'] ] = ( $summary[ $c['status'] ] ?? 0 ) + 1;
		}

		return [
			'checks'       => $checks,
			'summary'      => $summary,
			'generated_at' => gmdate( 'c' ),
		];
	}

	// ── Token secret notice + action ────────────────────────────────────────

	public function maybe_token_secret_notice(): void {
		// Only nag on our own pages.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, 'wpistic-ffl' ) === false ) {
			return;
		}
		if ( defined( 'WPISTIC_FFL_TOKEN_SECRET' ) && WPISTIC_FFL_TOKEN_SECRET ) {
			return;
		}
		if ( get_transient( 'wpistic_ffl_secret_notice_dismissed' ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'FFL Security:', 'advanced-ffl-checkout' ); ?></strong>
				<?php esc_html_e( 'For maximum portal-token security, define', 'advanced-ffl-checkout' ); ?>
				<code>WPISTIC_FFL_TOKEN_SECRET</code>
				<?php esc_html_e( 'in your wp-config.php. The plugin is currently using a DB-stored fallback secret — functional but not the strongest option.', 'advanced-ffl-checkout' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpistic-ffl-compliance' ) ); ?>">
					<?php esc_html_e( 'See compliance audit →', 'advanced-ffl-checkout' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Listen for POST from the compliance page "Regenerate" button.
	 * Hooked from the bootstrap on admin_init.
	 */
	public static function maybe_regenerate_secret(): void {
		if ( empty( $_POST['wpistic_ffl_security_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wpistic_ffl_security_actions' );

		$action = sanitize_key( wp_unslash( (string) $_POST['wpistic_ffl_security_action'] ) );
		if ( 'regenerate_token_secret' !== $action ) {
			return;
		}

		// Revoke every live token first so an attacker holding the old secret
		// can't continue to verify against the new one for old links.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'UPDATE ' . DB::table( 'dealer_tokens' ) . ' SET status = %s WHERE status = %s',
			'revoked',
			'active'
		) );

		// Rotate.
		update_option( 'wpistic_ffl_token_secret', wp_generate_password( 64, true, true ), false );

		// Audit.
		$wpdb->insert( DB::table( 'events' ), [ // phpcs:ignore WordPress.DB
			'transfer_id' => 0,
			'event_type'  => 'security_action',
			'notes'       => 'HMAC token secret regenerated; all active tokens revoked.',
			'actor'       => wp_get_current_user()->user_login ?: 'admin',
			'actor_ip'    => Token::client_ip(),
		] );

		add_action( 'admin_notices', function (): void {
			echo '<div class="notice notice-success is-dismissible"><p>HMAC secret rotated. All previously-issued dealer portal links are now invalid; resend from each transfer if needed.</p></div>';
		} );
	}

	// ── REST ────────────────────────────────────────────────────────────────

	public function register_routes(): void {
		register_rest_route( WPISTIC_FFL_REST_NS, '/compliance/audit', [
			'methods'             => 'GET',
			'permission_callback' => function (): bool {
				return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
			},
			'callback' => function (): \WP_REST_Response {
				return rest_ensure_response( self::run_audit() );
			},
		] );
	}
}
