<?php
/**
 * Settings page.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Admin;

use function WordPressistic\Memberistic\memberistic_current_user_can;
use function WordPressistic\Memberistic\memberistic_sanitize_hex_color;
use function WordPressistic\Memberistic\memberistic_sanitize_text;
use function WordPressistic\Memberistic\memberistic_sanitize_textarea;
use function WordPressistic\Memberistic\memberistic_sanitize_yes_no;
use function WordPressistic\Memberistic\memberistic_admin_url;
use function WordPressistic\Memberistic\memberistic_verify_admin_nonce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings_Page {
	/**
	 * Register settings API config.
	 */
	public static function register_settings() {
		register_setting(
			'memberistic_settings_group',
			'memberistic_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize_settings' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Handle settings actions.
	 */
	public static function handle_actions() {
		if ( empty( $_GET['page'] ) || 'memberistic-settings' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$action = empty( $_GET['memberistic_action'] ) ? '' : sanitize_key( wp_unslash( $_GET['memberistic_action'] ) );

		if ( ! in_array( $action, array( 'create_pages', 'remap_pages' ), true ) ) {
			return;
		}

		if ( ! memberistic_current_user_can( 'manage_memberistic_settings' ) || ! memberistic_verify_admin_nonce( 'memberistic_create_pages' ) ) {
			wp_safe_redirect( memberistic_admin_url( 'memberistic-settings', array( 'memberistic_notice' => 'invalid_request', 'memberistic_notice_type' => 'error' ) ) );
			exit;
		}

		self::create_required_pages( 'remap_pages' === $action );
		wp_safe_redirect( memberistic_admin_url( 'memberistic-settings', array( 'memberistic_notice' => 'remap_pages' === $action ? 'pages_remapped' : 'pages_created' ) ) );
		exit;
	}

	/**
	 * Sanitize settings.
	 *
	 * @param mixed $settings Raw settings.
	 * @return array<string, string>
	 */
	public static function sanitize_settings( $settings ) {
		$settings = is_array( $settings ) ? $settings : array();

		// Only surface the admin notice when we're actually in an admin screen
		// (the REST controller also uses this sanitizer and shouldn't pollute
		// the admin notices transient).
		if ( function_exists( 'add_settings_error' ) && is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			add_settings_error( 'memberistic_settings', 'settings_saved', __( 'Settings saved successfully.', 'memberistic' ), 'success' );
		}

		return array(
			'brand_label'              => isset( $settings['brand_label'] ) ? memberistic_sanitize_text( $settings['brand_label'] ) : 'Memberistic',
			'business_name'            => isset( $settings['business_name'] ) ? memberistic_sanitize_text( $settings['business_name'] ) : '',
			'business_phone'           => isset( $settings['business_phone'] ) ? memberistic_sanitize_text( $settings['business_phone'] ) : '',
			'business_address'         => isset( $settings['business_address'] ) ? memberistic_sanitize_textarea( $settings['business_address'] ) : '',
			'primary_brand_color'      => isset( $settings['primary_brand_color'] ) ? memberistic_sanitize_hex_color( $settings['primary_brand_color'] ) : '#0F2044',
			'enable_debug_logging'     => isset( $settings['enable_debug_logging'] ) ? memberistic_sanitize_yes_no( $settings['enable_debug_logging'] ) : 'no',
			'delete_data_on_uninstall' => isset( $settings['delete_data_on_uninstall'] ) ? memberistic_sanitize_yes_no( $settings['delete_data_on_uninstall'] ) : 'no',
			'currency'                 => isset( $settings['currency'] ) ? strtoupper( memberistic_sanitize_text( $settings['currency'] ) ) : 'USD',
			'stripe_enabled'           => isset( $settings['stripe_enabled'] ) ? memberistic_sanitize_yes_no( $settings['stripe_enabled'] ) : 'no',
			'stripe_mode'              => isset( $settings['stripe_mode'] ) && 'live' === $settings['stripe_mode'] ? 'live' : 'test',
			'stripe_test_publishable_key' => isset( $settings['stripe_test_publishable_key'] ) ? memberistic_sanitize_text( $settings['stripe_test_publishable_key'] ) : '',
			'stripe_test_secret_key'      => isset( $settings['stripe_test_secret_key'] ) ? memberistic_sanitize_text( $settings['stripe_test_secret_key'] ) : '',
			'stripe_live_publishable_key' => isset( $settings['stripe_live_publishable_key'] ) ? memberistic_sanitize_text( $settings['stripe_live_publishable_key'] ) : '',
			'stripe_live_secret_key'      => isset( $settings['stripe_live_secret_key'] ) ? memberistic_sanitize_text( $settings['stripe_live_secret_key'] ) : '',
			'stripe_webhook_secret'       => isset( $settings['stripe_webhook_secret'] ) ? memberistic_sanitize_text( $settings['stripe_webhook_secret'] ) : '',
			'woocommerce_enabled'         => isset( $settings['woocommerce_enabled'] ) ? memberistic_sanitize_yes_no( $settings['woocommerce_enabled'] ) : 'no',
			'woocommerce_webhook_secret'  => isset( $settings['woocommerce_webhook_secret'] ) ? memberistic_sanitize_text( $settings['woocommerce_webhook_secret'] ) : '',
			'email_from_name'             => isset( $settings['email_from_name'] ) ? memberistic_sanitize_text( $settings['email_from_name'] ) : 'Memberistic',
			'email_from_address'          => isset( $settings['email_from_address'] ) ? sanitize_email( $settings['email_from_address'] ) : get_option( 'admin_email' ),
			'logo_url'                    => isset( $settings['logo_url'] ) ? esc_url_raw( (string) $settings['logo_url'] ) : '',
			'accent_brand_color'          => isset( $settings['accent_brand_color'] ) ? memberistic_sanitize_hex_color( $settings['accent_brand_color'], '#1F3A8A' ) : '#1F3A8A',
			'timezone'                    => isset( $settings['timezone'] ) ? memberistic_sanitize_text( $settings['timezone'] ) : (string) get_option( 'timezone_string', 'UTC' ),
			'plans_page_id'            => isset( $settings['plans_page_id'] ) ? absint( $settings['plans_page_id'] ) : 0,
			'checkout_page_id'         => isset( $settings['checkout_page_id'] ) ? absint( $settings['checkout_page_id'] ) : 0,
			'account_page_id'          => isset( $settings['account_page_id'] ) ? absint( $settings['account_page_id'] ) : 0,
			'renewal_page_id'          => isset( $settings['renewal_page_id'] ) ? absint( $settings['renewal_page_id'] ) : 0,
			'login_page_id'            => isset( $settings['login_page_id'] ) ? absint( $settings['login_page_id'] ) : 0,
			'thank_you_page_id'        => isset( $settings['thank_you_page_id'] ) ? absint( $settings['thank_you_page_id'] ) : 0,
			'failed_payment_page_id'   => isset( $settings['failed_payment_page_id'] ) ? absint( $settings['failed_payment_page_id'] ) : 0,
			'staff_dashboard_page_id'  => isset( $settings['staff_dashboard_page_id'] ) ? absint( $settings['staff_dashboard_page_id'] ) : 0,
		);
	}

	/**
	 * Render the React settings console.
	 */
	public static function render() {
		if ( ! memberistic_current_user_can( 'manage_memberistic_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'memberistic' ) );
		}
		?>
		<div class="wrap memberistic-wrap">
			<div id="memberistic-settings-app" class="memberistic-react-root">
				<div class="memberistic-react-loading">
					<p><?php esc_html_e( 'Loading settings…', 'memberistic' ); ?></p>
					<noscript>
						<p><?php esc_html_e( 'The Memberistic settings console requires JavaScript.', 'memberistic' ); ?></p>
					</noscript>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Create missing frontend pages and save page IDs.
	 *
	 * Called both from the legacy admin GET-action handler and the new
	 * Settings REST controller.
	 */
	public static function create_required_pages( $force_remap = false ) {
		$settings = get_option( 'memberistic_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$pages = self::get_required_pages();

		foreach ( $pages as $key => $page ) {
			$current_id = ! empty( $settings[ $key ] ) ? absint( $settings[ $key ] ) : 0;

			if ( ! $force_remap && $current_id && 'trash' !== get_post_status( $current_id ) ) {
				continue;
			}

			$existing = get_page_by_path( $page['slug'] );

			if ( $existing && 'trash' !== get_post_status( $existing ) ) {
				$settings[ $key ] = (int) $existing->ID;
				continue;
			}

			$page_id = wp_insert_post(
				array(
					'post_title'   => $page['title'],
					'post_name'    => $page['slug'],
					'post_content' => $page['content'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( ! is_wp_error( $page_id ) ) {
				$settings[ $key ] = (int) $page_id;
			}
		}

		update_option( 'memberistic_settings', $settings, false );
	}

	/**
	 * Get branded frontend page defaults.
	 *
	 * @return array<string, array<string, string>>
	 */
	private static function get_required_pages() {
		$pages = array(
			'plans_page_id'          => array( 'title' => 'Memberistic Memberships', 'slug' => 'memberistic-memberships', 'content' => '[memberistic_plans]' ),
			'checkout_page_id'       => array( 'title' => 'Memberistic Checkout', 'slug' => 'memberistic-checkout', 'content' => '[memberistic_checkout]' ),
			'account_page_id'        => array( 'title' => 'Memberistic Account', 'slug' => 'memberistic-account', 'content' => '[memberistic_account]' ),
			'renewal_page_id'        => array( 'title' => 'Memberistic Renewal', 'slug' => 'memberistic-renewal', 'content' => '[memberistic_renewal]' ),
			'login_page_id'          => array( 'title' => 'Memberistic Login', 'slug' => 'memberistic-login', 'content' => '[memberistic_login]' ),
			'thank_you_page_id'      => array( 'title' => 'Memberistic Thank You', 'slug' => 'memberistic-thank-you', 'content' => '[memberistic_thank_you]' ),
			'failed_payment_page_id' => array( 'title' => 'Memberistic Payment Failed', 'slug' => 'memberistic-payment-failed', 'content' => '[memberistic_payment_failed]' ),
			'staff_dashboard_page_id'=> array( 'title' => 'Memberistic Staff Dashboard', 'slug' => 'memberistic-staff-dashboard', 'content' => '[memberistic_staff_dashboard]' ),
		);

		return apply_filters( 'memberistic_required_pages', $pages );
	}
}
