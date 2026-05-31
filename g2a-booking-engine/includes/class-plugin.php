<?php

/**
 * Main plugin class — singleton orchestrator.
 *
 * @package G2AB
 */
if (! defined('ABSPATH')) exit;

final class G2AB_Plugin
{

	private static $instance = null;
	private $installer = null;

	public static function instance()
	{
		if (null === self::$instance) self::$instance = new self();
		return self::$instance;
	}

	private function __construct()
	{
		add_action('init', array($this, 'load_textdomain'), 1);
		add_action('init', array($this, 'maybe_upgrade'), 5);
		add_action('init', array($this, 'init_components'), 10);
		add_action('init', array($this, 'ensure_cron_scheduled'), 15);
		add_action('rest_api_init', array($this, 'register_rest_routes'));
		add_action('admin_init', array($this, 'maybe_redirect_after_activation'));
		add_filter('plugin_action_links_' . G2AB_BASENAME, array($this, 'plugin_action_links'));
		add_filter('cron_schedules', array($this, 'register_cron_intervals'));

		add_action('g2ab_cleanup_expired_reservations', array($this, 'run_booking_expiry_cron'));
	}

	public function __clone()
	{
		_doing_it_wrong(__FUNCTION__, 'Cloning is forbidden.', '1.0.0');
	}
	public function __wakeup()
	{
		_doing_it_wrong(__FUNCTION__, 'Unserializing is forbidden.', '1.0.0');
	}

	public function register_cron_intervals($schedules)
	{
		if (! isset($schedules['every_five_minutes'])) {
			$schedules['every_five_minutes'] = array('interval' => 5 * MINUTE_IN_SECONDS, 'display' => __('Every 5 Minutes (G2A Booking)', 'g2a-booking'));
		}
		return $schedules;
	}

	public function load_textdomain()
	{
		load_plugin_textdomain('g2a-booking', false, dirname(G2AB_BASENAME) . '/languages');
	}

	public function maybe_upgrade()
	{
		$current = get_option('g2ab_db_version', '0.0.0');
		// Forward upgrade: run the normal installer + migrations.
		if (version_compare($current, G2AB_DB_VERSION, '<')) {
			$this->get_installer()->install();
			update_option('g2ab_db_version', G2AB_DB_VERSION);
			do_action('g2ab_after_db_upgrade', $current, G2AB_DB_VERSION);
			return;
		}
		// Reconciliation: when a previous staging deploy bumped the option
		// past the code's version, normalize it down so the Build Status
		// panel shows what's actually shipping. The column-existence
		// migration (slot_key) still runs every install regardless, so
		// no schema work is skipped.
		if (version_compare($current, G2AB_DB_VERSION, '>')) {
			update_option('g2ab_db_version', G2AB_DB_VERSION);
			$this->get_installer()->install(); // ensure slot_key / engines / etc.
			do_action('g2ab_after_db_normalize', $current, G2AB_DB_VERSION);
		}
	}

	public function get_installer()
	{
		if (null === $this->installer) $this->installer = new G2AB_Installer();
		return $this->installer;
	}

	public function init_components()
	{
		$safe_mode = (int) get_option('g2ab_safe_mode', 0);

		try {
			do_action('g2ab_before_init');

			if (class_exists('G2AB_Addon_Manager')) G2AB_Addon_Manager::instance();
			if (class_exists('G2AB_Module_Loader')) G2AB_Module_Loader::instance();

			// Events — custom post type + admin tab + banner-view shortcodes.
			// Registered in admin and on the front end (the CPT must exist in both).
			if (class_exists('G2AB_Events')) G2AB_Events::instance();

			if (is_admin() && class_exists('G2AB_Admin')) {
				G2AB_Admin::instance();
				if (class_exists('G2AB_Admin_Dashboard'))          G2AB_Admin_Dashboard::instance();
				if (class_exists('G2AB_Admin_Bookings_List'))      G2AB_Admin_Bookings_List::instance();
				if (class_exists('G2AB_Admin_Resources_Crud'))     G2AB_Admin_Resources_Crud::instance();
				if (class_exists('G2AB_Admin_Booking_Types_Crud')) G2AB_Admin_Booking_Types_Crud::instance();
				if (class_exists('G2AB_Admin_Forms_List'))         G2AB_Admin_Forms_List::instance();
				if (class_exists('G2AB_Admin_Availability_Crud'))  G2AB_Admin_Availability_Crud::instance();
				if (class_exists('G2AB_Admin_Payments_List'))      G2AB_Admin_Payments_List::instance();
				if (class_exists('G2AB_Admin_Reports'))            G2AB_Admin_Reports::instance();
				if (class_exists('G2AB_Admin_Settings_Pro'))       G2AB_Admin_Settings_Pro::instance();
				if (class_exists('G2AB_Admin_Manual_Booking'))     G2AB_Admin_Manual_Booking::instance();
				if (class_exists('G2AB_Admin_Calendar'))           G2AB_Admin_Calendar::instance();
				if (class_exists('G2AB_Admin_Shortcodes'))         G2AB_Admin_Shortcodes::instance();
				// Front Desk admin page is intentionally not loaded — the
				// Memberistic Staff Dashboard (/staff/) is the front desk for
				// Guns 2 Ammo, so this duplicate screen is left disabled.
				// if (class_exists('G2AB_Admin_Frontdesk'))       G2AB_Admin_Frontdesk::instance();
			}

			if (! is_admin() && class_exists('G2AB_Frontend')) {
				G2AB_Frontend::instance();
				if (class_exists('G2AB_Frontend_Shortcode_Events'))     G2AB_Frontend_Shortcode_Events::instance();
				if (class_exists('G2AB_Frontend_Shortcode_Banner'))     G2AB_Frontend_Shortcode_Banner::instance();
				if (class_exists('G2AB_Frontend_Shortcode_Reschedule')) G2AB_Frontend_Shortcode_Reschedule::instance();
				if (class_exists('G2AB_Frontend_Shortcode_Frontdesk'))  G2AB_Frontend_Shortcode_Frontdesk::instance();
				if (class_exists('G2AB_Frontend_Shortcode_Staff_Console')) G2AB_Frontend_Shortcode_Staff_Console::instance();
				if (class_exists('G2AB_Frontend_Shortcode_Member_Checkin')) G2AB_Frontend_Shortcode_Member_Checkin::instance();
			}

			if (class_exists('G2AB_Gateway_Manager')) G2AB_Gateway_Manager::instance();
			if (class_exists('G2AB_Seo')) G2AB_Seo::instance();

			add_action('g2ab_payment_succeeded', array($this, 'mark_booking_customer_role'), 10, 1);
			add_action('g2ab_booking_status_changed', array($this, 'maybe_mark_booking_customer_role_from_status'), 10, 2);

			if (! $safe_mode) {
				if (defined('PMPRO_VERSION') && class_exists('G2AB_Integration_PMPro')) G2AB_Integration_PMPro::instance();
				if (class_exists('WooCommerce') && class_exists('G2AB_Integration_WooCommerce')) G2AB_Integration_WooCommerce::instance();
			}

			do_action('g2ab_after_init', $this);
			$this->maybe_sync_client_defaults_runtime();

			if ($safe_mode) update_option('g2ab_safe_mode', 0);
		} catch (\Throwable $e) {
			error_log(sprintf('[G2AB] init_components fatal: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
			update_option('g2ab_safe_mode', 1);
			update_option('g2ab_last_fatal', array(
				'message' => $e->getMessage(),
				'file'    => $e->getFile(),
				'line'    => $e->getLine(),
				'time'    => current_time('mysql'),
			));
		}
	}

	private function maybe_sync_client_defaults_runtime() {
		$last_sync = (int) get_option( 'g2ab_client_defaults_last_sync', 0 );
		$now       = time();
		if ( $last_sync > 0 && ( $now - $last_sync ) < DAY_IN_SECONDS ) {
			return;
		}
		if ( class_exists( 'G2AB_Activator' ) && is_callable( array( 'G2AB_Activator', 'sync_client_defaults' ) ) ) {
			G2AB_Activator::sync_client_defaults();
			update_option( 'g2ab_client_defaults_last_sync', $now );
		}
	}

	public function register_rest_routes()
	{
		// Only controllers that actually ship today. Availability / resources /
		// payment-methods are sub-routes of the bookings controller; the broken
		// stand-alone references for those have been removed in v1.2.4.
		$controllers = array(
			'G2AB_REST_Bookings_Controller',
			'G2AB_REST_Forms_Controller',
			'G2AB_REST_Webhooks_Controller',
			'G2AB_REST_Admin_Bookings_Controller',
			'G2AB_REST_Calendar_Controller',
			'G2AB_REST_Frontdesk_Controller',
			'G2AB_REST_Staff_Controller',
			'G2AB_REST_Checkin_Controller',
		);
		foreach ($controllers as $c) {
			if (class_exists($c)) {
				(new $c())->register_routes();
			}
		}
		do_action('g2ab_register_rest_routes');
	}

	/**
	 * Make sure the booking-expiry cron is on the 5-minute interval. Re-runs
	 * the schedule defensively in case an old hourly schedule survived from
	 * a pre-1.2.4 install.
	 *
	 * @since 1.2.4
	 */
	public function ensure_cron_scheduled()
	{
		$hook = 'g2ab_cleanup_expired_reservations';
		$next = wp_next_scheduled($hook);
		$existing = wp_get_schedule($hook);
		if ($next && 'every_five_minutes' !== $existing) {
			wp_unschedule_event($next, $hook);
			$next = false;
		}
		if (! $next) {
			wp_schedule_event(time() + MINUTE_IN_SECONDS, 'every_five_minutes', $hook);
		}
	}

	/**
	 * Cron callback — expire stale unpaid bookings.
	 *
	 * Anything still `pending` or `reserved` on a paid payment_mode after the
	 * configured hold window gets flipped to `expired` and its slot freed.
	 * `in_store` and `free` modes are intentionally excluded — those don't
	 * pay online, so there's nothing to time out on.
	 *
	 * @since 1.2.4
	 */
	public function run_booking_expiry_cron()
	{
		if (! class_exists('G2AB_Booking_Expiry_Cron')) {
			return;
		}
		(new G2AB_Booking_Expiry_Cron())->run();
	}

	public function maybe_redirect_after_activation()
	{
		if (! get_transient('g2ab_activation_redirect')) return;
		delete_transient('g2ab_activation_redirect');
		if (isset($_GET['activate-multi']) || wp_doing_ajax()) return;
		if (! current_user_can('manage_options')) return;
		wp_safe_redirect(admin_url('admin.php?page=g2ab-bookings&welcome=1'));
		exit;
	}

	public function plugin_action_links($links)
	{
		$custom = array(
			'settings' => sprintf('<a href="%s">%s</a>', esc_url(admin_url('admin.php?page=g2ab-settings')), esc_html__('Settings', 'g2a-booking')),
			'docs'     => sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url('https://wordpressistic.com/docs/g2a-booking-engine'), esc_html__('Docs', 'g2a-booking')),
		);
		return array_merge($custom, $links);
	}

	public function maybe_mark_booking_customer_role_from_status($booking_id, $new_status)
	{
		if (! in_array((string) $new_status, array('paid', 'confirmed', 'completed'), true)) {
			return;
		}
		$this->mark_booking_customer_role($booking_id);
	}

	public function mark_booking_customer_role($booking_id)
	{
		$booking_id = absint($booking_id);
		if (! $booking_id) {
			return;
		}

		global $wpdb;
		$booking = $wpdb->get_row(
			$wpdb->prepare("SELECT user_id, metadata FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1", $booking_id)
		);
		if (! $booking || empty($booking->user_id)) {
			return;
		}

		$user = get_user_by('id', (int) $booking->user_id);
		if (! ($user instanceof WP_User)) {
			return;
		}

		$this->ensure_booking_user_role();
		if (! in_array('g2ab_booking_user', (array) $user->roles, true)) {
			$user->add_role('g2ab_booking_user');
		}

		$this->maybe_assign_membership_roles_to_booking_user($user, $booking->metadata);
	}

	private function ensure_booking_user_role()
	{
		if (! get_role('g2ab_booking_user')) {
			add_role('g2ab_booking_user', __('Range Member', 'g2a-booking'), array('read' => true));
		}
	}

	private function maybe_assign_membership_roles_to_booking_user(WP_User $user, $metadata)
	{
		$meta = json_decode((string) $metadata, true);
		if (! is_array($meta)) {
			return;
		}

		$membership = isset($meta['membership']) && is_array($meta['membership']) ? $meta['membership'] : array();
		$level_id = absint($membership['level_id'] ?? 0);
		$source = sanitize_key($membership['source'] ?? '');
		if (! $source && ! $level_id) {
			return;
		}

		if (! get_role('memberistic_member')) {
			add_role('memberistic_member', __('Membership User', 'memberistic'), array('read' => true));
		}
		if (! in_array('memberistic_member', (array) $user->roles, true)) {
			$user->add_role('memberistic_member');
		}

		if ($level_id > 0) {
			$plan_role = $this->memberistic_plan_role_key($level_id);
			if (! get_role($plan_role)) {
				add_role($plan_role, $this->memberistic_plan_role_label($level_id), array('read' => true));
			}
			if (! in_array($plan_role, (array) $user->roles, true)) {
				$user->add_role($plan_role);
			}
			update_user_meta($user->ID, 'memberistic_active_plan_id', $level_id);
			update_user_meta($user->ID, 'memberistic_active_plan_name', $this->memberistic_plan_role_label($level_id));
		}
	}

	private function memberistic_plan_role_key($plan_id)
	{
		$slug = 'plan_' . absint($plan_id);
		if (class_exists('\WordPressistic\Memberistic\Database\Plans_Repository')) {
			$plan = \WordPressistic\Memberistic\Database\Plans_Repository::get(absint($plan_id));
			if (is_array($plan) && ! empty($plan['slug'])) {
				$slug = sanitize_key($plan['slug']);
			}
		}
		return 'memberistic_plan_' . $slug;
	}

	private function memberistic_plan_role_label($plan_id)
	{
		$plan_name = sprintf(__('Plan %s', 'memberistic'), absint($plan_id));
		if (class_exists('\WordPressistic\Memberistic\Database\Plans_Repository')) {
			$plan = \WordPressistic\Memberistic\Database\Plans_Repository::get(absint($plan_id));
			if (is_array($plan) && ! empty($plan['name'])) {
				$plan_name = sanitize_text_field($plan['name']);
			}
		}
		return sprintf(__('Membership User - %s', 'memberistic'), $plan_name);
	}
}
