<?php
/**
 * Email notification engine for FFL transfer lifecycle events.
 *
 * Sends transactional emails to:
 *   - Customers on every status change
 *   - Staff/admin on specific events (dealer received, NICS delay, denial)
 *
 * All emails use wp_mail() — compatible with any SMTP plugin.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Mailer {

	/** Admin recipient email(s) — falls back to admin_email */
	private static function admin_email(): string {
		$settings = get_option( 'wpistic_ffl_settings', [] );
		return $settings['notification_email'] ?? get_option( 'admin_email' );
	}

	private static function from_name(): string {
		$settings = get_option( 'wpistic_ffl_settings', [] );
		return $settings['from_name'] ?? get_bloginfo( 'name' );
	}

	/**
	 * Send appropriate email(s) when a transfer status changes.
	 *
	 * @param int    $transfer_id
	 * @param string $new_status
	 */
	public static function send_status_update( int $transfer_id, string $new_status ): void {
		global $wpdb;

		self::log( "send_status_update called: transfer={$transfer_id} status={$new_status}" );

		$transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT t.*, d.business_name AS dealer_name, d.premise_street AS dealer_street,
			        d.premise_city AS dealer_city, d.premise_state AS dealer_state,
			        d.premise_zip AS dealer_zip, d.phone AS dealer_phone
			 FROM ' . DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d',
			$transfer_id
		) );

		if ( ! $transfer ) {
			self::log( "  -> abort: transfer {$transfer_id} not found in DB" );
			return;
		}

		// Settings — explicit opt-OUT. Missing key or truthy value = send (v1.1.3 fix).
		$settings = get_option( 'wpistic_ffl_settings', [] );
		if ( ! empty( $settings['disable_emails'] ) ) {
			self::log( '  -> abort: disable_emails flag is ON in wpistic_ffl_settings' );
			return;
		}

		// Key present AND explicitly falsy (0/false) = skip. Otherwise send.
		$send_customer = ! isset( $settings['customer_emails'] ) || ! empty( $settings['customer_emails'] );
		$send_admin    = ! isset( $settings['admin_emails'] )    || ! empty( $settings['admin_emails'] );

		// v1.1.3 — normalise status aliases. The API/dashboard use slightly different
		// names than the Mailer originally understood; accept all variants.
		$alias = [
			'payment_confirmed'    => 'payment_confirmed',
			'dealer_selected'      => 'payment_confirmed',  // created via REST → treat like "paid"
			'shipped'              => 'shipped',
			'shipped_to_dealer'    => 'shipped',            // dashboard + API canonical
			'dealer_received'      => 'dealer_received',
			'received_by_dealer'   => 'dealer_received',    // dashboard canonical
			'nics_pending'         => 'nics_pending',
			'background_check'     => 'nics_pending',       // dashboard canonical
			'nics_delayed'         => 'nics_delayed',
			'delayed'              => 'nics_delayed',       // dashboard canonical
			'nics_denied'          => 'nics_denied',
			'denied'               => 'nics_denied',        // dashboard canonical
			'approved'             => 'payment_confirmed',  // NICS approved — use confirmed template
			'transferred'          => 'transferred',
			'cancelled'            => 'cancelled',
			'issue_reported'       => 'issue_reported',
		];
		$bucket = $alias[ $new_status ] ?? null;
		if ( null === $bucket ) {
			self::log( "  -> no mail template mapped for status '{$new_status}'" );
			return;
		}

		self::log( "  -> bucket={$bucket}  send_customer=" . ( $send_customer ? 'YES' : 'NO' ) . "  send_admin=" . ( $send_admin ? 'YES' : 'NO' ) );

		switch ( $bucket ) {
			case 'payment_confirmed':
				if ( $send_customer ) self::email_customer_confirmed( $transfer );
				break;

			case 'shipped':
				if ( $send_customer ) self::email_customer_shipped( $transfer );
				break;

			case 'dealer_received':
				if ( $send_customer ) self::email_customer_dealer_received( $transfer );
				if ( $send_admin )    self::email_admin_dealer_received( $transfer );
				break;

			case 'nics_pending':
				if ( $send_customer ) self::email_customer_nics_pending( $transfer );
				break;

			case 'nics_delayed':
				if ( $send_customer ) self::email_customer_nics_delayed( $transfer );
				if ( $send_admin )    self::email_admin_nics_delayed( $transfer );
				break;

			case 'nics_denied':
				if ( $send_customer ) self::email_customer_nics_denied( $transfer );
				if ( $send_admin )    self::email_admin_nics_denied( $transfer );
				break;

			case 'transferred':
				if ( $send_customer ) self::email_customer_transferred( $transfer );
				break;

			case 'cancelled':
				if ( $send_customer ) self::email_customer_cancelled( $transfer );
				break;

			case 'issue_reported':
				// Admin notification only — customer doesn't need to see dealer-side issues
				break;
		}
	}

	/**
	 * v1.1.3 — file-based mail log so we can trace silent failures in production.
	 * Stored as a WP option (capped at last 100 entries) so it shows up in the
	 * Diagnostics panel without requiring server-log access.
	 */
	private static function log( string $message ): void {
		$entry = [
			'ts'  => gmdate( 'Y-m-d H:i:s' ),
			'msg' => $message,
		];

		$log = get_option( 'wpistic_ffl_mail_log', [] );
		if ( ! is_array( $log ) ) $log = [];
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 100 );
		update_option( 'wpistic_ffl_mail_log', $log, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[wpistic-ffl-mail] ' . $message ); // phpcs:ignore
		}
	}

	// ── Customer emails ───────────────────────────────────────────────────────

	private static function email_customer_confirmed( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Order Confirmed — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Thank you for your order!', 'advanced-ffl-checkout' ),
				sprintf(
					/* translators: %s: dealer name */
					__( 'Your firearm purchase has been confirmed and will be transferred through <strong>%s</strong>. We will contact you at each step of the transfer process.', 'advanced-ffl-checkout' ),
					esc_html( $transfer->dealer_name )
				) . self::dealer_block( $transfer ),
				'success'
			)
		);
	}

	private static function email_customer_shipped( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Item Shipped — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Your item has been shipped!', 'advanced-ffl-checkout' ),
				sprintf(
					__( 'Your firearm is on its way to <strong>%s</strong>.', 'advanced-ffl-checkout' ),
					esc_html( $transfer->dealer_name )
				) .
				( $transfer->shipment_tracking
					? '<p>Tracking: <strong>' . esc_html( $transfer->shipment_carrier . ' ' . $transfer->shipment_tracking ) . '</strong></p>'
					: ''
				) . self::dealer_block( $transfer ),
				'primary'
			)
		);
	}

	private static function email_customer_dealer_received( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Item Arrived at Dealer — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Your item has arrived at the dealer!', 'advanced-ffl-checkout' ),
				sprintf(
					__( 'Your firearm has been received by <strong>%s</strong>. The dealer will contact you to schedule your background check and pickup.', 'advanced-ffl-checkout' ),
					esc_html( $transfer->dealer_name )
				) . self::dealer_block( $transfer ),
				'primary'
			)
		);
	}

	private static function email_customer_nics_pending( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Background Check Initiated — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Background check in progress', 'advanced-ffl-checkout' ),
				__( 'Your NICS background check has been initiated. Most checks are completed instantly or within a few minutes. We will update you as soon as a decision is made.', 'advanced-ffl-checkout' ),
				'warning'
			)
		);
	}

	private static function email_customer_nics_delayed( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Background Check Delayed — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Your background check has been delayed', 'advanced-ffl-checkout' ),
				__( 'The FBI has requested additional time to process your NICS background check. This is a standard delay and does not indicate a denial. Under federal law, if no response is received within 3 business days, the dealer may proceed with the transfer at their discretion. We will keep you updated.', 'advanced-ffl-checkout' ),
				'warning'
			)
		);
	}

	private static function email_customer_nics_denied( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Background Check Denied — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Background check result: Denied', 'advanced-ffl-checkout' ),
				__( 'We regret to inform you that your NICS background check was denied. Your firearm cannot be transferred. You may appeal this decision through the FBI NICS E-Check program. Please contact us to arrange a refund.', 'advanced-ffl-checkout' ),
				'danger'
			)
		);
	}

	private static function email_customer_transferred( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Transfer Complete — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( '🎉 Transfer Complete!', 'advanced-ffl-checkout' ),
				sprintf(
					__( 'Your firearm transfer has been completed at <strong>%s</strong>. Thank you for your purchase. Please store your firearm safely and responsibly.', 'advanced-ffl-checkout' ),
					esc_html( $transfer->dealer_name )
				),
				'success'
			)
		);
	}

	private static function email_customer_cancelled( object $transfer ): void {
		self::send(
			$transfer->customer_email,
			sprintf( __( 'Transfer Cancelled — FFL Transfer #%s', 'advanced-ffl-checkout' ), $transfer->transfer_ref ),
			self::wrap(
				$transfer,
				__( 'Your transfer has been cancelled', 'advanced-ffl-checkout' ),
				__( 'Your FFL transfer has been cancelled. If you believe this is an error, please contact us. If applicable, a refund will be processed.', 'advanced-ffl-checkout' ),
				'primary'
			)
		);
	}

	// ── Admin emails ──────────────────────────────────────────────────────────

	private static function email_admin_dealer_received( object $transfer ): void {
		self::send(
			self::admin_email(),
			sprintf( '[G2A] Item Received at Dealer — Transfer #%s', $transfer->transfer_ref ),
			'<p><strong>' . esc_html( $transfer->business_name ?? $transfer->dealer_name ) . '</strong> has received the item for ' .
			esc_html( $transfer->customer_name ) . ' (Order #' . esc_html( $transfer->order_id ) . ').</p>' .
			'<p>Item: ' . esc_html( $transfer->item_description ) . '</p>' .
			'<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl&tab=transfers&id=' . $transfer->id ) ) . '">View Transfer</a></p>'
		);
	}

	private static function email_admin_nics_delayed( object $transfer ): void {
		self::send(
			self::admin_email(),
			sprintf( '[G2A] ⚠️ NICS Delay — Transfer #%s', $transfer->transfer_ref ),
			'<p>NICS background check delayed for <strong>' . esc_html( $transfer->customer_name ) . '</strong>.</p>' .
			'<p>Transfer: #' . esc_html( $transfer->transfer_ref ) . ' | Order: #' . esc_html( $transfer->order_id ) . '</p>' .
			'<p>3-day rule applies. Monitor and follow up with the dealer.</p>' .
			'<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl&tab=transfers&id=' . $transfer->id ) ) . '">View Transfer</a></p>'
		);
	}

	private static function email_admin_nics_denied( object $transfer ): void {
		self::send(
			self::admin_email(),
			sprintf( '[G2A] ❌ NICS Denial — Transfer #%s', $transfer->transfer_ref ),
			'<p>NICS background check <strong>DENIED</strong> for ' . esc_html( $transfer->customer_name ) . '.</p>' .
			'<p>Transfer: #' . esc_html( $transfer->transfer_ref ) . ' | Order: #' . esc_html( $transfer->order_id ) . '</p>' .
			'<p>Action required: Process refund and update order status.</p>' .
			'<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl&tab=transfers&id=' . $transfer->id ) ) . '">View Transfer</a></p>'
		);
	}

	// ── Email utilities ───────────────────────────────────────────────────────

	private static function send( string $to, string $subject, string $body, string $nonce = '' ): bool {
		// v1.1.4 — deliverability headers to reduce spam-folder routing
		$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$from_email = self::admin_email();
		$from_name  = self::from_name();
		$unique_id  = sprintf( '<%s@%s>', md5( $to . microtime( true ) ), $site_host ?: 'localhost' );

		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
			'Reply-To: ' . $from_name . ' <' . $from_email . '>',
			'Return-Path: <' . $from_email . '>',
			'Message-ID: ' . $unique_id,
			'X-Mailer: Advanced FFL Checkout ' . WPISTIC_FFL_VERSION,
			// List-Unsubscribe is a major spam-score reducer when emailing real addresses
			'List-Unsubscribe: <mailto:' . $from_email . '?subject=Unsubscribe>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		];

		// Filter-in a plain-text alternative (reduces spam score; required by Gmail 2024+ sender guidelines)
		$plain_text_cb = function ( $mailer ) use ( $body ) {
			if ( $mailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
				$mailer->AltBody = self::html_to_plain( $body );
			}
		};
		add_action( 'phpmailer_init', $plain_text_cb );

		// Capture wp_mail failures (SMTP auth fail, invalid recipient, etc.)
		$failure_msg = '';
		$capture     = function ( $err ) use ( &$failure_msg ) {
			if ( $err instanceof \WP_Error ) {
				$failure_msg = $err->get_error_message();
			}
		};
		add_action( 'wp_mail_failed', $capture );

		$ok = wp_mail( $to, $subject, $body, $headers );

		remove_action( 'wp_mail_failed', $capture );
		remove_action( 'phpmailer_init', $plain_text_cb );

		self::log( sprintf(
			"wp_mail to=%s subject='%s' result=%s%s",
			$to,
			substr( $subject, 0, 60 ),
			$ok ? 'SENT' : 'FAILED',
			$failure_msg ? ' (' . $failure_msg . ')' : ''
		) );

		return $ok;
	}

	/**
	 * Convert HTML email body → plain text alternative.
	 * Simple but effective — strips tags, decodes entities, collapses whitespace.
	 */
	private static function html_to_plain( string $html ): string {
		$text = preg_replace( '/<br\s*\/?>/i', "\n",  $html );
		$text = preg_replace( '/<\/p>/i',       "\n\n", (string) $text );
		$text = preg_replace( '/<\/tr>/i',      "\n",   (string) $text );
		$text = preg_replace( '/<a [^>]*href="([^"]+)"[^>]*>([^<]*)<\/a>/i', '$2 ($1)', (string) $text );
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}

	/**
	 * Build the customer-facing email frame using the Theming engine so brand
	 * colors, store name, logo and footer copy all reflect site settings
	 * (Guns2Ammo brass/graphite by default).
	 *
	 * The optional $accent_key argument selects a semantic color from theme
	 * settings (success / danger / warning) instead of taking a hardcoded hex.
	 */
	private static function wrap( object $transfer, string $heading, string $body, string $accent_key = 'primary' ): string {
		$theme       = \WpisticFFL\Theming::settings();
		$site        = $theme['business_name'] ?: get_bloginfo( 'name' );
		$logo        = $theme['logo_url'] ?: ( get_option( 'wpistic_ffl_settings', [] )['email_logo'] ?? '' );
		$year        = date( 'Y' );
		$ffl_license = $theme['ffl_license'] ?? '';

		$accent_map = [
			'primary' => $theme['color_primary'],
			'success' => $theme['color_success'],
			'danger'  => $theme['color_danger'],
			'warning' => $theme['color_warning'],
		];
		$accent     = $accent_map[ $accent_key ] ?? $theme['color_primary'];
		$surface    = $theme['color_surface'];
		$bg         = $theme['color_bg'];
		$text       = $theme['color_text'];
		$text_muted = $theme['color_text_muted'];
		$border     = $theme['color_border'];

		$show_branding = \WpisticFFL\License::show_branding();
		$footer_brand  = $show_branding
			? '<br>Powered by <a href="https://wordpressistic.com" style="color:' . esc_attr( $accent ) . ';text-decoration:none;">Wordpressistic</a>'
			: '';

		$ffl_block = $ffl_license
			? '<br><span style="font-size:11px;letter-spacing:.08em;text-transform:uppercase;">FFL #' . esc_html( $ffl_license ) . '</span>'
			: '';

		return '<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:' . esc_attr( $bg ) . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;color:' . esc_attr( $text ) . ';">
<table width="100%" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $bg ) . ';padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:' . esc_attr( $surface ) . ';border-radius:12px;overflow:hidden;box-shadow:0 12px 48px -12px rgba(0,0,0,.45);border:1px solid ' . esc_attr( $border ) . ';">

  <!-- Header -->
  <tr><td style="background:' . esc_attr( $accent ) . ';padding:24px 32px;text-align:center;">
    ' . ( $logo ? '<img src="' . esc_url( $logo ) . '" height="40" alt="' . esc_attr( $site ) . '">' : '<span style="color:#0F0E12;font-size:22px;font-weight:800;letter-spacing:-.01em;">' . esc_html( $site ) . '</span>' ) . '
  </td></tr>

  <!-- Body -->
  <tr><td style="padding:32px;">
    <h1 style="margin:0 0 16px;font-size:22px;color:' . esc_attr( $text ) . ';letter-spacing:-.01em;">' . wp_kses_post( $heading ) . '</h1>
    <div style="font-size:15px;line-height:1.7;color:' . esc_attr( $text ) . ';">' . wp_kses_post( $body ) . '</div>

    <!-- Transfer ref box -->
    <div style="margin:24px 0;padding:16px;background:' . esc_attr( $bg ) . ';border-left:4px solid ' . esc_attr( $accent ) . ';border-radius:0 8px 8px 0;">
      <p style="margin:0;font-size:11px;color:' . esc_attr( $text_muted ) . ';text-transform:uppercase;letter-spacing:.08em;">Transfer Reference</p>
      <p style="margin:4px 0 0;font-size:20px;font-weight:700;color:' . esc_attr( $accent ) . ';letter-spacing:1px;font-family:ui-monospace,SFMono-Regular,monospace;">' . esc_html( $transfer->transfer_ref ) . '</p>
    </div>
  </td></tr>

  <!-- Footer -->
  <tr><td style="padding:16px 32px;background:' . esc_attr( $bg ) . ';border-top:1px solid ' . esc_attr( $border ) . ';">
    <p style="margin:0;font-size:12px;color:' . esc_attr( $text_muted ) . ';text-align:center;line-height:1.6;">&copy; ' . esc_html( (string) $year ) . ' ' . esc_html( $site ) . $ffl_block . $footer_brand . '</p>
  </td></tr>

</table>
</td></tr>
</table>
</body></html>';
	}

	private static function dealer_block( object $transfer ): string {
		if ( ! $transfer->dealer_name ) {
			return '';
		}
		$theme  = \WpisticFFL\Theming::settings();
		$accent = $theme['color_primary'];
		$bg     = $theme['color_bg'];
		$border = $theme['color_border'];
		$text   = $theme['color_text'];
		$muted  = $theme['color_text_muted'];
		return '<div style="margin-top:16px;padding:12px 16px;background:' . esc_attr( $bg ) . ';border-radius:8px;border:1px solid ' . esc_attr( $border ) . ';">
			<p style="margin:0 0 4px;font-size:12px;font-weight:700;color:' . esc_attr( $accent ) . ';text-transform:uppercase;letter-spacing:.5px;">Your FFL Dealer</p>
			<p style="margin:0;font-weight:700;color:' . esc_attr( $text ) . ';">' . esc_html( $transfer->dealer_name ) . '</p>
			<p style="margin:2px 0 0;font-size:13px;color:' . esc_attr( $muted ) . ';">' . esc_html( $transfer->dealer_street . ', ' . $transfer->dealer_city . ', ' . $transfer->dealer_state . ' ' . $transfer->dealer_zip ) . '</p>'
			. ( $transfer->dealer_phone ? '<p style="margin:2px 0 0;font-size:13px;color:' . esc_attr( $muted ) . ';">📞 ' . esc_html( $transfer->dealer_phone ) . '</p>' : '' ) .
		'</div>';
	}

	// ──────────────────────────────────────────────────────────────────────────
	// v1.1.0 — Dealer confirmation portal email methods
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Send the "please confirm receipt" email to the receiving dealer.
	 *
	 * @param int    $transfer_id
	 * @param string $raw_token    Raw (un-hashed) token — gets baked into the URL.
	 */
	public static function send_dealer_confirmation_email( int $transfer_id, string $raw_token ): bool {
		$transfer = self::fetch_transfer_for_portal( $transfer_id );
		if ( ! $transfer ) {
			return false;
		}
		$dealer_email = self::dealer_email_for( $transfer );
		if ( is_wp_error( $dealer_email ) || ! $dealer_email ) {
			return false;
		}

		$theme         = \WpisticFFL\Theming::settings();
		$email_settings = get_option( 'wpistic_ffl_email_settings', [] );
		$portal_url    = \WpisticFFL\Token::build_url( $raw_token );
		$expiry_days   = (int) ( get_option( 'wpistic_ffl_portal_settings', [] )['token_expiry_days'] ?? 30 );

		$subject = $email_settings['confirmation_subject'] ?? '';
		if ( ! $subject ) {
			$subject = sprintf(
				/* translators: 1: store name, 2: transfer ref */
				__( 'Action Required: Confirm FFL Transfer Receipt — %1$s (#%2$s)', 'advanced-ffl-checkout' ),
				$theme['business_name'],
				$transfer->transfer_ref
			);
		} else {
			$subject = \WpisticFFL\Theming::replace_tags( $subject, [
				'{transfer_ref}' => $transfer->transfer_ref,
			] );
		}

		$intro = $email_settings['confirmation_intro'] ?? '';
		if ( ! $intro ) {
			$intro = __( 'A firearm shipment has been sent to your FFL for transfer to the customer below. Please confirm receipt by clicking the button below — no login required.', 'advanced-ffl-checkout' );
		}

		$body = self::render_portal_email( [
			'heading'     => __( 'Confirm Firearm Transfer Receipt', 'advanced-ffl-checkout' ),
			'intro'       => $intro,
			'transfer'    => $transfer,
			'portal_url'  => $portal_url,
			'expiry_days' => $expiry_days,
			'cta_label'   => __( 'Open Confirmation Portal', 'advanced-ffl-checkout' ),
			'theme'       => $theme,
			'is_reminder' => false,
		] );

		return self::send( $dealer_email, $subject, $body );
	}

	/**
	 * Reminder email — same layout, gentler subject, escalating urgency.
	 *
	 * @param int    $day_threshold  3 / 7 / 14
	 */
	public static function send_dealer_reminder_email( int $transfer_id, string $raw_token, int $day_threshold ): bool {
		$transfer = self::fetch_transfer_for_portal( $transfer_id );
		if ( ! $transfer ) {
			return false;
		}
		$dealer_email = self::dealer_email_for( $transfer );
		if ( is_wp_error( $dealer_email ) || ! $dealer_email ) {
			return false;
		}

		$theme          = \WpisticFFL\Theming::settings();
		$email_settings = get_option( 'wpistic_ffl_email_settings', [] );
		$portal_url     = \WpisticFFL\Token::build_url( $raw_token );
		$expiry_days    = (int) ( get_option( 'wpistic_ffl_portal_settings', [] )['token_expiry_days'] ?? 30 );

		$urgency = match ( true ) {
			$day_threshold >= 14 => __( 'Final reminder', 'advanced-ffl-checkout' ),
			$day_threshold >= 7  => __( 'Reminder', 'advanced-ffl-checkout' ),
			default              => __( 'Friendly reminder', 'advanced-ffl-checkout' ),
		};

		$subject = $email_settings['reminder_subject'] ?? '';
		if ( ! $subject ) {
			$subject = sprintf(
				/* translators: 1: urgency label, 2: transfer ref */
				__( '%1$s: FFL Transfer Confirmation Pending — #%2$s', 'advanced-ffl-checkout' ),
				$urgency,
				$transfer->transfer_ref
			);
		} else {
			$subject = \WpisticFFL\Theming::replace_tags( $subject, [
				'{transfer_ref}' => $transfer->transfer_ref,
				'{urgency}'      => $urgency,
			] );
		}

		$intro = $email_settings['reminder_intro'] ?? '';
		if ( ! $intro ) {
			$intro = sprintf(
				/* translators: %d: day count */
				__( 'This is a %s. We sent a firearm transfer to your FFL %d days ago but have not yet received confirmation that it arrived. Please confirm receipt — or report any issue — using the secure link below.', 'advanced-ffl-checkout' ),
				strtolower( $urgency ),
				$day_threshold
			);
		}

		$body = self::render_portal_email( [
			'heading'     => __( 'Confirmation Still Pending', 'advanced-ffl-checkout' ),
			'intro'       => $intro,
			'transfer'    => $transfer,
			'portal_url'  => $portal_url,
			'expiry_days' => $expiry_days,
			'cta_label'   => __( 'Confirm Receipt Now', 'advanced-ffl-checkout' ),
			'theme'       => $theme,
			'is_reminder' => true,
		] );

		return self::send( $dealer_email, $subject, $body );
	}

	/**
	 * Admin notification when the dealer confirms / reports an issue.
	 */
	public static function send_admin_portal_notification( object $transfer, string $event, string $notes = '' ): bool {
		$email_settings = get_option( 'wpistic_ffl_email_settings', [] );
		$to             = $email_settings['from_email'] ?? self::admin_email();

		$emoji = match ( $event ) {
			'confirmed'       => '✓',
			'report_issue'    => '⚠️',
			'not_my_shipment' => '✗',
			default           => '•',
		};

		$label = match ( $event ) {
			'confirmed'       => __( 'Dealer Confirmed Receipt', 'advanced-ffl-checkout' ),
			'report_issue'    => __( 'Dealer Reported Issue', 'advanced-ffl-checkout' ),
			'not_my_shipment' => __( 'Dealer Rejected Shipment', 'advanced-ffl-checkout' ),
			default           => __( 'Dealer Portal Update', 'advanced-ffl-checkout' ),
		};

		$subject = sprintf( '[%s] %s %s — #%s',
			get_bloginfo( 'name' ),
			$emoji,
			$label,
			$transfer->transfer_ref
		);

		$body = '<p><strong>' . esc_html( $label ) . '</strong></p>
			<p>' . esc_html( $transfer->dealer_name ?? 'Unknown dealer' ) . ' (FFL #' . esc_html( $transfer->dealer_license ?? '' ) . ') submitted this through the portal.</p>
			<p><strong>Transfer:</strong> #' . esc_html( $transfer->transfer_ref ) . '<br>
			<strong>Customer:</strong> ' . esc_html( $transfer->customer_name ) . '</p>'
			. ( $notes ? '<p><strong>Dealer notes:</strong><br>' . nl2br( esc_html( $notes ) ) . '</p>' : '' )
			. '<p><a href="' . esc_url( admin_url( 'admin.php?page=wpistic-ffl-transfers&id=' . $transfer->id ) ) . '" style="display:inline-block;padding:10px 20px;background:#111827;color:#fff;border-radius:6px;text-decoration:none;">View Transfer</a></p>';

		return self::send( $to, $subject, $body );
	}

	// ── Portal email helpers ──────────────────────────────────────────────────

	private static function fetch_transfer_for_portal( int $transfer_id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT t.*,
			        d.business_name AS dealer_name, d.license_number AS dealer_license,
			        d.premise_street AS dealer_street, d.premise_city AS dealer_city,
			        d.premise_state AS dealer_state, d.premise_zip AS dealer_zip,
			        d.phone AS dealer_phone
			 FROM ' . \WpisticFFL\DB::table( 'transfers' ) . ' t
			 LEFT JOIN ' . \WpisticFFL\DB::table( 'dealers' ) . ' d ON d.id = t.dealer_id
			 WHERE t.id = %d',
			$transfer_id
		) );
		return $row ?: null;
	}

	/**
	 * Figure out the dealer email address.
	 *
	 * Resolution order:
	 *   1. Dedicated `email` column on the dealers table.
	 *   2. Legacy regex match against the dealer `notes` field (`email: foo@bar`).
	 *   3. `wpistic_ffl_dealer_portal_email` filter for CRM / ATF API integrations.
	 *
	 * Returns WP_Error when none of the sources yield a usable address — the
	 * admin-email fallback was removed because magic-link portal URLs must not
	 * be delivered to a site admin who isn't the FFL dealer of record.
	 *
	 * @return string|\WP_Error
	 */
	private static function dealer_email_for( object $transfer ) {
		$dealer_email = '';

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
			'SELECT email, notes FROM ' . \WpisticFFL\DB::table( 'dealers' ) . ' WHERE id = %d',
			(int) $transfer->dealer_id
		) );

		if ( $row && ! empty( $row->email ) ) {
			$dealer_email = sanitize_email( (string) $row->email );
		}

		if ( ! $dealer_email && $row && ! empty( $row->notes ) && preg_match( '/email\s*[:=]\s*([^\s,;]+@[^\s,;]+)/i', (string) $row->notes, $m ) ) {
			$dealer_email = sanitize_email( $m[1] );
		}

		$dealer_email = apply_filters( 'wpistic_ffl_dealer_portal_email', $dealer_email, $transfer );

		if ( ! $dealer_email ) {
			self::log( sprintf(
				'dealer_email_for: no email on dealer #%d — portal magic link send skipped.',
				(int) $transfer->dealer_id
			) );
			return new \WP_Error(
				'wpistic_ffl_no_dealer_email',
				sprintf( 'No dealer email on file for dealer #%d.', (int) $transfer->dealer_id )
			);
		}

		return (string) $dealer_email;
	}

	/**
	 * Render the branded portal email (big CTA button, transfer details, expiry warning).
	 */
	private static function render_portal_email( array $ctx ): string {
		$theme       = $ctx['theme'];
		$transfer    = $ctx['transfer'];
		$portal_url  = $ctx['portal_url'];
		$expiry_days = $ctx['expiry_days'];
		$heading     = $ctx['heading'];
		$intro       = $ctx['intro'];
		$cta_label   = $ctx['cta_label'];

		$accent      = $theme['color_primary'] ?: '#DCB45F';
		$logo_url    = $theme['logo_url'];
		$store_name  = $theme['business_name'] ?: get_bloginfo( 'name' );
		$year        = date( 'Y' );

		$item = trim( ( $transfer->item_make ?? '' ) . ' ' . ( $transfer->item_model ?? '' ) );
		if ( ! $item ) $item = $transfer->item_description ?? '—';

		$customer_masked = \WpisticFFL\Theming::mask_name( (string) $transfer->customer_name );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $heading ); ?></title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#111827;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 6px 28px -6px rgba(0,0,0,.12);max-width:600px;width:100%;">

	<tr><td style="background:<?php echo esc_attr( $accent ); ?>;padding:28px 32px;text-align:center;">
		<?php if ( $logo_url ) : ?>
			<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $store_name ); ?>" height="48" style="display:inline-block;max-height:48px;">
		<?php else : ?>
			<span style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:-.01em;"><?php echo esc_html( $store_name ); ?></span>
		<?php endif; ?>
		<p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:12px;letter-spacing:.1em;text-transform:uppercase;">Secure FFL Transfer Portal</p>
	</td></tr>

	<tr><td style="padding:32px;">
		<h1 style="margin:0 0 12px;font-size:22px;color:#111827;letter-spacing:-.01em;"><?php echo esc_html( $heading ); ?></h1>
		<p style="margin:0 0 20px;font-size:15px;line-height:1.65;color:#374151;"><?php echo wp_kses_post( $intro ); ?></p>

		<!-- Transfer summary -->
		<table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;margin:16px 0 24px;">
			<tr><td style="padding:16px 20px;">
				<?php
				$rows = [
					__( 'Transfer Reference', 'advanced-ffl-checkout' ) => $transfer->transfer_ref,
					__( 'Item', 'advanced-ffl-checkout' )                => $item . ( ! empty( $transfer->item_caliber ) ? ' · ' . $transfer->item_caliber : '' ),
					__( 'Customer', 'advanced-ffl-checkout' )            => $customer_masked,
					__( 'Shipment', 'advanced-ffl-checkout' )            => trim( strtoupper( (string) $transfer->shipment_carrier ) . ' ' . (string) $transfer->shipment_tracking ) ?: '—',
					__( 'Shipped Date', 'advanced-ffl-checkout' )        => $transfer->shipped_date ? date_i18n( 'F j, Y', strtotime( (string) $transfer->shipped_date ) ) : '—',
				];
				foreach ( $rows as $k => $v ) :
				?>
					<div style="display:flex;justify-content:space-between;gap:16px;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:13px;">
						<span style="color:#6b7280;text-transform:uppercase;letter-spacing:.04em;font-size:11px;font-weight:600;"><?php echo esc_html( $k ); ?></span>
						<span style="color:#111827;font-weight:600;text-align:right;"><?php echo esc_html( (string) $v ); ?></span>
					</div>
				<?php endforeach; ?>
			</td></tr>
		</table>

		<!-- Primary CTA -->
		<table width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;">
			<tr><td align="center">
				<a href="<?php echo esc_url( $portal_url ); ?>" style="display:inline-block;background:<?php echo esc_attr( $accent ); ?>;color:#ffffff;padding:16px 36px;border-radius:10px;font-size:16px;font-weight:700;text-decoration:none;box-shadow:0 4px 14px -4px rgba(0,0,0,.25);"><?php echo esc_html( $cta_label ); ?></a>
			</td></tr>
		</table>

		<p style="margin:0;font-size:12px;color:#9ca3af;text-align:center;line-height:1.55;">
			<?php
			printf(
				/* translators: %d: days */
				esc_html__( 'This single-use link expires in %d days. If the button does not work, copy and paste this URL into your browser:', 'advanced-ffl-checkout' ),
				(int) $expiry_days
			);
			?>
			<br>
			<span style="word-break:break-all;font-family:ui-monospace,SFMono-Regular,monospace;color:#6b7280;"><?php echo esc_html( $portal_url ); ?></span>
		</p>
	</td></tr>

	<tr><td style="padding:16px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;">
		<p style="margin:0;font-size:12px;color:#6b7280;text-align:center;line-height:1.55;">
			<?php esc_html_e( 'You received this because your FFL is listed as the receiving dealer for the above transfer.', 'advanced-ffl-checkout' ); ?>
			<br>
			&copy; <?php echo esc_html( (string) $year ); ?> <?php echo esc_html( $store_name ); ?>
			<?php if ( \WpisticFFL\License::show_branding() ) : ?>
				· <span style="opacity:.75;">Powered by <a href="https://wordpressistic.com" style="color:<?php echo esc_attr( $accent ); ?>;text-decoration:none;">Wordpressistic</a></span>
			<?php endif; ?>
		</p>
	</td></tr>

</table>
</td></tr>
</table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}
}
