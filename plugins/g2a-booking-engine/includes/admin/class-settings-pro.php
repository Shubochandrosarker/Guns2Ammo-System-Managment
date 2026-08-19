<?php
/**
 * Tabbed Settings page — General · Templates · Payments · Notifications · Branding · Danger.
 * Replaces the simple settings page with a premium-plugin-grade UI.
 *
 * Payments tab includes per-gateway setup cards for Stripe, PayPal, Fortis Pay,
 * Authorize.net, WooCommerce Payments, Pay In Store.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin_Settings_Pro {

	private static $instance = null;
	const PAGE_SLUG = 'g2ab-settings';

	const TABS = array(
		'general'         => 'General',
		'form_customizer' => 'Form Customizer',
		'addons'          => 'Addons',
		'payments'        => 'Payments',
		'templates'       => 'Templates',
		'notifications'   => 'Notifications',
		'branding'        => 'Branding',
		'danger'          => 'Danger Zone',
	);

	/**
	 * Get final tab list — built-in TABS merged with module-registered tabs.
	 * Modules register via the 'g2ab_settings_tabs' filter inside their
	 * settings classes (e.g. email_automation, ai_autoreply, pdf_invoices,
	 * woocommerce). This makes Configure buttons land on the right tab.
	 */
	public static function tabs() {
		$tabs = self::TABS;
		// Insert module-registered tabs BEFORE branding/danger so payments-related ones group together.
		$module_tabs = apply_filters( 'g2ab_settings_tabs', array() );
		// Reorder: general, addons, payments, [module tabs...], branding, danger.
		$tail = array();
		foreach ( array( 'branding', 'danger' ) as $k ) {
			if ( isset( $tabs[ $k ] ) ) { $tail[ $k ] = $tabs[ $k ]; unset( $tabs[ $k ] ); }
		}
		return array_merge( $tabs, $module_tabs, $tail );
	}

	/**
	 * Options rendered as masked password fields. Stored values are never echoed
	 * back into the page; submitting the field empty keeps the saved value.
	 */
	const SECRET_OPTIONS = array(
		'g2ab_stripe_secret_key',
		'g2ab_stripe_webhook_secret',
		// Not secret, but its name matches the generic `_key` branch in
		// handle_save(), so an empty submit used to WIPE it. A blank
		// publishable key makes gateway_is_configured() report Stripe as
		// unconfigured (:307) even while is_available() is true — the kind of
		// mixed signal that invites an operator to switch Stripe off.
		'g2ab_stripe_publishable_key',
		'g2ab_paypal_secret',
		'g2ab_fortis_user_api_key',
		'g2ab_fortis_hmac_secret',
		'g2ab_fortis_webhook_secret',
		'g2ab_authnet_transaction_key',
		'g2ab_authnet_signature_key',
		'g2ab_twilio_token',
	);

	const GATEWAYS = array(
		'stripe'         => array( 'label' => 'Stripe',              'color' => '#635BFF', 'logo' => 'S' ),
		'paypal'         => array( 'label' => 'PayPal',              'color' => '#003087', 'logo' => 'P' ),
		'fortis'         => array( 'label' => 'Fortis Pay',          'color' => '#0F4C75', 'logo' => 'F' ),
		'authnet'        => array( 'label' => 'Authorize.net',       'color' => '#1F3864', 'logo' => 'A' ),
		'woocommerce'    => array( 'label' => 'WooCommerce Payments', 'color' => '#7F54B3', 'logo' => 'W' ),
		'pay_in_store'   => array( 'label' => 'Pay In Store',        'color' => '#E8802F', 'logo' => '$' ),
	);

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_g2ab_save_settings_pro', array( $this, 'handle_save' ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		$all_tabs = self::tabs();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
		if ( ! isset( $all_tabs[ $tab ] ) ) $tab = 'general';
		$saved = isset( $_GET['saved'] ) && '1' === $_GET['saved'];
		$this->print_styles();
		?>
		<div class="wrap g2ab-admin g2ab-set">
			<div class="g2ab-set__header">
				<div>
					<h1><span class="g2ab-set__stencil"><?php esc_html_e( 'SETTINGS', 'g2a-booking' ); ?></span></h1>
					<p class="g2ab-set__sub"><?php esc_html_e( 'Configure your range · payments · automation · branding', 'g2a-booking' ); ?></p>
				</div>
			</div>
			<nav class="g2ab-set__tabs">
				<?php foreach ( $all_tabs as $key => $label ) : ?>
					<a class="<?php echo $tab === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'g2a-booking' ); ?></p></div><?php endif; ?>
			<div class="g2ab-set__content">
				<?php
				// Built-in tabs first.
				switch ( $tab ) {
					case 'form_customizer': $this->render_form_customizer_tab(); break;
					case 'addons':        $this->render_addons_tab();        break;
					case 'payments':      $this->render_payments_tab();      break;
					case 'templates':     $this->render_templates_tab();     break;
					case 'notifications': $this->render_notifications_tab(); break;
					case 'branding':      $this->render_branding_tab();      break;
					case 'danger':        $this->render_danger_tab();        break;
					case 'general':       $this->render_general_tab();       break;
					default:
						// Module-registered tab — fire dispatcher action.
						$has_handler = has_action( 'g2ab_settings_render_' . $tab );
						if ( $has_handler ) {
							do_action( 'g2ab_settings_render_' . $tab );
						} else {
							$this->render_general_tab();
						}
				}
				?>
			</div>
		</div>
		<?php
	}

	private function open_form( $tab ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'g2ab_save_settings_pro', '_g2ab_nonce' );
		echo '<input type="hidden" name="action" value="g2ab_save_settings_pro" />';
		echo '<input type="hidden" name="active_tab" value="' . esc_attr( $tab ) . '" />';
	}

	private function close_form() {
		echo '<div class="g2ab-set__actions"><button type="submit" class="g2ab-set__btn g2ab-set__btn--primary">' . esc_html__( 'SAVE CHANGES', 'g2a-booking' ) . '</button></div>';
		echo '</form>';
	}

	/* ============================================================ */
	/*  ADDONS TAB                                                  */
	/* ============================================================ */
	private function render_addons_tab() {
		if ( class_exists( 'G2AB_Addon_Manager' ) ) {
			G2AB_Addon_Manager::instance()->render_admin_tab();
		} else {
			echo '<div class="notice notice-warning"><p>Addon Manager not loaded.</p></div>';
		}
	}

	/* ============================================================ */
	/*  GENERAL TAB                                                 */
	/* ============================================================ */
	private function render_general_tab() {
		$this->open_form( 'general' );
		?>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'BUSINESS', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Business Name', 'g2a-booking' ); ?></label><input type="text" name="g2ab_business_name" value="<?php echo esc_attr( g2ab_business_name() ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Phone', 'g2a-booking' ); ?></label><input type="text" name="g2ab_business_phone" value="<?php echo esc_attr( g2ab_business_phone() ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Address', 'g2a-booking' ); ?></label><input type="text" name="g2ab_business_address" value="<?php echo esc_attr( g2ab_business_address() ); ?>" />
				<small><?php esc_html_e( 'Pulled from the theme\'s Business Info settings (Customizer) until you save a value here explicitly.', 'g2a-booking' ); ?></small>
			</div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Currency', 'g2a-booking' ); ?></label>
				<select name="g2ab_currency"><?php $cur = get_option( 'g2ab_currency', 'USD' );
					foreach ( array( 'USD','CAD','EUR','GBP','AUD' ) as $c ) printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $c ), selected( $cur, $c, false ) ); ?></select>
			</div>
		</div>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'BOOKING BEHAVIOUR', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Reservation Hold (minutes)', 'g2a-booking' ); ?></label><input type="number" name="g2ab_reservation_hold_minutes" min="1" max="120" value="<?php echo esc_attr( get_option( 'g2ab_reservation_hold_minutes', 15 ) ); ?>" /><small><?php esc_html_e( 'How long an unpaid reservation is held before auto-cancel.', 'g2a-booking' ); ?></small></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Min Lead Time (minutes)', 'g2a-booking' ); ?></label><input type="number" name="g2ab_min_booking_lead_minutes" min="0" max="1440" value="<?php echo esc_attr( get_option( 'g2ab_min_booking_lead_minutes', 30 ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Max Advance Booking (days)', 'g2a-booking' ); ?></label><input type="number" name="g2ab_max_booking_advance_days" min="1" max="365" value="<?php echo esc_attr( get_option( 'g2ab_max_booking_advance_days', 60 ) ); ?>" /></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_allow_guest_booking" value="1" <?php checked( 1, (int) get_option( 'g2ab_allow_guest_booking', 1 ) ); ?> /> <?php esc_html_e( 'Allow guest bookings (no account required)', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Booking Page URL', 'g2a-booking' ); ?></label><input type="url" name="g2ab_booking_page_url" value="<?php echo esc_attr( get_option( 'g2ab_booking_page_url', '' ) ); ?>" placeholder="https://guns2ammo.com/book/" /><small><?php esc_html_e( 'Where customers land when clicking "RECRUIT NOW" on event banners.', 'g2a-booking' ); ?></small></div>
		</div>
		<?php
		$this->close_form();
	}

	/* ============================================================ */
	/*  PAYMENTS TAB                                                */
	/* ============================================================ */
	private function render_payments_tab() {
		$gw_sub = isset( $_GET['gw'] ) ? sanitize_key( $_GET['gw'] ) : 'overview';
		?>
		<nav class="g2ab-set__subtabs">
			<a class="<?php echo 'overview' === $gw_sub ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'payments', 'gw' => 'overview' ), admin_url( 'admin.php' ) ) ); ?>">OVERVIEW</a>
			<?php foreach ( self::GATEWAYS as $key => $g ) : ?>
				<a class="<?php echo $gw_sub === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'payments', 'gw' => $key ), admin_url( 'admin.php' ) ) ); ?>">
					<span class="g2ab-set__gw-logo" style="background:<?php echo esc_attr( $g['color'] ); ?>;"><?php echo esc_html( $g['logo'] ); ?></span>
					<?php echo esc_html( $g['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>
		<?php
		if ( 'overview' === $gw_sub ) {
			$this->render_payments_overview();
			return;
		}
		$this->open_form( 'payments' );
		echo '<input type="hidden" name="active_gw" value="' . esc_attr( $gw_sub ) . '" />';
		switch ( $gw_sub ) {
			case 'stripe':       $this->render_gateway_stripe(); break;
			case 'paypal':       $this->render_gateway_paypal(); break;
			case 'fortis':       $this->render_gateway_fortis(); break;
			case 'authnet':      $this->render_gateway_authnet(); break;
			case 'woocommerce':  $this->render_gateway_woocommerce(); break;
			case 'pay_in_store': $this->render_gateway_pay_in_store(); break;
		}
		$this->close_form();
	}

	private function render_payments_overview() {
		$default = get_option( 'g2ab_payment_gateway_default', 'stripe' );
		?>
		<div class="g2ab-set__gw-grid">
			<?php foreach ( self::GATEWAYS as $key => $g ) :
				$enabled = (int) get_option( 'g2ab_' . $key . '_enabled', 'pay_in_store' === $key ? 1 : 0 );
				$test    = (int) get_option( 'g2ab_' . $key . '_test_mode', 1 );
				$configured = $this->gateway_is_configured( $key );
			?>
				<div class="g2ab-set__gw-card <?php echo $enabled ? 'is-enabled' : ''; ?>">
					<div class="g2ab-set__gw-head">
						<span class="g2ab-set__gw-logo g2ab-set__gw-logo--lg" style="background:<?php echo esc_attr( $g['color'] ); ?>;"><?php echo esc_html( $g['logo'] ); ?></span>
						<div>
							<h4><?php echo esc_html( $g['label'] ); ?></h4>
							<?php if ( $configured ) : ?>
								<span class="g2ab-set__gw-status g2ab-set__gw-status--ok">● CONNECTED <?php if ( $test ) : ?><em>(TEST)</em><?php endif; ?></span>
							<?php else : ?>
								<span class="g2ab-set__gw-status">○ NOT CONFIGURED</span>
							<?php endif; ?>
						</div>
					</div>
					<p class="g2ab-set__gw-desc"><?php echo esc_html( $this->gateway_blurb( $key ) ); ?></p>
					<div class="g2ab-set__gw-actions">
						<a class="g2ab-set__btn" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'payments', 'gw' => $key ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'CONFIGURE →', 'g2a-booking' ); ?></a>
						<?php if ( $enabled ) : ?><span class="g2ab-set__pill g2ab-set__pill--on">ENABLED</span><?php else : ?><span class="g2ab-set__pill">OFF</span><?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'DEFAULT GATEWAY', 'g2a-booking' ); ?></h3>
			<p class="g2ab-set__desc"><?php esc_html_e( 'Selected when customer has multiple options. Change per-booking-type for finer control.', 'g2a-booking' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'g2ab_save_settings_pro', '_g2ab_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_settings_pro" />
				<input type="hidden" name="active_tab" value="payments" />
				<input type="hidden" name="active_gw" value="default" />
				<select name="g2ab_payment_gateway_default" style="min-width:240px;">
					<?php foreach ( self::GATEWAYS as $key => $g ) printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $key ), selected( get_option( 'g2ab_payment_gateway_default', 'stripe' ), $key, false ), esc_html( $g['label'] ) ); ?>
				</select>
				<p class="g2ab-set__desc" style="margin-top:16px;">
					<?php esc_html_e( 'Members whose plan includes lane time always reserve for $0. Everyone else follows the non-member policy below. Paid events always require verified online payment and ignore these options.', 'g2a-booking' ); ?>
				</p>

				<h3 style="margin:22px 0 6px;"><?php esc_html_e( 'NON-MEMBER PAYMENT POLICY', 'g2a-booking' ); ?></h3>
				<p class="g2ab-set__desc">
					<strong><?php esc_html_e( 'A non-member must complete secure online payment before a lane is held. This is a fixed rule and cannot be turned off here.', 'g2a-booking' ); ?></strong>
				</p>
				<p class="g2ab-set__desc">
					<?php esc_html_e( 'The reservation stays a pending-payment hold, invisible to operational rosters, until the payment is verified. Offline gateways (pay at store, cash, terminal, comp) are rejected on the public booking endpoint even for a crafted request.', 'g2a-booking' ); ?>
				</p>
				<p class="g2ab-set__desc">
					<?php esc_html_e( 'To take a booking at the desk, staff use Bookings → Add Booking, which records the staff member who took it. That path requires the "manage bookings" capability.', 'g2a-booking' ); ?>
				</p>
				<p style="margin:14px 0 6px;">
					<label><?php esc_html_e( 'Pending-payment hold expiry (minutes)', 'g2a-booking' ); ?></label><br />
					<input type="number" min="1" max="1440" name="g2ab_reservation_hold_minutes" value="<?php echo esc_attr( (int) get_option( 'g2ab_reservation_hold_minutes', 15 ) ); ?>" style="width:120px;" />
				</p>
				<p class="g2ab-set__desc">
					<?php esc_html_e( 'How long an unpaid checkout hold blocks the slot before it expires and the inventory is released.', 'g2a-booking' ); ?>
				</p>

				<button class="g2ab-set__btn g2ab-set__btn--primary" type="submit"><?php esc_html_e( 'SAVE PAYMENT POLICY', 'g2a-booking' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function gateway_blurb( $key ) {
		switch ( $key ) {
			case 'stripe':       return 'Industry-standard. PaymentIntents + webhooks. 2.9% + 30¢. Stripe Elements (PCI-friendly).';
			case 'paypal':       return 'Customer-trusted. Smart Buttons + Orders v2. PayPal/Venmo/Pay Later in one.';
			case 'fortis':       return 'Lower card-present rates for retail. Native ACH. Best for hybrid retail+services.';
			case 'authnet':      return 'Long-running merchant accounts. Accept Hosted (PCI-friendly) + CIM tokenization.';
			case 'woocommerce':  return 'Routes through your existing WooCommerce checkout. All WC gateways available.';
			case 'pay_in_store': return 'Customer reserves now, pays at front desk. No fees, manual confirmation.';
		}
		return '';
	}

	/**
	 * Echo value/placeholder attributes for a masked secret input. The stored
	 * secret is never rendered — an empty submit keeps it (see handle_save()).
	 */
	private function secret_input_attrs( $option, $empty_placeholder = '' ) {
		$has_saved = '' !== (string) get_option( $option, '' );
		$ph = $has_saved ? __( 'saved — enter new value to replace', 'g2a-booking' ) : $empty_placeholder;
		echo 'value=""' . ( $ph ? ' placeholder="' . esc_attr( $ph ) . '"' : '' );
	}

	private function gateway_is_configured( $key ) {
		switch ( $key ) {
			case 'stripe':       return get_option( 'g2ab_stripe_publishable_key' ) && get_option( 'g2ab_stripe_secret_key' );
			case 'paypal':       return get_option( 'g2ab_paypal_client_id' ) && get_option( 'g2ab_paypal_secret' );
			case 'fortis':       return get_option( 'g2ab_fortis_user_id' ) && get_option( 'g2ab_fortis_user_api_key' );
			case 'authnet':      return get_option( 'g2ab_authnet_login_id' ) && get_option( 'g2ab_authnet_transaction_key' );
			case 'woocommerce':  return class_exists( 'WooCommerce' );
			case 'pay_in_store': return true;
		}
		return false;
	}

	private function render_gateway_stripe() {
		?>
		<div class="g2ab-set__gw-card-detail">
			<div class="g2ab-set__gw-detail-head"><span class="g2ab-set__gw-logo g2ab-set__gw-logo--xl" style="background:#635BFF;">S</span><div><h2>Stripe</h2><p>Get your API keys at <a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener">dashboard.stripe.com/apikeys</a></p></div></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_stripe_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_stripe_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Enable Stripe', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_stripe_test_mode" value="1" <?php checked( 1, (int) get_option( 'g2ab_stripe_test_mode', 1 ) ); ?> /> <?php esc_html_e( 'Test mode (use pk_test_/sk_test_ keys)', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Publishable Key', 'g2a-booking' ); ?></label><input type="text" name="g2ab_stripe_publishable_key" value="<?php echo esc_attr( get_option( 'g2ab_stripe_publishable_key', '' ) ); ?>" placeholder="pk_live_..." /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Secret Key', 'g2a-booking' ); ?></label><input type="password" name="g2ab_stripe_secret_key" <?php $this->secret_input_attrs( 'g2ab_stripe_secret_key', 'sk_live_...' ); ?> autocomplete="new-password" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Webhook Signing Secret', 'g2a-booking' ); ?></label><input type="password" name="g2ab_stripe_webhook_secret" <?php $this->secret_input_attrs( 'g2ab_stripe_webhook_secret', 'whsec_...' ); ?> autocomplete="new-password" /><small><?php printf( esc_html__( 'Add a webhook in Stripe pointed at: %s', 'g2a-booking' ), '<code>' . esc_url( rest_url( G2AB_REST_NAMESPACE . '/webhooks/stripe' ) ) . '</code>' ); ?></small></div>
		</div>
		<?php
	}

	private function render_gateway_paypal() {
		?>
		<div class="g2ab-set__gw-card-detail">
			<div class="g2ab-set__gw-detail-head"><span class="g2ab-set__gw-logo g2ab-set__gw-logo--xl" style="background:#003087;">P</span><div><h2>PayPal</h2><p>Get your REST API credentials at <a href="https://developer.paypal.com/dashboard/applications/" target="_blank" rel="noopener">developer.paypal.com</a></p></div></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_paypal_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_paypal_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Enable PayPal', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_paypal_test_mode" value="1" <?php checked( 1, (int) get_option( 'g2ab_paypal_test_mode', 1 ) ); ?> /> <?php esc_html_e( 'Sandbox mode', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Client ID', 'g2a-booking' ); ?></label><input type="text" name="g2ab_paypal_client_id" value="<?php echo esc_attr( get_option( 'g2ab_paypal_client_id', '' ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Secret', 'g2a-booking' ); ?></label><input type="password" name="g2ab_paypal_secret" <?php $this->secret_input_attrs( 'g2ab_paypal_secret' ); ?> autocomplete="new-password" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Webhook ID', 'g2a-booking' ); ?></label><input type="text" name="g2ab_paypal_webhook_id" value="<?php echo esc_attr( get_option( 'g2ab_paypal_webhook_id', '' ) ); ?>" /><small><?php printf( esc_html__( 'Webhook URL: %s', 'g2a-booking' ), '<code>' . esc_url( rest_url( G2AB_REST_NAMESPACE . '/webhooks/paypal' ) ) . '</code>' ); ?></small></div>
		</div>
		<?php
	}

	private function render_gateway_fortis() {
		?>
		<div class="g2ab-set__gw-card-detail">
			<div class="g2ab-set__gw-detail-head"><span class="g2ab-set__gw-logo g2ab-set__gw-logo--xl" style="background:#0F4C75;">F</span><div><h2>Fortis Pay</h2><p>Get credentials at <a href="https://docs.fortispay.com/" target="_blank" rel="noopener">docs.fortispay.com</a> · 3-header auth (user-id, user-api-key, developer-id)</p></div></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_fortis_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_fortis_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Enable Fortis Pay', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_fortis_test_mode" value="1" <?php checked( 1, (int) get_option( 'g2ab_fortis_test_mode', 1 ) ); ?> /> <?php esc_html_e( 'Sandbox mode', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'User ID', 'g2a-booking' ); ?></label><input type="text" name="g2ab_fortis_user_id" value="<?php echo esc_attr( get_option( 'g2ab_fortis_user_id', '' ) ); ?>" /><small><?php esc_html_e( 'Your merchant user ID from Fortis.', 'g2a-booking' ); ?></small></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'User API Key', 'g2a-booking' ); ?></label><input type="password" name="g2ab_fortis_user_api_key" <?php $this->secret_input_attrs( 'g2ab_fortis_user_api_key' ); ?> autocomplete="new-password" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Developer ID', 'g2a-booking' ); ?></label><input type="text" name="g2ab_fortis_developer_id" value="<?php echo esc_attr( get_option( 'g2ab_fortis_developer_id', '' ) ); ?>" /><small><?php esc_html_e( 'Plugin partner identifier — provided to your client when they sign up.', 'g2a-booking' ); ?></small></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'HMAC Secret', 'g2a-booking' ); ?></label><input type="password" name="g2ab_fortis_hmac_secret" <?php $this->secret_input_attrs( 'g2ab_fortis_hmac_secret' ); ?> autocomplete="new-password" /><small><?php esc_html_e( 'Optional but recommended for production. Strengthens auth on /v2/transactions, /v2/payform, /v2/accountvault.', 'g2a-booking' ); ?></small></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Webhook Secret', 'g2a-booking' ); ?></label><input type="password" name="g2ab_fortis_webhook_secret" <?php $this->secret_input_attrs( 'g2ab_fortis_webhook_secret' ); ?> autocomplete="new-password" /><small><?php printf( esc_html__( 'Webhook URL: %s', 'g2a-booking' ), '<code>' . esc_url( rest_url( G2AB_REST_NAMESPACE . '/webhooks/fortis' ) ) . '</code>' ); ?></small></div>
		</div>
		<?php
	}

	private function render_gateway_authnet() {
		?>
		<div class="g2ab-set__gw-card-detail">
			<div class="g2ab-set__gw-detail-head"><span class="g2ab-set__gw-logo g2ab-set__gw-logo--xl" style="background:#1F3864;">A</span><div><h2>Authorize.net</h2><p>Get credentials at <a href="https://account.authorize.net/" target="_blank" rel="noopener">account.authorize.net</a></p></div></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_authnet_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_authnet_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Enable Authorize.net', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_authnet_test_mode" value="1" <?php checked( 1, (int) get_option( 'g2ab_authnet_test_mode', 1 ) ); ?> /> <?php esc_html_e( 'Sandbox mode', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'API Login ID', 'g2a-booking' ); ?></label><input type="text" name="g2ab_authnet_login_id" value="<?php echo esc_attr( get_option( 'g2ab_authnet_login_id', '' ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Transaction Key', 'g2a-booking' ); ?></label><input type="password" name="g2ab_authnet_transaction_key" <?php $this->secret_input_attrs( 'g2ab_authnet_transaction_key' ); ?> autocomplete="new-password" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Signature Key', 'g2a-booking' ); ?></label><input type="password" name="g2ab_authnet_signature_key" <?php $this->secret_input_attrs( 'g2ab_authnet_signature_key' ); ?> autocomplete="new-password" /><small><?php esc_html_e( 'Required for webhook signature verification.', 'g2a-booking' ); ?></small></div>
		</div>
		<?php
	}

	private function render_gateway_woocommerce() {
		$wc_active = class_exists( 'WooCommerce' );
		?>
		<div class="g2ab-set__gw-card-detail">
			<div class="g2ab-set__gw-detail-head"><span class="g2ab-set__gw-logo g2ab-set__gw-logo--xl" style="background:#7F54B3;">W</span><div><h2>WooCommerce Payments</h2><p>Routes bookings through your existing WooCommerce checkout. Customer can use any gateway you have configured in WC.</p></div></div>
			<?php if ( ! $wc_active ) : ?>
				<div class="g2ab-set__notice g2ab-set__notice--warn"><strong><?php esc_html_e( 'WooCommerce not detected.', 'g2a-booking' ); ?></strong> <?php esc_html_e( 'Install and activate WooCommerce to enable this gateway.', 'g2a-booking' ); ?></div>
			<?php else : ?>
				<div class="g2ab-set__notice g2ab-set__notice--ok"><strong><?php esc_html_e( 'WooCommerce active.', 'g2a-booking' ); ?></strong> <?php esc_html_e( 'Bookings will create WC orders for checkout.', 'g2a-booking' ); ?></div>
			<?php endif; ?>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_woocommerce_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_woocommerce_enabled', 0 ) ); ?> <?php disabled( ! $wc_active ); ?> /> <?php esc_html_e( 'Enable WooCommerce bridge', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'WC Product ID for booking line item', 'g2a-booking' ); ?></label><input type="number" name="g2ab_woo_product_id" value="<?php echo esc_attr( get_option( 'g2ab_woo_product_id', '' ) ); ?>" /><small><?php esc_html_e( 'Hidden product used as the cart line. Plugin auto-creates one if left blank.', 'g2a-booking' ); ?></small></div>
		</div>
		<?php
	}

	private function render_gateway_pay_in_store() {
		?>
		<div class="g2ab-set__gw-card-detail">
			<div class="g2ab-set__gw-detail-head"><span class="g2ab-set__gw-logo g2ab-set__gw-logo--xl" style="background:#E8802F;">$</span><div><h2>Pay In Store</h2><p>Customer reserves the slot, staff collects payment at the front desk on arrival.</p></div></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_pay_in_store_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_pay_in_store_enabled', 1 ) ); ?> /> <?php esc_html_e( 'Enable Pay In Store', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Reservation Hold (minutes from booking time)', 'g2a-booking' ); ?></label><input type="number" name="g2ab_pay_in_store_hold_minutes" min="5" max="240" value="<?php echo esc_attr( get_option( 'g2ab_pay_in_store_hold_minutes', 30 ) ); ?>" /><small><?php esc_html_e( 'How long before booking start the slot stays held without payment.', 'g2a-booking' ); ?></small></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Confirmation message', 'g2a-booking' ); ?></label><textarea name="g2ab_pay_in_store_message" rows="3"><?php echo esc_textarea( get_option( 'g2ab_pay_in_store_message', "Your spot is held. Pay at the front desk on arrival. We hold reservations for 30 minutes past start time." ) ); ?></textarea></div>
		</div>
		<?php
	}

	/* ============================================================ */
	/*  TEMPLATES TAB                                               */
	/* ============================================================ */
	private function render_templates_tab() {
		$this->open_form( 'templates' );
		$vars = '<code>{customer_name}</code> <code>{booking_uuid}</code> <code>{start_at}</code> <code>{end_at}</code> <code>{resource_name}</code> <code>{type_name}</code> <code>{total_amount}</code> <code>{paid_amount}</code> <code>{business_name}</code> <code>{business_phone}</code> <code>{business_address}</code> <code>{cancel_url}</code>';
		?>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'TEMPLATE VARIABLES', 'g2a-booking' ); ?></h3>
			<p class="g2ab-set__desc"><?php esc_html_e( 'Use these placeholders in any template — they\'re replaced when the email sends.', 'g2a-booking' ); ?></p>
			<p><?php echo $vars; ?></p>
		</div>
		<?php
		$templates = array(
			'confirmation' => array( 'label' => 'Booking Confirmation', 'desc' => 'Sent immediately after a booking is created.' ),
			'reminder'     => array( 'label' => 'Booking Reminder',     'desc' => 'Sent X hours before the booking start time (configured in Notifications tab).' ),
			'cancellation' => array( 'label' => 'Cancellation Notice',  'desc' => 'Sent when a booking is cancelled.' ),
			'refund'       => array( 'label' => 'Refund Issued',        'desc' => 'Sent when a refund is processed.' ),
			'admin_alert'  => array( 'label' => 'Admin New Booking',    'desc' => 'Internal alert sent to staff when a new booking comes in.' ),
		);
		foreach ( $templates as $key => $tpl ) :
			$subject = get_option( 'g2ab_tpl_' . $key . '_subject', '' );
			$body = get_option( 'g2ab_tpl_' . $key . '_body', '' );
		?>
			<div class="g2ab-set__panel">
				<h3><?php echo esc_html( $tpl['label'] ); ?></h3>
				<p class="g2ab-set__desc"><?php echo esc_html( $tpl['desc'] ); ?></p>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Subject', 'g2a-booking' ); ?></label><input type="text" name="g2ab_tpl_<?php echo esc_attr( $key ); ?>_subject" value="<?php echo esc_attr( $subject ); ?>" /></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Body', 'g2a-booking' ); ?></label><textarea name="g2ab_tpl_<?php echo esc_attr( $key ); ?>_body" rows="8"><?php echo esc_textarea( $body ); ?></textarea></div>
			</div>
		<?php endforeach;
		$this->close_form();
	}

	/* ============================================================ */
	/*  NOTIFICATIONS TAB                                           */
	/* ============================================================ */
	private function render_notifications_tab() {
		$this->open_form( 'notifications' );
		?>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'EMAIL', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Admin Notification Email', 'g2a-booking' ); ?></label><input type="email" name="g2ab_admin_notification_email" value="<?php echo esc_attr( get_option( 'g2ab_admin_notification_email', get_option( 'admin_email' ) ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'From Name', 'g2a-booking' ); ?></label><input type="text" name="g2ab_email_from_name" value="<?php echo esc_attr( get_option( 'g2ab_email_from_name', g2ab_business_name() ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'From Email', 'g2a-booking' ); ?></label><input type="email" name="g2ab_email_from_address" value="<?php echo esc_attr( get_option( 'g2ab_email_from_address', '' ) ); ?>" /></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_send_confirmation_email" value="1" <?php checked( 1, (int) get_option( 'g2ab_send_confirmation_email', 1 ) ); ?> /> <?php esc_html_e( 'Send booking confirmation email', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_send_reminder_email" value="1" <?php checked( 1, (int) get_option( 'g2ab_send_reminder_email', 1 ) ); ?> /> <?php esc_html_e( 'Send reminder email', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Reminder hours before start', 'g2a-booking' ); ?></label><input type="number" name="g2ab_reminder_hours_before" min="1" max="168" value="<?php echo esc_attr( get_option( 'g2ab_reminder_hours_before', 24 ) ); ?>" /></div>
		</div>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'SMS (Twilio)', 'g2a-booking' ); ?></h3>
			<p class="g2ab-set__desc"><?php esc_html_e( 'Optional SMS reminders. Requires a Twilio account.', 'g2a-booking' ); ?></p>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_sms_enabled" value="1" <?php checked( 1, (int) get_option( 'g2ab_sms_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Enable SMS reminders', 'g2a-booking' ); ?></label></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Twilio Account SID', 'g2a-booking' ); ?></label><input type="text" name="g2ab_twilio_sid" value="<?php echo esc_attr( get_option( 'g2ab_twilio_sid', '' ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Twilio Auth Token', 'g2a-booking' ); ?></label><input type="password" name="g2ab_twilio_token" <?php $this->secret_input_attrs( 'g2ab_twilio_token' ); ?> autocomplete="new-password" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Twilio From Number', 'g2a-booking' ); ?></label><input type="text" name="g2ab_twilio_from" value="<?php echo esc_attr( get_option( 'g2ab_twilio_from', '' ) ); ?>" placeholder="+15555555555" /></div>
		</div>
		<?php
		$this->close_form();
	}

	/* ============================================================ */
	/*  BRANDING TAB                                                */
	/* ============================================================ */
	private function render_branding_tab() {
		$this->open_form( 'branding' );
		?>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'COLORS', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Primary Color', 'g2a-booking' ); ?></label><input type="text" name="g2ab_brand_color_primary" value="<?php echo esc_attr( get_option( 'g2ab_brand_color_primary', '#C9A84C' ) ); ?>" /></div>
			<div class="g2ab-set__field"><label><?php esc_html_e( 'Accent Color', 'g2a-booking' ); ?></label><input type="text" name="g2ab_brand_color_accent" value="<?php echo esc_attr( get_option( 'g2ab_brand_color_accent', '#E8802F' ) ); ?>" /></div>
		</div>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'BUILD STATUS', 'g2a-booking' ); ?></h3>
			<p>REST: <code><?php echo esc_html( G2AB_REST_NAMESPACE ); ?></code> | DB: <code><?php echo esc_html( get_option( 'g2ab_db_version', 'n/a' ) ); ?></code> | Plugin: <code><?php echo esc_html( G2AB_VERSION ); ?></code> | PHP: <code><?php echo esc_html( PHP_VERSION ); ?></code> | WP: <code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></p>
		</div>
		<?php
		$this->close_form();
	}

	/* ============================================================ */
	/*  DANGER ZONE TAB                                             */
	/* ============================================================ */
	private function render_danger_tab() {
		$this->open_form( 'danger' );
		?>
		<div class="g2ab-set__panel" style="border-left:4px solid #C62828;">
			<h3 style="color:#C62828;"><?php esc_html_e( 'DANGER ZONE', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field"><label class="g2ab-set__check"><input type="checkbox" name="g2ab_remove_data_on_uninstall" value="1" <?php checked( 1, (int) get_option( 'g2ab_remove_data_on_uninstall', 0 ) ); ?> /> <?php esc_html_e( 'Remove all data on plugin delete', 'g2a-booking' ); ?></label><small><?php esc_html_e( 'Drops 8 tables, all bookings, payments, settings.', 'g2a-booking' ); ?></small></div>
			<p><a class="g2ab-set__btn" href="<?php echo esc_url( admin_url( 'admin.php?page=g2ab-tools' ) ); ?>"><?php esc_html_e( 'GO TO TOOLS PAGE →', 'g2a-booking' ); ?></a></p>
		</div>
		<?php
		$this->close_form();
	}

	/* ============================================================ */
	/*  FORM CUSTOMIZER TAB                                         */
	/* ============================================================ */
	private function render_form_customizer_tab() {
		$this->open_form( 'form_customizer' );

		$theme        = get_option( 'g2ab_form_theme', 'midnight' );
		$layout       = get_option( 'g2ab_form_layout', 'split' );
		$font         = get_option( 'g2ab_form_font', 'inter' );
		// Defaults are literal hex previews of the theme's live brass/ember/
		// void tokens (dark mode) — a color-picker swatch can't render a CSS
		// var() reference, so this mirrors what class-frontend.php's own
		// var(--color-brass)-style defaults resolve to today. Saving this
		// form without changing a field writes these hexes explicitly (no
		// longer inheriting the theme's live tokens across a light/dark
		// toggle) — a pre-existing tradeoff of a hex-based color picker, not
		// something this fix introduces.
		$primary      = get_option( 'g2ab_form_primary', '#C9A84C' );
		$accent       = get_option( 'g2ab_form_accent', '#E8802F' );
		$bg           = get_option( 'g2ab_form_bg', '#1A191E' );
		$surface      = get_option( 'g2ab_form_surface', '#211F26' );
		$surface2     = get_option( 'g2ab_form_surface2', '#2B2932' );
		$text         = get_option( 'g2ab_form_text', '#F7F7F9' );
		$muted        = get_option( 'g2ab_form_muted', '#A7A6AE' );
		$radius       = get_option( 'g2ab_form_radius', '16' );
		$title        = get_option( 'g2ab_form_title', '' );
		$subtitle     = get_option( 'g2ab_form_subtitle', '' );
		$description  = get_option( 'g2ab_form_description', '' );
		$features     = get_option( 'g2ab_form_features', "Instant confirmation email\nReschedule or cancel anytime\nNo spam — just your booking" );
		$continue_lbl = get_option( 'g2ab_form_continue_label', 'Continue' );
		$submit_lbl   = get_option( 'g2ab_form_submit_label', '' );
		?>
		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'PRESETS', 'g2a-booking' ); ?></h3>
			<p class="g2ab-set__desc"><?php esc_html_e( 'Pick a starting palette. You can fine-tune every token below.', 'g2a-booking' ); ?></p>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Theme', 'g2a-booking' ); ?></label>
				<select name="g2ab_form_theme">
					<option value="midnight" <?php selected( 'midnight', $theme ); ?>><?php esc_html_e( 'Midnight (dark, brass/ember accent)', 'g2a-booking' ); ?></option>
					<option value="light" <?php selected( 'light', $theme ); ?>><?php esc_html_e( 'Light (white surface)', 'g2a-booking' ); ?></option>
					<option value="dark" <?php selected( 'dark', $theme ); ?>><?php esc_html_e( 'Dark (flat dark)', 'g2a-booking' ); ?></option>
					<option value="custom" <?php selected( 'custom', $theme ); ?>><?php esc_html_e( 'Custom (use colors below)', 'g2a-booking' ); ?></option>
				</select>
				<small><?php esc_html_e( 'Custom themes always apply the color values below; preset themes use defaults that you can still override.', 'g2a-booking' ); ?></small>
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Layout', 'g2a-booking' ); ?></label>
				<select name="g2ab_form_layout">
					<option value="split" <?php selected( 'split', $layout ); ?>><?php esc_html_e( 'Split (service info left, booking right)', 'g2a-booking' ); ?></option>
					<option value="stacked" <?php selected( 'stacked', $layout ); ?>><?php esc_html_e( 'Stacked (single column, info on top)', 'g2a-booking' ); ?></option>
				</select>
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Typography', 'g2a-booking' ); ?></label>
				<select name="g2ab_form_font">
					<option value="system" <?php selected( 'system', $font ); ?>><?php esc_html_e( 'System (no extra requests)', 'g2a-booking' ); ?></option>
					<option value="inter" <?php selected( 'inter', $font ); ?>><?php esc_html_e( 'Inter', 'g2a-booking' ); ?></option>
					<option value="manrope" <?php selected( 'manrope', $font ); ?>><?php esc_html_e( 'Manrope', 'g2a-booking' ); ?></option>
					<option value="rubik" <?php selected( 'rubik', $font ); ?>><?php esc_html_e( 'Rubik', 'g2a-booking' ); ?></option>
					<option value="dmsans" <?php selected( 'dmsans', $font ); ?>><?php esc_html_e( 'DM Sans', 'g2a-booking' ); ?></option>
				</select>
			</div>
		</div>

		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'COLORS', 'g2a-booking' ); ?></h3>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;">
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Primary', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_primary" value="<?php echo esc_attr( $primary ); ?>" /> <small><?php esc_html_e( 'Buttons, selected state, links.', 'g2a-booking' ); ?></small></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Accent', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_accent" value="<?php echo esc_attr( $accent ); ?>" /> <small><?php esc_html_e( 'Secondary highlights, glow.', 'g2a-booking' ); ?></small></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Background', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_bg" value="<?php echo esc_attr( $bg ); ?>" /></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Card surface', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_surface" value="<?php echo esc_attr( $surface ); ?>" /></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Inner surface', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_surface2" value="<?php echo esc_attr( $surface2 ); ?>" /></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Text', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_text" value="<?php echo esc_attr( $text ); ?>" /></div>
				<div class="g2ab-set__field"><label><?php esc_html_e( 'Muted text', 'g2a-booking' ); ?></label><input type="color" name="g2ab_form_muted" value="<?php echo esc_attr( $muted ); ?>" /></div>
			</div>
		</div>

		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'SHAPE &amp; MOTION', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Corner radius (px)', 'g2a-booking' ); ?></label>
				<input type="number" name="g2ab_form_radius" min="0" max="32" value="<?php echo esc_attr( $radius ); ?>" />
				<small><?php esc_html_e( 'Try 4 for sharp, 16 for friendly, 24 for pill-ish.', 'g2a-booking' ); ?></small>
			</div>
			<div class="g2ab-set__field">
				<label class="g2ab-set__check"><input type="checkbox" name="g2ab_form_animations" value="1" <?php checked( 1, (int) get_option( 'g2ab_form_animations', 1 ) ); ?> /> <?php esc_html_e( 'Enable transitions and hover animations', 'g2a-booking' ); ?></label>
				<small><?php esc_html_e( 'Respects the visitor\'s prefers-reduced-motion setting.', 'g2a-booking' ); ?></small>
			</div>
		</div>

		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'SERVICE INFO CARD (LEFT)', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Title override', 'g2a-booking' ); ?></label>
				<input type="text" name="g2ab_form_title" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to use the booking type name.', 'g2a-booking' ); ?>" />
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Short subtitle', 'g2a-booking' ); ?></label>
				<input type="text" name="g2ab_form_subtitle" value="<?php echo esc_attr( $subtitle ); ?>" placeholder="<?php esc_attr_e( '1 line under the title', 'g2a-booking' ); ?>" />
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Description', 'g2a-booking' ); ?></label>
				<textarea name="g2ab_form_description" rows="3" placeholder="<?php esc_attr_e( 'A short paragraph describing the booking.', 'g2a-booking' ); ?>"><?php echo esc_textarea( $description ); ?></textarea>
				<small><?php esc_html_e( 'Plain text or basic HTML. Wrapped in paragraphs automatically.', 'g2a-booking' ); ?></small>
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Feature bullets', 'g2a-booking' ); ?></label>
				<textarea name="g2ab_form_features" rows="4" placeholder="<?php esc_attr_e( 'One per line', 'g2a-booking' ); ?>"><?php echo esc_textarea( $features ); ?></textarea>
				<small><?php esc_html_e( 'One bullet per line. Each appears with a coloured dot.', 'g2a-booking' ); ?></small>
			</div>
			<div class="g2ab-set__field">
				<label class="g2ab-set__check"><input type="checkbox" name="g2ab_form_badge_duration" value="1" <?php checked( 1, (int) get_option( 'g2ab_form_badge_duration', 1 ) ); ?> /> <?php esc_html_e( 'Show duration pill (e.g. "60 MIN")', 'g2a-booking' ); ?></label>
			</div>
			<div class="g2ab-set__field">
				<label class="g2ab-set__check"><input type="checkbox" name="g2ab_form_badge_price" value="1" <?php checked( 1, (int) get_option( 'g2ab_form_badge_price', 1 ) ); ?> /> <?php esc_html_e( 'Show price pill (e.g. "$25" or "FREE")', 'g2a-booking' ); ?></label>
			</div>
			<div class="g2ab-set__field">
				<label class="g2ab-set__check"><input type="checkbox" name="g2ab_form_show_pricing" value="1" <?php checked( 1, (int) get_option( 'g2ab_form_show_pricing', 1 ) ); ?> /> <?php esc_html_e( 'Show discount note for active members', 'g2a-booking' ); ?></label>
			</div>
		</div>

		<div class="g2ab-set__panel">
			<h3><?php esc_html_e( 'BOOKING STAGE (RIGHT)', 'g2a-booking' ); ?></h3>
			<div class="g2ab-set__field">
				<label class="g2ab-set__check"><input type="checkbox" name="g2ab_form_show_lane_grid" value="1" <?php checked( 1, (int) get_option( 'g2ab_form_show_lane_grid', 1 ) ); ?> /> <?php esc_html_e( 'Let the customer pick which lane/resource to book', 'g2a-booking' ); ?></label>
				<small><?php esc_html_e( 'Off = auto-assign first available resource (good if all lanes are identical).', 'g2a-booking' ); ?></small>
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( '"Continue" button label', 'g2a-booking' ); ?></label>
				<input type="text" name="g2ab_form_continue_label" value="<?php echo esc_attr( $continue_lbl ); ?>" />
			</div>
			<div class="g2ab-set__field">
				<label><?php esc_html_e( 'Reserve button label', 'g2a-booking' ); ?></label>
				<input type="text" name="g2ab_form_submit_label" value="<?php echo esc_attr( $submit_lbl ); ?>" placeholder="<?php esc_attr_e( 'Leave empty to auto-generate (e.g. "Reserve my lane")', 'g2a-booking' ); ?>" />
			</div>
		</div>

		<div class="g2ab-set__panel" style="background:#FAFBFD;">
			<h3><?php esc_html_e( 'PREVIEW', 'g2a-booking' ); ?></h3>
			<p class="g2ab-set__desc"><?php esc_html_e( 'Place this shortcode on a page to see the customized form:', 'g2a-booking' ); ?></p>
			<code style="display:block;padding:10px 12px;background:#1A191E;color:#FFD37A;border-radius:4px;font-size:13px;">[g2a_lane_booking]</code>
		</div>
		<?php
		$this->close_form();
	}

	/* ============================================================ */
	/*  SAVE HANDLER                                                */
	/* ============================================================ */
	public function handle_save() {
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_save_settings_pro', '_g2ab_nonce' );
		$tab = sanitize_key( $_POST['active_tab'] ?? 'general' );

		// Generic key/value save based on POST keys starting with g2ab_.
		foreach ( $_POST as $k => $v ) {
			if ( 0 !== strpos( $k, 'g2ab_' ) ) continue;
			if ( in_array( $k, array( 'g2ab_save_settings_pro', '_g2ab_nonce' ), true ) ) continue;

			// Determine sanitization based on key prefix/type.
			if ( in_array( $k, array( 'g2ab_admin_notification_email', 'g2ab_email_from_address' ), true ) ) {
				$val = sanitize_email( wp_unslash( $v ) );
				if ( $val && is_email( $val ) ) update_option( $k, $val );
			} elseif ( strpos( $k, '_secret' ) !== false || strpos( $k, '_key' ) !== false || strpos( $k, '_token' ) !== false || strpos( $k, '_password' ) !== false ) {
				$val = sanitize_text_field( wp_unslash( $v ) );
				// Masked secret fields render empty — an empty submit keeps the stored value.
				if ( '' === $val && in_array( $k, self::SECRET_OPTIONS, true ) ) continue;
				update_option( $k, $val );
			} elseif ( in_array( $k, array( 'g2ab_booking_page_url' ), true ) ) {
				update_option( $k, esc_url_raw( wp_unslash( $v ) ) );
			} elseif ( 'g2ab_reservation_hold_minutes' === $k ) {
				// Bounded integer: a zero/negative hold would disable expiry
				// entirely and leave unpaid holds blocking inventory forever.
				update_option( $k, max( 1, min( 1440, absint( wp_unslash( $v ) ) ) ) );
			} elseif ( strpos( $k, '_message' ) !== false || strpos( $k, '_body' ) !== false ) {
				update_option( $k, wp_kses_post( wp_unslash( $v ) ) );
			} elseif ( is_array( $v ) ) {
				$clean = array_map( 'sanitize_text_field', wp_unslash( $v ) );
				update_option( $k, $clean );
			} else {
				update_option( $k, sanitize_text_field( wp_unslash( $v ) ) );
			}
		}

		// Bool toggles — if unchecked, they aren't in $_POST, so explicitly clear them per tab.
		if ( 'general' === $tab ) {
			$bools = array( 'g2ab_allow_guest_booking' );
		} elseif ( 'payments' === $tab ) {
			$gw = sanitize_key( $_POST['active_gw'] ?? '' );
			$bools = array();
			if ( $gw && isset( self::GATEWAYS[ $gw ] ) ) {
				$bools[] = 'g2ab_' . $gw . '_enabled';
				$bools[] = 'g2ab_' . $gw . '_test_mode';
			}
			// The non-member payment policy is no longer a saved option: a
			// public non-member always pays online. Accepting either retired
			// checkbox name here would let a hand-crafted POST re-create the
			// option row, so nothing is added for the 'default' sub-form.
		} elseif ( 'notifications' === $tab ) {
			$bools = array( 'g2ab_send_confirmation_email', 'g2ab_send_reminder_email', 'g2ab_sms_enabled' );
		} elseif ( 'danger' === $tab ) {
			$bools = array( 'g2ab_remove_data_on_uninstall' );
		} elseif ( 'form_customizer' === $tab ) {
			$bools = array(
				'g2ab_form_animations',
				'g2ab_form_show_pricing',
				'g2ab_form_show_lane_grid',
				'g2ab_form_badge_duration',
				'g2ab_form_badge_price',
			);
		} else {
			$bools = array();
		}
		foreach ( $bools as $b ) {
			if ( ! isset( $_POST[ $b ] ) ) update_option( $b, 0 );
		}

		do_action( 'g2ab_settings_saved', $tab );

		$args = array( 'page' => self::PAGE_SLUG, 'tab' => $tab, 'saved' => '1' );
		if ( isset( $_POST['active_gw'] ) ) $args['gw'] = sanitize_key( $_POST['active_gw'] );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ============================================================ */
	private function print_styles() {
		echo '<style>
.g2ab-set{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
.g2ab-set__header{background:linear-gradient(135deg,#1A191E 0%,#26252C 100%);color:#F7F7F9;padding:24px 28px;margin:20px 0 0;border-left:4px solid #E8802F;}
.g2ab-set__stencil{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:30px;font-weight:700;letter-spacing:.04em;color:#fff;}
.g2ab-set__sub{margin:4px 0 0;color:#A7A6AE;font-size:13px;text-transform:uppercase;letter-spacing:.08em;}
.g2ab-set__tabs{background:#fff;border:1px solid #d0d4d9;border-top:none;display:flex;flex-wrap:wrap;}
.g2ab-set__tabs a{padding:14px 22px;text-decoration:none;color:#3c434a;font-size:12px;font-weight:700;letter-spacing:.08em;border-right:1px solid #f0f1f3;text-transform:uppercase;}
.g2ab-set__tabs a:hover{background:#f8f9fa;color:#E8802F;}
.g2ab-set__tabs a.is-active{background:#1A191E;color:#fff;border-right-color:#E8802F;}
.g2ab-set__subtabs{display:flex;gap:6px;flex-wrap:wrap;background:#fff;border:1px solid #d0d4d9;border-top:none;padding:10px 14px;}
.g2ab-set__subtabs a{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;color:#3c434a;text-decoration:none;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:2px;}
.g2ab-set__subtabs a:hover{background:#f0f1f3;}
.g2ab-set__subtabs a.is-active{background:#E8802F;color:#fff;}
.g2ab-set__gw-logo{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:3px;color:#fff;font-weight:700;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:14px;}
.g2ab-set__gw-logo--lg{width:48px;height:48px;font-size:24px;border-radius:4px;}
.g2ab-set__gw-logo--xl{width:64px;height:64px;font-size:32px;border-radius:6px;}
.g2ab-set__content{margin-top:14px;}
.g2ab-set__panel{background:#fff;border:1px solid #d0d4d9;padding:22px 28px;margin-bottom:14px;}
.g2ab-set__panel h3{font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-size:13px;letter-spacing:.12em;color:#E8802F;margin:0 0 14px;font-weight:700;}
.g2ab-set__field{margin-bottom:14px;}
.g2ab-set__field label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;color:#3c434a;text-transform:uppercase;margin-bottom:5px;}
.g2ab-set__field input[type="text"],.g2ab-set__field input[type="email"],.g2ab-set__field input[type="url"],.g2ab-set__field input[type="number"],.g2ab-set__field input[type="password"],.g2ab-set__field select,.g2ab-set__field textarea{width:100%;max-width:520px;padding:9px 12px;border:1px solid #d0d4d9;border-radius:2px;font-size:13px;font-family:inherit;}
.g2ab-set__field textarea{font-family:"SF Mono","Monaco",monospace;font-size:12px;}
.g2ab-set__field input:focus,.g2ab-set__field select:focus,.g2ab-set__field textarea:focus{outline:none;border-color:#E8802F;box-shadow:0 0 0 2px rgba(210,105,30,.15);}
.g2ab-set__field small{display:block;color:#A7A6AE;font-size:12px;margin-top:4px;}
.g2ab-set__field small code{background:#f0f1f3;padding:1px 6px;border-radius:2px;color:#3c434a;font-size:11px;}
.g2ab-set__check{display:inline-flex;align-items:center;gap:6px;text-transform:none;letter-spacing:0;font-weight:400;font-size:13px;}
.g2ab-set__check input{width:16px;height:16px;accent-color:#E8802F;}
.g2ab-set__desc{color:#A7A6AE;font-size:12px;margin:0 0 12px;}
.g2ab-set__actions{padding:18px 28px;background:#f8f9fa;border:1px solid #d0d4d9;border-top:none;}
/* (Old duplicate button rules consolidated below for predictable hover behavior.) */
.g2ab-set__gw-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-bottom:14px;}
.g2ab-set__gw-card{background:#fff;border:1px solid #d0d4d9;padding:20px;transition:all .15s ease;}
.g2ab-set__gw-card.is-enabled{border-top:4px solid #4CAF50;}
.g2ab-set__gw-card:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(0,0,0,.08);}
.g2ab-set__gw-head{display:flex;align-items:center;gap:14px;margin-bottom:10px;}
.g2ab-set__gw-head h4{margin:0 0 3px;font-size:16px;color:#1A191E;}
.g2ab-set__gw-status{font-size:10px;letter-spacing:.06em;font-weight:700;color:#A7A6AE;}
.g2ab-set__gw-status--ok{color:#4CAF50;}
.g2ab-set__gw-status em{color:#E8802F;font-style:normal;margin-left:4px;}
.g2ab-set__gw-desc{color:#3c434a;font-size:12px;margin:0 0 14px;line-height:1.5;}
.g2ab-set__gw-actions{display:flex;justify-content:space-between;align-items:center;}
.g2ab-set__pill{display:inline-block;padding:3px 8px;font-size:9px;letter-spacing:.06em;font-weight:700;border-radius:2px;background:#A7A6AE;color:#fff;}
.g2ab-set__pill--on{background:#4CAF50;}
/* ---- Buttons (single source of truth) ---- */
.g2ab-set__btn{display:inline-block;padding:10px 18px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border:1px solid #c8d1e0;border-radius:4px;background:#fff;color:#0f2044;text-decoration:none;cursor:pointer;font-family:inherit;transition:background .15s ease,color .15s ease,border-color .15s ease,box-shadow .15s ease,transform .15s ease;}
.g2ab-set__btn:hover,.g2ab-set__btn:focus{background:#0f2044;color:#ffffff;border-color:#0f2044;box-shadow:0 6px 14px rgba(15,32,68,.16);transform:translateY(-1px);text-decoration:none;}
.g2ab-set__btn:active{transform:translateY(0);}
.g2ab-set__btn:disabled,.g2ab-set__btn[aria-disabled="true"]{opacity:.55;cursor:not-allowed;transform:none;box-shadow:none;}
.g2ab-set__btn--primary{background:#0f2044;color:#ffffff;border-color:#0f2044;padding:11px 22px;}
.g2ab-set__btn--primary:hover,.g2ab-set__btn--primary:focus{background:#1a3a7a;color:#ffffff;border-color:#1a3a7a;box-shadow:0 8px 20px rgba(15,32,68,.24);}
.g2ab-set__btn--ghost{background:transparent;border-color:#c8d1e0;color:#0f2044;}
.g2ab-set__btn--ghost:hover,.g2ab-set__btn--ghost:focus{background:#f0f3f9;color:#0f2044;border-color:#0f2044;box-shadow:none;}
.g2ab-set__btn--danger{background:#fff;color:#C62828;border-color:#C62828;}
.g2ab-set__btn--danger:hover,.g2ab-set__btn--danger:focus{background:#C62828;color:#ffffff;border-color:#C62828;}
.g2ab-set__actions{margin-top:24px;padding-top:20px;border-top:1px solid #E2E5E9;}
.g2ab-set__header{background:#fff!important;background-image:repeating-linear-gradient(45deg,rgba(15,32,68,.035) 0,rgba(15,32,68,.035) 2px,transparent 2px,transparent 9px)!important;border:1px solid #d9e2ef!important;border-left:4px solid #0f2044!important;border-radius:8px!important;box-shadow:0 18px 38px rgba(15,32,68,.08)!important;color:#0f2044!important;margin:20px 0 18px!important;padding:28px 32px!important;position:relative!important;overflow:hidden!important;}
.g2ab-set__header:after{content:"";position:absolute;right:-80px;top:-80px;width:220px;height:220px;border:1px solid rgba(0,166,106,.16);border-radius:50%;pointer-events:none;}
.g2ab-set__stencil{color:#0f2044!important;text-shadow:none!important;-webkit-text-fill-color:#0f2044!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;}
.g2ab-set__sub{color:#526078!important;font-weight:500!important;letter-spacing:.02em!important;text-transform:none!important;}
.g2ab-set__tabs{border:1px solid #d9e2ef!important;border-radius:8px!important;overflow:hidden!important;margin-bottom:18px!important;box-shadow:0 10px 24px rgba(15,32,68,.05)!important;}
.g2ab-set__tabs a{color:#0f2044!important;letter-spacing:0!important;text-transform:none!important;transition:background .18s ease,color .18s ease,transform .18s ease!important;}
.g2ab-set__tabs a:hover{background:#f6f9fc!important;color:#07162f!important;transform:translateY(-1px)!important;}
.g2ab-set__tabs a.is-active{background:#0f2044!important;color:#fff!important;}
.g2ab-set__panel,.g2ab-set__gw-card,.g2ab-set__gw-card-detail{border:1px solid #d9e2ef!important;border-top:3px solid #0f2044!important;border-radius:8px!important;box-shadow:0 14px 32px rgba(15,32,68,.075)!important;transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease!important;}
.g2ab-set__panel:hover,.g2ab-set__gw-card:hover,.g2ab-set__gw-card-detail:hover{transform:translateY(-3px)!important;box-shadow:0 24px 52px rgba(15,32,68,.14)!important;border-color:rgba(0,166,106,.45)!important;}
.g2ab-set__gw-card{position:relative!important;overflow:hidden!important;background:linear-gradient(180deg,#fff 0%,#f8fbff 140%)!important;}
.g2ab-set__gw-card:before{content:"";position:absolute;right:-54px;top:-54px;width:140px;height:140px;background:radial-gradient(circle,rgba(0,166,106,.16),transparent 68%);opacity:0;transition:opacity .22s ease,transform .22s ease;pointer-events:none;}
.g2ab-set__gw-card:hover:before{opacity:1;transform:scale(1.08);}
.g2ab-set__gw-card.is-enabled{border-top-color:#00a66a!important;background:linear-gradient(180deg,#fff 0%,#f0fff8 155%)!important;}
.g2ab-ax__card{border:1px solid #d9e2ef!important;border-top:3px solid #0f2044!important;border-radius:8px!important;box-shadow:0 14px 32px rgba(15,32,68,.075)!important;background:linear-gradient(180deg,#fff 0%,#f8fbff 145%)!important;}
.g2ab-ax__card:hover{transform:translateY(-5px) scale(1.01)!important;box-shadow:0 28px 58px rgba(15,32,68,.16)!important;border-color:rgba(0,166,106,.5)!important;}
.g2ab-ax__card.is-active{border-top-color:#00a66a!important;background:linear-gradient(180deg,#fff 0%,#f0fff8 160%)!important;}
.g2ab-ax__hero{background:#fff!important;background-image:repeating-linear-gradient(45deg,rgba(15,32,68,.035) 0,rgba(15,32,68,.035) 2px,transparent 2px,transparent 9px)!important;border:1px solid #d9e2ef!important;border-left:4px solid #0f2044!important;border-radius:8px!important;box-shadow:0 18px 38px rgba(15,32,68,.08)!important;color:#0f2044!important;}
.g2ab-ax__hero-title,.g2ab-ax__hero p{color:#0f2044!important;text-shadow:none!important;}
</style>';
	}
}
