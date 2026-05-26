<?php
/**
 * Spam stack — reCAPTCHA v3, Cloudflare Turnstile, Akismet,
 * IP blocklist, per-IP rate limit.
 *
 * Used in two places:
 *  - Shortcode handler: full pre-check (captcha + blocklist + rate limit).
 *  - Capture pipeline:  Akismet + blocklist + rate limit, so plugin-integrated
 *    captures that the host plugin's own protections let through still get
 *    filtered before landing in the inbox.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless spam helpers.
 */
class WPISTIC_CF_Spam {

	/**
	 * Whether reCAPTCHA v3 is configured & enabled.
	 *
	 * @return bool
	 */
	public static function recaptcha_active() {
		return '1' === get_option( 'WPISTIC_CF_spam_recaptcha_enabled', '0' )
			&& '' !== trim( (string) get_option( 'WPISTIC_CF_spam_recaptcha_site_key', '' ) )
			&& '' !== trim( (string) get_option( 'WPISTIC_CF_spam_recaptcha_secret_key', '' ) );
	}

	/**
	 * Whether Turnstile is configured & enabled.
	 *
	 * @return bool
	 */
	public static function turnstile_active() {
		return '1' === get_option( 'WPISTIC_CF_spam_turnstile_enabled', '0' )
			&& '' !== trim( (string) get_option( 'WPISTIC_CF_spam_turnstile_site_key', '' ) )
			&& '' !== trim( (string) get_option( 'WPISTIC_CF_spam_turnstile_secret_key', '' ) );
	}

	/**
	 * Whether Akismet is enabled here AND available in the install.
	 *
	 * @return bool
	 */
	public static function akismet_active() {
		return '1' === get_option( 'WPISTIC_CF_spam_akismet_enabled', '0' ) && class_exists( 'Akismet' );
	}

	/* ------------------------------------------------------------------
	 * Pre-submit hooks for the [wpistic_contact_form] shortcode
	 * ------------------------------------------------------------------ */

	/**
	 * Print the reCAPTCHA v3 script + hidden input snippet inside a form.
	 * Safe to call multiple times — uses a static guard.
	 */
	public static function print_recaptcha_field() {
		static $printed = false;
		if ( ! self::recaptcha_active() ) {
			return;
		}
		$site = esc_attr( get_option( 'WPISTIC_CF_spam_recaptcha_site_key', '' ) );
		echo '<input type="hidden" name="WPISTIC_CF_recaptcha_token" value="">';
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script src="https://www.google.com/recaptcha/api.js?render=<?php echo $site; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped ?>"></script>
		<script>
		(function(){
			function inject(){
				if (typeof grecaptcha === 'undefined' || !grecaptcha.ready) { setTimeout(inject, 250); return; }
				grecaptcha.ready(function(){
					document.querySelectorAll('form.WPISTIC_CF-form').forEach(function(form){
						form.addEventListener('submit', function(e){
							var field = form.querySelector('input[name="WPISTIC_CF_recaptcha_token"]');
							if (!field || field.value) { return; }
							e.preventDefault();
							grecaptcha.execute('<?php echo esc_js( $site ); ?>', { action: 'WPISTIC_CF_submit' }).then(function(token){
								field.value = token;
								if (typeof form.requestSubmit === 'function') {
									form.requestSubmit();
									return;
								}
								form.submit();
							});
						}, { once: false });
					});
				});
			}
			inject();
		})();
		</script>
		<?php
	}

	/**
	 * Print the Cloudflare Turnstile widget inside a form.
	 */
	public static function print_turnstile_field() {
		static $printed = false;
		if ( ! self::turnstile_active() ) {
			return;
		}
		$site = esc_attr( get_option( 'WPISTIC_CF_spam_turnstile_site_key', '' ) );
		echo '<div class="cf-turnstile" data-sitekey="' . $site . '"></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped
		if ( $printed ) {
			return;
		}
		$printed = true;
		echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
	}

	/**
	 * Verify reCAPTCHA v3 token from POST.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_recaptcha() {
		if ( ! self::recaptcha_active() ) {
			return true;
		}
		$token = isset( $_POST['WPISTIC_CF_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['WPISTIC_CF_recaptcha_token'] ) ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'WPISTIC_CF_recaptcha_missing', __( 'reCAPTCHA token missing.', 'wpistic-contact-form' ) );
		}
		$resp = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => get_option( 'WPISTIC_CF_spam_recaptcha_secret_key', '' ),
				'response' => $token,
				'remoteip' => self::client_ip(),
			],
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$score     = isset( $body['score'] ) ? (float) $body['score'] : 0;
		$threshold = (float) get_option( 'WPISTIC_CF_spam_recaptcha_threshold', 0.5 );
		if ( empty( $body['success'] ) || $score < $threshold ) {
			return new WP_Error( 'WPISTIC_CF_recaptcha_failed', __( 'reCAPTCHA verification failed.', 'wpistic-contact-form' ) );
		}
		return true;
	}

	/**
	 * Verify Cloudflare Turnstile token from POST.
	 *
	 * @return true|WP_Error
	 */
	public static function verify_turnstile() {
		if ( ! self::turnstile_active() ) {
			return true;
		}
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'WPISTIC_CF_turnstile_missing', __( 'Turnstile token missing.', 'wpistic-contact-form' ) );
		}
		$resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => get_option( 'WPISTIC_CF_spam_turnstile_secret_key', '' ),
				'response' => $token,
				'remoteip' => self::client_ip(),
			],
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['success'] ) ) {
			return new WP_Error( 'WPISTIC_CF_turnstile_failed', __( 'Turnstile verification failed.', 'wpistic-contact-form' ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Pre-store gate (used by WPISTIC_CF_Capture::store)
	 * ------------------------------------------------------------------ */

	/**
	 * Decide whether a candidate submission should be blocked at capture time.
	 *
	 * @param string $form_name Form name.
	 * @param array  $fields    Captured fields.
	 * @param string $ip        Client IP.
	 * @param string $email     Sender email (may be empty).
	 * @return true|WP_Error    True to allow, WP_Error to block.
	 */
	public static function pre_store_check( $form_name, array $fields, $ip, $email ) {
		if ( $ip && self::ip_is_blocked( $ip ) ) {
			return new WP_Error( 'WPISTIC_CF_ip_blocked', __( 'Submitter IP is on the blocklist.', 'wpistic-contact-form' ) );
		}
		if ( $ip && ! self::within_rate_limit( $ip ) ) {
			return new WP_Error( 'WPISTIC_CF_rate_limited', __( 'Submission rate limit exceeded for this IP.', 'wpistic-contact-form' ) );
		}
		if ( self::akismet_active() ) {
			$message = '';
			foreach ( $fields as $v ) {
				$message .= $v . "\n";
			}
			if ( self::akismet_is_spam( $email, $message, $ip ) ) {
				return new WP_Error( 'WPISTIC_CF_akismet_spam', __( 'Submission flagged as spam by Akismet.', 'wpistic-contact-form' ) );
			}
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Components
	 * ------------------------------------------------------------------ */

	/**
	 * Is the given IP on the configured blocklist?
	 *
	 * @param string $ip Client IP.
	 * @return bool
	 */
	public static function ip_is_blocked( $ip ) {
		$raw = (string) get_option( 'WPISTIC_CF_spam_ip_blocklist', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}
		$list = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $raw ) ) );
		return in_array( $ip, $list, true );
	}

	/**
	 * Rate-limit check; also increments the counter when within budget.
	 *
	 * @param string $ip Client IP.
	 * @return bool True if allowed, false if exceeded.
	 */
	public static function within_rate_limit( $ip ) {
		if ( '1' !== get_option( 'WPISTIC_CF_spam_rate_limit_enabled', '1' ) ) {
			return true;
		}
		$max    = max( 1, (int) get_option( 'WPISTIC_CF_spam_rate_limit_max', 3 ) );
		$window = max( 60, (int) get_option( 'WPISTIC_CF_spam_rate_limit_window', 3600 ) );
		$key    = 'WPISTIC_CF_rl_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$count  = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window );
		return true;
	}

	/**
	 * Ask Akismet whether the submission looks like spam.
	 *
	 * @param string $email   Sender email.
	 * @param string $content Combined body to evaluate.
	 * @param string $ip      Client IP.
	 * @return bool
	 */
	public static function akismet_is_spam( $email, $content, $ip ) {
		if ( ! class_exists( 'Akismet' ) ) {
			return false;
		}
		$data = [
			'blog'                 => home_url(),
			'user_ip'              => $ip,
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'             => (string) wp_get_referer(),
			'comment_type'         => 'contact-form',
			'comment_author_email' => $email,
			'comment_content'      => $content,
		];
		$response = Akismet::http_post( http_build_query( $data ), 'comment-check' );
		return isset( $response[1] ) && 'true' === trim( (string) $response[1] );
	}

	/**
	 * Best-effort client IP (respects CF / proxy headers if trusted).
	 *
	 * @return string
	 */
	public static function client_ip() {
		$candidates = [];
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$xff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			foreach ( explode( ',', $xff ) as $part ) {
				$candidates[] = trim( $part );
			}
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$candidates[] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		foreach ( $candidates as $ip ) {
			if ( $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		return '';
	}
}
