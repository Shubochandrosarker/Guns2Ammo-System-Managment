<?php
/**
 * Admin Settings view.
 *
 * @package WpisticFFL
 */
defined( 'ABSPATH' ) || exit;

$settings = get_option( 'wpistic_ffl_settings', [] );
$radius   = $settings['default_radius']     ?? 50;
$notify   = $settings['notification_email'] ?? get_option( 'admin_email' );
$from     = $settings['from_name']          ?? get_bloginfo( 'name' );
$gmaps    = $settings['google_maps_key']    ?? '';
?>

<div class="wrap wpistic-ffl-admin">
	<div class="wpistic-ffl-page-header">
		<h1 class="wpistic-ffl-page-title"><?php esc_html_e( 'G2A - FFL Settings', 'advanced-ffl-checkout' ); ?></h1>
	</div>

	<div id="wpistic-ffl-settings-saved" class="notice notice-success wpistic-ffl-save-notice" style="display:none;">
		<p><?php esc_html_e( '✓ Settings saved successfully.', 'advanced-ffl-checkout' ); ?></p>
	</div>

	<div class="wpistic-ffl-settings-grid">

		<!-- General Settings -->
		<div class="wpistic-ffl-card">
			<div class="wpistic-ffl-card-header"><h2><?php esc_html_e( 'General', 'advanced-ffl-checkout' ); ?></h2></div>
			<table class="wpistic-ffl-settings-table">
				<tr>
					<th><label for="default_radius"><?php esc_html_e( 'Default Search Radius', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="number" id="default_radius" name="default_radius" value="<?php echo esc_attr( $radius ); ?>" min="10" max="200" class="small-text"> <?php esc_html_e( 'miles', 'advanced-ffl-checkout' ); ?>
						<p class="description"><?php esc_html_e( 'Default radius for the checkout dealer search widget.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="google_maps_key"><?php esc_html_e( 'Google Maps API Key', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="text" id="google_maps_key" name="google_maps_key" value="<?php echo esc_attr( $gmaps ); ?>" class="regular-text" placeholder="AIza...">
						<p class="description"><?php esc_html_e( 'Optional — enables the map display widget. Dealer search works without it.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Email Settings -->
		<div class="wpistic-ffl-card">
			<div class="wpistic-ffl-card-header"><h2><?php esc_html_e( 'Email Notifications', 'advanced-ffl-checkout' ); ?></h2></div>
			<table class="wpistic-ffl-settings-table">
				<tr>
					<th><label for="notification_email"><?php esc_html_e( 'Admin Notification Email', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="email" id="notification_email" name="notification_email" value="<?php echo esc_attr( $notify ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Receives alerts for NICS delays, denials, and dealer arrival notifications.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="from_name"><?php esc_html_e( 'From Name', 'advanced-ffl-checkout' ); ?></label></th>
					<td>
						<input type="text" id="from_name" name="from_name" value="<?php echo esc_attr( $from ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Customer Emails', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="customer_emails" value="1" <?php checked( $settings['customer_emails'] ?? true ); ?>>
							<?php esc_html_e( 'Send status update emails to customers', 'advanced-ffl-checkout' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Admin Emails', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="admin_emails" value="1" <?php checked( $settings['admin_emails'] ?? true ); ?>>
							<?php esc_html_e( 'Send admin alerts (NICS delay, denial, etc.)', 'advanced-ffl-checkout' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Disable All Emails', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="disable_emails" value="1" <?php checked( ! empty( $settings['disable_emails'] ) ); ?>>
							<?php esc_html_e( 'Temporarily disable all FFL notification emails', 'advanced-ffl-checkout' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<!-- Data Management -->
		<div class="wpistic-ffl-card">
			<div class="wpistic-ffl-card-header"><h2><?php esc_html_e( 'Data Management', 'advanced-ffl-checkout' ); ?></h2></div>
			<table class="wpistic-ffl-settings-table">
				<tr>
					<th><?php esc_html_e( 'Delete Data on Uninstall', 'advanced-ffl-checkout' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_data_on_uninstall'] ) ); ?>>
							<?php esc_html_e( 'Remove all plugin tables and options when plugin is deleted', 'advanced-ffl-checkout' ); ?>
						</label>
						<p class="description" style="color:#dc2626;"><?php esc_html_e( 'Warning: This cannot be undone. Leave unchecked to preserve data across reinstalls.', 'advanced-ffl-checkout' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<!-- Plugin Info -->
		<div class="wpistic-ffl-card wpistic-ffl-card--info">
			<div class="wpistic-ffl-card-header"><h2><?php esc_html_e( 'Plugin Information', 'advanced-ffl-checkout' ); ?></h2></div>
			<div class="wpistic-ffl-plugin-info">
				<div class="wpistic-ffl-brand-block">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 50" class="wpistic-ffl-brand-logo">
						<rect width="200" height="50" rx="8" fill="#7c5cf6"/>
						<text x="14" y="33" font-family="system-ui,sans-serif" font-weight="700" font-size="20" fill="#ffffff">WordPressistic</text>
					</svg>
					<p><?php esc_html_e( 'Advanced FFL Checkout Solutions', 'advanced-ffl-checkout' ); ?> v<?php echo esc_html( WPISTIC_FFL_VERSION ); ?></p>
					<p><a href="https://wordpressistic.com" target="_blank" rel="noopener">wordpressistic.com</a></p>
				</div>
				<div class="wpistic-ffl-info-grid">
					<div>
						<strong><?php esc_html_e( 'REST API Namespace', 'advanced-ffl-checkout' ); ?></strong>
						<code><?php echo esc_html( WPISTIC_FFL_REST_NS ); ?></code>
					</div>
					<div>
						<strong><?php esc_html_e( 'DB Prefix', 'advanced-ffl-checkout' ); ?></strong>
						<code><?php echo esc_html( $GLOBALS['wpdb']->prefix . WPISTIC_FFL_DB_PREFIX ); ?></code>
					</div>
					<div>
						<strong><?php esc_html_e( 'ZIP DB Count', 'advanced-ffl-checkout' ); ?></strong>
						<code><?php echo esc_html( number_format( (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WpisticFFL\DB::table( 'zip_coords' ) ) ) ); ?></code>
					</div>
					<div>
						<strong><?php esc_html_e( 'Dealer Count', 'advanced-ffl-checkout' ); ?></strong>
						<code><?php echo esc_html( number_format( (int) $GLOBALS['wpdb']->get_var( 'SELECT COUNT(*) FROM ' . \WpisticFFL\DB::table( 'dealers' ) . ' WHERE is_active = 1' ) ) ); ?></code>
					</div>
				</div>
			</div>
		</div>

	</div><!-- .wpistic-ffl-settings-grid -->

	<div class="wpistic-ffl-settings-footer">
		<button type="button" id="wpistic-ffl-save-settings" class="button button-primary wpistic-ffl-save-btn">
			<?php esc_html_e( 'Save Settings', 'advanced-ffl-checkout' ); ?>
		</button>
	</div>

</div><!-- .wrap -->
