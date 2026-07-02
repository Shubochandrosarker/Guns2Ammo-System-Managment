<?php
/**
 * Verifyistic settings tab.
 *
 * @package G2AB\Modules\Verifyistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Verifyistic_Settings {

	public function __construct() {
		add_filter( 'g2ab_settings_tabs', array( $this, 'register_tab' ) );
		add_action( 'g2ab_settings_render_verifyistic', array( $this, 'render' ) );
		add_action( 'admin_post_g2ab_save_verifyistic', array( $this, 'save' ) );
	}

	public function register_tab( $tabs ) {
		$tabs['verifyistic'] = 'Age Verification';
		return $tabs;
	}

	public function render() {
		$module       = G2AB_Module_Verifyistic::instance();
		$active       = $module->verifyistic_active();
		$enabled      = (int) get_option( G2AB_Module_Verifyistic::OPT_ENABLED, 1 );
		$require      = (int) get_option( G2AB_Module_Verifyistic::OPT_REQUIRE_VERIFICATION, 0 );
		$auto_waiver  = (int) get_option( G2AB_Module_Verifyistic::OPT_AUTO_WAIVER, 1 );
		$autofill     = (int) get_option( G2AB_Module_Verifyistic::OPT_AUTOFILL_NAME, 1 );
		$max_age      = (int) get_option( G2AB_Module_Verifyistic::OPT_MAX_AGE, 525600 );

		// Counters from the Verifyistic logs table — only if it exists.
		$today_count = 0;
		$total_count = 0;
		$recent      = array();
		if ( $active ) {
			global $wpdb;
			$table = $wpdb->prefix . 'verifyistic_logs';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$today_count = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE DATE(verified_at) = %s AND status = 'passed'",
					current_time( 'Y-m-d' )
				) );
				$total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'passed'" );
				$recent      = (array) $wpdb->get_results(
					"SELECT id, first_name, last_name, age_at_verify, verify_type, verified_at, ip_address FROM {$table} WHERE status = 'passed' ORDER BY id DESC LIMIT 8"
				);
			}
		}

		$msg = ! empty( $_GET['saved'] ) ? '<div class="notice notice-success is-dismissible"><p>Age verification settings saved.</p></div>' : '';
		?>
		<style>
			.g2ab-vfy__panel{background:#fff;border:1px solid #d0d4d9;padding:24px;margin-bottom:18px;border-radius:8px;}
			.g2ab-vfy__status{display:inline-block;padding:4px 10px;border-radius:2px;color:#fff;font-size:11px;letter-spacing:.06em;font-weight:700;}
			.g2ab-vfy__row{margin-bottom:16px;}
			.g2ab-vfy__row label{display:block;font-weight:600;margin-bottom:6px;font-size:13px;color:#0f2044;}
			.g2ab-vfy__row input[type=number]{width:120px;}
			.g2ab-vfy__row small{display:block;color:#646970;font-size:12px;margin-top:6px;}
			.g2ab-vfy__inline{display:flex;align-items:center;gap:8px;font-weight:500!important;text-transform:none;letter-spacing:0;}
			.g2ab-vfy__kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px;}
			.g2ab-vfy__kpi{background:#fff;border:1px solid #d0d4d9;border-top:3px solid #14b8a6;padding:14px 16px;border-radius:6px;}
			.g2ab-vfy__kpi span{display:block;font-size:11px;color:#8A95A5;letter-spacing:.06em;font-weight:700;text-transform:uppercase;}
			.g2ab-vfy__kpi strong{display:block;font-size:24px;color:#0f2044;font-weight:800;margin-top:6px;}
			.g2ab-vfy__table{width:100%;border-collapse:collapse;}
			.g2ab-vfy__table th,.g2ab-vfy__table td{padding:8px 10px;border-bottom:1px solid #f0f1f3;font-size:13px;text-align:left;}
			.g2ab-vfy__table th{color:#646970;font-size:11px;letter-spacing:.06em;text-transform:uppercase;}
			.g2ab-vfy__example{background:#f0fdfa;border-left:4px solid #14b8a6;padding:12px 14px;margin:10px 0;font-size:13px;}
		</style>

		<?php echo $msg; // phpcs:ignore ?>

		<div class="g2ab-vfy__panel">
			<h2 style="margin-top:0;">Verifyistic Plugin
				<?php if ( $active ) : ?>
					<span class="g2ab-vfy__status" style="background:#4CAF50;">DETECTED</span>
				<?php else : ?>
					<span class="g2ab-vfy__status" style="background:#C62828;">NOT INSTALLED</span>
				<?php endif; ?>
			</h2>
			<?php if ( ! $active ) : ?>
				<p>Install and activate the <strong>Verifyistic — Advanced Age Verification</strong> plugin to use this integration. Once installed, configure the age-verification popup separately under "Verifyistic" in your WordPress admin.</p>
				<p><em>This integration only links Verifyistic verifications to G2A bookings — it doesn't replace the Verifyistic popup.</em></p>
			<?php else : ?>
				<p>The Verifyistic popup is detected. When a visitor passes age verification, their record is stored in <code>wp_verifyistic_logs</code> and a cookie is set. G2A reads that cookie and attaches the verification to each booking they make.</p>
				<div class="g2ab-vfy__example">
					<strong>How it works:</strong>
					Customer opens the site → Verifyistic popup → enters DOB / clicks Yes → cookie set → customer books a lane → G2A stores the verification token + verified DOB + name on the booking row.
				</div>
				<?php if ( $total_count ) : ?>
					<div class="g2ab-vfy__kpis">
						<div class="g2ab-vfy__kpi"><span>Today</span><strong><?php echo (int) $today_count; ?></strong></div>
						<div class="g2ab-vfy__kpi"><span>All-Time Passed</span><strong><?php echo (int) $total_count; ?></strong></div>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'g2ab_save_verifyistic', '_g2ab_nonce' ); ?>
			<input type="hidden" name="action" value="g2ab_save_verifyistic" />

			<div class="g2ab-vfy__panel">
				<h2 style="margin-top:0;">Integration Settings</h2>

				<div class="g2ab-vfy__row">
					<label class="g2ab-vfy__inline">
						<input type="checkbox" name="enabled" value="1" <?php checked( 1, $enabled ); ?> />
						Enable Verifyistic ↔ G2A linking
					</label>
					<small>When disabled, bookings are saved without any age-verification reference.</small>
				</div>

				<div class="g2ab-vfy__row">
					<label class="g2ab-vfy__inline">
						<input type="checkbox" name="require" value="1" <?php checked( 1, $require ); ?> />
						Require age verification before booking
					</label>
					<small>
						When ON, the <code>/wp-json/g2a-booking/v1/bookings</code> endpoint refuses POSTs from visitors without a valid <code>verifyistic_verified</code> cookie. The customer sees a clear "please verify your age first" error and the booking page popup handles the rest. Staff with <code>manage_g2ab_bookings</code> capability are exempt (so you can still book guests in person via admin).
					</small>
				</div>

				<div class="g2ab-vfy__row">
					<label class="g2ab-vfy__inline">
						<input type="checkbox" name="auto_waiver" value="1" <?php checked( 1, $auto_waiver ); ?> />
						Auto-accept liability waiver for age-verified visitors
					</label>
					<small>If a customer has already been verified through Verifyistic, mark the booking's <code>waiver_signed</code> column as 1 automatically. Saves customers from clicking the same checkbox twice.</small>
				</div>

				<div class="g2ab-vfy__row">
					<label class="g2ab-vfy__inline">
						<input type="checkbox" name="autofill" value="1" <?php checked( 1, $autofill ); ?> />
						Allow the booking form to pre-fill name from verification record
					</label>
					<small>When ON, the frontend can request <code>/wp-json/g2a-booking/v1/verifyistic/me</code> and pre-populate the customer-name field from the Verifyistic record (DOB-mode and ID-face-mode capture first/last name).</small>
				</div>

				<div class="g2ab-vfy__row">
					<label>Maximum verification age (minutes)</label>
					<input type="number" name="max_age" min="1" max="525600" value="<?php echo esc_attr( $max_age ); ?>" />
					<small>How recent the verification must be to count for a booking. The default <strong>525600</strong> (≈1 year) effectively disables this window so the Verifyistic cookie's own expiry is what matters — recommended, and it prevents validly-verified returning customers from being blocked. Only lower it (e.g. 1440 = 24h) if you specifically want to force re-verification sooner than the Verifyistic cookie lasts.</small>
				</div>
			</div>

			<button class="button button-primary">Save Integration Settings</button>
		</form>

		<?php if ( $active && ! empty( $recent ) ) : ?>
			<div class="g2ab-vfy__panel">
				<h2 style="margin-top:0;">Recent Passed Verifications</h2>
				<table class="g2ab-vfy__table">
					<thead>
						<tr><th>ID</th><th>Name</th><th>Age</th><th>Type</th><th>When</th><th>IP</th></tr>
					</thead>
					<tbody>
					<?php foreach ( $recent as $r ) : ?>
						<tr>
							<td>#<?php echo (int) $r->id; ?></td>
							<td><?php echo esc_html( trim( $r->first_name . ' ' . $r->last_name ) ?: '—' ); ?></td>
							<td><?php echo (int) $r->age_at_verify ?: '—'; ?></td>
							<td><?php echo esc_html( strtoupper( str_replace( '_', ' ', $r->verify_type ) ) ); ?></td>
							<td><?php echo esc_html( mysql2date( 'M j · g:i A', $r->verified_at ) ); ?></td>
							<td><code><?php echo esc_html( $r->ip_address ); ?></code></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top:14px;"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=verifyistic-logs' ) ); ?>">Open Verifyistic Logs →</a></p>
			</div>
		<?php endif; ?>
		<?php
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_verifyistic', '_g2ab_nonce' );

		update_option( G2AB_Module_Verifyistic::OPT_ENABLED, isset( $_POST['enabled'] ) ? 1 : 0 );
		update_option( G2AB_Module_Verifyistic::OPT_REQUIRE_VERIFICATION, isset( $_POST['require'] ) ? 1 : 0 );
		update_option( G2AB_Module_Verifyistic::OPT_AUTO_WAIVER, isset( $_POST['auto_waiver'] ) ? 1 : 0 );
		update_option( G2AB_Module_Verifyistic::OPT_AUTOFILL_NAME, isset( $_POST['autofill'] ) ? 1 : 0 );

		$max_age = isset( $_POST['max_age'] ) ? max( 1, min( 525600, (int) $_POST['max_age'] ) ) : 525600;
		update_option( G2AB_Module_Verifyistic::OPT_MAX_AGE, $max_age );

		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'verifyistic', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
