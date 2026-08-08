<?php
/**
 * Purpose-bound signed action tokens for email CTAs + their public endpoints.
 *
 * Every actionable email link (complete payment, view booking, cancel,
 * reschedule) carries a token that is:
 *   - purpose-bound: a cancel token cannot drive a payment action
 *   - expiring: per-action TTL embedded and enforced server-side
 *   - versioned: bumping the site token version revokes everything issued
 *   - unguessable: HMAC-SHA256 over a per-site random secret
 *   - scope-limited: bound to one booking UUID
 *
 * Token format (URL-safe base64):  v1.<payload>.<hmac>
 *   payload = JSON {u: booking uuid, a: action, e: expiry unix, v: version}
 *
 * Endpoints are registered on template_redirect under
 *   /?g2ab_action=<action>&g2ab_at=<token>
 * and always validate server-side before acting. No open redirects: the only
 * redirect targets are home_url() pages and gateway URLs minted server-side.
 *
 * @package G2AB\Services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Email_Actions {

	const ACTION_PAY        = 'pay';
	const ACTION_VIEW       = 'view';
	const ACTION_CANCEL     = 'cancel';
	const ACTION_RESCHEDULE = 'reschedule';

	/** Per-action TTL in seconds. */
	private static function ttl( $action ) {
		$map = array(
			self::ACTION_PAY        => 3 * DAY_IN_SECONDS,
			self::ACTION_VIEW       => 30 * DAY_IN_SECONDS,
			self::ACTION_CANCEL     => 30 * DAY_IN_SECONDS,
			self::ACTION_RESCHEDULE => 30 * DAY_IN_SECONDS,
		);
		$ttl = isset( $map[ $action ] ) ? $map[ $action ] : DAY_IN_SECONDS;
		return (int) apply_filters( 'g2ab_email_action_ttl', $ttl, $action );
	}

	public static function register() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_handle_action' ), 2 );
	}

	/**
	 * Per-site random secret (generated once). Constant override supported
	 * for hardened deployments.
	 */
	private static function secret() {
		if ( defined( 'G2AB_ACTION_TOKEN_SECRET' ) && G2AB_ACTION_TOKEN_SECRET ) {
			return (string) G2AB_ACTION_TOKEN_SECRET;
		}
		$secret = (string) get_option( 'g2ab_action_token_secret', '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			add_option( 'g2ab_action_token_secret', $secret, '', 'no' );
		}
		return $secret;
	}

	/**
	 * Current token version. Bumping `g2ab_action_token_version` revokes all
	 * previously issued tokens site-wide.
	 */
	private static function version() {
		return max( 1, (int) get_option( 'g2ab_action_token_version', 1 ) );
	}

	/**
	 * Issue a signed, purpose-bound token for one booking + action.
	 *
	 * @param string $booking_uuid Booking UUID.
	 * @param string $action       One of the ACTION_* constants.
	 * @return string Token, or '' on invalid input.
	 */
	public static function issue( $booking_uuid, $action ) {
		$booking_uuid = strtolower( sanitize_text_field( (string) $booking_uuid ) );
		$action       = sanitize_key( (string) $action );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $booking_uuid ) || '' === $action ) {
			return '';
		}
		$payload = wp_json_encode( array(
			'u' => $booking_uuid,
			'a' => $action,
			'e' => time() + self::ttl( $action ),
			'v' => self::version(),
		) );
		$encoded = self::b64_encode( $payload );
		$mac     = hash_hmac( 'sha256', 'v1.' . $encoded, self::secret() );
		return 'v1.' . $encoded . '.' . $mac;
	}

	/**
	 * Validate a token for an expected action.
	 *
	 * @param string $token           Raw token from the URL.
	 * @param string $expected_action Action this endpoint serves.
	 * @return array|WP_Error ['uuid' => ...] on success.
	 */
	public static function validate( $token, $expected_action ) {
		$token = (string) $token;
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) || 'v1' !== $parts[0] ) {
			return new WP_Error( 'g2ab_bad_token', __( 'This link is invalid.', 'g2a-booking' ) );
		}
		$expected_mac = hash_hmac( 'sha256', $parts[0] . '.' . $parts[1], self::secret() );
		if ( ! hash_equals( $expected_mac, (string) $parts[2] ) ) {
			return new WP_Error( 'g2ab_bad_token', __( 'This link is invalid.', 'g2a-booking' ) );
		}
		$payload = json_decode( self::b64_decode( $parts[1] ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'g2ab_bad_token', __( 'This link is invalid.', 'g2a-booking' ) );
		}
		if ( sanitize_key( (string) ( $payload['a'] ?? '' ) ) !== sanitize_key( (string) $expected_action ) ) {
			return new WP_Error( 'g2ab_wrong_purpose', __( 'This link cannot perform that action.', 'g2a-booking' ) );
		}
		if ( (int) ( $payload['v'] ?? 0 ) !== self::version() ) {
			return new WP_Error( 'g2ab_token_revoked', __( 'This link is no longer valid. Please request a new one.', 'g2a-booking' ) );
		}
		if ( (int) ( $payload['e'] ?? 0 ) < time() ) {
			return new WP_Error( 'g2ab_token_expired', __( 'This link has expired. Please request a new one.', 'g2a-booking' ) );
		}
		$uuid = strtolower( sanitize_text_field( (string) ( $payload['u'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			return new WP_Error( 'g2ab_bad_token', __( 'This link is invalid.', 'g2a-booking' ) );
		}
		return array( 'uuid' => $uuid );
	}

	/**
	 * Build a full action URL for an email CTA.
	 *
	 * @param string $booking_uuid Booking UUID.
	 * @param string $action       ACTION_* constant.
	 * @return string URL ('' when the token could not be issued).
	 */
	public static function url( $booking_uuid, $action ) {
		$token = self::issue( $booking_uuid, $action );
		if ( '' === $token ) {
			return '';
		}
		return add_query_arg(
			array(
				'g2ab_action' => sanitize_key( $action ),
				'g2ab_at'     => rawurlencode( $token ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Front-controller for ?g2ab_action=… links.
	 */
	public static function maybe_handle_action() {
		if ( empty( $_GET['g2ab_action'] ) || empty( $_GET['g2ab_at'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		nocache_headers();
		$action = sanitize_key( wp_unslash( $_GET['g2ab_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token  = sanitize_text_field( rawurldecode( wp_unslash( $_GET['g2ab_at'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$valid = self::validate( $token, $action );
		if ( is_wp_error( $valid ) ) {
			self::render_notice( __( 'Booking link problem', 'g2a-booking' ), $valid->get_error_message() );
		}

		$booking = self::get_booking( $valid['uuid'] );
		if ( ! $booking ) {
			self::render_notice( __( 'Booking not found', 'g2a-booking' ), __( 'We could not find this reservation. Please contact us.', 'g2a-booking' ) );
		}

		switch ( $action ) {
			case self::ACTION_PAY:
				self::handle_pay( $booking );
				break;
			case self::ACTION_CANCEL:
				self::handle_cancel( $booking );
				break;
			case self::ACTION_VIEW:
				self::render_booking_summary( $booking );
				break;
			case self::ACTION_RESCHEDULE:
				self::handle_reschedule( $booking );
				break;
			default:
				self::render_notice( __( 'Unknown action', 'g2a-booking' ), __( 'This link cannot perform that action.', 'g2a-booking' ) );
		}
		exit;
	}

	/**
	 * Complete/resume payment: a stable action page. If the original Stripe
	 * session is live → redirect to it; if it expired → mint a fresh session
	 * (availability re-checked) instead of a dead URL.
	 */
	private static function handle_pay( $booking ) {
		global $wpdb;

		if ( in_array( (string) $booking->status, array( 'paid', 'confirmed', 'completed' ), true ) ) {
			self::render_booking_summary( $booking, __( 'This reservation is already paid and confirmed — see you at the range!', 'g2a-booking' ) );
		}

		$pt      = $wpdb->prefix . 'g2ab_payments';
		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$pt} WHERE booking_id = %d AND gateway = 'stripe' AND status IN ('creating','pending','open') ORDER BY id DESC LIMIT 1",
			(int) $booking->id
		) );
		if ( $payment ) {
			$saved   = json_decode( (string) $payment->gateway_response, true );
			$url     = is_array( $saved ) ? esc_url_raw( (string) ( $saved['url'] ?? '' ) ) : '';
			$expires = ! empty( $payment->checkout_expires_at ) ? strtotime( (string) $payment->checkout_expires_at ) : 0;
			if ( $url && ( ! $expires || $expires > time() + 60 ) && 'pending' === (string) $booking->status ) {
				wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect — Stripe checkout is an intentional external destination minted server-side.
				exit;
			}
		}

		// Expired session/hold → recreate through the controller's safe path.
		if ( class_exists( 'G2AB_REST_Bookings_Controller' ) ) {
			$controller = new G2AB_REST_Bookings_Controller();
			$reflection = new ReflectionMethod( $controller, 'revive_and_recreate_checkout' );
			$reflection->setAccessible( true );
			$intent = $reflection->invoke( $controller, $booking );
			if ( ! is_wp_error( $intent ) && ! empty( $intent['redirect_url'] ) ) {
				wp_redirect( esc_url_raw( (string) $intent['redirect_url'] ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
				exit;
			}
			$message = is_wp_error( $intent )
				? $intent->get_error_message()
				: __( 'We could not restart checkout. Please try again shortly or call us.', 'g2a-booking' );
			self::render_notice( __( 'Payment unavailable', 'g2a-booking' ), $message );
		}

		self::render_notice( __( 'Payment unavailable', 'g2a-booking' ), __( 'Please call us to complete this reservation.', 'g2a-booking' ) );
	}

	/**
	 * Real cancellation with confirm step (GET shows confirm form, POST acts).
	 */
	private static function handle_cancel( $booking ) {
		if ( in_array( (string) $booking->status, array( 'cancelled', 'refunded', 'expired' ), true ) ) {
			self::render_notice( __( 'Already cancelled', 'g2a-booking' ), __( 'This reservation is already cancelled.', 'g2a-booking' ) );
		}

		$confirmed = isset( $_POST['g2ab_confirm_cancel'] )
			&& isset( $_POST['_g2ab_cancel_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_g2ab_cancel_nonce'] ) ), 'g2ab_email_cancel_' . $booking->uuid );

		if ( ! $confirmed ) {
			self::render_cancel_confirm( $booking );
		}

		if ( class_exists( 'G2AB_Booking_Transitions' ) ) {
			$result = G2AB_Booking_Transitions::transition( (int) $booking->id, 'cancelled', array(
				'source' => 'customer_email_link',
				'reason' => 'customer_cancelled_via_email',
			) );
			if ( is_wp_error( $result ) ) {
				self::render_notice( __( 'Could not cancel', 'g2a-booking' ), $result->get_error_message() );
			}
		}

		do_action( 'g2ab_booking_cancelled_by_customer', (int) $booking->id );
		self::render_notice(
			__( 'Reservation cancelled', 'g2a-booking' ),
			__( 'Your reservation has been cancelled. If you already paid, our team will process your refund according to the range policy.', 'g2a-booking' )
		);
	}

	private static function handle_reschedule( $booking ) {
		// Reuse the dedicated reschedule page when configured; token-gated.
		$page_id = (int) get_option( 'g2ab_reschedule_page_id', 0 );
		if ( $page_id && 'publish' === get_post_status( $page_id ) ) {
			$target = add_query_arg(
				array(
					'g2ab_reschedule' => rawurlencode( (string) $booking->uuid ),
					'g2ab_at'         => rawurlencode( self::issue( (string) $booking->uuid, self::ACTION_RESCHEDULE ) ),
				),
				get_permalink( $page_id )
			);
			wp_safe_redirect( $target );
			exit;
		}
		self::render_booking_summary( $booking, __( 'To reschedule, reply to your confirmation email or call us and we will move your reservation.', 'g2a-booking' ) );
	}

	private static function get_booking( $uuid ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT b.*, r.name AS resource_name FROM {$wpdb->prefix}g2ab_bookings b
			 LEFT JOIN {$wpdb->prefix}g2ab_resources r ON r.id = b.resource_id
			 WHERE b.uuid = %s LIMIT 1",
			$uuid
		) );
	}

	/* ── Minimal branded output helpers (no theme dependency) ─────────────── */

	private static function page_shell( $title, $body_html ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
		echo '<meta name="robots" content="noindex,nofollow"><title>' . esc_html( $title ) . '</title>';
		echo '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0F1115;color:#F4F4F6;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:20px;box-sizing:border-box;}
		.card{max-width:520px;width:100%;background:#161A21;border:1px solid #2A323D;border-radius:14px;padding:32px;box-shadow:0 20px 50px rgba(0,0,0,.35);}
		.card::before{content:"";display:block;height:3px;margin:-32px -32px 24px;border-radius:14px 14px 0 0;background:linear-gradient(90deg,#C9A84C,#E8802F);}
		h1{font-size:22px;margin:0 0 12px;} p{color:#B9C2CE;line-height:1.6;margin:0 0 16px;}
		.row{display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid #222A34;font-size:14.5px;}
		.row .l{color:#8A95A5;} .row .v{font-weight:600;}
		.btn{display:inline-block;background:linear-gradient(180deg,#F0922F,#E8802F);color:#1A1408;border-radius:8px;padding:13px 24px;font-weight:700;text-decoration:none;text-transform:uppercase;letter-spacing:.05em;border:0;font-size:14px;cursor:pointer;}
		.btn--ghost{background:transparent;color:#C9A84C;border:1px solid #C9A84C;margin-left:8px;}
		</style></head><body><div class="card" role="main">' . $body_html . '</div></body></html>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — built from escaped fragments below.
		exit;
	}

	private static function render_notice( $title, $message ) {
		self::page_shell( $title, '<h1>' . esc_html( $title ) . '</h1><p role="alert">' . esc_html( $message ) . '</p><p><a class="btn" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Back to site', 'g2a-booking' ) . '</a></p>' );
	}

	private static function render_booking_summary( $booking, $note = '' ) {
		$label = class_exists( 'G2AB_Booking_Visibility' ) ? G2AB_Booking_Visibility::status_label( $booking ) : ucfirst( (string) $booking->status );
		$rows  = '<div class="row"><span class="l">' . esc_html__( 'Status', 'g2a-booking' ) . '</span><span class="v">' . esc_html( $label ) . '</span></div>';
		$rows .= '<div class="row"><span class="l">' . esc_html__( 'Resource', 'g2a-booking' ) . '</span><span class="v">' . esc_html( (string) ( $booking->resource_name ?: __( 'Event', 'g2a-booking' ) ) ) . '</span></div>';
		$rows .= '<div class="row"><span class="l">' . esc_html__( 'When', 'g2a-booking' ) . '</span><span class="v">' . esc_html( mysql2date( 'M j, Y g:i A', (string) $booking->start_at ) ) . '</span></div>';
		$rows .= '<div class="row"><span class="l">' . esc_html__( 'Total', 'g2a-booking' ) . '</span><span class="v">' . esc_html( function_exists( 'g2ab_format_currency' ) ? g2ab_format_currency( (float) $booking->total_amount, (string) $booking->currency ) : number_format( (float) $booking->total_amount, 2 ) ) . '</span></div>';
		$note_html = $note ? '<p>' . esc_html( $note ) . '</p>' : '';
		self::page_shell(
			__( 'Your reservation', 'g2a-booking' ),
			'<h1>' . esc_html__( 'Your reservation', 'g2a-booking' ) . '</h1>' . $rows . $note_html
		);
	}

	private static function render_cancel_confirm( $booking ) {
		$action_url = add_query_arg(
			array(
				'g2ab_action' => self::ACTION_CANCEL,
				'g2ab_at'     => rawurlencode( self::issue( (string) $booking->uuid, self::ACTION_CANCEL ) ),
			),
			home_url( '/' )
		);
		$body  = '<h1>' . esc_html__( 'Cancel this reservation?', 'g2a-booking' ) . '</h1>';
		$body .= '<div class="row"><span class="l">' . esc_html__( 'Resource', 'g2a-booking' ) . '</span><span class="v">' . esc_html( (string) ( $booking->resource_name ?: __( 'Event', 'g2a-booking' ) ) ) . '</span></div>';
		$body .= '<div class="row"><span class="l">' . esc_html__( 'When', 'g2a-booking' ) . '</span><span class="v">' . esc_html( mysql2date( 'M j, Y g:i A', (string) $booking->start_at ) ) . '</span></div>';
		$body .= '<form method="post" action="' . esc_url( $action_url ) . '" style="margin-top:20px;">';
		$body .= wp_nonce_field( 'g2ab_email_cancel_' . $booking->uuid, '_g2ab_cancel_nonce', true, false );
		$body .= '<button type="submit" name="g2ab_confirm_cancel" value="1" class="btn">' . esc_html__( 'Yes, cancel it', 'g2a-booking' ) . '</button>';
		$body .= '<a class="btn btn--ghost" href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Keep my reservation', 'g2a-booking' ) . '</a>';
		$body .= '</form>';
		self::page_shell( __( 'Cancel reservation', 'g2a-booking' ), $body );
	}

	private static function b64_encode( $data ) {
		return rtrim( strtr( base64_encode( (string) $data ), '+/', '-_' ), '=' );
	}

	private static function b64_decode( $data ) {
		return base64_decode( strtr( (string) $data, '-_', '+/' ) );
	}
}
