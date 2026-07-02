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
		$body    = $this->merge( $tpl['body_html'], $tags );
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
	 * Build merge tag map from a booking row + context.
	 */
	public function build_tags( $booking, $context = array() ) {
		$booking = is_object( $booking ) ? (array) $booking : (array) $booking;

		$site_url    = home_url( '/' );
		$biz_name    = get_option( 'g2ab_business_name', get_bloginfo( 'name' ) );
		$biz_phone   = get_option( 'g2ab_business_phone', '' );
		$biz_addr    = get_option( 'g2ab_business_address', '' );
		$brand_color = get_option( self::OPTION_BRAND_HEX, '#4A5D3A' );
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

	public function from_name() { return get_option( self::OPTION_FROM_NAME, get_option( 'g2ab_business_name', get_bloginfo( 'name' ) ) ); }
	public function from_addr() { return get_option( self::OPTION_FROM_ADDR, get_option( 'admin_email' ) ); }

	/**
	 * Wrap inner HTML in a tactical-themed responsive shell.
	 */
	public function wrap_html( $inner, $subject ) {
		$brand = esc_attr( get_option( self::OPTION_BRAND_HEX, '#4A5D3A' ) );
		$logo  = esc_url( get_option( self::OPTION_LOGO_URL, '' ) );
		$biz   = esc_html( get_option( 'g2ab_business_name', get_bloginfo( 'name' ) ) );
		$footer = wp_kses_post( get_option( self::OPTION_FOOTER, '' ) );
		if ( empty( $footer ) ) {
			$footer = sprintf( '<p style="margin:0;font-size:12px;color:#8A95A5;">&copy; %d %s. All rights reserved.</p>', date( 'Y' ), $biz );
		}

		$logo_html = $logo
			? sprintf( '<img src="%s" alt="%s" style="max-height:48px;height:auto;display:block;" />', $logo, $biz )
			: sprintf( '<strong style="color:#fff;font-family:Inter,\'Segoe UI\',Arial,sans-serif;font-size:24px;letter-spacing:.06em;">%s</strong>', $biz );

		return '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html( $subject ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#F4F5F7;font-family:Arial,Helvetica,sans-serif;color:#0F1115;">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#F4F5F7;padding:32px 16px;">'
			. '<tr><td align="center">'
			. '<table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="background:#fff;max-width:600px;width:100%;border-collapse:collapse;border-top:4px solid ' . $brand . ';">'
			. '<tr><td style="background:#0F1115;padding:24px 32px;">' . $logo_html . '</td></tr>'
			. '<tr><td style="padding:32px;font-size:15px;line-height:1.6;color:#0F1115;">' . $inner . '</td></tr>'
			. '<tr><td style="background:#F4F5F7;padding:20px 32px;border-top:1px solid #E2E5E9;">' . $footer . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Default templates per event.
	 */
	public function default_templates() {
		return array(
			'booking_created' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation received — {resource_name} on {start_at}',
				'body_html'          => '<h2 style="color:{brand_color};margin:0 0 16px;">Reservation Received</h2>'
					. '<p>Hi {customer_name},</p>'
					. '<p>We received your reservation request. Here are the details:</p>'
					. '<table style="width:100%;border-collapse:collapse;margin:16px 0;">'
					. '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Confirmation #</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{uuid}</td></tr>'
					. '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Lane / Service</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{resource_name}</td></tr>'
					. '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>When</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{start_at}</td></tr>'
					. '<tr><td style="padding:8px;border-bottom:1px solid #eee;"><strong>Duration</strong></td><td style="padding:8px;border-bottom:1px solid #eee;">{duration} min</td></tr>'
					. '<tr><td style="padding:8px;"><strong>Party size</strong></td><td style="padding:8px;">{party_size}</td></tr>'
					. '</table>'
					. '<p><a href="{pay_url}" style="display:inline-block;background:{brand_color};color:#fff;padding:14px 28px;text-decoration:none;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;">Complete Payment</a></p>'
					. '<p style="margin-top:24px;font-size:13px;color:#666;">Need to make changes? Reply to this email or call {business_phone}.</p>',
			),
			'booking_confirmed' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Mission confirmed — {resource_name} {start_at}',
				'body_html'          => '<h2 style="color:{brand_color};margin:0 0 16px;">You\'re Confirmed</h2>'
					. '<p>Hi {customer_name},</p>'
					. '<p>Your reservation is locked in. Bring this confirmation number with you:</p>'
					. '<p style="font-size:24px;font-family:monospace;background:#F4F5F7;padding:16px;text-align:center;letter-spacing:.1em;">{uuid}</p>'
					. '<p><strong>{resource_name}</strong><br>{start_at} ({duration} min)</p>'
					. '<p>Arrive 10 minutes early for safety briefing. Bring valid photo ID.</p>'
					. '<p>Address: {business_address}</p>'
					. '<p style="margin-top:24px;"><a href="{cancel_url}" style="color:#C62828;font-size:13px;">Cancel reservation</a></p>',
			),
			'booking_paid' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Payment received — ${amount} {currency}',
				'body_html'          => '<h2 style="color:{brand_color};margin:0 0 16px;">Payment Received</h2>'
					. '<p>Hi {customer_name},</p>'
					. '<p>We received your payment of <strong>${amount} {currency}</strong> for confirmation <code>{uuid}</code>.</p>'
					. '<p>Your invoice is attached and available online:</p>'
					. '<p><a href="{invoice_url}" style="display:inline-block;background:{brand_color};color:#fff;padding:14px 28px;text-decoration:none;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;">View Invoice</a></p>'
					. '<p style="margin-top:24px;font-size:13px;color:#666;">Thank you for your business.</p>',
			),
			'booking_reminder_24h' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Reminder — Your reservation tomorrow at {start_at}',
				'body_html'          => '<h2 style="color:{brand_color};margin:0 0 16px;">See You Tomorrow</h2>'
					. '<p>Hi {customer_name},</p>'
					. '<p>This is a quick reminder of your upcoming reservation:</p>'
					. '<p><strong>{resource_name}</strong><br>{start_at} ({duration} min)<br>Confirmation: <code>{uuid}</code></p>'
					. '<p><strong>What to bring:</strong> valid photo ID, eye and ear protection (or rent at counter), closed-toe shoes.</p>'
					. '<p>Need to reschedule? Reply to this email or call {business_phone}.</p>',
			),
			'booking_reminder_2h' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'See you in 2 hours — {resource_name}',
				'body_html'          => '<p>Hi {customer_name}, this is a quick heads-up that your reservation is in 2 hours.</p>'
					. '<p><strong>{resource_name}</strong> @ {start_at}<br>Confirmation: <code>{uuid}</code></p>'
					. '<p>Address: {business_address}<br>Phone: {business_phone}</p>',
			),
			'booking_cancelled' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation cancelled — {uuid}',
				'body_html'          => '<h2 style="color:#C62828;margin:0 0 16px;">Reservation Cancelled</h2>'
					. '<p>Hi {customer_name},</p>'
					. '<p>Your reservation <code>{uuid}</code> for {resource_name} on {start_at} has been cancelled.</p>'
					. '<p>If a refund is owed, it will be processed back to your original payment method within 3-5 business days.</p>'
					. '<p>Want to rebook? <a href="{site_url}" style="color:{brand_color};">Visit our site</a></p>',
			),
			'booking_no_show' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Missed reservation — {uuid}',
				'body_html'          => '<p>Hi {customer_name},</p>'
					. '<p>We had your reservation for {resource_name} on {start_at} but didn\'t see you at the range.</p>'
					. '<p>If something came up, please call {business_phone} so we can reschedule. Per our policy, a no-show fee may apply.</p>',
			),
			'booking_completed' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Thanks for visiting — leave us a review?',
				'body_html'          => '<h2 style="color:{brand_color};margin:0 0 16px;">Thanks for Visiting</h2>'
					. '<p>Hi {customer_name},</p>'
					. '<p>Thanks for choosing {business_name} for your range time. We hope you had a great session.</p>'
					. '<p>If you enjoyed it, would you take 30 seconds to leave us a review on Google? It really helps.</p>'
					. '<p><a href="https://search.google.com/local/writereview?placeid=" style="display:inline-block;background:{brand_color};color:#fff;padding:14px 28px;text-decoration:none;font-weight:bold;text-transform:uppercase;">Leave a Review</a></p>',
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
