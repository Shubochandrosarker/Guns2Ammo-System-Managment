<?php
/**
 * G2AB_Email_Engine — renders templates + sends via wp_mail.
 *
 * Templates are stored in the g2ab_email_templates option as an associative
 * array keyed by event id. Each template is { subject, body_html, enabled,
 * recipient_admin, recipient_customer }.
 *
 * Merge tags supported in subject + body:
 *   {customer_name}, {customer_email}, {customer_phone},
 *   {booking_id}, {uuid}, {resource_name}, {start_at}, {end_at},
 *   {duration}, {party_size}, {amount}, {currency},
 *   {business_name}, {business_phone}, {business_address},
 *   {invoice_url}, {pay_url}, {cancel_url},
 *   {site_url}, {date_now}, {brand_color}, {brand_logo_url}
 *
 * @package G2AB\Modules\EmailAutomation
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_Email_Engine {

	const OPTION_TEMPLATES = 'g2ab_email_templates';
	const OPTION_FROM_NAME = 'g2ab_email_from_name';
	// Canonical option keys must match what the settings UI saves
	// (see includes/admin/class-settings-pro.php).
	const OPTION_FROM_ADDR = 'g2ab_email_from_address';
	const OPTION_ADMIN_TO  = 'g2ab_admin_notification_email';
	const OPTION_LOGO_URL  = 'g2ab_email_logo_url';
	const OPTION_BRAND_HEX = 'g2ab_email_brand_color';
	const OPTION_FOOTER    = 'g2ab_email_footer_html';

	/**
	 * Send an event email. Looks up template, merges tags, sends to customer
	 * + admin per template config.
	 */
	public function send_event( $event, $booking, $context = array() ) {
		$tpl = $this->get_template( $event );
		if ( empty( $tpl ) || empty( $tpl['enabled'] ) ) return false;

		$tags = $this->build_tags( $booking, $context );
		$subject = $this->merge( $tpl['subject'], $tags );
		// Customer-sourced values are escaped before substitution into the HTML
		// body so stored markup in a booking can't inject HTML into inboxes.
		$body    = $this->merge( $tpl['body_html'], $this->escape_customer_tags( $tags ) );
		$body    = $this->wrap_html( $body, $subject );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name(), $this->from_addr() ),
		);

		$attachments = apply_filters( 'g2ab_email_attachments', array(), $event, $booking, $context );

		$results = array();

		// Customer.
		if ( ! empty( $tpl['recipient_customer'] ) && ! empty( $tags['customer_email'] ) ) {
			$results['customer'] = wp_mail( $tags['customer_email'], $subject, $body, $headers, $attachments );
		}

		// Admin.
		if ( ! empty( $tpl['recipient_admin'] ) ) {
			$admin_email = get_option( self::OPTION_ADMIN_TO, get_option( 'admin_email' ) );
			$admin_subject = '[Admin] ' . $subject;
			$results['admin'] = wp_mail( $admin_email, $admin_subject, $body, $headers, $attachments );
		}

		do_action( 'g2ab_email_sent', $event, $booking, $tpl, $results );
		return $results;
	}

	/**
	 * Get template for a given event, falling back to defaults.
	 */
	public function get_template( $event ) {
		$saved = get_option( self::OPTION_TEMPLATES, array() );
		if ( isset( $saved[ $event ] ) ) {
			return wp_parse_args( $saved[ $event ], $this->default_templates()[ $event ] ?? array() );
		}
		$defaults = $this->default_templates();
		return isset( $defaults[ $event ] ) ? $defaults[ $event ] : null;
	}

	/**
	 * Save a template.
	 */
	public function save_template( $event, $data ) {
		$saved = get_option( self::OPTION_TEMPLATES, array() );
		$saved[ $event ] = wp_parse_args( $data, array(
			'enabled'            => 1,
			'subject'            => '',
			'body_html'          => '',
			'recipient_customer' => 1,
			'recipient_admin'    => 0,
		) );
		update_option( self::OPTION_TEMPLATES, $saved );
	}

	/**
	 * Send a custom email (used by reminder cron + AI auto-reply).
	 */
	public function send_custom( $to, $subject, $body, $attachments = array() ) {
		$body = $this->wrap_html( $body, $subject );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name(), $this->from_addr() ),
		);
		return wp_mail( $to, $subject, $body, $headers, $attachments );
	}

	/**
	 * Merge {tags} into a string.
	 */
	public function merge( $template, $tags ) {
		$out = $template;
		foreach ( $tags as $k => $v ) {
			$out = str_replace( '{' . $k . '}', (string) $v, $out );
		}
		return $out;
	}

	/**
	 * esc_html() the customer-sourced merge values for substitution into HTML
	 * bodies. Admin-authored template markup is left untouched.
	 */
	private function escape_customer_tags( $tags ) {
		foreach ( array( 'customer_name', 'customer_email', 'customer_phone' ) as $k ) {
			if ( isset( $tags[ $k ] ) ) {
				$tags[ $k ] = esc_html( (string) $tags[ $k ] );
			}
		}
		return $tags;
	}

	/**
	 * Build merge tag map from a booking row + context.
	 */
	public function build_tags( $booking, $context = array() ) {
		$booking = is_object( $booking ) ? (array) $booking : (array) $booking;

		$site_url    = home_url( '/' );
		$biz_name    = g2ab_business_name();
		$biz_phone   = g2ab_business_phone();
		$biz_addr    = g2ab_business_address();
		$brand_color = get_option( self::OPTION_BRAND_HEX, '#E8802F' );
		$brand_logo  = get_option( self::OPTION_LOGO_URL, '' );

		$uuid = isset( $booking['uuid'] ) ? $booking['uuid'] : '';
		$invoice_url = $uuid ? add_query_arg( 'g2ab_invoice', $uuid, $site_url ) : '';
		$pay_url     = isset( $context['pay_url'] ) ? $context['pay_url'] : '';
		$cancel_url  = $uuid ? add_query_arg( array( 'g2ab_cancel' => $uuid ), $site_url ) : '';

		$customer_name  = '';
		$customer_email = '';
		$customer_phone = '';
		if ( ! empty( $booking['fields'] ) ) {
			$fields = is_string( $booking['fields'] ) ? json_decode( $booking['fields'], true ) : $booking['fields'];
			if ( is_array( $fields ) ) {
				$customer_name  = $fields['name'] ?? trim( ( $fields['first_name'] ?? '' ) . ' ' . ( $fields['last_name'] ?? '' ) );
				$customer_email = $fields['email'] ?? '';
				$customer_phone = $fields['phone'] ?? '';
			}
		}
		if ( empty( $customer_email ) && ! empty( $booking['customer_email'] ) ) $customer_email = $booking['customer_email'];

		return array(
			'customer_name'    => $customer_name ?: 'Guest',
			'customer_email'   => $customer_email,
			'customer_phone'   => $customer_phone,
			'booking_id'       => isset( $booking['id'] ) ? (int) $booking['id'] : 0,
			'uuid'             => $uuid,
			'resource_name'    => $booking['resource_name'] ?? ( $context['resource_name'] ?? '' ),
			'start_at'         => isset( $booking['start_at'] ) ? $this->format_dt( $booking['start_at'] ) : '',
			'end_at'           => isset( $booking['end_at'] ) ? $this->format_dt( $booking['end_at'] ) : '',
			'duration'         => isset( $booking['duration_min'] ) ? (int) $booking['duration_min'] : 60,
			'party_size'       => isset( $booking['party_size'] ) ? (int) $booking['party_size'] : 1,
			'amount'           => isset( $booking['amount'] ) ? number_format( (float) $booking['amount'], 2 ) : '0.00',
			'currency'         => get_option( 'g2ab_currency', 'USD' ),
			'business_name'    => $biz_name,
			'business_phone'   => $biz_phone,
			'business_address' => $biz_addr,
			'invoice_url'      => $invoice_url,
			'pay_url'          => $pay_url,
			'cancel_url'       => $cancel_url,
			'site_url'         => $site_url,
			'date_now'         => date_i18n( get_option( 'date_format' ) ),
			'brand_color'      => $brand_color,
			'brand_logo_url'   => $brand_logo,
		);
	}

	private function format_dt( $iso ) {
		$ts = strtotime( $iso );
		if ( ! $ts ) return $iso;
		return date_i18n( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ), $ts );
	}

	public function from_name() { return get_option( self::OPTION_FROM_NAME, g2ab_business_name() ); }
	public function from_addr() { return get_option( self::OPTION_FROM_ADDR, get_option( 'admin_email' ) ); }

	/**
	 * Wrap inner HTML in a modernized, brass/ember-branded responsive shell.
	 *
	 * Table-based layout with inline styles throughout (required for Outlook/
	 * older email-client compatibility — no external stylesheet, no flexbox/
	 * grid). Rounded corners and shadows degrade gracefully to square corners
	 * on clients that don't support them; nothing depends on them rendering.
	 */
	public function wrap_html( $inner, $subject ) {
		$brand = esc_attr( get_option( self::OPTION_BRAND_HEX, '#E8802F' ) );
		$logo  = esc_url( get_option( self::OPTION_LOGO_URL, '' ) );
		$biz   = esc_html( g2ab_business_name() );
		$phone = trim( (string) g2ab_business_phone() );
		$addr  = trim( (string) g2ab_business_address() );

		$footer = wp_kses_post( get_option( self::OPTION_FOOTER, '' ) );
		if ( empty( $footer ) ) {
			$nap_line = trim( implode( ' · ', array_filter( array( $addr, $phone ) ) ) );
			$footer   = '<p style="margin:0 0 6px;font-size:13px;font-weight:600;color:#2E2D33;">' . $biz . '</p>'
				. ( $nap_line ? '<p style="margin:0 0 10px;font-size:12px;color:#6E6C74;">' . esc_html( $nap_line ) . '</p>' : '' )
				. '<p style="margin:0;font-size:11px;color:#9A98A0;">&copy; ' . esc_html( date( 'Y' ) ) . ' ' . $biz . '. All rights reserved.</p>';
		}

		$logo_html = $logo
			? sprintf( '<img src="%s" alt="%s" style="max-height:44px;height:auto;display:block;" />', $logo, $biz )
			: sprintf( '<strong style="color:#F7F7F9;font-family:\'Inter\',\'Segoe UI\',Arial,sans-serif;font-size:22px;letter-spacing:.04em;">%s</strong>', $biz );

		return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#EEEDE7;font-family:\'Inter\',\'Segoe UI\',Roboto,Arial,Helvetica,sans-serif;color:#1A191E;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#EEEDE7;padding:40px 16px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:#FFFFFF;max-width:600px;width:100%;border-collapse:separate;border-spacing:0;border-radius:16px;overflow:hidden;box-shadow:0 8px 32px rgba(26,25,30,0.10);">'
			// Header — void background, brand-color underline, logo/name.
			. '<tr><td style="background:#1A191E;padding:28px 32px;border-bottom:3px solid ' . $brand . ';">' . $logo_html . '</td></tr>'
			// Body — generous padding, comfortable line-height, modern type.
			. '<tr><td style="padding:36px 32px;font-size:15px;line-height:1.65;color:#1A191E;">' . $inner . '</td></tr>'
			// Footer — full NAP, muted, on a soft neutral tint with a hairline divider.
			. '<tr><td style="background:#F7F6F2;padding:24px 32px;border-top:1px solid #E7E5DE;">' . $footer . '</td></tr>'
			. '</table>'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;width:100%;">'
			. '<tr><td align="center" style="padding:18px 16px 0;font-size:11px;color:#9A98A0;">You\'re receiving this because you have an active reservation with ' . $biz . '.</td></tr>'
			. '</table>'
			. '</td></tr></table></body></html>';
	}

	/**
	 * Default templates per event.
	 */
	public function default_templates() {
		// Shared building blocks (kept as constants-in-code, not extracted
		// methods, since these are one-time-assembled array literals).
		$h2      = 'font-size:20px;font-weight:700;letter-spacing:-.01em;margin:0 0 18px;';
		$cta     = 'display:inline-block;background:{brand_color};color:#fff;padding:13px 26px;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px;';
		$muted   = 'margin-top:28px;font-size:13px;color:#6E6C74;';
		$row     = 'padding:12px 0;border-bottom:1px solid #EEEDE7;';
		$label   = 'font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#9A98A0;';
		$value   = 'font-size:14px;font-weight:600;color:#1A191E;';

		return array(
			'booking_created' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation received — {resource_name} on {start_at}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">Reservation Received</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">We received your reservation request. Here are the details:</p>'
					. '<table role="presentation" style="width:100%;border-collapse:collapse;">'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Confirmation #</div><div style="' . $value . '">{uuid}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Lane / Service</div><div style="' . $value . '">{resource_name}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">When</div><div style="' . $value . '">{start_at}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Duration</div><div style="' . $value . '">{duration} min</div></td></tr>'
					. '<tr><td style="padding:12px 0;"><div style="' . $label . '">Party size</div><div style="' . $value . '">{party_size}</div></td></tr>'
					. '</table>'
					. '<p style="margin:24px 0 0;"><a href="{pay_url}" style="' . $cta . '">Complete payment</a></p>'
					. '<p style="' . $muted . '">Need to make changes? Reply to this email or call {business_phone}.</p>',
			),
			'booking_confirmed' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Mission confirmed — {resource_name} {start_at}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">You\'re Confirmed</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Your reservation is locked in. Bring this confirmation number with you:</p>'
					. '<p style="font-size:22px;font-family:\'SF Mono\',Consolas,monospace;font-weight:700;background:#F7F6F2;border-radius:10px;padding:18px;text-align:center;letter-spacing:.08em;color:{brand_color};margin:0 0 20px;">{uuid}</p>'
					. '<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 20px;">'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Lane / Service</div><div style="' . $value . '">{resource_name}</div></td></tr>'
					. '<tr><td style="padding:12px 0;"><div style="' . $label . '">When</div><div style="' . $value . '">{start_at} · {duration} min</div></td></tr>'
					. '</table>'
					. '<p style="margin:0 0 8px;color:#3A3940;">Arrive 10 minutes early for the safety briefing. Bring valid photo ID.</p>'
					. '<p style="margin:0 0 4px;font-size:13px;color:#6E6C74;">📍 {business_address}</p>'
					. '<p style="margin-top:24px;"><a href="{cancel_url}" style="color:#C62828;font-size:13px;text-decoration:none;">Cancel reservation →</a></p>',
			),
			'booking_paid' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Payment received — ${amount} {currency}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">Payment Received</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">We received your payment of <strong style="color:#1A191E;">${amount} {currency}</strong> for confirmation <code style="background:#F7F6F2;padding:2px 6px;border-radius:4px;">{uuid}</code>.</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Your invoice is attached and available online:</p>'
					. '<p style="margin:0;"><a href="{invoice_url}" style="' . $cta . '">View invoice</a></p>'
					. '<p style="' . $muted . '">Thank you for your business.</p>',
			),
			'booking_reminder_24h' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Reminder — Your reservation tomorrow at {start_at}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">See You Tomorrow</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">This is a quick reminder of your upcoming reservation:</p>'
					. '<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 20px;">'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Lane / Service</div><div style="' . $value . '">{resource_name}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">When</div><div style="' . $value . '">{start_at} · {duration} min</div></td></tr>'
					. '<tr><td style="padding:12px 0;"><div style="' . $label . '">Confirmation</div><div style="' . $value . '">{uuid}</div></td></tr>'
					. '</table>'
					. '<p style="margin:0 0 8px;color:#3A3940;"><strong style="color:#1A191E;">What to bring:</strong> valid photo ID, eye and ear protection (or rent at the counter), closed-toe shoes.</p>'
					. '<p style="' . $muted . '">Need to reschedule? Reply to this email or call {business_phone}.</p>',
			),
			'booking_reminder_2h' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'See you in 2 hours — {resource_name}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">See You Soon</h2>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Hi {customer_name}, this is a quick heads-up that your reservation is in 2 hours.</p>'
					. '<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 20px;">'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Lane / Service</div><div style="' . $value . '">{resource_name}</div></td></tr>'
					. '<tr><td style="padding:12px 0;"><div style="' . $label . '">When</div><div style="' . $value . '">{start_at}</div></td></tr>'
					. '</table>'
					. '<p style="margin:0 0 4px;font-size:13px;color:#6E6C74;">Confirmation: {uuid}</p>'
					. '<p style="margin:0;font-size:13px;color:#6E6C74;">📍 {business_address} · {business_phone}</p>',
			),
			'booking_cancelled' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation cancelled — {uuid}',
				'body_html'          => '<h2 style="' . $h2 . 'color:#C62828;">Reservation Cancelled</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Your reservation <code style="background:#F7F6F2;padding:2px 6px;border-radius:4px;">{uuid}</code> for {resource_name} on {start_at} has been cancelled.</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">If a refund is owed, it will be processed back to your original payment method within 3-5 business days.</p>'
					. '<p style="margin:0;"><a href="{site_url}" style="' . $cta . '">Want to rebook? Visit our site</a></p>',
			),
			'booking_no_show' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Missed reservation — {uuid}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">We Missed You</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">We had your reservation for {resource_name} on {start_at} but didn\'t see you at the range.</p>'
					. '<p style="margin:0;color:#3A3940;">If something came up, please call {business_phone} so we can reschedule. Per our policy, a no-show fee may apply.</p>',
			),
			'booking_completed' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Thanks for visiting — leave us a review?',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">Thanks for Visiting</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Thanks for choosing {business_name} for your range time. We hope you had a great session.</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">If you enjoyed it, would you take 30 seconds to leave us a review on Google? It really helps.</p>'
					. '<p style="margin:0;"><a href="https://search.google.com/local/writereview?placeid=" style="' . $cta . '">Leave a review</a></p>',
			),
		);
	}

	public function event_labels() {
		return array(
			'booking_created'      => 'Booking Created (sent on form submit)',
			'booking_confirmed'    => 'Booking Confirmed (after admin approval)',
			'booking_paid'         => 'Payment Received',
			'booking_reminder_24h' => 'Reminder — 24 hours before',
			'booking_reminder_2h'  => 'Reminder — 2 hours before',
			'booking_cancelled'    => 'Booking Cancelled',
			'booking_no_show'      => 'No-Show Notice',
			'booking_completed'    => 'Post-Visit Review Request',
		);
	}
}
