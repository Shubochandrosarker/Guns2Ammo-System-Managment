<?php
/**
 * Settings registry — defines every option group / key the plugin uses.
 *
 * The Admin class renders the actual settings UI; this class is the source
 * of truth for option keys, defaults, sanitization, and AJAX save.
 *
 * Option groups:
 *   wpistic_ffl_settings           — legacy general settings (kept for back-compat)
 *   wpistic_ffl_portal_settings    — dealer portal behaviour
 *   wpistic_ffl_theme_settings     — theming / white-label
 *   wpistic_ffl_email_settings     — confirmation + reminder email content
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Settings {

	public function __construct() {
		add_action( 'admin_init',                                   [ $this, 'register' ] );
		add_action( 'wp_ajax_wpistic_ffl_save_portal_settings',       [ $this, 'ajax_save_portal_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_save_theme_settings',        [ $this, 'ajax_save_theme_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_save_email_settings',        [ $this, 'ajax_save_email_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_save_integrations_settings', [ $this, 'ajax_save_integrations_settings' ] );
		add_action( 'wp_ajax_wpistic_ffl_send_test_email',            [ $this, 'ajax_send_test_email' ] );
	}

	public function register(): void {
		register_setting( 'wpistic_ffl_portal', 'wpistic_ffl_portal_settings', [ 'sanitize_callback' => [ $this, 'sanitize_portal' ] ] );
		register_setting( 'wpistic_ffl_theme',  'wpistic_ffl_theme_settings',  [ 'sanitize_callback' => [ $this, 'sanitize_theme' ] ] );
		register_setting( 'wpistic_ffl_email',  'wpistic_ffl_email_settings',  [ 'sanitize_callback' => [ $this, 'sanitize_email' ] ] );
	}

	// ── Sanitizers ────────────────────────────────────────────────────────────

	public function sanitize_portal( array $in ): array {
		$valid_2fa = [ 'none', 'last4_ffl', 'email_otp' ];

		return [
			'enabled'                  => empty( $in['enabled'] ) ? 0 : 1,
			'token_expiry_days'        => max( 1, min( 365, (int) ( $in['token_expiry_days'] ?? 30 ) ) ),
			'two_factor_method'        => in_array( $in['two_factor_method'] ?? '', $valid_2fa, true ) ? $in['two_factor_method'] : 'last4_ffl',
			'portal_slug'              => self::sanitize_slug( $in['portal_slug'] ?? WPISTIC_FFL_PORTAL_SLUG ),
			'single_use'               => empty( $in['single_use'] ) ? 0 : 1,
			'rate_limit_per_min'       => max( 1, min( 60, (int) ( $in['rate_limit_per_min'] ?? 5 ) ) ),
			'allow_replay_view'        => empty( $in['allow_replay_view'] ) ? 0 : 1,
			'auto_send_on_ship'        => empty( $in['auto_send_on_ship'] ) ? 0 : 1,
			'notify_dealer_on_order'   => empty( $in['notify_dealer_on_order'] ) ? 0 : 1,
			'reminder_days'            => sanitize_text_field( $in['reminder_days'] ?? '3,7,14' ),
			'track_email_opens'        => empty( $in['track_email_opens'] ) ? 0 : 1,
			'analytics_retention_days' => max( 7, min( 3650, (int) ( $in['analytics_retention_days'] ?? 365 ) ) ),
			'require_https'            => empty( $in['require_https'] ) ? 0 : 1,
		];
	}

	public function sanitize_theme( array $in ): array {
		$out = [];

		$colors = [ 'color_primary', 'color_primary_hover', 'color_success', 'color_danger', 'color_warning', 'color_bg', 'color_surface', 'color_text', 'color_text_muted', 'color_border' ];
		foreach ( $colors as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = self::sanitize_color( (string) $in[ $k ] );
			}
		}

		$text_fields = [ 'business_name', 'support_email', 'support_phone', 'welcome_heading', 'welcome_subtext', 'btn_received_label', 'btn_issue_label', 'btn_not_mine_label', 'footer_text', 'font_family', 'preset', 'card_style', 'button_style', 'header_style', 'heading_weight' ];
		foreach ( $text_fields as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}

		if ( isset( $in['logo_url'] ) )    $out['logo_url']    = esc_url_raw( $in['logo_url'] );
		if ( isset( $in['favicon_url'] ) ) $out['favicon_url'] = esc_url_raw( $in['favicon_url'] );
		if ( isset( $in['privacy_url'] ) ) $out['privacy_url'] = esc_url_raw( $in['privacy_url'] );
		if ( isset( $in['terms_url'] ) )   $out['terms_url']   = esc_url_raw( $in['terms_url'] );

		if ( isset( $in['body_size'] ) )   $out['body_size']   = max( 12, min( 24, (int) $in['body_size'] ) );
		if ( isset( $in['max_width'] ) )   $out['max_width']   = max( 320, min( 1200, (int) $in['max_width'] ) );

		// Custom CSS — Pro only; quietly ignored on free
		if ( isset( $in['custom_css'] ) && License::can( 'custom_css' ) ) {
			$out['custom_css'] = wp_strip_all_tags( $in['custom_css'] );
		}

		// Branding toggle — Pro only
		if ( License::can( 'remove_branding' ) ) {
			$out['show_branding'] = empty( $in['show_branding'] ) ? 0 : 1;
		} else {
			$out['show_branding'] = 1;
		}

		return $out;
	}

	public function sanitize_email( array $in ): array {
		return [
			'from_name'                  => sanitize_text_field( $in['from_name'] ?? get_bloginfo( 'name' ) ),
			'from_email'                 => sanitize_email( $in['from_email'] ?? get_option( 'admin_email' ) ),
			'reply_to'                   => sanitize_email( $in['reply_to'] ?? '' ),
			'confirmation_subject'       => sanitize_text_field( $in['confirmation_subject'] ?? '' ),
			'confirmation_intro'         => wp_kses_post( $in['confirmation_intro'] ?? '' ),
			'reminder_subject'           => sanitize_text_field( $in['reminder_subject'] ?? '' ),
			'reminder_intro'             => wp_kses_post( $in['reminder_intro'] ?? '' ),
			'admin_notify_on_confirm'    => empty( $in['admin_notify_on_confirm'] ) ? 0 : 1,
			'admin_notify_on_issue'      => empty( $in['admin_notify_on_issue'] ) ? 0 : 1,
		];
	}

	// ── AJAX handlers ─────────────────────────────────────────────────────────

	public function ajax_save_portal_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		$raw   = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : [];
		$clean = $this->sanitize_portal( $raw );
		update_option( 'wpistic_ffl_portal_settings', $clean );

		// Slug change → flush rewrites
		flush_rewrite_rules();

		wp_send_json_success( [ 'message' => __( 'Portal settings saved.', 'advanced-ffl-checkout' ), 'settings' => $clean ] );
	}

	public function ajax_save_theme_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		$raw   = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : [];
		$clean = $this->sanitize_theme( $raw );
		// Merge with existing so partial saves work
		$existing = get_option( 'wpistic_ffl_theme_settings', [] );
		update_option( 'wpistic_ffl_theme_settings', array_merge( is_array( $existing ) ? $existing : [], $clean ) );
		wp_send_json_success( [ 'message' => __( 'Theme settings saved.', 'advanced-ffl-checkout' ) ] );
	}

	public function ajax_save_email_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		$raw   = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : [];
		$clean = $this->sanitize_email( $raw );
		update_option( 'wpistic_ffl_email_settings', $clean );
		wp_send_json_success( [ 'message' => __( 'Email settings saved.', 'advanced-ffl-checkout' ) ] );
	}

	public function ajax_save_integrations_settings(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}
		$raw = isset( $_POST['settings'] ) ? (array) wp_unslash( $_POST['settings'] ) : [];
		$clean = [
			'chatbotistic_api_key' => sanitize_text_field( $raw['chatbotistic_api_key'] ?? '' ),
			'otter_api_key'        => sanitize_text_field( $raw['otter_api_key'] ?? '' ),
		];
		Integrations::set_settings( $clean );
		wp_send_json_success( [ 'message' => __( 'Integration credentials saved.', 'advanced-ffl-checkout' ) ] );
	}

	public function ajax_send_test_email(): void {
		check_ajax_referer( 'wpistic_ffl_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
		}

		$email_settings = get_option( 'wpistic_ffl_email_settings', [] );
		$to             = sanitize_email( $email_settings['from_email'] ?? get_option( 'admin_email' ) );
		$subject        = '[Advanced FFL] Test email — ' . date( 'Y-m-d H:i:s' );
		$body           = '<p style="font-family:system-ui;">This is a test email from <strong>Advanced FFL Checkout v' . WPISTIC_FFL_VERSION . '</strong>.</p>'
		                . '<p>If you received this, your SMTP plugin (Elementor Site Mailer / WP Mail SMTP / Fluent SMTP / etc.) is correctly intercepting <code>wp_mail()</code>.</p>'
		                . '<p>Sender address detected: <code>' . esc_html( $email_settings['from_email'] ?? get_option( 'admin_email' ) ) . '</code></p>'
		                . '<p style="color:#6b7280;font-size:12px;">Sent from ' . esc_html( get_bloginfo( 'name' ) ) . ' at ' . esc_html( current_time( 'mysql' ) ) . '</p>';

		// Capture wp_mail failures via the wp_mail_failed action
		$failure = null;
		$capture = function ( $error ) use ( &$failure ) {
			$failure = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture );

		$ok = wp_mail( $to, $subject, $body, [ 'Content-Type: text/html; charset=UTF-8' ] );

		remove_action( 'wp_mail_failed', $capture );

		if ( $ok && ! $failure ) {
			wp_send_json_success( [
				'message' => sprintf( __( 'Test email sent to %s. Check your inbox in 1–2 minutes.', 'advanced-ffl-checkout' ), $to ),
			] );
		} else {
			wp_send_json_error( [
				'message' => sprintf( __( 'wp_mail() failed: %s', 'advanced-ffl-checkout' ), $failure ?: 'No error reported (but PHPMailer returned false). Check your SMTP plugin settings.' ),
			] );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	public static function sanitize_slug( string $slug ): string {
		$slug = preg_replace( '/[^a-z0-9\-]/i', '', strtolower( $slug ) );
		return $slug ?: WPISTIC_FFL_PORTAL_SLUG;
	}

	public static function sanitize_color( string $color ): string {
		$color = trim( $color );
		if ( preg_match( '/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6}|[A-Fa-f0-9]{8})$/', $color ) ) {
			return $color;
		}
		// Allow rgb / rgba / hsl
		if ( preg_match( '/^(rgb|rgba|hsl|hsla)\([0-9\s,.%\/]+\)$/i', $color ) ) {
			return $color;
		}
		return '';
	}

	/**
	 * Get a portal setting with default fallback.
	 */
	public static function portal( string $key, mixed $default = '' ): mixed {
		$opts = get_option( 'wpistic_ffl_portal_settings', [] );
		return $opts[ $key ] ?? $default;
	}

	public static function email( string $key, mixed $default = '' ): mixed {
		$opts = get_option( 'wpistic_ffl_email_settings', [] );
		return $opts[ $key ] ?? $default;
	}
}
