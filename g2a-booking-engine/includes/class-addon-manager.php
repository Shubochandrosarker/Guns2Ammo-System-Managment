<?php
/**
 * G2AB Addon Manager — registry + activation state for every plugin addon.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Addon_Manager {

	private static $instance = null;
	private $registry = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_g2ab_toggle_addon', array( $this, 'handle_toggle' ) );
		add_action( 'admin_post_g2ab_toggle_agency', array( $this, 'handle_agency_toggle' ) );
	}

	public function is_agency_mode() {
		if ( 1 === (int) get_option( 'g2ab_agency_mode', 0 ) ) return true;
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host && false !== stripos( $host, 'guns2ammo.com' ) ) return true;
		return false;
	}

	public function is_active( $id ) {
		return in_array( $id, $this->get_active_ids(), true );
	}

	public function get_active_ids() {
		$saved = get_option( 'g2ab_addons_active', null );
		if ( null === $saved ) {
			$defaults = array();
			foreach ( $this->all() as $id => $a ) {
				if ( ! empty( $a['default_active'] ) ) $defaults[] = $id;
			}
			update_option( 'g2ab_addons_active', $defaults );
			return $defaults;
		}
		return is_array( $saved ) ? $saved : array();
	}

	public function set_active_ids( $ids ) {
		update_option( 'g2ab_addons_active', array_values( array_unique( array_map( 'sanitize_key', (array) $ids ) ) ) );
	}

	public function activate( $id ) {
		$ids = $this->get_active_ids();
		if ( ! in_array( $id, $ids, true ) ) $ids[] = $id;
		$this->set_active_ids( $ids );
	}

	public function deactivate( $id ) {
		$this->set_active_ids( array_diff( $this->get_active_ids(), array( $id ) ) );
	}

	public function can_user_toggle( $id ) {
		$a = $this->get( $id );
		if ( ! $a ) return false;
		if ( 'coming_soon' === $a['status'] ) return false;
		if ( 'pro' === $a['tier'] && ! $this->is_agency_mode() ) return false;
		return true;
	}

	public function get( $id ) {
		$all = $this->all();
		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	public function all() {
		if ( null !== $this->registry ) return $this->registry;
		$this->registry = $this->build_registry();
		$this->registry = apply_filters( 'g2ab_addons', $this->registry );
		return $this->registry;
	}

	public function reset_registry() {
		$this->registry = null;
	}

	private function build_registry() {
		$a = array();
		// Payments
		$a['pay_in_store']  = array( 'name' => 'Pay In Store',         'desc' => 'Customer reserves online, pays at the front desk. Always free, always available.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'payments', 'icon' => '$', 'color' => '#4A5D3A', 'configure' => 'admin.php?page=g2ab-settings&tab=payments&gw=pay_in_store' );
		$a['stripe']        = array( 'name' => 'Stripe Payments',      'desc' => 'Credit/debit cards via Stripe Checkout. Industry-standard. 2.9% + 30c.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'payments', 'icon' => 'S', 'color' => '#635BFF', 'configure' => 'admin.php?page=g2ab-settings&tab=payments&gw=stripe' );
		$a['paypal']        = array( 'name' => 'PayPal Payments',      'desc' => 'PayPal Smart Buttons + Venmo + Pay Later. Customer-trusted checkout.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'payments', 'icon' => 'P', 'color' => '#003087', 'configure' => 'admin.php?page=g2ab-settings&tab=payments&gw=paypal' );
		$a['fortis']        = array( 'name' => 'Fortis Pay',           'desc' => 'Lower card-present rates plus native ACH. Best for retail+services hybrids.', 'tier' => 'pro', 'status' => 'active', 'default_active' => false, 'category' => 'payments', 'icon' => 'F', 'color' => '#0F4C75', 'configure' => 'admin.php?page=g2ab-settings&tab=payments&gw=fortis' );
		$a['authnet']       = array( 'name' => 'Authorize.net',        'desc' => 'Live Authorize.net hosted-payment-page integration with HMAC-verified silent-post webhook. Best for established merchant accounts.', 'tier' => 'pro', 'status' => 'active', 'default_active' => false, 'category' => 'payments', 'icon' => 'A', 'color' => '#1F3864', 'configure' => 'admin.php?page=g2ab-settings&tab=payments&gw=authnet' );
		$a['woocommerce']   = array( 'name' => 'WooCommerce Bridge',   'desc' => 'Route bookings through WooCommerce checkout. All active WC gateways (Stripe, PayPal, Square, manual) become available. Bidirectional status sync.', 'tier' => 'pro', 'status' => 'active', 'default_active' => false, 'category' => 'payments', 'icon' => 'W', 'color' => '#7F54B3', 'configure' => 'admin.php?page=g2ab-settings&tab=woocommerce' );
		// Notifications — Email Automation registers itself via the module loader (id: email_automation).
		$a['sms_twilio']      = array( 'name' => 'SMS Notifications (Twilio)', 'desc' => 'Booking reminders + confirmations via SMS. Requires Twilio account.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'notifications', 'icon' => 'M', 'color' => '#F22F46' );
		$a['whatsapp']        = array( 'name' => 'WhatsApp Integration', 'desc' => 'Booking confirmations + reminders via WhatsApp Cloud API.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'notifications', 'icon' => 'W', 'color' => '#25D366' );
		// Booking Features
		$a['deposit_payments']  = array( 'name' => 'Deposit Payments', 'desc' => 'Charge a deposit at booking, balance at checkout.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'booking_features', 'icon' => 'D', 'color' => '#4A5D3A' );
		$a['discount_coupons']  = array( 'name' => 'Discount Coupons', 'desc' => 'Promo codes with % or flat-amount, expiry, usage limits, per-type.', 'tier' => 'free', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'booking_features', 'icon' => '%', 'color' => '#4A5D3A' );
		$a['service_extras']    = array( 'name' => 'Service Extras', 'desc' => 'Optional paid add-ons: gun rental, ammo, eye/ear protection.', 'tier' => 'free', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'booking_features', 'icon' => '+', 'color' => '#4A5D3A' );
		$a['pricing_by_people'] = array( 'name' => 'Pricing by Number of People', 'desc' => 'Tiered pricing based on party size.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'booking_features', 'icon' => 'N', 'color' => '#0F4C75' );
		$a['custom_duration']   = array( 'name' => 'Custom Service Duration', 'desc' => 'Customer picks duration at booking with auto-pricing.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'booking_features', 'icon' => 'C', 'color' => '#0F4C75' );
		$a['waiting_list']      = array( 'name' => 'Event Waiting List', 'desc' => 'Auto-promote on cancellation when classes fill up.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'booking_features', 'icon' => 'L', 'color' => '#0F4C75' );
		// Sales — PDF Invoices registers itself via the module loader (id: pdf_invoices).
		$a['cart_page']     = array( 'name' => 'Cart Page',        'desc' => 'Multi-booking checkout in one cart.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'sales', 'icon' => 'C', 'color' => '#1F3864' );
		$a['event_tickets'] = array( 'name' => 'Event Tickets',    'desc' => 'PDF tickets with QR for door scanning. Mobile wallet ready.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'sales', 'icon' => 'T', 'color' => '#1F3864' );
		// Developer
		$a['rest_api'] = array( 'name' => 'REST API',          'desc' => 'Full bookings + availability + resources REST API. Live at /wp-json/g2a-booking/v1/.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'developer', 'icon' => 'R', 'color' => '#4A5D3A' );
		$a['webhooks'] = array( 'name' => 'Outbound Webhooks',  'desc' => 'POST booking events to Slack, Zapier, custom integrations.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'developer', 'icon' => 'H', 'color' => '#0F4C75' );
		// Locations
		$a['unlimited_locations'] = array( 'name' => 'Unlimited Locations', 'desc' => 'Run multiple ranges from one install with per-location resources, hours, staff.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'locations', 'icon' => 'L', 'color' => '#4A5D3A' );
		// Calendar & CRM
		$a['calendar_drag']     = array( 'name' => 'Calendar Drag-Reschedule', 'desc' => 'Drag bookings to reschedule, color-coded by resource.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'calendar', 'icon' => 'C', 'color' => '#0F4C75' );
		$a['customer_profiles'] = array( 'name' => 'Customer Profiles', 'desc' => 'Auto-aggregated booking history, lifetime value, no-show rate.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'calendar', 'icon' => 'P', 'color' => '#0F4C75' );
		// Templates
		$a['seo_landing_pages'] = array( 'name' => '6 SEO Landing Page Templates', 'desc' => '/event/{slug}/ landing pages with full JSON-LD schema.', 'tier' => 'free', 'status' => 'active', 'default_active' => true, 'category' => 'templates', 'icon' => 'L', 'color' => '#D2691E' );
		$a['landing_pack_pro']  = array( 'name' => 'Premium Landing Pages Pack', 'desc' => '12 additional industry-tuned landing templates.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'templates', 'icon' => 'P', 'color' => '#1F3864' );
		// Page Builders
		$a['elementor_widgets'] = array( 'name' => 'Elementor Widgets', 'desc' => 'Drag-and-drop booking widgets for Elementor.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'page_builders', 'icon' => 'E', 'color' => '#92003B' );
		$a['gutenberg_blocks']  = array( 'name' => 'Gutenberg Blocks', 'desc' => 'Native Block Editor blocks for booking flows.', 'tier' => 'pro', 'status' => 'coming_soon', 'default_active' => false, 'category' => 'page_builders', 'icon' => 'G', 'color' => '#0073AA' );
		return $a;
	}

	public function categories() {
		return array(
			'payments'         => 'Payments',
			'notifications'    => 'Notifications',
			'booking_features' => 'Booking Features',
			'sales'            => 'Sales & Tickets',
			'tools'            => 'Tools',
			'integrations'     => 'Integrations',
			'developer'        => 'Developer',
			'locations'        => 'Locations',
			'calendar'         => 'Calendar & CRM',
			'templates'        => 'Templates',
			'page_builders'    => 'Page Builders',
		);
	}

	public function counts() {
		$out = array( 'all' => 0, 'active' => 0, 'free' => 0, 'pro' => 0, 'coming_soon' => 0 );
		foreach ( $this->all() as $id => $a ) {
			$out['all']++;
			if ( $this->is_active( $id ) ) $out['active']++;
			if ( 'free' === $a['tier'] ) $out['free']++;
			if ( 'pro' === $a['tier'] ) $out['pro']++;
			if ( 'coming_soon' === $a['status'] ) $out['coming_soon']++;
		}
		return $out;
	}

	public function handle_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_toggle_addon', '_g2ab_nonce' );
		$id = sanitize_key( isset( $_POST['addon'] ) ? $_POST['addon'] : '' );
		if ( ! $this->can_user_toggle( $id ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'addons', 'g2ab_msg' => 'locked' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( $this->is_active( $id ) ) {
			$this->deactivate( $id ); $msg = 'deactivated';
		} else {
			$this->activate( $id ); $msg = 'activated';
		}
		do_action( 'g2ab_addon_toggled', $id, $msg );
		$cat = isset( $_POST['cat'] ) ? sanitize_key( $_POST['cat'] ) : '';
		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'addons', 'g2ab_msg' => $msg, 'cat' => $cat ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_agency_toggle() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_toggle_agency', '_g2ab_nonce' );
		$cur = (int) get_option( 'g2ab_agency_mode', 0 );
		update_option( 'g2ab_agency_mode', $cur ? 0 : 1 );
		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'addons' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_admin_tab() {
		$cats = $this->categories();
		$active_cat = isset( $_GET['cat'] ) ? sanitize_key( $_GET['cat'] ) : 'all';
		$counts = $this->counts();
		$agency = $this->is_agency_mode();
		$agency_explicit = (int) get_option( 'g2ab_agency_mode', 0 );

		$msg = '';
		if ( isset( $_GET['g2ab_msg'] ) ) {
			$mt = sanitize_key( $_GET['g2ab_msg'] );
			$messages = array(
				'activated'   => array( 'success', 'Addon activated successfully.' ),
				'deactivated' => array( 'success', 'Addon deactivated.' ),
				'locked'      => array( 'error',   'This addon requires a Pro license. Enable Agency Mode for development.' ),
			);
			if ( isset( $messages[ $mt ] ) ) {
				$msg = sprintf( '<div class="g2ab-ax__toast g2ab-ax__toast--%s">%s</div>', esc_attr( $messages[ $mt ][0] ), esc_html( $messages[ $mt ][1] ) );
			}
		}

		$this->print_styles();
		?>

		<div class="g2ab-ax">

			<header class="g2ab-ax__hero <?php echo $agency ? 'is-agency' : ''; ?>">
				<div class="g2ab-ax__hero-l">
					<span class="g2ab-ax__eyebrow"><?php echo $agency ? 'AGENCY UNLOCKED' : 'EXTENSIONS'; ?></span>
					<h1 class="g2ab-ax__hero-title"><?php echo $agency ? 'All Addons Unlocked' : 'Power Up Your Booking Engine'; ?></h1>
					<p class="g2ab-ax__hero-sub">
						<?php if ( $agency ) : ?>
							<?php echo $agency_explicit ? 'Agency mode enabled in options.' : 'Auto-unlocked for guns2ammo.com — every Pro addon is yours.'; ?>
						<?php else : ?>
							Toggle modules on or off without touching code. Each addon adds capability — payments, notifications, automation, AI.
						<?php endif; ?>
					</p>
				</div>

				<div class="g2ab-ax__hero-r">
					<div class="g2ab-ax__stat">
						<span class="g2ab-ax__stat-num"><?php echo (int) $counts['active']; ?></span>
						<span class="g2ab-ax__stat-label">Active</span>
					</div>
					<div class="g2ab-ax__stat">
						<span class="g2ab-ax__stat-num"><?php echo (int) $counts['all']; ?></span>
						<span class="g2ab-ax__stat-label">Total</span>
					</div>
					<div class="g2ab-ax__stat g2ab-ax__stat--soon">
						<span class="g2ab-ax__stat-num"><?php echo (int) $counts['coming_soon']; ?></span>
						<span class="g2ab-ax__stat-label">Coming Soon</span>
					</div>

					<?php if ( ! $agency ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-ax__hero-form">
							<?php wp_nonce_field( 'g2ab_toggle_agency', '_g2ab_nonce' ); ?>
							<input type="hidden" name="action" value="g2ab_toggle_agency" />
							<button class="g2ab-ax__agency-btn"><?php echo $agency_explicit ? 'Disable Agency' : 'Enable Agency Mode'; ?></button>
						</form>
					<?php endif; ?>
				</div>
			</header>

			<?php echo $msg; // phpcs:ignore ?>

			<nav class="g2ab-ax__filter">
				<a class="g2ab-ax__chip <?php echo 'all' === $active_cat ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'addons', 'cat' => 'all' ), admin_url( 'admin.php' ) ) ); ?>">
					All <span class="g2ab-ax__chip-c"><?php echo (int) $counts['all']; ?></span>
				</a>
				<?php foreach ( $cats as $key => $label ) :
					$cat_count = 0;
					foreach ( $this->all() as $a ) if ( $a['category'] === $key ) $cat_count++;
				?>
					<a class="g2ab-ax__chip <?php echo $active_cat === $key ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'addons', 'cat' => $key ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html( $label ); ?> <span class="g2ab-ax__chip-c"><?php echo (int) $cat_count; ?></span>
					</a>
				<?php endforeach; ?>
				<a class="g2ab-ax__chip g2ab-ax__chip--soon <?php echo 'coming_soon' === $active_cat ? 'is-on' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'g2ab-settings', 'tab' => 'addons', 'cat' => 'coming_soon' ), admin_url( 'admin.php' ) ) ); ?>">
					Coming Soon <span class="g2ab-ax__chip-c"><?php echo (int) $counts['coming_soon']; ?></span>
				</a>
			</nav>

			<div class="g2ab-ax__grid">
				<?php foreach ( $this->all() as $id => $a ) :
					if ( 'all' !== $active_cat && 'coming_soon' !== $active_cat && $a['category'] !== $active_cat ) continue;
					if ( 'coming_soon' === $active_cat && 'coming_soon' !== $a['status'] ) continue;
					$is_active   = $this->is_active( $id );
					$is_soon     = 'coming_soon' === $a['status'];
					$is_pro_lock = 'pro' === $a['tier'] && ! $agency && ! $is_soon;
					$icon_color  = isset( $a['color'] ) ? $a['color'] : '#4A5D3A';
				?>
					<article class="g2ab-ax__card <?php echo $is_active ? 'is-active' : ''; ?> <?php echo $is_soon ? 'is-soon' : ''; ?> <?php echo $is_pro_lock ? 'is-locked' : ''; ?>">
						<div class="g2ab-ax__card-glow" style="--glow:<?php echo esc_attr( $icon_color ); ?>;"></div>

						<div class="g2ab-ax__card-top">
							<div class="g2ab-ax__icon-wrap" style="--ic:<?php echo esc_attr( $icon_color ); ?>;">
								<span class="g2ab-ax__icon"><?php echo esc_html( $a['icon'] ); ?></span>
							</div>
							<div class="g2ab-ax__pills">
								<?php if ( $is_soon ) : ?>
									<span class="g2ab-ax__pill g2ab-ax__pill--soon">
										<svg width="8" height="8" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" fill="#F9A825"/></svg>
										Soon
									</span>
								<?php elseif ( $is_active ) : ?>
									<span class="g2ab-ax__pill g2ab-ax__pill--active">
										<svg width="8" height="8" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" fill="#4CAF50"/></svg>
										Active
									</span>
								<?php endif; ?>
								<?php if ( 'pro' === $a['tier'] ) : ?>
									<span class="g2ab-ax__pill g2ab-ax__pill--pro"><?php echo $agency ? 'AGENCY' : 'PRO'; ?></span>
								<?php else : ?>
									<span class="g2ab-ax__pill g2ab-ax__pill--free">FREE</span>
								<?php endif; ?>
							</div>
						</div>

						<h3 class="g2ab-ax__card-title"><?php echo esc_html( $a['name'] ); ?></h3>
						<p class="g2ab-ax__card-desc"><?php echo esc_html( $a['desc'] ); ?></p>

						<div class="g2ab-ax__card-actions">
							<?php if ( $is_soon ) : ?>
								<span class="g2ab-ax__hint">In active development</span>
							<?php elseif ( $is_pro_lock ) : ?>
								<a class="g2ab-ax__btn g2ab-ax__btn--upgrade" href="https://wordpressistic.com/g2a-booking-engine/pricing" target="_blank" rel="noopener">
									Upgrade to Pro
									<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4 2l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
								</a>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="g2ab-ax__form">
									<?php wp_nonce_field( 'g2ab_toggle_addon', '_g2ab_nonce' ); ?>
									<input type="hidden" name="action" value="g2ab_toggle_addon" />
									<input type="hidden" name="addon" value="<?php echo esc_attr( $id ); ?>" />
									<input type="hidden" name="cat" value="<?php echo esc_attr( $active_cat ); ?>" />
									<button class="g2ab-ax__btn <?php echo $is_active ? 'g2ab-ax__btn--off' : 'g2ab-ax__btn--on'; ?>">
										<?php if ( $is_active ) : ?>
											<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
											Deactivate
										<?php else : ?>
											<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M6 3v6M3 6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
											Activate
										<?php endif; ?>
									</button>
								</form>
								<?php if ( ! empty( $a['configure'] ) && $is_active ) : ?>
									<a class="g2ab-ax__btn g2ab-ax__btn--ghost" href="<?php echo esc_url( admin_url( $a['configure'] ) ); ?>">
										Configure
										<svg width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M4 2l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
									</a>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</article>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
	}

	private function print_styles() {
		echo '<style>
.g2ab-ax{font-family:-apple-system,BlinkMacSystemFont,"Inter","Segoe UI",Roboto,Arial,sans-serif;color:#0F1115;margin:20px 20px 40px 0;}
.g2ab-ax *{box-sizing:border-box;}

/* HERO */
.g2ab-ax__hero{position:relative;background:linear-gradient(135deg,#0F1115 0%,#1F242C 60%,#2A3038 100%);color:#fff;padding:36px 40px;border-radius:14px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:24px;overflow:hidden;margin-bottom:24px;border:1px solid rgba(255,255,255,.06);}
.g2ab-ax__hero::before{content:"";position:absolute;top:-50%;right:-10%;width:50%;height:200%;background:radial-gradient(ellipse at center,rgba(74,93,58,.18) 0%,transparent 60%);pointer-events:none;}
.g2ab-ax__hero.is-agency{background:linear-gradient(135deg,#1F1208 0%,#3D2412 50%,#0F1115 100%);}
.g2ab-ax__hero.is-agency::before{background:radial-gradient(ellipse at center,rgba(210,105,30,.32) 0%,transparent 60%);}
.g2ab-ax__hero-l{position:relative;z-index:1;flex:1;min-width:280px;}
.g2ab-ax__eyebrow{display:inline-block;font-size:11px;letter-spacing:.18em;font-weight:700;color:#D2691E;margin-bottom:10px;}
.g2ab-ax__hero.is-agency .g2ab-ax__eyebrow{color:#F9A825;}
.g2ab-ax__hero-title{color:#fff !important;font-size:30px;line-height:1.15;margin:0 0 8px;font-weight:800;letter-spacing:-.01em;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;padding:0;}
.g2ab-ax__hero h1.g2ab-ax__hero-title{color:#fff !important;}
.g2ab-ax__hero-sub{font-size:14px;color:rgba(255,255,255,.65);margin:0;max-width:540px;line-height:1.55;}
.g2ab-ax__hero-r{position:relative;z-index:1;display:flex;gap:18px;align-items:center;flex-wrap:wrap;}
.g2ab-ax__stat{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);padding:14px 22px;border-radius:10px;text-align:center;backdrop-filter:blur(10px);min-width:80px;}
.g2ab-ax__stat-num{display:block;font-size:28px;font-weight:800;line-height:1;color:#fff;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;}
.g2ab-ax__stat-label{display:block;font-size:10px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.12em;margin-top:6px;}
.g2ab-ax__stat--soon .g2ab-ax__stat-num{color:#F9A825;}
.g2ab-ax__hero-form{margin:0;}
.g2ab-ax__agency-btn{background:linear-gradient(135deg,#D2691E,#F9A825);color:#0F1115;border:0;padding:14px 22px;font-size:11px;letter-spacing:.12em;text-transform:uppercase;font-weight:800;border-radius:8px;cursor:pointer;box-shadow:0 4px 14px rgba(210,105,30,.3);transition:transform .15s ease,box-shadow .15s ease;}
.g2ab-ax__agency-btn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(210,105,30,.45);}

/* TOAST */
.g2ab-ax__toast{padding:14px 20px;border-radius:10px;font-size:14px;font-weight:600;margin-bottom:18px;border-left:4px solid;}
.g2ab-ax__toast--success{background:#E8F5E9;color:#1B5E20;border-color:#4CAF50;}
.g2ab-ax__toast--error{background:#FFEBEE;color:#B71C1C;border-color:#C62828;}

/* FILTER CHIPS */
.g2ab-ax__filter{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:24px;padding:10px;background:#fff;border:1px solid #E2E5E9;border-radius:12px;}
.g2ab-ax__chip{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;background:transparent;color:#5F6772;text-decoration:none;font-size:12px;font-weight:700;letter-spacing:.04em;border-radius:8px;transition:all .15s ease;border:1px solid transparent;}
.g2ab-ax__chip:hover{background:#F4F5F7;color:#0F1115;}
.g2ab-ax__chip.is-on{background:#0F1115;color:#fff;}
.g2ab-ax__chip-c{background:rgba(0,0,0,.08);padding:2px 8px;border-radius:99px;font-size:10px;font-weight:800;}
.g2ab-ax__chip.is-on .g2ab-ax__chip-c{background:#D2691E;color:#fff;}
.g2ab-ax__chip--soon{margin-left:auto;}

/* GRID */
.g2ab-ax__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px;}

/* CARD */
.g2ab-ax__card{position:relative;background:#fff;border:1px solid #E2E5E9;border-radius:14px;padding:24px;display:flex;flex-direction:column;transition:all .25s cubic-bezier(.2,.8,.2,1);overflow:hidden;}
.g2ab-ax__card:hover{transform:translateY(-3px);box-shadow:0 12px 32px rgba(15,17,21,.08);border-color:#D0D4D9;}
.g2ab-ax__card-glow{position:absolute;top:-40px;right:-40px;width:160px;height:160px;background:radial-gradient(circle,var(--glow,#4A5D3A) 0%,transparent 70%);opacity:0;transition:opacity .3s ease;pointer-events:none;}
.g2ab-ax__card:hover .g2ab-ax__card-glow{opacity:.12;}
.g2ab-ax__card.is-active{border-color:#4CAF50;background:linear-gradient(180deg,#fff 0%,#F1F8E9 200%);}
.g2ab-ax__card.is-active .g2ab-ax__card-glow{opacity:.08;}
.g2ab-ax__card.is-soon{opacity:.72;}
.g2ab-ax__card.is-soon:hover{opacity:.92;}
.g2ab-ax__card.is-locked{background:linear-gradient(180deg,#fff 0%,#FFF8F0 200%);}

.g2ab-ax__card-top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px;position:relative;z-index:1;}
.g2ab-ax__icon-wrap{position:relative;width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,var(--ic) 0%,color-mix(in srgb,var(--ic) 70%,#000) 100%);display:flex;align-items:center;justify-content:center;box-shadow:0 4px 12px color-mix(in srgb,var(--ic) 30%,transparent);}
.g2ab-ax__icon{color:#fff;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;font-weight:800;font-size:24px;letter-spacing:-.02em;}

.g2ab-ax__pills{display:flex;flex-direction:column;gap:5px;align-items:flex-end;}
.g2ab-ax__pill{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;font-size:9.5px;letter-spacing:.1em;font-weight:800;border-radius:99px;text-transform:uppercase;}
.g2ab-ax__pill--free{background:#E8F5E9;color:#2E7D32;}
.g2ab-ax__pill--pro{background:linear-gradient(135deg,#D2691E,#F9A825);color:#fff;box-shadow:0 2px 6px rgba(210,105,30,.25);}
.g2ab-ax__pill--active{background:#E8F5E9;color:#2E7D32;}
.g2ab-ax__pill--soon{background:#FFF3E0;color:#E65100;}

.g2ab-ax__card-title{font-size:17px;font-weight:700;line-height:1.3;color:#0F1115;margin:0 0 8px;letter-spacing:-.01em;}
.g2ab-ax__card-desc{font-size:13px;line-height:1.55;color:#5F6772;margin:0 0 20px;flex:1;}
.g2ab-ax__card-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}

.g2ab-ax__btn{display:inline-flex;align-items:center;gap:6px;padding:10px 16px;font-size:11.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:8px;border:1px solid;cursor:pointer;text-decoration:none;font-family:inherit;transition:all .15s ease;background:#fff;color:#0F1115;border-color:#D0D4D9;}
.g2ab-ax__btn:hover{transform:translateY(-1px);}
.g2ab-ax__btn--on{background:#0F1115;color:#fff;border-color:#0F1115;box-shadow:0 4px 12px rgba(15,17,21,.18);}
.g2ab-ax__btn--on:hover{background:#1F242C;box-shadow:0 6px 16px rgba(15,17,21,.25);}
.g2ab-ax__btn--off{background:#fff;color:#5F6772;border-color:#D0D4D9;}
.g2ab-ax__btn--off:hover{background:#FFEBEE;color:#C62828;border-color:#C62828;}
.g2ab-ax__btn--upgrade{background:linear-gradient(135deg,#D2691E,#F9A825);color:#fff;border-color:transparent;box-shadow:0 4px 12px rgba(210,105,30,.25);}
.g2ab-ax__btn--upgrade:hover{box-shadow:0 6px 18px rgba(210,105,30,.4);}
.g2ab-ax__btn--ghost{background:transparent;color:#0F1115;border-color:#0F1115;}
.g2ab-ax__btn--ghost:hover{background:#0F1115;color:#fff;}

.g2ab-ax__hint{font-size:11.5px;color:#8A95A5;font-style:italic;letter-spacing:.02em;}

@media (max-width: 782px){
  .g2ab-ax__hero{padding:28px 22px;}
  .g2ab-ax__hero-title{font-size:24px;}
  .g2ab-ax__stat{padding:10px 14px;min-width:64px;}
  .g2ab-ax__stat-num{font-size:22px;}
  .g2ab-ax__chip--soon{margin-left:0;}
}
</style>';
	}
}
