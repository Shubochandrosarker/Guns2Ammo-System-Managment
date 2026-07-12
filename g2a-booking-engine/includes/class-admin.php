<?php
/**
 * Admin orchestrator — registers menu, settings, Tools.
 * Build Status moved to Settings page footer.
 * Dashboard render is overridden by G2AB_Admin_Dashboard via admin_menu priority 11.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Admin {

	private static $instance = null;
	const MENU_SLUG = 'g2ab-bookings';
	const SETTINGS_SLUG = 'g2ab-settings';
	const MENU_CAP = 'manage_g2ab_bookings';

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_settings_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_g2ab_run_install', array( $this, 'handle_tools_run_install' ) );
		add_action( 'admin_post_g2ab_reinstall_table', array( $this, 'handle_tools_reinstall_table' ) );
		add_action( 'admin_post_g2ab_reset_all', array( $this, 'handle_tools_reset_all' ) );
		add_action( 'admin_post_g2ab_reseed', array( $this, 'handle_tools_reseed' ) );
	}

	public function register_menu() {
		add_menu_page( __( 'G2A Booking', 'g2a-booking' ), __( 'G2A Booking', 'g2a-booking' ), self::MENU_CAP, self::MENU_SLUG, array( $this, 'render_dashboard_page' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'g2a-booking' ), __( 'Dashboard', 'g2a-booking' ), self::MENU_CAP, self::MENU_SLUG, array( $this, 'render_dashboard_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Bookings', 'g2a-booking' ), __( 'Bookings', 'g2a-booking' ), 'manage_g2ab_bookings', 'g2ab-bookings-list', array( $this, 'render_bookings_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Resources', 'g2a-booking' ), __( 'Resources', 'g2a-booking' ), 'manage_g2ab_resources', 'g2ab-resources', array( $this, 'render_resources_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Booking Types', 'g2a-booking' ), __( 'Booking Types', 'g2a-booking' ), 'manage_g2ab_settings', 'g2ab-booking-types', array( $this, 'render_booking_types_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Forms', 'g2a-booking' ), __( 'Forms', 'g2a-booking' ), 'manage_g2ab_forms', 'g2ab-forms', array( $this, 'render_forms_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Availability', 'g2a-booking' ), __( 'Availability', 'g2a-booking' ), 'manage_g2ab_settings', 'g2ab-availability', array( $this, 'render_availability_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Payments', 'g2a-booking' ), __( 'Payments', 'g2a-booking' ), 'manage_g2ab_payments', 'g2ab-payments', array( $this, 'render_payments_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Reports', 'g2a-booking' ), __( 'Reports', 'g2a-booking' ), 'view_g2ab_reports', 'g2ab-reports', array( $this, 'render_reports_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Settings', 'g2a-booking' ), __( 'Settings', 'g2a-booking' ), 'manage_g2ab_settings', self::SETTINGS_SLUG, array( $this, 'render_settings_page' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Tools', 'g2a-booking' ), __( 'Tools', 'g2a-booking' ), 'manage_options', 'g2ab-tools', array( $this, 'render_tools_page' ) );
	}

	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, 'g2ab' ) ) return;
		$css = '.g2ab-wrap{max-width:1280px;}.g2ab-card,.g2ab-admin .g2ab-dash__panel,.g2ab-admin .g2ab-dash__kpi,.g2ab-admin .g2ab-mod__card,.g2ab-admin .g2ab-mod__panel,.g2ab-admin .g2ab-set__panel,.g2ab-admin .g2ab-bt__card,.g2ab-admin .g2ab-rs__card,.g2ab-admin .g2ab-fb__panel,.g2ab-admin .g2ab-pl__panel{background:#fff!important;border:1px solid #dcdcde!important;border-radius:8px!important;box-shadow:0 1px 1px rgba(0,0,0,.04)!important;}.g2ab-card{padding:20px;margin:18px 0;}.g2ab-card h2{margin-top:0;color:#0f2044;}.g2ab-admin .g2ab-dash__header,.g2ab-admin .g2ab-mod__header,.g2ab-admin .g2ab-set__hero,.g2ab-admin .g2ab-bt__hero,.g2ab-admin .g2ab-rs__hero,.g2ab-admin .g2ab-fb__hero,.g2ab-admin .g2ab-pl__hero{background:#fff!important;color:#1d2327!important;border:1px solid #dcdcde!important;border-left:4px solid #0f2044!important;border-radius:8px!important;box-shadow:0 1px 1px rgba(0,0,0,.04)!important;}.g2ab-admin .g2ab-dash__stencil,.g2ab-admin .g2ab-mod__stencil,.g2ab-admin h1 span{color:#0f2044!important;text-shadow:none!important;letter-spacing:0!important;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;text-transform:none!important;}.g2ab-admin .g2ab-dash__sub,.g2ab-admin .g2ab-mod__sub{color:#646970!important;letter-spacing:0!important;text-transform:none!important;}.g2ab-admin .g2ab-dash__kpi,.g2ab-admin .g2ab-mod__card,.g2ab-admin .g2ab-bt__card,.g2ab-admin .g2ab-rs__card{transition:transform .18s ease,box-shadow .18s ease!important;}.g2ab-admin .g2ab-dash__kpi:hover,.g2ab-admin .g2ab-mod__card:hover,.g2ab-admin .g2ab-bt__card:hover,.g2ab-admin .g2ab-rs__card:hover{transform:translateY(-2px)!important;box-shadow:0 10px 24px rgba(15,32,68,.1)!important;}.g2ab-admin .g2ab-dash__btn,.g2ab-admin .g2ab-mod__btn,.g2ab-admin .g2ab-set__btn,.g2ab-admin .g2ab-bt__btn,.g2ab-admin .g2ab-rs__btn,.g2ab-admin .g2ab-fb__btn{border-radius:6px!important;letter-spacing:0!important;text-transform:none!important;}.g2ab-admin .g2ab-dash__kpi-val,.g2ab-admin .g2ab-mod__stat-num{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif!important;color:#0f2044!important;}.g2ab-admin .g2ab-mod__stat{background:#f6f7f7!important;border-radius:8px!important;}.g2ab-admin .g2ab-mod__stat-lbl{color:#646970!important;letter-spacing:0!important;}.g2ab-phase-tag{display:inline-block;background:#0f2044;color:#fff;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;letter-spacing:0;text-transform:none;}.g2ab-coming{padding:18px;background:#fff;border-left:4px solid #00a66a;border-radius:8px;}';
		wp_register_style( 'g2ab-admin-inline', false, array(), G2AB_VERSION );
		wp_enqueue_style( 'g2ab-admin-inline' );
		wp_add_inline_style( 'g2ab-admin-inline', $css );
		$polish = '.g2ab-admin,.g2ab-wrap{--g2ab-navy:#0f2044;--g2ab-green:#00a66a;--g2ab-ink:#172033;--g2ab-line:#d9e2ef;}.g2ab-admin h1,.g2ab-wrap h1,.g2ab-admin h2,.g2ab-wrap h2{color:var(--g2ab-navy)!important;letter-spacing:0!important;text-transform:none!important;}.g2ab-admin .nav-tab-wrapper,.g2ab-wrap .nav-tab-wrapper{border-bottom:1px solid var(--g2ab-line)!important;padding-top:10px!important;}.g2ab-admin .nav-tab,.g2ab-wrap .nav-tab{background:#fff!important;border:1px solid var(--g2ab-line)!important;border-bottom:0!important;color:var(--g2ab-navy)!important;border-radius:8px 8px 0 0!important;font-weight:700!important;transition:background .18s ease,color .18s ease,transform .18s ease!important;}.g2ab-admin .nav-tab:hover,.g2ab-wrap .nav-tab:hover{background:#f6f9fc!important;color:#07162f!important;transform:translateY(-1px)!important;}.g2ab-admin .nav-tab-active,.g2ab-wrap .nav-tab-active{background:var(--g2ab-navy)!important;color:#fff!important;border-color:var(--g2ab-navy)!important;}.g2ab-admin .wp-list-table,.g2ab-wrap .wp-list-table,.g2ab-admin table.form-table,.g2ab-wrap table.form-table{background:#fff!important;border-radius:8px!important;overflow:hidden!important;box-shadow:0 10px 24px rgba(15,32,68,.06)!important;}.g2ab-admin .button-primary,.g2ab-wrap .button-primary{background:var(--g2ab-navy)!important;border-color:var(--g2ab-navy)!important;border-radius:6px!important;}.g2ab-admin .button,.g2ab-wrap .button{border-radius:6px!important;}.g2ab-admin .notice,.g2ab-wrap .notice{border-radius:8px!important;}';
		wp_add_inline_style( 'g2ab-admin-inline', $polish );
		$premium = '.g2ab-admin .g2ab-dash__header,.g2ab-admin .g2ab-mod__header,.g2ab-admin .g2ab-bt__header,.g2ab-admin .g2ab-rs__header,.g2ab-admin .g2ab-fb__header,.g2ab-admin .g2ab-pl__header,.g2ab-admin .g2ab-set__hero,.g2ab-admin .g2ab-av__header,.g2ab-admin .g2ab-rp__header{background:#fff!important;background-image:repeating-linear-gradient(45deg,rgba(15,32,68,.035) 0,rgba(15,32,68,.035) 2px,transparent 2px,transparent 9px)!important;border:1px solid #d9e2ef!important;border-left:4px solid #0f2044!important;border-radius:8px!important;box-shadow:0 18px 38px rgba(15,32,68,.08)!important;color:#0f2044!important;margin:20px 0 18px!important;padding:28px 32px!important;position:relative!important;overflow:hidden!important;}.g2ab-admin .g2ab-dash__header:after,.g2ab-admin .g2ab-mod__header:after,.g2ab-admin .g2ab-bt__header:after,.g2ab-admin .g2ab-rs__header:after,.g2ab-admin .g2ab-fb__header:after,.g2ab-admin .g2ab-pl__header:after,.g2ab-admin .g2ab-set__hero:after{content:"";position:absolute;right:-80px;top:-80px;width:220px;height:220px;border:1px solid rgba(0,166,106,.16);border-radius:50%;pointer-events:none;}.g2ab-admin .g2ab-dash__stencil,.g2ab-admin .g2ab-mod__stencil,.g2ab-admin .g2ab-bt__stencil,.g2ab-admin .g2ab-rs__stencil,.g2ab-admin .g2ab-fb__stencil,.g2ab-admin .g2ab-pl__stencil,.g2ab-admin .g2ab-set__stencil{color:#0f2044!important;text-shadow:none!important;-webkit-text-fill-color:#0f2044!important;font-size:30px!important;font-weight:900!important;letter-spacing:0!important;}.g2ab-admin .g2ab-dash__sub,.g2ab-admin .g2ab-mod__sub,.g2ab-admin .g2ab-bt__sub,.g2ab-admin .g2ab-rs__sub,.g2ab-admin .g2ab-fb__sub,.g2ab-admin .g2ab-pl__sub{color:#526078!important;font-weight:500!important;letter-spacing:.02em!important;}.g2ab-admin .g2ab-dash__kpi,.g2ab-admin .g2ab-mod__card,.g2ab-admin .g2ab-bt__card,.g2ab-admin .g2ab-rs__card,.g2ab-admin .g2ab-fb__panel,.g2ab-admin .g2ab-pl__panel,.g2ab-admin .g2ab-set__panel,.g2ab-admin .g2ab-card{border:1px solid #d9e2ef!important;border-top:3px solid #0f2044!important;border-radius:8px!important;box-shadow:0 12px 28px rgba(15,32,68,.07)!important;transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease!important;}.g2ab-admin .g2ab-dash__kpi:hover,.g2ab-admin .g2ab-mod__card:hover,.g2ab-admin .g2ab-bt__card:hover,.g2ab-admin .g2ab-rs__card:hover,.g2ab-admin .g2ab-fb__panel:hover,.g2ab-admin .g2ab-pl__panel:hover,.g2ab-admin .g2ab-set__panel:hover,.g2ab-admin .g2ab-card:hover{transform:translateY(-4px)!important;box-shadow:0 22px 48px rgba(15,32,68,.13)!important;border-color:rgba(0,166,106,.45)!important;}.g2ab-admin .g2ab-bt__tabs a,.g2ab-admin .g2ab-rs__tabs a{transition:background .18s ease,color .18s ease,transform .18s ease!important;}.g2ab-admin .g2ab-bt__tabs a:hover,.g2ab-admin .g2ab-rs__tabs a:hover{transform:translateY(-1px)!important;}';
		wp_add_inline_style( 'g2ab-admin-inline', $premium );

		// Final override — normalizes all G2A admin button hover behaviour so
		// no button ever becomes "white text on white background" or loses its
		// border on hover. Loaded last so it wins the cascade.
		$button_normalize = '
.g2ab-admin .g2ab-dash__btn,.g2ab-admin .g2ab-mod__btn,.g2ab-admin .g2ab-set__btn,.g2ab-admin .g2ab-bt__btn,.g2ab-admin .g2ab-rs__btn,.g2ab-admin .g2ab-fb__btn,.g2ab-admin .g2ab-av__btn,.g2ab-admin .g2ab-rp__btn,.g2ab-admin .g2ab-pl__btn{display:inline-flex;align-items:center;gap:6px;padding:9px 16px;background:#ffffff;color:#0f2044;border:1px solid #c8d1e0;text-decoration:none;font-size:12px;font-weight:600;letter-spacing:0;text-transform:none;border-radius:6px;cursor:pointer;font-family:inherit;transition:background .15s ease,color .15s ease,border-color .15s ease,box-shadow .15s ease,transform .15s ease;}
.g2ab-admin .g2ab-dash__btn:hover,.g2ab-admin .g2ab-mod__btn:hover,.g2ab-admin .g2ab-set__btn:hover,.g2ab-admin .g2ab-bt__btn:hover,.g2ab-admin .g2ab-rs__btn:hover,.g2ab-admin .g2ab-fb__btn:hover,.g2ab-admin .g2ab-av__btn:hover,.g2ab-admin .g2ab-rp__btn:hover,.g2ab-admin .g2ab-pl__btn:hover,.g2ab-admin .g2ab-dash__btn:focus,.g2ab-admin .g2ab-mod__btn:focus,.g2ab-admin .g2ab-set__btn:focus,.g2ab-admin .g2ab-bt__btn:focus,.g2ab-admin .g2ab-rs__btn:focus,.g2ab-admin .g2ab-fb__btn:focus,.g2ab-admin .g2ab-av__btn:focus,.g2ab-admin .g2ab-rp__btn:focus,.g2ab-admin .g2ab-pl__btn:focus{background:#0f2044!important;color:#ffffff!important;border-color:#0f2044!important;box-shadow:0 6px 14px rgba(15,32,68,.16);transform:translateY(-1px);text-decoration:none;}
.g2ab-admin .g2ab-dash__btn--primary,.g2ab-admin .g2ab-mod__btn--primary,.g2ab-admin .g2ab-set__btn--primary,.g2ab-admin .g2ab-bt__btn--primary,.g2ab-admin .g2ab-rs__btn--primary,.g2ab-admin .g2ab-fb__btn--primary,.g2ab-admin .g2ab-av__btn--primary,.g2ab-admin .g2ab-rp__btn--primary,.g2ab-admin .g2ab-pl__btn--primary{background:#0f2044!important;color:#ffffff!important;border-color:#0f2044!important;padding:10px 18px;}
.g2ab-admin .g2ab-dash__btn--primary:hover,.g2ab-admin .g2ab-mod__btn--primary:hover,.g2ab-admin .g2ab-set__btn--primary:hover,.g2ab-admin .g2ab-bt__btn--primary:hover,.g2ab-admin .g2ab-rs__btn--primary:hover,.g2ab-admin .g2ab-fb__btn--primary:hover,.g2ab-admin .g2ab-av__btn--primary:hover,.g2ab-admin .g2ab-rp__btn--primary:hover,.g2ab-admin .g2ab-pl__btn--primary:hover,.g2ab-admin .g2ab-dash__btn--primary:focus,.g2ab-admin .g2ab-mod__btn--primary:focus,.g2ab-admin .g2ab-set__btn--primary:focus,.g2ab-admin .g2ab-bt__btn--primary:focus,.g2ab-admin .g2ab-rs__btn--primary:focus,.g2ab-admin .g2ab-fb__btn--primary:focus,.g2ab-admin .g2ab-av__btn--primary:focus,.g2ab-admin .g2ab-rp__btn--primary:focus,.g2ab-admin .g2ab-pl__btn--primary:focus{background:#1a3a7a!important;color:#ffffff!important;border-color:#1a3a7a!important;box-shadow:0 8px 20px rgba(15,32,68,.24);transform:translateY(-1px);}
.g2ab-admin .g2ab-dash__btn--danger,.g2ab-admin .g2ab-mod__btn--danger,.g2ab-admin .g2ab-set__btn--danger,.g2ab-admin .g2ab-bt__btn--danger,.g2ab-admin .g2ab-rs__btn--danger,.g2ab-admin .g2ab-fb__btn--danger,.g2ab-admin .g2ab-av__btn--danger,.g2ab-admin .g2ab-rp__btn--danger,.g2ab-admin .g2ab-pl__btn--danger{background:#ffffff!important;color:#C62828!important;border-color:#C62828!important;}
.g2ab-admin .g2ab-dash__btn--danger:hover,.g2ab-admin .g2ab-mod__btn--danger:hover,.g2ab-admin .g2ab-set__btn--danger:hover,.g2ab-admin .g2ab-bt__btn--danger:hover,.g2ab-admin .g2ab-rs__btn--danger:hover,.g2ab-admin .g2ab-fb__btn--danger:hover,.g2ab-admin .g2ab-av__btn--danger:hover,.g2ab-admin .g2ab-rp__btn--danger:hover,.g2ab-admin .g2ab-pl__btn--danger:hover{background:#C62828!important;color:#ffffff!important;border-color:#C62828!important;}
.g2ab-admin .g2ab-set__btn--ghost{background:transparent!important;color:#0f2044!important;border-color:#c8d1e0!important;}
.g2ab-admin .g2ab-set__btn--ghost:hover{background:#f0f3f9!important;color:#0f2044!important;border-color:#0f2044!important;}
/* WP primary buttons (e.g. FILTER, UPDATE STATUS) must keep white text on the navy fill. */
.g2ab-admin .button-primary,.g2ab-wrap .button-primary{color:#fff!important;}
/* The booking-detail header is a dark gradient; its title/stencil must be light, not the
   global navy that the admin theme otherwise forces on .g2ab-mod__stencil (MISSION BRIEF). */
.g2ab-admin .g2ab-mod__detail-head .g2ab-mod__stencil,.g2ab-admin .g2ab-mod__detail-head h1,.g2ab-admin .g2ab-mod__detail-head h1 span{color:#fff!important;-webkit-text-fill-color:#fff!important;}
';
		wp_add_inline_style( 'g2ab-admin-inline', $button_normalize );
	}

	// Fallback dashboard — only shown if G2AB_Admin_Dashboard isn't loaded.
	public function render_dashboard_page() {
		if ( ! current_user_can( self::MENU_CAP ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		if ( class_exists( 'G2AB_Admin_Dashboard' ) ) return; // real component owns this page
		$this->render_placeholder( __( 'Dashboard', 'g2a-booking' ), __( 'No live data yet — start taking bookings to populate metrics.', 'g2a-booking' ) );
	}

	public function render_bookings_page() {
		$this->require_cap( 'manage_g2ab_bookings' );
		if ( class_exists( 'G2AB_Admin_Bookings_List' ) ) return;
		$this->render_placeholder( __( 'Bookings', 'g2a-booking' ), __( 'No bookings yet.', 'g2a-booking' ) );
	}

	public function render_resources_page() {
		$this->require_cap( 'manage_g2ab_resources' );
		if ( class_exists( 'G2AB_Admin_Resources_Crud' ) ) return;
		$this->render_placeholder( __( 'Resources', 'g2a-booking' ), __( 'No resources configured.', 'g2a-booking' ) );
	}

	public function render_booking_types_page() {
		$this->require_cap( 'manage_g2ab_settings' );
		if ( class_exists( 'G2AB_Admin_Booking_Types_Crud' ) ) return;
		$this->render_placeholder( __( 'Booking Types', 'g2a-booking' ), __( 'No booking types configured.', 'g2a-booking' ) );
	}

	public function render_forms_page() {
		$this->require_cap( 'manage_g2ab_forms' );
		if ( class_exists( 'G2AB_Admin_Forms_List' ) ) return;
		$this->render_placeholder( __( 'Forms', 'g2a-booking' ), __( 'No forms yet.', 'g2a-booking' ) );
	}

	public function render_availability_page() {
		$this->require_cap( 'manage_g2ab_settings' );
		if ( class_exists( 'G2AB_Admin_Availability_Crud' ) ) return;
		$this->render_placeholder( __( 'Availability Rules', 'g2a-booking' ), __( 'No availability rules set.', 'g2a-booking' ) );
	}

	public function render_payments_page() {
		$this->require_cap( 'manage_g2ab_payments' );
		if ( class_exists( 'G2AB_Admin_Payments_List' ) ) return;
		$this->render_placeholder( __( 'Payments', 'g2a-booking' ), __( 'No payment activity yet.', 'g2a-booking' ) );
	}

	public function render_reports_page() {
		$this->require_cap( 'view_g2ab_reports' );
		if ( class_exists( 'G2AB_Admin_Reports' ) ) return;
		$this->render_placeholder( __( 'Reports', 'g2a-booking' ), __( 'Reports populate as bookings come in.', 'g2a-booking' ) );
	}

	public function render_settings_page() {
		$this->require_cap( 'manage_g2ab_settings' );
		// Settings Pro owns this page. Render it directly as a fallback in case
		// the original submenu callback is still attached on an upgraded site.
		if ( class_exists( 'G2AB_Admin_Settings_Pro' ) ) {
			G2AB_Admin_Settings_Pro::instance()->render();
			return;
		}
		$saved = isset( $_GET['settings-updated'] ) && '1' === $_GET['settings-updated'];
		?>
		<div class="wrap g2ab-wrap">
			<h1><?php esc_html_e( 'G2A Booking — Settings', 'g2a-booking' ); ?></h1>
			<?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'g2a-booking' ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'g2ab_save_settings', '_g2ab_settings_nonce' ); ?>
				<input type="hidden" name="action" value="g2ab_save_settings" />
				<div class="g2ab-card"><h2><?php esc_html_e( 'Business', 'g2a-booking' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Business Name', 'g2a-booking' ); ?></th><td><input name="g2ab_business_name" type="text" class="regular-text" value="<?php echo esc_attr( g2ab_business_name() ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Phone', 'g2a-booking' ); ?></th><td><input name="g2ab_business_phone" type="text" class="regular-text" value="<?php echo esc_attr( g2ab_business_phone() ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Address', 'g2a-booking' ); ?></th><td><input name="g2ab_business_address" type="text" class="regular-text" value="<?php echo esc_attr( g2ab_business_address() ); ?>" /><br><small><?php esc_html_e( 'Pulled from the theme\'s Business Info settings (Customizer) until you save a value here explicitly.', 'g2a-booking' ); ?></small></td></tr>
						<tr><th><?php esc_html_e( 'Currency', 'g2a-booking' ); ?></th><td><select name="g2ab_currency"><?php $cur = get_option( 'g2ab_currency', 'USD' ); foreach ( array( 'USD','CAD','EUR','GBP','AUD' ) as $c ) printf( '<option value="%1$s" %2$s>%1$s</option>', esc_attr( $c ), selected( $cur, $c, false ) ); ?></select></td></tr>
					</table>
				</div>
				<div class="g2ab-card"><h2><?php esc_html_e( 'Booking Behaviour', 'g2a-booking' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Reservation Hold (min)', 'g2a-booking' ); ?></th><td><input name="g2ab_reservation_hold_minutes" type="number" min="1" max="120" value="<?php echo esc_attr( get_option( 'g2ab_reservation_hold_minutes', 15 ) ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Min Lead Time (min)', 'g2a-booking' ); ?></th><td><input name="g2ab_min_booking_lead_minutes" type="number" min="0" max="1440" value="<?php echo esc_attr( get_option( 'g2ab_min_booking_lead_minutes', 30 ) ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Max Advance (days)', 'g2a-booking' ); ?></th><td><input name="g2ab_max_booking_advance_days" type="number" min="1" max="365" value="<?php echo esc_attr( get_option( 'g2ab_max_booking_advance_days', 60 ) ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Allow Guests', 'g2a-booking' ); ?></th><td><label><input name="g2ab_allow_guest_booking" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'g2ab_allow_guest_booking', 1 ) ); ?> /> <?php esc_html_e( 'Customers can book without an account.', 'g2a-booking' ); ?></label></td></tr>
					</table>
				</div>
				<div class="g2ab-card"><h2><?php esc_html_e( 'Notifications', 'g2a-booking' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Admin Email', 'g2a-booking' ); ?></th><td><input name="g2ab_admin_notification_email" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'g2ab_admin_notification_email', get_option( 'admin_email' ) ) ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Send Confirmation', 'g2a-booking' ); ?></th><td><label><input name="g2ab_send_confirmation_email" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'g2ab_send_confirmation_email', 1 ) ); ?> /></label></td></tr>
						<tr><th><?php esc_html_e( 'Reminder Email', 'g2a-booking' ); ?></th><td><label><input name="g2ab_send_reminder_email" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'g2ab_send_reminder_email', 1 ) ); ?> /> <?php esc_html_e( 'Hours before:', 'g2a-booking' ); ?></label> <input name="g2ab_reminder_hours_before" type="number" min="1" max="168" value="<?php echo esc_attr( get_option( 'g2ab_reminder_hours_before', 24 ) ); ?>" style="width:80px;" /></td></tr>
					</table>
				</div>
				<div class="g2ab-card"><h2><?php esc_html_e( 'Payments', 'g2a-booking' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Default Gateway', 'g2a-booking' ); ?></th><td><select name="g2ab_payment_gateway_default"><?php $gw = get_option( 'g2ab_payment_gateway_default', 'pay_in_store' ); foreach ( array( 'pay_in_store' => 'Pay In Store', 'stripe' => 'Stripe' ) as $v => $l ) printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $v ), selected( $gw, $v, false ), esc_html( $l ) ); ?></select></td></tr>
						<tr><th><?php esc_html_e( 'Stripe', 'g2a-booking' ); ?></th><td><label><input name="g2ab_stripe_enabled" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'g2ab_stripe_enabled', 0 ) ); ?> /> <?php esc_html_e( 'Enable', 'g2a-booking' ); ?></label> &nbsp; <label><input name="g2ab_stripe_test_mode" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'g2ab_stripe_test_mode', 1 ) ); ?> /> <?php esc_html_e( 'Test mode', 'g2a-booking' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Publishable Key', 'g2a-booking' ); ?></th><td><input name="g2ab_stripe_publishable_key" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'g2ab_stripe_publishable_key', '' ) ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Secret Key', 'g2a-booking' ); ?></th><td><input name="g2ab_stripe_secret_key" type="password" class="regular-text" value="<?php echo esc_attr( get_option( 'g2ab_stripe_secret_key', '' ) ); ?>" autocomplete="new-password" /></td></tr>
						<tr><th><?php esc_html_e( 'Webhook Secret', 'g2a-booking' ); ?></th><td><input name="g2ab_stripe_webhook_secret" type="password" class="regular-text" value="<?php echo esc_attr( get_option( 'g2ab_stripe_webhook_secret', '' ) ); ?>" autocomplete="new-password" /></td></tr>
					</table>
				</div>
				<div class="g2ab-card"><h2><?php esc_html_e( 'Branding', 'g2a-booking' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Primary Color', 'g2a-booking' ); ?></th><td><input name="g2ab_brand_color_primary" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'g2ab_brand_color_primary', '#C9A84C' ) ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'Accent Color', 'g2a-booking' ); ?></th><td><input name="g2ab_brand_color_accent" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'g2ab_brand_color_accent', '#E8802F' ) ); ?>" /></td></tr>
					</table>
				</div>
				<div class="g2ab-card"><h2><?php esc_html_e( 'Danger Zone', 'g2a-booking' ); ?></h2>
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Remove Data on Uninstall', 'g2a-booking' ); ?></th><td><label><input name="g2ab_remove_data_on_uninstall" type="checkbox" value="1" <?php checked( 1, (int) get_option( 'g2ab_remove_data_on_uninstall', 0 ) ); ?> /> <?php esc_html_e( 'Drop tables and delete settings on plugin delete.', 'g2a-booking' ); ?></label></td></tr>
					</table>
				</div>
				<?php submit_button( __( 'Save Settings', 'g2a-booking' ) ); ?>
			</form>
			<div class="g2ab-card"><h2><?php esc_html_e( 'Build Status', 'g2a-booking' ); ?></h2>
				<p><?php esc_html_e( 'System info — useful when filing support tickets.', 'g2a-booking' ); ?></p>
				<p><strong>REST namespace:</strong> <code><?php echo esc_html( G2AB_REST_NAMESPACE ); ?></code> &nbsp;|&nbsp; <strong>DB version:</strong> <code><?php echo esc_html( get_option( 'g2ab_db_version', 'n/a' ) ); ?></code> &nbsp;|&nbsp; <strong>Plugin:</strong> <code><?php echo esc_html( G2AB_VERSION ); ?></code> &nbsp;|&nbsp; <strong>PHP:</strong> <code><?php echo esc_html( PHP_VERSION ); ?></code> &nbsp;|&nbsp; <strong>WP:</strong> <code><?php echo esc_html( get_bloginfo( 'version' ) ); ?></code></p>
			</div>
		</div>
		<?php
	}

	public function handle_settings_save() {
		if ( ! isset( $_POST['action'] ) || 'g2ab_save_settings' !== $_POST['action'] ) return;
		if ( class_exists( 'G2AB_Admin_Settings_Pro' ) ) return; // Settings Pro owns the save flow now.
		if ( ! current_user_can( 'manage_g2ab_settings' ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
		check_admin_referer( 'g2ab_save_settings', '_g2ab_settings_nonce' );
		$text_keys = array( 'g2ab_business_name', 'g2ab_business_phone', 'g2ab_business_address', 'g2ab_stripe_publishable_key', 'g2ab_stripe_secret_key', 'g2ab_stripe_webhook_secret', 'g2ab_brand_color_primary', 'g2ab_brand_color_accent' );
		foreach ( $text_keys as $k ) if ( isset( $_POST[ $k ] ) ) update_option( $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) );
		if ( isset( $_POST['g2ab_admin_notification_email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['g2ab_admin_notification_email'] ) );
			if ( $email && is_email( $email ) ) update_option( 'g2ab_admin_notification_email', $email );
		}
		$selects = array( 'g2ab_currency' => array( 'USD','CAD','EUR','GBP','AUD' ), 'g2ab_payment_gateway_default' => array( 'pay_in_store','stripe' ) );
		foreach ( $selects as $k => $allowed ) {
			if ( isset( $_POST[ $k ] ) ) {
				$v = sanitize_key( wp_unslash( $_POST[ $k ] ) );
				if ( in_array( $v, $allowed, true ) ) update_option( $k, $v );
			}
		}
		$ints = array( 'g2ab_reservation_hold_minutes' => array(1,120), 'g2ab_min_booking_lead_minutes' => array(0,1440), 'g2ab_max_booking_advance_days' => array(1,365), 'g2ab_reminder_hours_before' => array(1,168) );
		foreach ( $ints as $k => $r ) {
			if ( isset( $_POST[ $k ] ) ) {
				$v = absint( wp_unslash( $_POST[ $k ] ) );
				$v = max( $r[0], min( $r[1], $v ) );
				update_option( $k, $v );
			}
		}
		$bools = array( 'g2ab_allow_guest_booking', 'g2ab_send_confirmation_email', 'g2ab_send_reminder_email', 'g2ab_stripe_enabled', 'g2ab_stripe_test_mode', 'g2ab_remove_data_on_uninstall' );
		foreach ( $bools as $k ) update_option( $k, isset( $_POST[ $k ] ) ? 1 : 0 );
		do_action( 'g2ab_settings_saved' );
		wp_safe_redirect( add_query_arg( array( 'page' => self::SETTINGS_SLUG, 'settings-updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render_tools_page() {
		$this->require_cap( 'manage_options' );
		$installer = G2AB_Plugin::instance()->get_installer();
		$status = $installer->verify_install_detailed();
		$last = $installer->get_last_install_result();
		$notice = '';
		if ( isset( $_GET['g2ab_msg'] ) ) {
			$mt = sanitize_key( $_GET['g2ab_msg'] );
			$msgs = array(
				'install_ok' => array( 'success', __( 'Schema migrations re-ran successfully.', 'g2a-booking' ) ),
				'install_fail' => array( 'error', __( 'Some tables failed. Check status below.', 'g2a-booking' ) ),
				'reset_ok' => array( 'success', __( 'Data reset complete.', 'g2a-booking' ) ),
				'reseed_ok' => array( 'success', __( 'Default data reseeded.', 'g2a-booking' ) ),
				'table_ok' => array( 'success', __( 'Table reinstalled.', 'g2a-booking' ) ),
				'table_fail' => array( 'error', __( 'Could not reinstall that table.', 'g2a-booking' ) ),
				'confirm_fail' => array( 'error', __( 'Confirmation did not match.', 'g2a-booking' ) ),
			);
			if ( isset( $msgs[ $mt ] ) ) $notice = sprintf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[$mt][0] ), esc_html( $msgs[$mt][1] ) );
		}
		$all_healthy = true;
		foreach ( $status as $r ) if ( ! $r['healthy'] ) { $all_healthy = false; break; }
		?>
		<div class="wrap g2ab-wrap">
			<h1><?php esc_html_e( 'G2A Booking — Tools', 'g2a-booking' ); ?> <span class="g2ab-phase-tag"><?php esc_html_e( 'Database', 'g2a-booking' ); ?></span></h1>
			<?php echo $notice; ?>
			<?php if ( $all_healthy ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'All 8 tables present and healthy.', 'g2a-booking' ); ?></strong></p></div>
			<?php else : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Schema issue. Use Re-run Schema Migrations to repair.', 'g2a-booking' ); ?></strong></p></div>
			<?php endif; ?>
			<div class="g2ab-card"><h2><?php esc_html_e( 'Schema Status', 'g2a-booking' ); ?></h2>
				<p><strong><?php esc_html_e( 'DB version:', 'g2a-booking' ); ?></strong> <code><?php echo esc_html( get_option( 'g2ab_db_version', 'n/a' ) ); ?></code> | <strong><?php esc_html_e( 'Code version:', 'g2a-booking' ); ?></strong> <code><?php echo esc_html( G2AB_DB_VERSION ); ?></code></p>
				<table class="widefat striped"><thead><tr><th>Table</th><th>Full Name</th><th>Exists</th><th>Columns</th><th>Rows</th><th>Health</th><th>Action</th></tr></thead><tbody>
				<?php foreach ( $status as $name => $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $name ); ?></strong></td>
						<td><code><?php echo esc_html( $r['full_name'] ); ?></code></td>
						<td><?php echo $r['exists'] ? 'Yes' : 'No'; ?></td>
						<td><?php echo (int) $r['columns_actual']; ?> / <?php echo (int) $r['columns_expected']; ?></td>
						<td><?php echo (int) $r['row_count']; ?></td>
						<td><?php echo $r['healthy'] ? '<span style="color:#4CAF50;">Healthy</span>' : '<span style="color:#C62828;">Issue</span>'; ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
								<?php wp_nonce_field( 'g2ab_reinstall_table_' . $name, '_g2ab_nonce' ); ?>
								<input type="hidden" name="action" value="g2ab_reinstall_table" />
								<input type="hidden" name="table" value="<?php echo esc_attr( $name ); ?>" />
								<button type="submit" class="button button-small" onclick="return confirm('<?php echo esc_js( __( 'Drop and recreate this table?', 'g2a-booking' ) ); ?>');"><?php esc_html_e( 'Reinstall', 'g2a-booking' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<div class="g2ab-card"><h2><?php esc_html_e( 'Repair Actions', 'g2a-booking' ); ?></h2>
				<p><?php esc_html_e( 'Re-run schema migrations is non-destructive. Existing data preserved.', 'g2a-booking' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'g2ab_run_install', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_run_install" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Re-run Schema Migrations', 'g2a-booking' ); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline; margin-left:8px;">
					<?php wp_nonce_field( 'g2ab_reseed', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_reseed" />
					<button type="submit" class="button"><?php esc_html_e( 'Reseed Default Data', 'g2a-booking' ); ?></button>
				</form>
			</div>
			<?php if ( ! empty( $last['tables'] ) ) : ?>
			<div class="g2ab-card"><h2><?php esc_html_e( 'Last Install Output', 'g2a-booking' ); ?></h2>
				<p><strong><?php esc_html_e( 'Ran at:', 'g2a-booking' ); ?></strong> <?php echo esc_html( $last['timestamp'] ?? 'n/a' ); ?></p>
				<table class="widefat striped"><thead><tr><th>Table</th><th>Created</th><th>dbDelta Output</th><th>Errors</th></tr></thead><tbody>
				<?php foreach ( $last['tables'] as $name => $r ) : ?>
					<tr><td><strong><?php echo esc_html( $name ); ?></strong></td><td><?php echo ! empty( $r['created'] ) ? 'Yes' : 'No'; ?></td><td><pre style="white-space:pre-wrap;font-size:11px;margin:0;max-width:400px;"><?php echo esc_html( implode( "\n", (array) ( $r['dbdelta'] ?? array() ) ) ); ?></pre></td><td style="color:#C62828;"><?php echo esc_html( implode( '; ', (array) ( $r['errors'] ?? array() ) ) ); ?></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			</div>
			<?php endif; ?>
			<div class="g2ab-card" style="border-color:#C62828;">
				<h2 style="color:#C62828;"><?php esc_html_e( 'Danger Zone — Full Reset', 'g2a-booking' ); ?></h2>
				<p><?php esc_html_e( 'Drops all 8 tables, recreates schema, reseeds defaults. No undo. Type RESET.', 'g2a-booking' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Are you sure?', 'g2a-booking' ) ); ?>');">
					<?php wp_nonce_field( 'g2ab_reset_all', '_g2ab_nonce' ); ?>
					<input type="hidden" name="action" value="g2ab_reset_all" />
					<input type="text" name="confirm" placeholder="Type RESET" class="regular-text" required pattern="RESET" />
					<button type="submit" class="button" style="background:#C62828;color:#fff;border-color:#C62828;"><?php esc_html_e( 'Wipe & Reset', 'g2a-booking' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_tools_run_install() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_run_install', '_g2ab_nonce' );
		$result = G2AB_Plugin::instance()->get_installer()->force_install();
		$ok = true;
		foreach ( $result as $r ) if ( empty( $r['created'] ) ) { $ok = false; break; }
		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-tools', 'g2ab_msg' => $ok ? 'install_ok' : 'install_fail' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_tools_reinstall_table() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		$table = isset( $_POST['table'] ) ? sanitize_key( wp_unslash( $_POST['table'] ) ) : '';
		check_admin_referer( 'g2ab_reinstall_table_' . $table, '_g2ab_nonce' );
		$ok = G2AB_Plugin::instance()->get_installer()->reinstall_table( $table );
		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-tools', 'g2ab_msg' => $ok ? 'table_ok' : 'table_fail' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_tools_reset_all() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_reset_all', '_g2ab_nonce' );
		$confirm = isset( $_POST['confirm'] ) ? sanitize_text_field( wp_unslash( $_POST['confirm'] ) ) : '';
		if ( 'RESET' !== $confirm ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-tools', 'g2ab_msg' => 'confirm_fail' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		G2AB_Plugin::instance()->get_installer()->reset_all_data();
		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-tools', 'g2ab_msg' => 'reset_ok' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_tools_reseed() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No permission.' );
		check_admin_referer( 'g2ab_reseed', '_g2ab_nonce' );
		if ( class_exists( 'G2AB_Activator' ) ) G2AB_Activator::seed_initial_data();
		wp_safe_redirect( add_query_arg( array( 'page' => 'g2ab-tools', 'g2ab_msg' => 'reseed_ok' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private function render_placeholder( $title, $description, $phase = '' ) {
		// G2A tactical empty state — replaces WP yellow notice. $phase kept for back-compat.
		echo '<div class="wrap g2ab-empty"><div class="g2ab-empty__inner">';
		echo '<div class="g2ab-empty__eyebrow">G2A BOOKING ENGINE</div>';
		echo '<h1 class="g2ab-empty__title">' . esc_html( $title ) . '</h1>';
		echo '<p class="g2ab-empty__sub">' . esc_html( $description ) . '</p>';
		echo '</div></div>';
		echo '<style>
			.g2ab-empty{margin:20px 20px 40px 0;}
			.g2ab-empty__inner{background:linear-gradient(135deg,#1A191E 0%,#1F242C 60%,#2A3038 100%);color:#fff;padding:48px 40px;border-radius:14px;border:1px solid rgba(255,255,255,.06);position:relative;overflow:hidden;}
			.g2ab-empty__inner::before{content:"";position:absolute;top:-50%;right:-10%;width:50%;height:200%;background:radial-gradient(ellipse at center,rgba(74,93,58,.18) 0%,transparent 60%);pointer-events:none;}
			.g2ab-empty__eyebrow{position:relative;z-index:1;display:inline-block;font-size:11px;letter-spacing:.18em;font-weight:700;color:#E8802F;margin-bottom:10px;}
			.g2ab-empty__title{position:relative;z-index:1;color:#fff !important;font-size:30px;line-height:1.15;margin:0 0 8px;font-weight:800;letter-spacing:-.01em;font-family:"Inter","Segoe UI",system-ui,-apple-system,sans-serif;padding:0;}
			.g2ab-empty__sub{position:relative;z-index:1;font-size:14px;color:rgba(255,255,255,.65);margin:0;max-width:540px;line-height:1.55;}
		</style>';
	}

	private function require_cap( $cap ) {
		if ( ! current_user_can( $cap ) ) wp_die( esc_html__( 'No permission.', 'g2a-booking' ) );
	}
}
