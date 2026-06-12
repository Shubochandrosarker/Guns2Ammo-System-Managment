<?php
/**
 * Branded transactional emails — a reusable Guns 2 Ammo email shell
 * (logo header / brass rules / footer) plus the newsletter welcome email.
 *
 * The shell is intentionally generic: any other plugin email can call
 * WPISTIC_CF_Emails::wrap( $inner_html ) to get the same premium frame,
 * and WPISTIC_CF_Emails::send() to dispatch it as HTML with the correct
 * From headers and the internal-send flag set.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Branded email shell + newsletter welcome sender.
 */
class WPISTIC_CF_Emails {

	/** Brand palette. */
	const COLOR_DARK  = '#1A191E'; // Header band / headings.
	const COLOR_BRASS = '#C9A84C'; // Accent rules.
	const COLOR_EMBER = '#E8802F'; // CTA button.

	/** Inline font stack used across every branded email. */
	const FONT_STACK = "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

	/**
	 * Register hooks — send the newsletter welcome on first-ever subscribe.
	 */
	public function register() {
		add_action( 'wpcf_newsletter_subscribed', [ __CLASS__, 'send_newsletter_welcome' ], 10, 2 );
	}

	/**
	 * Public URL of the bundled brand logo.
	 *
	 * @return string
	 */
	public static function logo_url() {
		/**
		 * Filter the logo used in the branded email header.
		 *
		 * @param string $url Logo image URL.
		 */
		return apply_filters( 'wpcf_email_logo_url', WPISTIC_CF_URL . 'assets/img/guns2ammo-logo.png' );
	}

	/**
	 * Business info line shown in the email footer.
	 *
	 * @return string
	 */
	public static function business_line() {
		/**
		 * Filter the footer business line of branded emails.
		 *
		 * @param string $line Plain-text business line.
		 */
		return apply_filters(
			'wpcf_email_business_line',
			'Guns 2 Ammo &middot; 6030 E Main St, Ste 103, Mesa, AZ 85205 &middot; (602) 715-2677'
		);
	}

	/**
	 * Render the ember CTA button.
	 *
	 * @param string $label Button text.
	 * @param string $url   Target URL.
	 * @return string HTML.
	 */
	public static function button( $label, $url ) {
		return '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:28px auto 8px;">'
			. '<tr><td align="center" bgcolor="' . esc_attr( self::COLOR_EMBER ) . '" style="border-radius:6px;">'
			. '<a href="' . esc_url( $url ) . '" target="_blank" '
			. 'style="display:inline-block; padding:14px 36px; font-family:' . self::FONT_STACK . '; '
			. 'font-size:15px; font-weight:700; letter-spacing:0.08em; text-transform:uppercase; '
			. 'color:#FFFFFF; text-decoration:none; border-radius:6px; background-color:' . esc_attr( self::COLOR_EMBER ) . ';">'
			. esc_html( $label )
			. '</a></td></tr></table>';
	}

	/**
	 * Wrap inner HTML in the branded 600px shell: dark header band with the
	 * centered logo, brass accent rules, white body card, and footer with
	 * business info + an unsubscribe note.
	 *
	 * @param string $inner_html Pre-escaped body markup.
	 * @param array  $args       {
	 *     Optional overrides.
	 *     @type string $preheader        Hidden preview text.
	 *     @type string $unsubscribe_note Footer unsubscribe line (HTML allowed).
	 * }
	 * @return string Full HTML document.
	 */
	public static function wrap( $inner_html, $args = [] ) {
		$args = wp_parse_args( $args, [
			'preheader'        => '',
			'unsubscribe_note' => sprintf(
				/* translators: %s: site URL */
				esc_html__( "You're receiving this email because you signed up for range updates at %s. Don't want these emails? Reply with \u{201C}unsubscribe\u{201D} and we'll take you off the list.", 'wpistic-contact-form' ),
				esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ?: home_url() )
			),
		] );

		$logo      = self::logo_url();
		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$font      = self::FONT_STACK;

		$preheader_html = '';
		if ( '' !== $args['preheader'] ) {
			$preheader_html = '<div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">'
				. esc_html( $args['preheader'] )
				. '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>';
		}

		$html  = '<!DOCTYPE html>';
		$html .= '<html lang="en"><head><meta charset="utf-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$html .= '<title>' . esc_html( $site_name ) . '</title></head>';
		$html .= '<body style="margin:0; padding:0; background-color:#F2F1EE;">';
		$html .= $preheader_html;

		// Outer canvas.
		$html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" bgcolor="#F2F1EE" style="background-color:#F2F1EE;">';
		$html .= '<tr><td align="center" style="padding:24px 12px;">';

		// 600px card.
		$html .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="600" style="width:600px; max-width:100%; border-collapse:collapse;">';

		// Header band — dark with centered logo and brass bottom rule.
		$html .= '<tr><td align="center" bgcolor="' . esc_attr( self::COLOR_DARK ) . '" '
			. 'style="background-color:' . esc_attr( self::COLOR_DARK ) . '; padding:28px 24px; '
			. 'border-radius:10px 10px 0 0; border-bottom:3px solid ' . esc_attr( self::COLOR_BRASS ) . ';">';
		$html .= '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site_name ) . '" width="190" '
			. 'style="display:block; width:190px; max-width:60%; height:auto; margin:0 auto; border:0;">';
		$html .= '</td></tr>';

		// Body card.
		$html .= '<tr><td bgcolor="#FFFFFF" style="background-color:#FFFFFF; padding:36px 40px 32px; '
			. 'font-family:' . $font . '; font-size:16px; line-height:1.65; color:#33323A;">';
		$html .= $inner_html;
		$html .= '</td></tr>';

		// Brass rule above the footer.
		$html .= '<tr><td style="height:3px; line-height:3px; font-size:0; background-color:' . esc_attr( self::COLOR_BRASS ) . ';">&nbsp;</td></tr>';

		// Footer.
		$html .= '<tr><td bgcolor="' . esc_attr( self::COLOR_DARK ) . '" align="center" '
			. 'style="background-color:' . esc_attr( self::COLOR_DARK ) . '; padding:24px 32px 28px; border-radius:0 0 10px 10px;">';
		$html .= '<p style="margin:0 0 10px; font-family:' . $font . '; font-size:13px; font-weight:700; '
			. 'letter-spacing:0.04em; color:' . esc_attr( self::COLOR_BRASS ) . ';">' . self::business_line() . '</p>';
		$html .= '<p style="margin:0; font-family:' . $font . '; font-size:12px; line-height:1.6; color:#9B99A4;">'
			. wp_kses( $args['unsubscribe_note'], [ 'a' => [ 'href' => [], 'style' => [] ], 'br' => [] ] )
			. '</p>';
		$html .= '</td></tr>';

		$html .= '</table></td></tr></table></body></html>';

		/**
		 * Filter the fully assembled branded email HTML.
		 *
		 * @param string $html       Full HTML document.
		 * @param string $inner_html Body markup that was wrapped.
		 * @param array  $args       Wrap args.
		 */
		return apply_filters( 'wpcf_email_wrapped_html', $html, $inner_html, $args );
	}

	/**
	 * Send a branded HTML email with correct Content-Type + From headers,
	 * flagged as internal so the wp_mail capture doesn't loop on it.
	 *
	 * @param string|array $to      Recipient(s).
	 * @param string       $subject Subject line.
	 * @param string       $html    Full HTML document (use wrap()).
	 * @param array        $headers Extra headers.
	 * @return bool
	 */
	public static function send( $to, $subject, $html, $headers = [] ) {
		$from_name  = get_option( 'WPISTIC_CF_reply_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'WPISTIC_CF_reply_from_email', get_option( 'admin_email' ) );

		$headers   = (array) $headers;
		$headers[] = 'Content-Type: text/html; charset=UTF-8';
		if ( is_email( $from_email ) ) {
			$headers[] = sprintf( 'From: %s <%s>', $from_name, $from_email );
			$headers[] = 'Reply-To: ' . $from_email;
		}

		return WPISTIC_CF_Capture::send_internal( $to, $subject, $html, $headers );
	}

	/**
	 * Whether outbound plugin email is globally disabled (staging guard).
	 *
	 * @return bool
	 */
	protected static function emails_disabled() {
		return ( defined( 'WPCF_EMAIL_DISABLED' ) && WPCF_EMAIL_DISABLED )
			|| (bool) get_option( 'WPISTIC_CF_emails_disabled', false );
	}

	/**
	 * Send the newsletter welcome/confirmation email to a new subscriber.
	 *
	 * Fired on `wpcf_newsletter_subscribed`, which WPISTIC_CF_Newsletter only
	 * emits on a brand-new row (the email column is UNIQUE; duplicates and
	 * re-activations never fire it) — so each subscriber gets exactly one
	 * welcome. A short transient adds a belt-and-braces guard against
	 * double-fire races.
	 *
	 * @param string $email  Subscriber email.
	 * @param string $source Signup source tag.
	 * @return bool Whether a send was attempted successfully.
	 */
	public static function send_newsletter_welcome( $email, $source = '' ) {
		if ( self::emails_disabled() ) {
			return false;
		}
		if ( '1' !== get_option( 'WPISTIC_CF_nl_welcome_enabled', '1' ) ) {
			return false;
		}
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return false;
		}

		// Dedupe guard — never double-send to the same address.
		$guard = 'wpcf_nl_welcome_' . md5( strtolower( $email ) );
		if ( get_transient( $guard ) ) {
			return false;
		}
		set_transient( $guard, 1, DAY_IN_SECONDS );

		$subject = (string) get_option(
			'WPISTIC_CF_nl_welcome_subject',
			__( 'Welcome to the Guns 2 Ammo range list 🎯', 'wpistic-contact-form' )
		);
		if ( '' === trim( $subject ) ) {
			$subject = __( 'Welcome to the Guns 2 Ammo range list 🎯', 'wpistic-contact-form' );
		}

		/**
		 * Filter the newsletter welcome subject line.
		 *
		 * @param string $subject Subject.
		 * @param string $email   Subscriber email.
		 * @param string $source  Signup source.
		 */
		$subject = apply_filters( 'wpcf_newsletter_welcome_subject', $subject, $email, $source );

		$html = self::wrap(
			self::newsletter_welcome_body(),
			[
				'preheader' => __( "You're on the list — range updates, Ladies Tuesday, class dates and member deals are headed your way.", 'wpistic-contact-form' ),
			]
		);

		/**
		 * Filter the full newsletter welcome HTML before sending.
		 *
		 * @param string $html  Full HTML document.
		 * @param string $email Subscriber email.
		 */
		$html = apply_filters( 'wpcf_newsletter_welcome_html', $html, $email );

		$sent = self::send( $email, $subject, $html );

		/**
		 * Fires after the newsletter welcome email is dispatched.
		 *
		 * @param string $email Subscriber email.
		 * @param bool   $sent  wp_mail result.
		 */
		do_action( 'wpcf_newsletter_welcome_sent', $email, $sent );

		return $sent;
	}

	/**
	 * Inner body markup for the newsletter welcome email.
	 *
	 * @return string HTML.
	 */
	protected static function newsletter_welcome_body() {
		$font  = self::FONT_STACK;
		$brass = self::COLOR_BRASS;
		$dark  = self::COLOR_DARK;

		$items = [
			__( '<strong>Range updates</strong> — lane availability, hours and what&#8217;s new on the line', 'wpistic-contact-form' ),
			__( '<strong>Ladies Tuesday</strong> — weekly ladies&#8217; night details and specials', 'wpistic-contact-form' ),
			__( '<strong>Class dates</strong> — CCW, defensive pistol and first-shots courses as they open', 'wpistic-contact-form' ),
			__( '<strong>Member deals</strong> — subscriber-only offers and membership perks', 'wpistic-contact-form' ),
		];

		$list = '';
		foreach ( $items as $item ) {
			$list .= '<tr>'
				. '<td valign="top" style="padding:7px 12px 7px 0; font-family:' . $font . '; font-size:18px; line-height:1.5; color:' . esc_attr( $brass ) . ';">&#9656;</td>'
				. '<td valign="top" style="padding:7px 0; font-family:' . $font . '; font-size:15px; line-height:1.6; color:#33323A;">'
				. wp_kses( $item, [ 'strong' => [] ] )
				. '</td></tr>';
		}

		$body  = '<h1 style="margin:0 0 6px; font-family:' . $font . '; font-size:26px; line-height:1.3; font-weight:800; color:' . esc_attr( $dark ) . ';">'
			. esc_html__( "You're on the list. 🎯", 'wpistic-contact-form' ) . '</h1>';
		$body .= '<div style="width:56px; height:3px; background-color:' . esc_attr( $brass ) . '; margin:14px 0 22px;"></div>';
		$body .= '<p style="margin:0 0 16px; font-family:' . $font . '; font-size:16px; line-height:1.65; color:#33323A;">'
			. esc_html__( "Welcome to the Guns 2 Ammo range family — we're glad to have you. From now on you'll be the first to hear what's happening on the range here in Mesa.", 'wpistic-contact-form' )
			. '</p>';
		$body .= '<p style="margin:0 0 8px; font-family:' . $font . '; font-size:13px; font-weight:700; letter-spacing:0.1em; text-transform:uppercase; color:' . esc_attr( $brass ) . ';">'
			. esc_html__( "What you'll get", 'wpistic-contact-form' ) . '</p>';
		$body .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 10px;">' . $list . '</table>';
		$body .= '<p style="margin:18px 0 0; font-family:' . $font . '; font-size:16px; line-height:1.65; color:#33323A;">'
			. esc_html__( 'Ready to send some rounds downrange? Grab a lane and come see us.', 'wpistic-contact-form' )
			. '</p>';
		$body .= self::button( __( 'Book A Lane', 'wpistic-contact-form' ), 'https://guns2ammo.com/book-a-lane/' );
		$body .= '<p style="margin:22px 0 0; font-family:' . $font . '; font-size:15px; line-height:1.6; color:#6E6C78;">'
			. esc_html__( 'See you on the line,', 'wpistic-contact-form' ) . '<br>'
			. '<strong style="color:' . esc_attr( $dark ) . ';">' . esc_html__( 'The Guns 2 Ammo Team', 'wpistic-contact-form' ) . '</strong>'
			. '</p>';

		return $body;
	}
}
