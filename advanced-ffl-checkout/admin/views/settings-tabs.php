<?php
/**
 * Tabbed Settings view — v1.1.0
 *
 * Tabs:
 *   General      → legacy wpistic_ffl_settings (emails, radius, Maps key, uninstall)
 *   Dealer Portal→ token expiry, 2FA, slug, rate limits
 *   Theme        → portal colors/typography/logo/white-label
 *   Email Templates → subject/body overrides for confirmation + reminder emails
 *   License      → activation UI (stub for v1.2.0)
 *
 * @package WpisticFFL
 */
defined( 'ABSPATH' ) || exit;

$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

$portal = get_option( 'wpistic_ffl_portal_settings', [] );
$theme  = \WpisticFFL\Theming::settings();
$email  = get_option( 'wpistic_ffl_email_settings', [] );
$gen    = get_option( 'wpistic_ffl_settings', [] );
$lic    = \WpisticFFL\License::entitlements();

$tabs = [
	'general'      => __( 'General', 'advanced-ffl-checkout' ),
	'portal'       => __( 'Dealer Portal', 'advanced-ffl-checkout' ),
	'theme'        => __( 'Theme', 'advanced-ffl-checkout' ),
	'emails'       => __( 'Email Templates', 'advanced-ffl-checkout' ),
	'integrations' => __( 'Integrations', 'advanced-ffl-checkout' ),
	'license'      => __( 'License', 'advanced-ffl-checkout' ),
];

$integrations = \WpisticFFL\Integrations::get_settings();

$tab_url = static function ( string $tab ): string {
	return esc_url( admin_url( 'admin.php?page=wpistic-ffl-settings&tab=' . $tab ) );
};
?>

<div class="wrap wpistic-ffl-admin">
	<h1><?php esc_html_e( 'Advanced FFL — Settings', 'advanced-ffl-checkout' ); ?>
		<span style="font-size:12px;background:#e0e7ff;color:#4338ca;padding:3px 8px;border-radius:999px;font-weight:600;margin-left:8px;">v<?php echo esc_html( WPISTIC_FFL_VERSION ); ?></span>
	</h1>

	<h2 class="nav-tab-wrapper">
		<?php foreach ( $tabs as $slug => $label ) : ?>
			<a href="<?php echo $tab_url( $slug ); // phpcs:ignore ?>" class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<div id="wpistic-ffl-settings-saved" class="notice notice-success is-dismissible" style="display:none;margin-top:16px;">
		<p><strong>✓ <?php esc_html_e( 'Settings saved.', 'advanced-ffl-checkout' ); ?></strong></p>
	</div>

	<?php if ( 'general' === $active_tab ) : ?>

		<form id="wpistic-ffl-general-form" style="max-width:760px;margin-top:24px;">
			<?php wp_nonce_field( 'wpistic_ffl_admin_nonce', 'wpistic_ffl_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th><label for="default_radius"><?php esc_html_e( 'Default dealer search radius (miles)', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="number" id="default_radius" name="default_radius" value="<?php echo esc_attr( (string) ( $gen['default_radius'] ?? 50 ) ); ?>" min="5" max="200" class="small-text"> miles</td>
				</tr>
				<tr>
					<th><label for="notification_email"><?php esc_html_e( 'Admin notification email', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="email" id="notification_email" name="notification_email" value="<?php echo esc_attr( (string) ( $gen['notification_email'] ?? get_option( 'admin_email' ) ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="from_name"><?php esc_html_e( 'From name (outgoing emails)', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="from_name" name="from_name" value="<?php echo esc_attr( (string) ( $gen['from_name'] ?? get_bloginfo( 'name' ) ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email toggles', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label><input type="checkbox" name="customer_emails" <?php checked( ! empty( $gen['customer_emails'] ) || ! isset( $gen['customer_emails'] ) ); ?>> <?php esc_html_e( 'Send customer status emails', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="admin_emails" <?php checked( ! empty( $gen['admin_emails'] ) || ! isset( $gen['admin_emails'] ) ); ?>> <?php esc_html_e( 'Send admin notification emails', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="disable_emails" <?php checked( ! empty( $gen['disable_emails'] ) ); ?>> <?php esc_html_e( 'Disable all plugin emails (maintenance mode)', 'advanced-ffl-checkout' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="google_maps_key"><?php esc_html_e( 'Google Maps API key (optional)', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="google_maps_key" name="google_maps_key" value="<?php echo esc_attr( (string) ( $gen['google_maps_key'] ?? '' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Uninstall behaviour', 'advanced-ffl-checkout' ); ?></th>
					<td><label><input type="checkbox" name="delete_data_on_uninstall" <?php checked( ! empty( $gen['delete_data_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete all plugin data when plugin is deleted (irreversible).', 'advanced-ffl-checkout' ); ?></label></td>
				</tr>
			</table>
			<p><button type="button" class="button button-primary" id="wpistic-ffl-save-general"><?php esc_html_e( 'Save General Settings', 'advanced-ffl-checkout' ); ?></button></p>
		</form>

	<?php elseif ( 'portal' === $active_tab ) : ?>

		<form id="wpistic-ffl-portal-form" style="max-width:760px;margin-top:24px;">
			<h3><?php esc_html_e( 'Dealer Confirmation Portal', 'advanced-ffl-checkout' ); ?></h3>
			<p class="description"><?php esc_html_e( 'When a transfer is marked shipped, the plugin emails the receiving FFL a signed one-click link. Clicking it confirms receipt — no login, no account, no friction. Every action is logged for audit.', 'advanced-ffl-checkout' ); ?></p>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Portal status', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $portal['enabled'] ) ); ?>> <?php esc_html_e( 'Enable dealer portal', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="notify_dealer_on_order" value="1" <?php checked( ! empty( $portal['notify_dealer_on_order'] ) ); ?>> <?php esc_html_e( 'Email dealer immediately when an order is placed (sends portal link at checkout)', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="auto_send_on_ship" value="1" <?php checked( ! empty( $portal['auto_send_on_ship'] ) ); ?>> <?php esc_html_e( 'Auto-send confirmation email when a transfer enters "shipped to dealer"', 'advanced-ffl-checkout' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="portal_slug"><?php esc_html_e( 'Portal URL slug', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<code><?php echo esc_html( home_url( '/' ) ); ?></code>
						<input type="text" id="portal_slug" name="portal_slug" value="<?php echo esc_attr( (string) ( $portal['portal_slug'] ?? 'ffl-confirm' ) ); ?>" class="regular-text" pattern="[a-z0-9\-]+" style="max-width:200px;">
						<code>/{token}/</code>
						<p class="description"><?php esc_html_e( 'URL where the dealer lands. Lowercase letters, numbers, and hyphens only.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="token_expiry_days"><?php esc_html_e( 'Token expiry', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<select id="token_expiry_days" name="token_expiry_days">
							<?php foreach ( [ 7, 14, 30, 60, 90, 180 ] as $d ) : ?>
								<option value="<?php echo esc_attr( (string) $d ); ?>" <?php selected( (int) ( $portal['token_expiry_days'] ?? 30 ), $d ); ?>><?php echo esc_html( $d ); ?> days</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="two_factor_method"><?php esc_html_e( 'Second-factor verification', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<select id="two_factor_method" name="two_factor_method">
							<option value="none"      <?php selected( $portal['two_factor_method'] ?? 'last4_ffl', 'none' ); ?>><?php esc_html_e( 'None — one-click only', 'advanced-ffl-checkout' ); ?></option>
							<option value="last4_ffl" <?php selected( $portal['two_factor_method'] ?? 'last4_ffl', 'last4_ffl' ); ?>><?php esc_html_e( 'Last 4 of FFL license (recommended)', 'advanced-ffl-checkout' ); ?></option>
							<option value="email_otp" <?php selected( $portal['two_factor_method'] ?? 'last4_ffl', 'email_otp' ); ?>><?php esc_html_e( 'Email OTP (coming in v1.2.0)', 'advanced-ffl-checkout' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Prevents forwarded-email abuse. "Last 4 of FFL" asks the dealer to enter the final four characters of their license number.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="rate_limit_per_min"><?php esc_html_e( 'Rate limit (attempts/minute per IP)', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="number" id="rate_limit_per_min" name="rate_limit_per_min" min="1" max="60" value="<?php echo esc_attr( (string) ( $portal['rate_limit_per_min'] ?? 5 ) ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Behaviour flags', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label><input type="checkbox" name="single_use" value="1" <?php checked( ! empty( $portal['single_use'] ) ); ?>> <?php esc_html_e( 'Single-use tokens (recommended)', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="allow_replay_view" value="1" <?php checked( ! empty( $portal['allow_replay_view'] ) ); ?>> <?php esc_html_e( 'After use, still show a read-only confirmation screen on replay', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="track_email_opens" value="1" <?php checked( ! empty( $portal['track_email_opens'] ) ); ?>> <?php esc_html_e( 'Track email opens via tracking pixel (disclosed in footer)', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="require_https" value="1" <?php checked( ! empty( $portal['require_https'] ) ); ?>> <?php esc_html_e( 'Force HTTPS for the portal', 'advanced-ffl-checkout' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><label for="reminder_days"><?php esc_html_e( 'Auto-reminder schedule', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="text" id="reminder_days" name="reminder_days" value="<?php echo esc_attr( (string) ( $portal['reminder_days'] ?? '3,7,14' ) ); ?>" class="regular-text" placeholder="3,7,14">
						<p class="description"><?php esc_html_e( 'Comma-separated days after shipment to send reminder emails (up to token expiry).', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="analytics_retention_days"><?php esc_html_e( 'Analytics retention (days)', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="number" id="analytics_retention_days" name="analytics_retention_days" min="7" max="3650" value="<?php echo esc_attr( (string) ( $portal['analytics_retention_days'] ?? 365 ) ); ?>" class="small-text"></td>
				</tr>
			</table>

			<p><button type="button" class="button button-primary" id="wpistic-ffl-save-portal"><?php esc_html_e( 'Save Portal Settings', 'advanced-ffl-checkout' ); ?></button></p>
		</form>

	<?php elseif ( 'theme' === $active_tab ) : ?>

		<form id="wpistic-ffl-theme-form" style="max-width:860px;margin-top:24px;">
			<h3><?php esc_html_e( 'Portal Theme', 'advanced-ffl-checkout' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Every setting here is reflected instantly on the public /ffl-confirm/ page. Pick a preset, tweak colors, upload your logo, and customise the copy.', 'advanced-ffl-checkout' ); ?></p>

			<table class="form-table">
				<tr>
					<th><label for="preset"><?php esc_html_e( 'Preset', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<select id="preset" name="preset">
							<option value="custom"    <?php selected( $theme['preset'], 'custom' ); ?>><?php esc_html_e( 'Custom (my colors below)', 'advanced-ffl-checkout' ); ?></option>
							<option value="guns2ammo" <?php selected( $theme['preset'], 'guns2ammo' ); ?>><?php esc_html_e( 'Guns2Ammo Tactical (red/black)', 'advanced-ffl-checkout' ); ?></option>
							<option value="dark"      <?php selected( $theme['preset'], 'dark' ); ?>><?php esc_html_e( 'Dark (purple accent)', 'advanced-ffl-checkout' ); ?></option>
							<option value="default"   <?php selected( $theme['preset'], 'default' ); ?>><?php esc_html_e( 'Default (light)', 'advanced-ffl-checkout' ); ?></option>
							<option value="minimal"   <?php selected( $theme['preset'], 'minimal' ); ?>><?php esc_html_e( 'Minimal (black on white)', 'advanced-ffl-checkout' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="business_name"><?php esc_html_e( 'Business name', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="business_name" name="business_name" value="<?php echo esc_attr( (string) $theme['business_name'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="logo_url"><?php esc_html_e( 'Logo URL', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="url" id="logo_url" name="logo_url" value="<?php echo esc_attr( (string) $theme['logo_url'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'PNG / SVG. Shown on the portal header and in emails.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="support_email"><?php esc_html_e( 'Support email', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="email" id="support_email" name="support_email" value="<?php echo esc_attr( (string) $theme['support_email'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="support_phone"><?php esc_html_e( 'Support phone', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="support_phone" name="support_phone" value="<?php echo esc_attr( (string) $theme['support_phone'] ); ?>" class="regular-text"></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Colors', 'advanced-ffl-checkout' ); ?></h3>
			<table class="form-table">
				<?php
				$color_fields = [
					'color_primary'       => __( 'Primary / button color', 'advanced-ffl-checkout' ),
					'color_primary_hover' => __( 'Primary hover', 'advanced-ffl-checkout' ),
					'color_bg'            => __( 'Page background', 'advanced-ffl-checkout' ),
					'color_surface'       => __( 'Card background', 'advanced-ffl-checkout' ),
					'color_text'          => __( 'Text', 'advanced-ffl-checkout' ),
					'color_text_muted'    => __( 'Muted text', 'advanced-ffl-checkout' ),
					'color_border'        => __( 'Border', 'advanced-ffl-checkout' ),
					'color_success'       => __( 'Success', 'advanced-ffl-checkout' ),
					'color_warning'       => __( 'Warning', 'advanced-ffl-checkout' ),
					'color_danger'        => __( 'Danger', 'advanced-ffl-checkout' ),
				];
				foreach ( $color_fields as $key => $label ) :
				?>
					<tr>
						<th><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<input type="text" id="<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) ( $theme[ $key ] ?? '' ) ); ?>" class="regular-text" style="max-width:160px;">
							<input type="color" value="<?php echo esc_attr( substr( (string) ( $theme[ $key ] ?? '#000000' ), 0, 7 ) ); ?>" onchange="document.getElementById('<?php echo esc_js( $key ); ?>').value = this.value;">
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h3><?php esc_html_e( 'Layout', 'advanced-ffl-checkout' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="card_style"><?php esc_html_e( 'Card style', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<select id="card_style" name="card_style">
							<option value="shadow"   <?php selected( $theme['card_style'], 'shadow' ); ?>>Shadow</option>
							<option value="flat"     <?php selected( $theme['card_style'], 'flat' ); ?>>Flat</option>
							<option value="bordered" <?php selected( $theme['card_style'], 'bordered' ); ?>>Bordered</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="button_style"><?php esc_html_e( 'Button style', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<select id="button_style" name="button_style">
							<option value="rounded" <?php selected( $theme['button_style'], 'rounded' ); ?>>Rounded</option>
							<option value="square"  <?php selected( $theme['button_style'], 'square' ); ?>>Square</option>
							<option value="pill"    <?php selected( $theme['button_style'], 'pill' ); ?>>Pill</option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="max_width"><?php esc_html_e( 'Max width (px)', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="number" id="max_width" name="max_width" min="320" max="1200" value="<?php echo esc_attr( (string) $theme['max_width'] ); ?>" class="small-text"></td>
				</tr>
			</table>

			<h3><?php esc_html_e( 'Copy', 'advanced-ffl-checkout' ); ?></h3>
			<table class="form-table">
				<tr>
					<th><label for="welcome_heading"><?php esc_html_e( 'Portal heading', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="welcome_heading" name="welcome_heading" value="<?php echo esc_attr( (string) $theme['welcome_heading'] ); ?>" class="large-text"></td>
				</tr>
				<tr>
					<th><label for="welcome_subtext"><?php esc_html_e( 'Sub-text / instructions', 'advanced-ffl-checkout' ); ?></label></th>
					<td><textarea id="welcome_subtext" name="welcome_subtext" rows="3" class="large-text"><?php echo esc_textarea( (string) $theme['welcome_subtext'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="btn_received_label">"<?php esc_html_e( 'Item Received', 'advanced-ffl-checkout' ); ?>"</label></th>
					<td><input type="text" id="btn_received_label" name="btn_received_label" value="<?php echo esc_attr( (string) $theme['btn_received_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="btn_issue_label">"<?php esc_html_e( 'Report Issue', 'advanced-ffl-checkout' ); ?>"</label></th>
					<td><input type="text" id="btn_issue_label" name="btn_issue_label" value="<?php echo esc_attr( (string) $theme['btn_issue_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="btn_not_mine_label">"<?php esc_html_e( 'Not My Shipment', 'advanced-ffl-checkout' ); ?>"</label></th>
					<td><input type="text" id="btn_not_mine_label" name="btn_not_mine_label" value="<?php echo esc_attr( (string) $theme['btn_not_mine_label'] ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="footer_text"><?php esc_html_e( 'Footer text', 'advanced-ffl-checkout' ); ?></label></th>
					<td><textarea id="footer_text" name="footer_text" rows="2" class="large-text"><?php echo esc_textarea( (string) $theme['footer_text'] ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Branding', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="show_branding" value="1" <?php checked( ! empty( $theme['show_branding'] ) ); ?> <?php disabled( ! \WpisticFFL\License::can( 'remove_branding' ) ); ?>>
							<?php esc_html_e( 'Show "Powered by Wordpressistic" footer', 'advanced-ffl-checkout' ); ?>
						</label>
						<?php if ( ! \WpisticFFL\License::can( 'remove_branding' ) ) : ?>
							<p class="description"><?php esc_html_e( 'Pro feature — free plugin always shows branding.', 'advanced-ffl-checkout' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><label for="custom_css"><?php esc_html_e( 'Custom CSS', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<textarea id="custom_css" name="custom_css" rows="8" class="large-text code" <?php disabled( ! \WpisticFFL\License::can( 'custom_css' ) ); ?>><?php echo esc_textarea( (string) ( $theme['custom_css'] ?? '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Injected at the end of the portal stylesheet. Pro only.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
			</table>

			<p><button type="button" class="button button-primary" id="wpistic-ffl-save-theme"><?php esc_html_e( 'Save Theme', 'advanced-ffl-checkout' ); ?></button>
				<a href="<?php echo esc_url( home_url( '/' . ( $portal['portal_slug'] ?? 'ffl-confirm' ) . '/PREVIEW/' ) ); ?>" class="button" target="_blank"><?php esc_html_e( '👁 Preview Portal (mock data — admin only)', 'advanced-ffl-checkout' ); ?></a>
			</p>
		</form>

	<?php elseif ( 'emails' === $active_tab ) : ?>

		<form id="wpistic-ffl-email-form" style="max-width:760px;margin-top:24px;">
			<h3><?php esc_html_e( 'Confirmation + Reminder Email Copy', 'advanced-ffl-checkout' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Leave fields blank to use the built-in defaults. Supports merge tags: {store_name}, {transfer_ref}, {urgency}.', 'advanced-ffl-checkout' ); ?></p>

			<table class="form-table">
				<tr>
					<th><label for="from_name"><?php esc_html_e( 'From name', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="from_name" name="from_name" value="<?php echo esc_attr( (string) ( $email['from_name'] ?? get_bloginfo( 'name' ) ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="from_email"><?php esc_html_e( 'From email', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="email" id="from_email" name="from_email" value="<?php echo esc_attr( (string) ( $email['from_email'] ?? get_option( 'admin_email' ) ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="reply_to"><?php esc_html_e( 'Reply-To', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="email" id="reply_to" name="reply_to" value="<?php echo esc_attr( (string) ( $email['reply_to'] ?? '' ) ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="confirmation_subject"><?php esc_html_e( 'Confirmation email subject', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="confirmation_subject" name="confirmation_subject" value="<?php echo esc_attr( (string) ( $email['confirmation_subject'] ?? '' ) ); ?>" class="large-text" placeholder="Action Required: Confirm FFL Transfer Receipt — {transfer_ref}"></td>
				</tr>
				<tr>
					<th><label for="confirmation_intro"><?php esc_html_e( 'Confirmation intro HTML', 'advanced-ffl-checkout' ); ?></label></th>
					<td><textarea id="confirmation_intro" name="confirmation_intro" rows="5" class="large-text"><?php echo esc_textarea( (string) ( $email['confirmation_intro'] ?? '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th><label for="reminder_subject"><?php esc_html_e( 'Reminder subject', 'advanced-ffl-checkout' ); ?></label></th>
					<td><input type="text" id="reminder_subject" name="reminder_subject" value="<?php echo esc_attr( (string) ( $email['reminder_subject'] ?? '' ) ); ?>" class="large-text" placeholder="{urgency}: FFL Transfer Confirmation Pending — #{transfer_ref}"></td>
				</tr>
				<tr>
					<th><label for="reminder_intro"><?php esc_html_e( 'Reminder intro HTML', 'advanced-ffl-checkout' ); ?></label></th>
					<td><textarea id="reminder_intro" name="reminder_intro" rows="5" class="large-text"><?php echo esc_textarea( (string) ( $email['reminder_intro'] ?? '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Admin notifications', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label><input type="checkbox" name="admin_notify_on_confirm" value="1" <?php checked( ! empty( $email['admin_notify_on_confirm'] ) ); ?>> <?php esc_html_e( 'Email admin when dealer confirms', 'advanced-ffl-checkout' ); ?></label><br>
						<label><input type="checkbox" name="admin_notify_on_issue"   value="1" <?php checked( ! empty( $email['admin_notify_on_issue'] ) ); ?>> <?php esc_html_e( 'Email admin when dealer reports an issue', 'advanced-ffl-checkout' ); ?></label>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="wpistic-ffl-save-email"><?php esc_html_e( 'Save Email Templates', 'advanced-ffl-checkout' ); ?></button>
				<button type="button" class="button" id="wpistic-ffl-send-test-email" style="margin-left:8px;"><?php esc_html_e( '📧 Send Test Email', 'advanced-ffl-checkout' ); ?></button>
				<span id="wpistic-ffl-test-email-result" style="display:none;margin-left:10px;font-weight:600;"></span>
			</p>
			<p class="description" style="max-width:560px;">
				<?php esc_html_e( 'The plugin uses wp_mail() — any SMTP plugin (Elementor Site Mailer, WP Mail SMTP, Fluent SMTP) will handle delivery. Click "Send Test Email" to verify the pipeline works before shipping real dealer notifications.', 'advanced-ffl-checkout' ); ?>
			</p>
		</form>

	<?php elseif ( 'integrations' === $active_tab ) : ?>

		<form id="wpistic-ffl-integrations-form" style="max-width:760px;margin-top:24px;">
			<h3><?php esc_html_e( 'External Integrations', 'advanced-ffl-checkout' ); ?></h3>
			<p class="description"><?php esc_html_e( 'API credentials stored server-side and used only to proxy analytics requests from your dashboard. Never exposed to the browser.', 'advanced-ffl-checkout' ); ?></p>

			<table class="form-table">
				<tr>
					<th><label for="chatbotistic_api_key"><?php esc_html_e( 'ChatBotistic API key', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="password" id="chatbotistic_api_key" name="chatbotistic_api_key" value="<?php echo esc_attr( (string) ( $integrations['chatbotistic_api_key'] ?? '' ) ); ?>" class="regular-text" autocomplete="off">
						<button type="button" class="button button-small" onclick="const f=document.getElementById('chatbotistic_api_key'); f.type=f.type==='password'?'text':'password';">👁</button>
						<p class="description"><?php esc_html_e( 'Powers the WhatsApp lead analytics (Chatbot Widget, Contact Form, FFL Transfer Form). Get it from app.chatbotistic.com → Settings → API.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="otter_api_key"><?php esc_html_e( 'Otter Text JWT token', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="password" id="otter_api_key" name="otter_api_key" value="<?php echo esc_attr( (string) ( $integrations['otter_api_key'] ?? '' ) ); ?>" class="large-text" autocomplete="off" style="font-family:ui-monospace,monospace;font-size:12px;">
						<p class="description"><?php esc_html_e( 'Used for bulk SMS campaign analytics (being replaced by Twilio in v1.3.0).', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
			</table>

			<p>
				<button type="button" class="button button-primary" id="wpistic-ffl-save-integrations"><?php esc_html_e( 'Save Integration Keys', 'advanced-ffl-checkout' ); ?></button>
				<a href="<?php echo esc_url( rest_url( WPISTIC_FFL_REST_NS . '/integrations/test' ) ); ?>" class="button" target="_blank" style="margin-left:8px;"><?php esc_html_e( '🔌 Test Connections', 'advanced-ffl-checkout' ); ?></a>
			</p>

			<div style="background:#FEF3C7;border-left:4px solid #F59E0B;padding:14px 18px;border-radius:6px;margin-top:24px;font-size:13px;">
				<strong>🔐 Security note:</strong>
				<?php esc_html_e( 'For maximum security, define these constants in your wp-config.php instead — they will override the database values and keep the keys out of backups:', 'advanced-ffl-checkout' ); ?>
				<pre style="background:#fff;padding:10px;margin-top:8px;border-radius:4px;font-size:11px;overflow-x:auto;">define( 'WPISTIC_FFL_CHATBOTISTIC_KEY', 'your-key-here' );
define( 'WPISTIC_FFL_OTTER_KEY', 'your-jwt-here' );</pre>
			</div>
		</form>

	<?php elseif ( 'license' === $active_tab ) : ?>

		<div style="max-width:720px;margin-top:24px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px 32px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Plugin License', 'advanced-ffl-checkout' ); ?></h3>
			<p><strong><?php esc_html_e( 'Current tier:', 'advanced-ffl-checkout' ); ?></strong>
				<span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:999px;font-weight:600;">
					<?php echo esc_html( ucfirst( \WpisticFFL\License::tier() ) ); ?>
				</span>
			</p>
			<?php if ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) : ?>
				<div class="notice notice-info inline" style="padding:12px;margin:16px 0;">
					<p><strong><?php esc_html_e( 'Unlimited mode active', 'advanced-ffl-checkout' ); ?></strong> — <?php esc_html_e( 'WPISTIC_FFL_UNLIMITED constant is set in wp-config.php. All Pro and Agency features are unlocked for this site.', 'advanced-ffl-checkout' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! ( defined( 'WPISTIC_FFL_UNLIMITED' ) && WPISTIC_FFL_UNLIMITED ) ) : ?>
				<p class="description"><?php esc_html_e( 'Activates against wordpressistic.com. Entitlements are cached locally and revalidated weekly, with a 7-day grace period if the license server is briefly unreachable.', 'advanced-ffl-checkout' ); ?></p>

				<?php if ( 'remote' === ( $lic['mode'] ?? '' ) && ! empty( $lic['key'] ) ) : ?>
					<p class="description">
						<?php
						if ( ! empty( $lic['last_validated'] ) ) {
							printf( esc_html__( 'Last validated: %s', 'advanced-ffl-checkout' ), esc_html( date_i18n( 'M j, Y g:i a', (int) $lic['last_validated'] ) ) );
						}
						if ( ! empty( $lic['grace_since'] ) ) {
							echo ' — <strong style="color:#B45309;">' . esc_html__( 'License server unreachable — running on grace period.', 'advanced-ffl-checkout' ) . '</strong>';
						}
						?>
					</p>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><label for="license_key"><?php esc_html_e( 'License key', 'advanced-ffl-checkout' ); ?></label></th>
						<td>
							<input type="text" id="license_key" name="license_key" value="<?php echo esc_attr( (string) ( $lic['key'] ?? '' ) ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Paste your license key', 'advanced-ffl-checkout' ); ?>">
							<button type="button" class="button button-primary" id="wpistic-ffl-license-activate"><?php esc_html_e( 'Activate', 'advanced-ffl-checkout' ); ?></button>
							<?php if ( ! empty( $lic['key'] ) ) : ?>
								<button type="button" class="button" id="wpistic-ffl-license-deactivate"><?php esc_html_e( 'Deactivate', 'advanced-ffl-checkout' ); ?></button>
							<?php endif; ?>
							<p class="description" id="wpistic-ffl-license-result"></p>
						</td>
					</tr>
				</table>

				<script>
				(function(){
					var nonce = <?php echo wp_json_encode( wp_create_nonce( 'wpistic_ffl_admin_nonce' ) ); ?>;
					var result = document.getElementById('wpistic-ffl-license-result');
					function call(action, extra) {
						var fd = new FormData();
						fd.append('action', action);
						fd.append('nonce', nonce);
						if (extra) { fd.append('license_key', extra); }
						result.textContent = 'Working…';
						result.style.color = '#666';
						fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
							.then(function(r){ return r.json(); })
							.then(function(j){
								if (j && j.success) {
									result.textContent = '✓ Done — reloading…';
									result.style.color = '#16A34A';
									setTimeout(function(){ location.reload(); }, 600);
								} else {
									result.textContent = '✗ ' + ((j && j.data && j.data.message) || 'Failed');
									result.style.color = '#DC2626';
								}
							})
							.catch(function(){ result.textContent = '✗ Network error'; result.style.color = '#DC2626'; });
					}
					var actBtn = document.getElementById('wpistic-ffl-license-activate');
					if (actBtn) {
						actBtn.addEventListener('click', function(){
							call('wpistic_ffl_license_activate', document.getElementById('license_key').value);
						});
					}
					var deactBtn = document.getElementById('wpistic-ffl-license-deactivate');
					if (deactBtn) {
						deactBtn.addEventListener('click', function(){
							if (! confirm('<?php echo esc_js( __( 'Deactivate this license? Pro/Agency features will lock immediately.', 'advanced-ffl-checkout' ) ); ?>')) return;
							call('wpistic_ffl_license_deactivate');
						});
					}
				})();
				</script>
			<?php endif; ?>
		</div>

	<?php endif; ?>
</div>

<script>
// v1.1.2 — wait for DOMContentLoaded so wp_localize_script has run
document.addEventListener('DOMContentLoaded', function() {
	'use strict';
	const globals = window.wpistic_ffl_admin || {};
	const ajax  = globals.ajax_url || window.ajaxurl || '/wp-admin/admin-ajax.php';
	const nonce = globals.nonce || '';

	if (!nonce) {
		console.warn('[wpistic-ffl] Admin nonce missing — Save buttons will fail. Reload the page.');
	}

	function save(formId, action, btnId) {
		const btn = document.getElementById(btnId);
		if (!btn) return;
		btn.addEventListener('click', function() {
			btn.disabled = true;
			btn.innerText = 'Saving…';
			const form = document.getElementById(formId);
			const data = new FormData();
			data.append('action', action);
			data.append('nonce', nonce);
			const inputs = form.querySelectorAll('input, select, textarea');
			inputs.forEach(function(el) {
				if (el.type === 'checkbox') {
					data.append('settings[' + el.name + ']', el.checked ? '1' : '0');
				} else if (el.name) {
					data.append('settings[' + el.name + ']', el.value);
				}
			});
			fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(r => r.json())
				.then(json => {
					btn.disabled = false;
					btn.innerText = btn.getAttribute('data-label') || btn.innerText.replace('Saving…', 'Save');
					const notice = document.getElementById('wpistic-ffl-settings-saved');
					if (json && json.success) {
						if (notice) { notice.style.display = 'block'; setTimeout(() => notice.style.display = 'none', 3000); }
					} else {
						alert((json && json.data && json.data.message) || 'Save failed.');
					}
				})
				.catch(e => { btn.disabled = false; alert('Network error: ' + e.message); });
		});
	}

	// General (legacy endpoint)
	const genBtn = document.getElementById('wpistic-ffl-save-general');
	if (genBtn) {
		genBtn.addEventListener('click', function() {
			genBtn.disabled = true;
			const form = document.getElementById('wpistic-ffl-general-form');
			const data = new FormData(form);
			data.append('action', 'wpistic_ffl_save_settings');
			data.append('nonce', nonce);
			fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(r => r.json())
				.then(() => {
					genBtn.disabled = false;
					const notice = document.getElementById('wpistic-ffl-settings-saved');
					if (notice) { notice.style.display = 'block'; setTimeout(() => notice.style.display = 'none', 3000); }
				});
		});
	}

	save('wpistic-ffl-portal-form',        'wpistic_ffl_save_portal_settings',        'wpistic-ffl-save-portal');
	save('wpistic-ffl-theme-form',         'wpistic_ffl_save_theme_settings',         'wpistic-ffl-save-theme');
	save('wpistic-ffl-email-form',         'wpistic_ffl_save_email_settings',         'wpistic-ffl-save-email');
	save('wpistic-ffl-integrations-form',  'wpistic_ffl_save_integrations_settings',  'wpistic-ffl-save-integrations');

	// Send Test Email handler
	const testBtn = document.getElementById('wpistic-ffl-send-test-email');
	if (testBtn) {
		testBtn.addEventListener('click', function() {
			testBtn.disabled = true;
			const orig = testBtn.innerText;
			testBtn.innerText = 'Sending…';
			const data = new FormData();
			data.append('action', 'wpistic_ffl_send_test_email');
			data.append('nonce', nonce);
			fetch(ajax, { method: 'POST', body: data, credentials: 'same-origin' })
				.then(r => r.json())
				.then(json => {
					testBtn.disabled = false;
					testBtn.innerText = orig;
					const result = document.getElementById('wpistic-ffl-test-email-result');
					if (result) {
						result.style.display = 'block';
						if (json && json.success) {
							result.style.color = '#16A34A';
							result.innerHTML = '✓ ' + (json.data && json.data.message || 'Test email sent. Check your inbox in 1–2 minutes.');
						} else {
							result.style.color = '#DC2626';
							result.innerHTML = '✗ ' + (json && json.data && json.data.message || 'Failed — check SMTP plugin settings.');
						}
					}
				})
				.catch(function(e) { testBtn.disabled = false; testBtn.innerText = orig; alert('Network error: ' + e.message); });
		});
	}
});
</script>
