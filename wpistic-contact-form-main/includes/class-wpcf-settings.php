<?php
/**
 * Settings — tabbed admin settings page (General / Captures / Spam /
 * Auto-Responder / Attachments).
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all plugin options and renders the tabbed settings UI.
 */
class WPISTIC_CF_Settings {

	/** Settings page slug suffix. */
	const PAGE = 'wpistic-contact-settings';

	/** Capability required. */
	const CAP = 'manage_options';

	/** Option group passed to settings_fields(). */
	const GROUP = 'WPISTIC_CF_settings';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/**
	 * Bool sanitizer ("1" / "0").
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public static function sanitize_bool( $v ) {
		return $v ? '1' : '0';
	}

	/**
	 * Float threshold sanitizer clamped to [0, 1].
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public static function sanitize_threshold( $v ) {
		$f = (float) $v;
		if ( $f < 0 ) {
			$f = 0;
		}
		if ( $f > 1 ) {
			$f = 1;
		}
		return (string) $f;
	}

	/**
	 * Positive int sanitizer.
	 *
	 * @param mixed $v Raw value.
	 * @return int
	 */
	public static function sanitize_positive_int( $v ) {
		return max( 0, (int) $v );
	}

	/**
	 * Lowercase comma-list sanitizer (used for allowed file extensions).
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public static function sanitize_ext_list( $v ) {
		$parts = array_filter( array_map( 'trim', explode( ',', strtolower( (string) $v ) ) ) );
		$parts = array_map( function ( $p ) {
			return preg_replace( '/[^a-z0-9]/', '', $p );
		}, $parts );
		return implode( ',', array_filter( $parts ) );
	}

	/**
	 * Sanitize IP blocklist textarea — one IP per line, only valid IPs kept.
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public static function sanitize_ip_blocklist( $v ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $v );
		$out   = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			if ( filter_var( $line, FILTER_VALIDATE_IP ) ) {
				$out[] = $line;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Register every plugin option with sane sanitizers.
	 */
	public function register_settings() {
		$bool = [ self::class, 'sanitize_bool' ];

		// General (existing).
		register_setting( self::GROUP, 'WPISTIC_CF_notify_admin',    [ 'sanitize_callback' => $bool,                'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_notify_email',    [ 'sanitize_callback' => 'sanitize_email',     'default' => get_option( 'admin_email' ) ] );
		register_setting( self::GROUP, 'WPISTIC_CF_reply_from_name', [ 'sanitize_callback' => 'sanitize_text_field','default' => get_bloginfo( 'name' ) ] );
		register_setting( self::GROUP, 'WPISTIC_CF_reply_from_email',[ 'sanitize_callback' => 'sanitize_email',     'default' => get_option( 'admin_email' ) ] );
		register_setting( self::GROUP, 'WPISTIC_CF_reply_signature', [ 'sanitize_callback' => 'sanitize_textarea_field','default' => '' ] );

		// Captures — per-integration toggles.
		register_setting( self::GROUP, 'WPISTIC_CF_capture_cf7',     [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_capture_wpforms', [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_capture_gform',   [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_capture_fluent',  [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_capture_g2a',     [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_capture_wpmail',  [ 'sanitize_callback' => $bool, 'default' => '0' ] );

		// Spam — reCAPTCHA v3.
		register_setting( self::GROUP, 'WPISTIC_CF_spam_recaptcha_enabled',   [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_recaptcha_site_key',  [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_recaptcha_secret_key',[ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_recaptcha_threshold', [ 'sanitize_callback' => [ self::class, 'sanitize_threshold' ], 'default' => '0.5' ] );

		// Spam — Cloudflare Turnstile.
		register_setting( self::GROUP, 'WPISTIC_CF_spam_turnstile_enabled',   [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_turnstile_site_key',  [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_turnstile_secret_key',[ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

		// Spam — Akismet.
		register_setting( self::GROUP, 'WPISTIC_CF_spam_akismet_enabled', [ 'sanitize_callback' => $bool, 'default' => '0' ] );

		// Spam — IP blocklist + rate limit.
		register_setting( self::GROUP, 'WPISTIC_CF_spam_ip_blocklist',       [ 'sanitize_callback' => [ self::class, 'sanitize_ip_blocklist' ], 'default' => '' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_rate_limit_enabled', [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_rate_limit_max',     [ 'sanitize_callback' => [ self::class, 'sanitize_positive_int' ], 'default' => 3 ] );
		register_setting( self::GROUP, 'WPISTIC_CF_spam_rate_limit_window',  [ 'sanitize_callback' => [ self::class, 'sanitize_positive_int' ], 'default' => 3600 ] );

		// Auto-responder.
		register_setting( self::GROUP, 'WPISTIC_CF_ar_enabled', [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_ar_subject', [
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => __( 'Thanks for contacting {site_name}', 'wpistic-contact-form' ),
		] );
		register_setting( self::GROUP, 'WPISTIC_CF_ar_body',    [
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => __( "Hi {name},\n\nThanks for your message — we received it and will get back to you shortly.\n\nFor your records, here is what you sent:\n\n{message}\n\n— {site_name}\n{site_url}", 'wpistic-contact-form' ),
		] );

		// Attachments.
		register_setting( self::GROUP, 'WPISTIC_CF_att_enabled',        [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_att_max_size_mb',    [ 'sanitize_callback' => [ self::class, 'sanitize_positive_int' ], 'default' => 5 ] );
		register_setting( self::GROUP, 'WPISTIC_CF_att_allowed_types',  [
			'sanitize_callback' => [ self::class, 'sanitize_ext_list' ],
			'default'           => 'jpg,jpeg,png,gif,pdf,doc,docx',
		] );

		// GDPR — consent + auto-purge.
		register_setting( self::GROUP, 'WPISTIC_CF_gdpr_consent_enabled',  [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_gdpr_required',         [ 'sanitize_callback' => $bool, 'default' => '1' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_gdpr_consent_text',     [
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => __( 'I agree to the processing of my personal data as described in the privacy policy.', 'wpistic-contact-form' ),
		] );
		register_setting( self::GROUP, 'WPISTIC_CF_gdpr_autopurge_enabled',[ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_gdpr_autopurge_days',   [ 'sanitize_callback' => [ self::class, 'sanitize_positive_int' ], 'default' => 365 ] );

		// Webhooks.
		register_setting( self::GROUP, 'WPISTIC_CF_webhook_enabled', [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_webhook_urls',    [ 'sanitize_callback' => [ self::class, 'sanitize_url_list' ], 'default' => '' ] );
		register_setting( self::GROUP, 'WPISTIC_CF_webhook_secret',  [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );

		// AI + automation (v1.6.0).
		register_setting( self::GROUP, 'wpistic_cf_ai_provider',         [ 'sanitize_callback' => 'sanitize_key', 'default' => 'local_rules' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_endpoint',         [ 'sanitize_callback' => 'esc_url_raw', 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_model',            [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_api_key',          [ 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_smart_reply_enabled', [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_auto_reply_enabled',  [ 'sanitize_callback' => $bool, 'default' => '0' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_auto_reply_subject',  [ 'sanitize_callback' => 'sanitize_text_field', 'default' => 'Thanks for contacting {site_name}' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_auto_reply_rules',    [ 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_faq_text',          [ 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_kb_text',           [ 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_google_sheets_urls',[ 'sanitize_callback' => [ self::class, 'sanitize_url_list' ], 'default' => '' ] );
		register_setting( self::GROUP, 'wpistic_cf_ai_text_sources',      [ 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '' ] );
	}

	/**
	 * Sanitize a textarea list of URLs — one per line, only valid http(s) URLs.
	 *
	 * @param mixed $v Raw value.
	 * @return string
	 */
	public static function sanitize_url_list( $v ) {
		$lines = preg_split( '/\r\n|\r|\n/', (string) $v );
		$out   = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$url = esc_url_raw( $line );
			if ( $url && preg_match( '~^https?://~i', $url ) ) {
				$out[] = $url;
			}
		}
		return implode( "\n", array_unique( $out ) );
	}

	/**
	 * Currently active tab (defaults to general).
	 *
	 * @return string
	 */
	public function current_tab() {
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		$allowed = [ 'general', 'captures', 'spam', 'autoresponder', 'attachments', 'gdpr', 'webhooks', 'templates', 'ai' ];
		return in_array( $tab, $allowed, true ) ? $tab : 'general';
	}

	/**
	 * Render the tabbed settings page.
	 *
	 * @param callable $header_renderer Callable( $subtitle ) provided by WPISTIC_CF_Admin.
	 */
	public function render( $header_renderer ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$tab  = $this->current_tab();
		$tabs = [
			'general'       => __( 'General', 'wpistic-contact-form' ),
			'captures'      => __( 'Captures', 'wpistic-contact-form' ),
			'spam'          => __( 'Spam', 'wpistic-contact-form' ),
			'autoresponder' => __( 'Auto-Responder', 'wpistic-contact-form' ),
			'attachments'   => __( 'Attachments', 'wpistic-contact-form' ),
			'gdpr'          => __( 'GDPR', 'wpistic-contact-form' ),
			'webhooks'      => __( 'Webhooks', 'wpistic-contact-form' ),
			'templates'     => __( 'Reply Templates', 'wpistic-contact-form' ),
			'ai'            => __( 'AI & Automation', 'wpistic-contact-form' ),
		];
		$notice = isset( $_GET['WPISTIC_CF_notice'] ) ? sanitize_key( $_GET['WPISTIC_CF_notice'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$count  = isset( $_GET['n'] ) ? (int) $_GET['n'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap WPISTIC_CF-wrap">
			<?php call_user_func( $header_renderer, __( 'Notification, capture, spam, auto-responder, attachment, GDPR and webhook settings.', 'wpistic-contact-form' ) ); ?>

			<?php if ( 'webhook_test' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						/* translators: %d: number of URLs */
						esc_html( _n( 'Test payload dispatched to %d webhook URL.', 'Test payload dispatched to %d webhook URLs.', max( 1, $count ), 'wpistic-contact-form' ) ),
						(int) $count
					);
					?>
				</p></div>
			<?php elseif ( 'template_saved' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template saved.', 'wpistic-contact-form' ); ?></p></div>
			<?php elseif ( 'template_deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Template deleted.', 'wpistic-contact-form' ); ?></p></div>
			<?php elseif ( 'template_invalid' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'A template needs at least a name and a body.', 'wpistic-contact-form' ); ?></p></div>
			<?php endif; ?>

			<nav class="WPISTIC_CF-tabs">
				<?php foreach ( $tabs as $slug => $label ) :
					$url    = add_query_arg( [ 'page' => self::PAGE, 'tab' => $slug ], admin_url( 'admin.php' ) );
					$active = ( $slug === $tab ) ? ' is-active' : '';
					?>
					<a class="WPISTIC_CF-tab<?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="WPISTIC_CF-panel WPISTIC_CF-panel--pad">
				<?php if ( 'templates' === $tab ) : ?>
					<?php $this->render_tab_templates(); ?>
				<?php else : ?>
					<form method="post" action="options.php">
						<?php
						settings_fields( self::GROUP );
						$method = 'render_tab_' . $tab;
						if ( method_exists( $this, $method ) ) {
							$this->$method();
						}
						submit_button( __( 'Save Settings', 'wpistic-contact-form' ) );
						?>
					</form>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Tab renderers
	 * ------------------------------------------------------------------ */

	protected function render_tab_general() {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'New submission email', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_notify_admin" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_notify_admin" value="1" <?php checked( get_option( 'WPISTIC_CF_notify_admin', '1' ), '1' ); ?>>
						<?php esc_html_e( 'Email me when a new form submission is received', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_notify_email"><?php esc_html_e( 'Notification email address', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="email" class="regular-text" id="WPISTIC_CF_notify_email" name="WPISTIC_CF_notify_email" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_notify_email', get_option( 'admin_email' ) ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_reply_from_name"><?php esc_html_e( 'Reply "From" name', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" class="regular-text" id="WPISTIC_CF_reply_from_name" name="WPISTIC_CF_reply_from_name" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_reply_from_name', get_bloginfo( 'name' ) ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_reply_from_email"><?php esc_html_e( 'Reply "From" email', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="email" class="regular-text" id="WPISTIC_CF_reply_from_email" name="WPISTIC_CF_reply_from_email" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_reply_from_email', get_option( 'admin_email' ) ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_reply_signature"><?php esc_html_e( 'Reply signature', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="4" id="WPISTIC_CF_reply_signature" name="WPISTIC_CF_reply_signature"><?php echo esc_textarea( get_option( 'WPISTIC_CF_reply_signature', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Appended to the bottom of every reply you send from the dashboard.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function render_tab_captures() {
		$rows = [
			'WPISTIC_CF_capture_cf7'     => [ __( 'Contact Form 7',    'wpistic-contact-form' ), '1', __( 'Capture submissions sent via Contact Form 7.', 'wpistic-contact-form' ) ],
			'WPISTIC_CF_capture_wpforms' => [ __( 'WPForms',           'wpistic-contact-form' ), '1', __( 'Capture submissions processed by WPForms.', 'wpistic-contact-form' ) ],
			'WPISTIC_CF_capture_gform'   => [ __( 'Gravity Forms',     'wpistic-contact-form' ), '1', __( 'Capture submissions completed in Gravity Forms.', 'wpistic-contact-form' ) ],
			'WPISTIC_CF_capture_fluent'  => [ __( 'Fluent Forms',      'wpistic-contact-form' ), '1', __( 'Capture submissions stored by Fluent Forms.', 'wpistic-contact-form' ) ],
			'WPISTIC_CF_capture_g2a'     => [ __( 'Guns 2 Ammo theme', 'wpistic-contact-form' ), '1', __( 'Capture theme-bundled g2a_request / g2a_reservation handlers.', 'wpistic-contact-form' ) ],
			'WPISTIC_CF_capture_wpmail'  => [ __( 'wp_mail intercept', 'wpistic-contact-form' ), '0', __( 'Catch-all: record a snapshot of any outgoing email triggered by a form submission. Use with care — also captures non-form site emails (password resets, comment notifications, etc).', 'wpistic-contact-form' ) ],
		];
		?>
		<p class="description" style="margin-top:0;"><?php esc_html_e( 'Toggle which form sources WPistic Contact should monitor. Disabled hooks add zero overhead.', 'wpistic-contact-form' ); ?></p>
		<table class="form-table" role="presentation">
			<?php foreach ( $rows as $key => $row ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="0">
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( get_option( $key, $row[1] ), '1' ); ?>>
							<?php esc_html_e( 'Enabled', 'wpistic-contact-form' ); ?>
						</label>
						<p class="description"><?php echo esc_html( $row[2] ); ?></p>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	protected function render_tab_spam() {
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Google reCAPTCHA v3', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_spam_recaptcha_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_spam_recaptcha_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_spam_recaptcha_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Validate the [wpistic_contact_form] shortcode with reCAPTCHA v3', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_recaptcha_site_key"><?php esc_html_e( 'Site key', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" class="regular-text" id="WPISTIC_CF_spam_recaptcha_site_key" name="WPISTIC_CF_spam_recaptcha_site_key" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_recaptcha_site_key', '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_recaptcha_secret_key"><?php esc_html_e( 'Secret key', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="password" class="regular-text" id="WPISTIC_CF_spam_recaptcha_secret_key" name="WPISTIC_CF_spam_recaptcha_secret_key" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_recaptcha_secret_key', '' ) ); ?>" autocomplete="new-password"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_recaptcha_threshold"><?php esc_html_e( 'Minimum score', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="number" step="0.05" min="0" max="1" class="small-text" id="WPISTIC_CF_spam_recaptcha_threshold" name="WPISTIC_CF_spam_recaptcha_threshold" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_recaptcha_threshold', '0.5' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Submissions scoring below this value are rejected. Recommended: 0.5.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Cloudflare Turnstile', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_spam_turnstile_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_spam_turnstile_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_spam_turnstile_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Validate the [wpistic_contact_form] shortcode with Cloudflare Turnstile', 'wpistic-contact-form' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'If both reCAPTCHA and Turnstile are enabled, both must pass.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_turnstile_site_key"><?php esc_html_e( 'Site key', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" class="regular-text" id="WPISTIC_CF_spam_turnstile_site_key" name="WPISTIC_CF_spam_turnstile_site_key" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_turnstile_site_key', '' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_turnstile_secret_key"><?php esc_html_e( 'Secret key', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="password" class="regular-text" id="WPISTIC_CF_spam_turnstile_secret_key" name="WPISTIC_CF_spam_turnstile_secret_key" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_turnstile_secret_key', '' ) ); ?>" autocomplete="new-password"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Akismet', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_spam_akismet_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_spam_akismet_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_spam_akismet_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Check every captured submission against the active Akismet API key', 'wpistic-contact-form' ); ?>
					</label>
					<p class="description">
						<?php
						if ( class_exists( 'Akismet' ) ) {
							esc_html_e( 'Akismet plugin detected — uses your existing API key.', 'wpistic-contact-form' );
						} else {
							esc_html_e( 'Requires the Akismet plugin to be installed and configured with an API key.', 'wpistic-contact-form' );
						}
						?>
					</p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'IP blocklist', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_ip_blocklist"><?php esc_html_e( 'Blocked IPs', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="5" id="WPISTIC_CF_spam_ip_blocklist" name="WPISTIC_CF_spam_ip_blocklist" placeholder="203.0.113.42&#10;198.51.100.7"><?php echo esc_textarea( get_option( 'WPISTIC_CF_spam_ip_blocklist', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One IPv4 or IPv6 address per line. Invalid lines are silently dropped on save.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Rate limit (per IP)', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_spam_rate_limit_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_spam_rate_limit_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_spam_rate_limit_enabled', '1' ), '1' ); ?>>
						<?php esc_html_e( 'Limit how many submissions one IP can make in a rolling window', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_rate_limit_max"><?php esc_html_e( 'Max submissions', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="number" min="1" max="9999" class="small-text" id="WPISTIC_CF_spam_rate_limit_max" name="WPISTIC_CF_spam_rate_limit_max" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_rate_limit_max', 3 ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_spam_rate_limit_window"><?php esc_html_e( 'Window (seconds)', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="number" min="60" max="86400" class="small-text" id="WPISTIC_CF_spam_rate_limit_window" name="WPISTIC_CF_spam_rate_limit_window" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_spam_rate_limit_window', 3600 ) ); ?>">
					<p class="description"><?php esc_html_e( 'Default 3600 = 1 hour.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function render_tab_autoresponder() {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_ar_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_ar_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_ar_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Send an automatic acknowledgement to the sender after every captured submission', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_ar_subject"><?php esc_html_e( 'Subject', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" class="regular-text" id="WPISTIC_CF_ar_subject" name="WPISTIC_CF_ar_subject" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_ar_subject', __( 'Thanks for contacting {site_name}', 'wpistic-contact-form' ) ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_ar_body"><?php esc_html_e( 'Message body', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea class="large-text" rows="10" id="WPISTIC_CF_ar_body" name="WPISTIC_CF_ar_body"><?php echo esc_textarea( get_option( 'WPISTIC_CF_ar_body', '' ) ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Placeholders:', 'wpistic-contact-form' ); ?>
						<code>{name}</code>, <code>{form}</code>, <code>{message}</code>, <code>{site_name}</code>, <code>{site_url}</code>, <code>{date}</code>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function render_tab_gdpr() {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Consent checkbox', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_gdpr_consent_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_gdpr_consent_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_gdpr_consent_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Show a consent checkbox on the [wpistic_contact_form] shortcode', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Required', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_gdpr_required" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_gdpr_required" value="1" <?php checked( get_option( 'WPISTIC_CF_gdpr_required', '1' ), '1' ); ?>>
						<?php esc_html_e( 'Reject submissions that don\'t tick the box', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_gdpr_consent_text"><?php esc_html_e( 'Consent text', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="text" class="large-text" id="WPISTIC_CF_gdpr_consent_text" name="WPISTIC_CF_gdpr_consent_text" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_gdpr_consent_text', __( 'I agree to the processing of my personal data as described in the privacy policy.', 'wpistic-contact-form' ) ) ); ?>">
					<p class="description"><?php esc_html_e( 'You can link to your privacy policy here as plain text; HTML is not rendered.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Auto-purge', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_gdpr_autopurge_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_gdpr_autopurge_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_gdpr_autopurge_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'Automatically delete submissions older than the cutoff each day', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_gdpr_autopurge_days"><?php esc_html_e( 'Retention (days)', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="number" min="1" max="3650" class="small-text" id="WPISTIC_CF_gdpr_autopurge_days" name="WPISTIC_CF_gdpr_autopurge_days" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_gdpr_autopurge_days', 365 ) ); ?>">
					<p class="description"><?php esc_html_e( 'Submissions older than this are deleted permanently (along with replies and attachments) by a daily cron.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'WordPress Privacy Tools', 'wpistic-contact-form' ); ?></th>
				<td>
					<p class="description">
						<?php
						printf(
							/* translators: 1: Export Personal Data URL, 2: Erase Personal Data URL */
							wp_kses( __( 'Captured submissions are included in WordPress\'s built-in <a href="%1$s">Export Personal Data</a> and <a href="%2$s">Erase Personal Data</a> tools, matched by sender email.', 'wpistic-contact-form' ), [ 'a' => [ 'href' => [] ] ] ),
							esc_url( admin_url( 'export-personal-data.php' ) ),
							esc_url( admin_url( 'erase-personal-data.php' ) )
						);
						?>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function render_tab_webhooks() {
		$test_url = wp_nonce_url( admin_url( 'admin-post.php?action=WPISTIC_CF_webhook_test' ), 'WPISTIC_CF_webhook_test' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable webhooks', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_webhook_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_webhook_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_webhook_enabled', '0' ), '1' ); ?>>
						<?php esc_html_e( 'POST a JSON payload to every configured URL on each captured submission', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_webhook_urls"><?php esc_html_e( 'Webhook URLs', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea class="large-text code" rows="5" id="WPISTIC_CF_webhook_urls" name="WPISTIC_CF_webhook_urls" placeholder="https://hooks.zapier.com/...&#10;https://hook.eu1.make.com/..."><?php echo esc_textarea( get_option( 'WPISTIC_CF_webhook_urls', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One https:// URL per line. Compatible with Zapier, Make, n8n, custom endpoints. Invalid URLs are silently dropped on save.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_webhook_secret"><?php esc_html_e( 'Signing secret', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="WPISTIC_CF_webhook_secret" name="WPISTIC_CF_webhook_secret" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_webhook_secret', '' ) ); ?>" autocomplete="off">
					<p class="description"><?php esc_html_e( 'Optional. If set, every request includes an X-WPISTIC_CF-Signature: sha256=<HMAC> header so your endpoint can verify authenticity.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Test send', 'wpistic-contact-form' ); ?></th>
				<td>
					<a class="button" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Send sample payload to all URLs', 'wpistic-contact-form' ); ?></a>
					<p class="description"><?php esc_html_e( 'Save your settings first, then click to fire a sample webhook to every configured URL.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Payload schema', 'wpistic-contact-form' ); ?></th>
				<td>
					<pre style="background:#f6f7fb;border:1px solid #e4e5ee;border-radius:8px;padding:12px;font-size:12px;overflow:auto;">{
  "event": "submission.created",
  "id": 123,
  "form": "Contact Form",
  "status": "new",
  "created_at": "2026-05-21 17:30:00",
  "sender": { "name": "...", "email": "...", "phone": "...", "ip": "..." },
  "subject": "...",
  "message": "...",
  "fields": { "Name": "...", "Email": "..." },
  "source_url": "...",
  "attachments": 0,
  "site_url": "<?php echo esc_html( home_url( '/' ) ); ?>",
  "dashboard_url": "..."
}</pre>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function render_tab_templates() {
		$templates = class_exists( 'WPISTIC_CF_Templates' ) ? WPISTIC_CF_Templates::all() : [];
		$edit_id   = isset( $_GET['template_id'] ) ? sanitize_key( $_GET['template_id'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$editing   = [];
		foreach ( $templates as $t ) {
			if ( isset( $t['id'] ) && $t['id'] === $edit_id ) {
				$editing = $t;
				break;
			}
		}
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Saved Reply Templates', 'wpistic-contact-form' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Templates appear in the reply popup\'s "Insert template" dropdown so you can answer repeat questions in one click. Placeholders {name}, {form}, {message}, {site_name}, {site_url}, {date} are replaced when inserted.', 'wpistic-contact-form' ); ?></p>

		<?php if ( $templates ) : ?>
			<table class="wp-list-table widefat striped" style="margin:14px 0;">
				<thead><tr>
					<th><?php esc_html_e( 'Name', 'wpistic-contact-form' ); ?></th>
					<th><?php esc_html_e( 'Subject', 'wpistic-contact-form' ); ?></th>
					<th class="check-column"><?php esc_html_e( 'Actions', 'wpistic-contact-form' ); ?></th>
				</tr></thead>
				<tbody>
					<?php foreach ( $templates as $t ) :
						$edit_url   = add_query_arg( [ 'page' => self::PAGE, 'tab' => 'templates', 'template_id' => $t['id'] ], admin_url( 'admin.php' ) );
						$delete_url = wp_nonce_url(
							add_query_arg( [ 'action' => 'WPISTIC_CF_delete_template', 'template_id' => $t['id'] ], admin_url( 'admin-post.php' ) ),
							'WPISTIC_CF_delete_template_' . $t['id']
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( $t['name'] ); ?></strong></td>
							<td><?php echo esc_html( $t['subject'] ?: '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wpistic-contact-form' ); ?></a>
								<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Delete this template?', 'wpistic-contact-form' ) ); ?>');"><?php esc_html_e( 'Delete', 'wpistic-contact-form' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><em><?php esc_html_e( 'No templates yet — add your first one below.', 'wpistic-contact-form' ); ?></em></p>
		<?php endif; ?>

		<h2><?php echo $editing ? esc_html__( 'Edit Template', 'wpistic-contact-form' ) : esc_html__( 'Add a Template', 'wpistic-contact-form' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="WPISTIC_CF_save_template">
			<input type="hidden" name="template_id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>">
			<?php wp_nonce_field( 'WPISTIC_CF_templates' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="WPISTIC_CF_template_name"><?php esc_html_e( 'Name', 'wpistic-contact-form' ); ?></label></th>
					<td><input type="text" id="WPISTIC_CF_template_name" name="template_name" class="regular-text" value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="WPISTIC_CF_template_subject"><?php esc_html_e( 'Subject (optional)', 'wpistic-contact-form' ); ?></label></th>
					<td>
						<input type="text" id="WPISTIC_CF_template_subject" name="template_subject" class="regular-text" value="<?php echo esc_attr( $editing['subject'] ?? '' ); ?>">
						<p class="description"><?php esc_html_e( 'If blank, the reply modal keeps the auto-generated "Re: …" subject.', 'wpistic-contact-form' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="WPISTIC_CF_template_body"><?php esc_html_e( 'Body', 'wpistic-contact-form' ); ?></label></th>
					<td>
						<textarea id="WPISTIC_CF_template_body" name="template_body" class="large-text" rows="10" required><?php echo esc_textarea( $editing['body'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Plain text or simple HTML. Placeholders: {name}, {form}, {message}, {subject}, {site_name}, {site_url}, {date}.', 'wpistic-contact-form' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( $editing ? __( 'Update Template', 'wpistic-contact-form' ) : __( 'Add Template', 'wpistic-contact-form' ) ); ?>
			<?php if ( $editing ) : ?>
				<a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'templates' ], admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Cancel', 'wpistic-contact-form' ); ?></a>
			<?php endif; ?>
		</form>
		<?php
	}

	protected function render_tab_ai() {
		?>
		<h2 style="margin-top:0;"><?php esc_html_e( 'Free AI Connection System', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_provider"><?php esc_html_e( 'Provider', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<select id="wpistic_cf_ai_provider" name="wpistic_cf_ai_provider">
						<option value="local_rules" <?php selected( get_option( 'wpistic_cf_ai_provider', 'local_rules' ), 'local_rules' ); ?>><?php esc_html_e( 'Local Rules (No API, Free)', 'wpistic-contact-form' ); ?></option>
						<option value="ollama" <?php selected( get_option( 'wpistic_cf_ai_provider', '' ), 'ollama' ); ?>><?php esc_html_e( 'Ollama (Local LLM, Free)', 'wpistic-contact-form' ); ?></option>
						<option value="openrouter" <?php selected( get_option( 'wpistic_cf_ai_provider', '' ), 'openrouter' ); ?>><?php esc_html_e( 'OpenRouter (Free model routes)', 'wpistic-contact-form' ); ?></option>
						<option value="huggingface" <?php selected( get_option( 'wpistic_cf_ai_provider', '' ), 'huggingface' ); ?>><?php esc_html_e( 'HuggingFace Inference (free tier)', 'wpistic-contact-form' ); ?></option>
						<option value="custom" <?php selected( get_option( 'wpistic_cf_ai_provider', '' ), 'custom' ); ?>><?php esc_html_e( 'Custom Endpoint', 'wpistic-contact-form' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Use Local Rules if you want zero external dependency.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_endpoint"><?php esc_html_e( 'API Endpoint', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="url" class="large-text" id="wpistic_cf_ai_endpoint" name="wpistic_cf_ai_endpoint" value="<?php echo esc_attr( get_option( 'wpistic_cf_ai_endpoint', '' ) ); ?>" placeholder="http://127.0.0.1:11434/api/generate"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_model"><?php esc_html_e( 'Model', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wpistic_cf_ai_model" name="wpistic_cf_ai_model" value="<?php echo esc_attr( get_option( 'wpistic_cf_ai_model', '' ) ); ?>" placeholder="llama3.1:8b-instruct"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_api_key"><?php esc_html_e( 'API Key (optional)', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="password" class="regular-text" id="wpistic_cf_ai_api_key" name="wpistic_cf_ai_api_key" value="<?php echo esc_attr( get_option( 'wpistic_cf_ai_api_key', '' ) ); ?>" autocomplete="new-password"></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Train with Custom Data', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_faq_text"><?php esc_html_e( 'FAQs', 'wpistic-contact-form' ); ?></label></th>
				<td><textarea id="wpistic_cf_ai_faq_text" name="wpistic_cf_ai_faq_text" class="large-text" rows="5"><?php echo esc_textarea( get_option( 'wpistic_cf_ai_faq_text', '' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_kb_text"><?php esc_html_e( 'Knowledge Base', 'wpistic-contact-form' ); ?></label></th>
				<td><textarea id="wpistic_cf_ai_kb_text" name="wpistic_cf_ai_kb_text" class="large-text" rows="6"><?php echo esc_textarea( get_option( 'wpistic_cf_ai_kb_text', '' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_google_sheets_urls"><?php esc_html_e( 'Google Sheets URLs', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea id="wpistic_cf_ai_google_sheets_urls" name="wpistic_cf_ai_google_sheets_urls" class="large-text code" rows="4"><?php echo esc_textarea( get_option( 'wpistic_cf_ai_google_sheets_urls', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One published CSV URL per line.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_text_sources"><?php esc_html_e( 'Text File Sources', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea id="wpistic_cf_ai_text_sources" name="wpistic_cf_ai_text_sources" class="large-text code" rows="4"><?php echo esc_textarea( get_option( 'wpistic_cf_ai_text_sources', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One source per line: public URL or local absolute text file path.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Automated Reply System', 'wpistic-contact-form' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Smart Reply Drafts', 'wpistic-contact-form' ); ?></th>
				<td><input type="hidden" name="wpistic_cf_ai_smart_reply_enabled" value="0"><label><input type="checkbox" name="wpistic_cf_ai_smart_reply_enabled" value="1" <?php checked( get_option( 'wpistic_cf_ai_smart_reply_enabled', '0' ), '1' ); ?>> <?php esc_html_e( 'Generate AI reply drafts and tags', 'wpistic-contact-form' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable Auto Reply Send', 'wpistic-contact-form' ); ?></th>
				<td><input type="hidden" name="wpistic_cf_ai_auto_reply_enabled" value="0"><label><input type="checkbox" name="wpistic_cf_ai_auto_reply_enabled" value="1" <?php checked( get_option( 'wpistic_cf_ai_auto_reply_enabled', '0' ), '1' ); ?>> <?php esc_html_e( 'Automatically send replies based on rules/AI', 'wpistic-contact-form' ); ?></label></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_auto_reply_subject"><?php esc_html_e( 'Auto Reply Subject', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" class="regular-text" id="wpistic_cf_ai_auto_reply_subject" name="wpistic_cf_ai_auto_reply_subject" value="<?php echo esc_attr( get_option( 'wpistic_cf_ai_auto_reply_subject', 'Thanks for contacting {site_name}' ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="wpistic_cf_ai_auto_reply_rules"><?php esc_html_e( 'Easy Automation Rules', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<textarea id="wpistic_cf_ai_auto_reply_rules" name="wpistic_cf_ai_auto_reply_rules" class="large-text code" rows="8"><?php echo esc_textarea( get_option( 'wpistic_cf_ai_auto_reply_rules', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Format: keyword => reply template. Placeholders: {name}, {site_name}, {site_url}. One rule per line.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	protected function render_tab_attachments() {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Enable file uploads', 'wpistic-contact-form' ); ?></th>
				<td>
					<input type="hidden" name="WPISTIC_CF_att_enabled" value="0">
					<label>
						<input type="checkbox" name="WPISTIC_CF_att_enabled" value="1" <?php checked( get_option( 'WPISTIC_CF_att_enabled', '1' ), '1' ); ?>>
						<?php esc_html_e( 'Accept file uploads in the [wpistic_contact_form] shortcode and capture attachment references from integrated form plugins', 'wpistic-contact-form' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_att_max_size_mb"><?php esc_html_e( 'Max file size (MB)', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="number" min="1" max="100" class="small-text" id="WPISTIC_CF_att_max_size_mb" name="WPISTIC_CF_att_max_size_mb" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_att_max_size_mb', 5 ) ); ?>">
					<p class="description"><?php esc_html_e( 'Also capped by your server upload_max_filesize / post_max_size.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="WPISTIC_CF_att_allowed_types"><?php esc_html_e( 'Allowed extensions', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="text" class="regular-text" id="WPISTIC_CF_att_allowed_types" name="WPISTIC_CF_att_allowed_types" value="<?php echo esc_attr( get_option( 'WPISTIC_CF_att_allowed_types', 'jpg,jpeg,png,gif,pdf,doc,docx' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated, lowercase, no dots. e.g. jpg,pdf,docx', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Storage location', 'wpistic-contact-form' ); ?></th>
				<td>
					<?php
					$dir = class_exists( 'WPISTIC_CF_Attachments' ) ? WPISTIC_CF_Attachments::storage_dir() : '';
					echo '<code>' . esc_html( $dir ) . '</code>';
					?>
					<p class="description"><?php esc_html_e( 'Protected from direct access via .htaccess and an index.php silence file. Files are served through an authenticated endpoint inside wp-admin.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}
