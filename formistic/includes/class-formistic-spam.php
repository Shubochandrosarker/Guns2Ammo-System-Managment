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
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless spam helpers.
 */
class Wpistic_Formistic_Spam {

	/**
	 * Whether reCAPTCHA v3 is configured & enabled.
	 *
	 * @return bool
	 */
	public static function recaptcha_active() {
		if ( ! self::addon_active() ) {
			return false;
		}
		return '1' === get_option( 'wpistic_formistic_spam_recaptcha_enabled', '0' )
			&& '' !== trim( (string) get_option( 'wpistic_formistic_spam_recaptcha_site_key', '' ) )
			&& '' !== trim( (string) get_option( 'wpistic_formistic_spam_recaptcha_secret_key', '' ) );
	}

	/**
	 * Whether Turnstile is configured & enabled.
	 *
	 * @return bool
	 */
	public static function turnstile_active() {
		if ( ! self::addon_active() ) {
			return false;
		}
		return '1' === get_option( 'wpistic_formistic_spam_turnstile_enabled', '0' )
			&& '' !== trim( (string) get_option( 'wpistic_formistic_spam_turnstile_site_key', '' ) )
			&& '' !== trim( (string) get_option( 'wpistic_formistic_spam_turnstile_secret_key', '' ) );
	}

	/**
	 * Whether Akismet is enabled here AND available in the install.
	 *
	 * @return bool
	 */
	public static function akismet_active() {
		return self::addon_active() && '1' === get_option( 'wpistic_formistic_spam_akismet_enabled', '0' ) && class_exists( 'Akismet' );
	}

	/**
	 * Whether the Spam Protection addon is enabled. When off, the entire
	 * spam stack (honeypot/rate-limit/IP/CAPTCHA/Akismet) is inert.
	 *
	 * @return bool
	 */
	public static function addon_active() {
		return Wpistic_Formistic_Addons::is_active( 'spam' );
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
		$site = esc_attr( get_option( 'wpistic_formistic_spam_recaptcha_site_key', '' ) );
		echo '<input type="hidden" name="wpistic_formistic_recaptcha_token" value="">';
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
					document.querySelectorAll('form.wpistic-formistic-form').forEach(function(form){
						form.addEventListener('submit', function(e){
							var field = form.querySelector('input[name="wpistic_formistic_recaptcha_token"]');
							if (!field || field.value) { return; }
							e.preventDefault();
							grecaptcha.execute('<?php echo esc_js( $site ); ?>', { action: 'wpistic_formistic_submit' }).then(function(token){
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
		$site = esc_attr( get_option( 'wpistic_formistic_spam_turnstile_site_key', '' ) );
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
		$token = isset( $_POST['wpistic_formistic_recaptcha_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wpistic_formistic_recaptcha_token'] ) ) : '';
		if ( '' === $token ) {
			return new WP_Error( 'wpistic_formistic_recaptcha_missing', __( 'reCAPTCHA token missing.', 'formistic' ) );
		}
		$resp = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => get_option( 'wpistic_formistic_spam_recaptcha_secret_key', '' ),
				'response' => $token,
				'remoteip' => self::client_ip(),
			],
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		$score     = isset( $body['score'] ) ? (float) $body['score'] : 0;
		$threshold = (float) get_option( 'wpistic_formistic_spam_recaptcha_threshold', 0.5 );
		if ( empty( $body['success'] ) || $score < $threshold ) {
			return new WP_Error( 'wpistic_formistic_recaptcha_failed', __( 'reCAPTCHA verification failed.', 'formistic' ) );
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
			return new WP_Error( 'wpistic_formistic_turnstile_missing', __( 'Turnstile token missing.', 'formistic' ) );
		}
		$resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => get_option( 'wpistic_formistic_spam_turnstile_secret_key', '' ),
				'response' => $token,
				'remoteip' => self::client_ip(),
			],
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( empty( $body['success'] ) ) {
			return new WP_Error( 'wpistic_formistic_turnstile_failed', __( 'Turnstile verification failed.', 'formistic' ) );
		}
		return true;
	}

	/* ------------------------------------------------------------------
	 * Pre-store gate (used by Wpistic_Formistic_Capture::store)
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
		if ( ! self::addon_active() ) {
			return true;
		}
		if ( $ip && self::ip_is_blocked( $ip ) ) {
			return new WP_Error( 'wpistic_formistic_ip_blocked', __( 'Submitter IP is on the blocklist.', 'formistic' ) );
		}
		if ( $ip && ! self::within_rate_limit( $ip ) ) {
			return new WP_Error( 'wpistic_formistic_rate_limited', __( 'Submission rate limit exceeded for this IP.', 'formistic' ) );
		}
		if ( self::akismet_active() ) {
			$message = '';
			foreach ( $fields as $v ) {
				$message .= $v . "\n";
			}
			if ( self::akismet_is_spam( $email, $message, $ip ) ) {
				return new WP_Error( 'wpistic_formistic_akismet_spam', __( 'Submission flagged as spam by Akismet.', 'formistic' ) );
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
		$raw = (string) get_option( 'wpistic_formistic_spam_ip_blocklist', '' );
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
		if ( '1' !== get_option( 'wpistic_formistic_spam_rate_limit_enabled', '1' ) ) {
			return true;
		}
		$max    = max( 1, (int) get_option( 'wpistic_formistic_spam_rate_limit_max', 3 ) );
		$window = max( 60, (int) get_option( 'wpistic_formistic_spam_rate_limit_window', 3600 ) );
		$key    = 'wpistic_formistic_rl_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
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
		$remote_addr = ! empty( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		// Proxy headers (X-Forwarded-For, CF-Connecting-IP) are spoofable.
		// Trust them only when REMOTE_ADDR matches a configured proxy IP
		// (or a CIDR range). Allowlist comes from:
		//   - constant WPISTIC_FORMISTIC_TRUSTED_PROXIES (comma-separated string, takes precedence)
		//   - option  wpistic_formistic_trusted_proxies (admin setting, same format)
		//   - filter  wpistic_formistic_trusted_proxies (array, after both above)
		// Default = empty allowlist = NEVER trust proxy headers, so a bare
		// install on a non-proxied server can't be IP-spoofed.
		if ( self::request_is_from_trusted_proxy( $remote_addr ) ) {
			if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
				$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
				if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
					return $cf;
				}
			}
			if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
				$xff = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
				// The left-most non-empty entry is the original client.
				foreach ( explode( ',', $xff ) as $part ) {
					$part = trim( $part );
					if ( $part && filter_var( $part, FILTER_VALIDATE_IP ) ) {
						return $part;
					}
				}
			}
		}

		return $remote_addr && filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '';
	}

	/**
	 * Whether the immediate HTTP peer (REMOTE_ADDR) is in the configured
	 * trusted-proxy allowlist. Each entry may be a literal IPv4/IPv6
	 * address or a CIDR range like "10.0.0.0/8" or "2400:cb00::/32".
	 */
	private static function request_is_from_trusted_proxy( $remote_addr ) {
		if ( '' === $remote_addr || ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$raw = '';
		if ( defined( 'WPISTIC_FORMISTIC_TRUSTED_PROXIES' ) ) {
			$raw = (string) WPISTIC_FORMISTIC_TRUSTED_PROXIES;
		}
		if ( '' === $raw ) {
			$raw = (string) get_option( 'wpistic_formistic_trusted_proxies', '' );
		}
		$list = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$list = apply_filters( 'wpistic_formistic_trusted_proxies', $list );
		if ( empty( $list ) ) {
			return false;
		}
		foreach ( $list as $entry ) {
			if ( self::ip_matches_entry( $remote_addr, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Match an IP against a single allowlist entry (literal or CIDR).
	 */
	private static function ip_matches_entry( $ip, $entry ) {
		if ( false === strpos( $entry, '/' ) ) {
			return $ip === $entry;
		}
		list( $subnet, $bits ) = explode( '/', $entry, 2 );
		$bits = (int) $bits;
		$ip_bin     = inet_pton( $ip );
		$subnet_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rem   = $bits % 8;
		if ( $bytes > 0 && substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}
		if ( 0 === $rem ) {
			return true;
		}
		$mask = chr( 0xff << ( 8 - $rem ) & 0xff );
		return ( ord( $ip_bin[ $bytes ] ) & ord( $mask ) ) === ( ord( $subnet_bin[ $bytes ] ) & ord( $mask ) );
	}
}
