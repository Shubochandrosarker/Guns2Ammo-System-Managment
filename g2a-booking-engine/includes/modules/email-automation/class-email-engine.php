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
		if ( 'payment_required' === $event && empty( $tags['pay_url'] ) ) {
			do_action( 'g2ab_email_precondition_failed', $event, $booking, 'missing_pay_url' );
			return false;
		}
		$subject = $this->merge( $tpl['subject'], $tags );
		$body_template = $tpl['body_html'];
		if ( empty( $tags['pay_url'] ) && false !== strpos( (string) $body_template, '{pay_url}' ) ) {
			$body_template = $this->strip_missing_pay_url_cta( (string) $body_template );
		}
		// Customer-sourced values are escaped before substitution into the HTML
		// body so stored markup in a booking can't inject HTML into inboxes.
		$body    = $this->merge( $body_template, $this->escape_customer_tags( $tags ) );
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

	private function strip_missing_pay_url_cta( $html ) {
		$html = preg_replace( '#<p\b[^>]*>.*?<a\b[^>]*href=["\']\{pay_url\}["\'][^>]*>.*?</a>.*?</p>#is', '', $html );
		$html = preg_replace( '#<a\b[^>]*href=["\']\{pay_url\}["\'][^>]*>.*?</a>#is', '', $html );
		return $html;
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
		$out = preg_replace_callback( '/\{\{#if\s+([a-zA-Z0-9_]+)\}\}(.*?)\{\{\/if\}\}/s', static function ( $m ) use ( $tags ) {
			$key = $m[1];
			return ! empty( $tags[ $key ] ) ? $m[2] : '';
		}, $out );
		foreach ( $tags as $k => $v ) {
			$out = str_replace( '{' . $k . '}', (string) $v, $out );
		}
		return preg_replace( '/\{[a-zA-Z0-9_]+\}/', '', $out );
	}

	/**
	 * esc_html() the customer-sourced merge values for substitution into HTML
	 * bodies. Admin-authored template markup is left untouched.
	 */
	private function escape_customer_tags( $tags ) {
		foreach ( array( 'customer_name', 'customer_email', 'customer_phone', 'resource_name', 'service_name', 'event_title', 'booking_type', 'business_name', 'business_phone', 'business_address', 'confirmation_number', 'payment_status', 'booking_status' ) as $k ) {
			if ( isset( $tags[ $k ] ) ) {
				$tags[ $k ] = esc_html( (string) $tags[ $k ] );
			}
		}
		foreach ( array( 'invoice_url', 'pay_url', 'payment_resume_url', 'cancel_url', 'site_url', 'directions_url', 'brand_logo_url' ) as $k ) {
			if ( isset( $tags[ $k ] ) ) {
				$tags[ $k ] = esc_url( (string) $tags[ $k ] );
			}
		}
		return $tags;
	}

	/**
	 * Build merge tag map from a booking row + context.
	 */
	public function build_tags( $booking, $context = array() ) {
		$booking = is_object( $booking ) ? (array) $booking : (array) $booking;
		$booking = $this->hydrate_booking_tags_row( $booking );

		$site_url    = home_url( '/' );
		$biz_name    = g2ab_business_name();
		$biz_phone   = g2ab_business_phone();
		$biz_addr    = g2ab_business_address();
		$brand_color = get_option( self::OPTION_BRAND_HEX, '#E8802F' );
		$brand_logo  = get_option( self::OPTION_LOGO_URL, '' );

		$uuid = isset( $booking['uuid'] ) ? $booking['uuid'] : '';
		$invoice_url = $uuid ? add_query_arg( 'g2ab_invoice', $uuid, $site_url ) : '';
		$pay_url     = isset( $context['pay_url'] ) ? $context['pay_url'] : '';
		if ( empty( $pay_url ) && ! empty( $booking['latest_payment_response'] ) ) {
			$response = is_string( $booking['latest_payment_response'] ) ? json_decode( $booking['latest_payment_response'], true ) : $booking['latest_payment_response'];
			if ( is_array( $response ) && ! empty( $response['url'] ) ) {
				$pay_url = esc_url_raw( (string) $response['url'] );
			}
		}
		$cancel_url  = $uuid ? add_query_arg( array( 'g2ab_cancel' => $uuid ), $site_url ) : '';

		$form_data = ! empty( $booking['form_data'] ) ? json_decode( (string) $booking['form_data'], true ) : array();
		$form_data = is_array( $form_data ) ? $form_data : array();
		$customer_name  = trim( (string) ( $booking['customer_name'] ?? '' ) );
		$customer_email = trim( (string) ( $booking['customer_email'] ?? '' ) );
		$customer_phone = trim( (string) ( $booking['customer_phone'] ?? '' ) );
		if ( '' === $customer_name ) {
			$customer_name = trim( (string) ( $form_data['name'] ?? '' ) );
		}
		if ( '' === $customer_name ) {
			$customer_name = trim( (string) ( ( $form_data['first_name'] ?? '' ) . ' ' . ( $form_data['last_name'] ?? '' ) ) );
		}
		if ( '' === $customer_email ) {
			$customer_email = trim( (string) ( $form_data['email'] ?? '' ) );
		}
		if ( '' === $customer_phone ) {
			$customer_phone = trim( (string) ( $form_data['phone'] ?? '' ) );
		}

		$total        = isset( $booking['total_amount'] ) ? (float) $booking['total_amount'] : (float) ( $booking['amount'] ?? 0 );
		$paid         = isset( $booking['paid_amount'] ) ? (float) $booking['paid_amount'] : 0.0;
		$latest_amt   = isset( $booking['latest_payment_amount'] ) ? (float) $booking['latest_payment_amount'] : 0.0;
		$balance_due  = max( 0, $total - $paid );
		$due_now      = (float) ( $context['due_now'] ?? ( $latest_amt > 0 ? $latest_amt : $balance_due ) );
		$currency     = strtoupper( (string) ( $context['currency'] ?? ( $booking['currency'] ?? get_option( 'g2ab_currency', 'USD' ) ) ) );
		$service_name = $booking['event_title'] ?? ( $booking['resource_name'] ?? ( $booking['type_name'] ?? '' ) );
		$expires_at   = (string) ( $context['checkout_expires_at'] ?? ( $booking['checkout_expires_at'] ?? '' ) );

		return array(
			'customer_name'    => $customer_name ?: 'Guest',
			'customer_email'   => $customer_email,
			'customer_phone'   => $customer_phone,
			'booking_id'       => isset( $booking['id'] ) ? (int) $booking['id'] : 0,
			'uuid'             => $uuid,
			'confirmation_number' => $uuid,
			'resource_name'    => $booking['resource_name'] ?? ( $context['resource_name'] ?? '' ),
			'service_name'     => $service_name,
			'event_title'      => $booking['event_title'] ?? '',
			'booking_type'     => $booking['type_name'] ?? '',
			'start_at'         => isset( $booking['start_at'] ) ? $this->format_dt( $booking['start_at'] ) : '',
			'end_at'           => isset( $booking['end_at'] ) ? $this->format_dt( $booking['end_at'] ) : '',
			'duration'         => isset( $booking['duration_min'] ) ? (int) $booking['duration_min'] : 60,
			'party_size'       => isset( $booking['party_size'] ) ? (int) $booking['party_size'] : 1,
			'amount'           => number_format( $total, 2 ),
			'due_now'          => number_format( $due_now, 2 ),
			'balance_due'      => number_format( $balance_due, 2 ),
			'paid_amount'      => number_format( $paid, 2 ),
			'currency'         => $currency,
			'gateway'          => $context['gateway'] ?? ( $booking['latest_gateway'] ?? '' ),
			'payment_status'   => $booking['latest_payment_status'] ?? '',
			'booking_status'   => $booking['status'] ?? '',
			'checkout_expires_at' => $expires_at ? $this->format_dt( $expires_at ) : '',
			'business_name'    => $biz_name,
			'business_phone'   => $biz_phone,
			'business_address' => $biz_addr,
			'invoice_url'      => $invoice_url,
			'pay_url'          => $pay_url,
			'payment_resume_url' => $pay_url,
			'cancel_url'       => $cancel_url,
			'site_url'         => $site_url,
			'directions_url'   => get_option( 'g2ab_business_directions_url', '' ),
			'date_now'         => date_i18n( get_option( 'date_format' ) ),
			'brand_color'      => $brand_color,
			'brand_logo_url'   => $brand_logo,
		);
	}

	private function hydrate_booking_tags_row( $booking ) {
		$id = absint( $booking['id'] ?? 0 );
		if ( ! $id ) {
			return $booking;
		}
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';
		$tt = $wpdb->prefix . 'g2ab_booking_types';
		$et = $wpdb->prefix . 'g2ab_events';
		$pt = $wpdb->prefix . 'g2ab_payments';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT b.*, r.name AS resource_name, t.name AS type_name, e.title AS event_title,
			        p.gateway AS latest_gateway, p.status AS latest_payment_status, p.amount AS latest_payment_amount,
			        p.gateway_response AS latest_payment_response, p.checkout_expires_at
			 FROM {$bt} b
			 LEFT JOIN {$rt} r ON r.id = b.resource_id
			 LEFT JOIN {$tt} t ON t.id = b.booking_type_id
			 LEFT JOIN {$et} e ON e.id = b.event_id
			 LEFT JOIN {$pt} p ON p.id = (SELECT pp.id FROM {$pt} pp WHERE pp.booking_id = b.id ORDER BY pp.created_at DESC, pp.id DESC LIMIT 1)
			 WHERE b.id = %d LIMIT 1",
			$id
		), ARRAY_A );
		return is_array( $row ) ? array_merge( $booking, $row ) : $booking;
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
					. '<p style="' . $muted . '">If payment is required, a secure payment email will follow once checkout is ready. Need to make changes? Reply to this email or call {business_phone}.</p>',
			),
			'payment_required' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Payment required to confirm {service_name}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">Complete Payment</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Your reservation hold is ready. Complete secure payment to move it onto the confirmed booking roster.</p>'
					. '<table role="presentation" style="width:100%;border-collapse:collapse;">'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Confirmation #</div><div style="' . $value . '">{confirmation_number}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Service</div><div style="' . $value . '">{service_name}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">When</div><div style="' . $value . '">{start_at}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Due now</div><div style="' . $value . '">${due_now} {currency}</div></td></tr>'
					. '<tr><td style="padding:12px 0;"><div style="' . $label . '">Checkout expires</div><div style="' . $value . '">{checkout_expires_at}</div></td></tr>'
					. '</table>'
					. '{{#if pay_url}}<p style="margin:24px 0 0;"><a href="{pay_url}" style="' . $cta . '">Pay securely now</a></p>{{/if}}'
					. '<p style="' . $muted . '">This hold is not confirmed until payment is verified by Stripe. If the link has expired, reply to this email or call {business_phone}.</p>',
			),
			'pay_in_store_reservation' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Pay-at-store reservation received - {service_name}',
				'body_html'          => '<h2 style="' . $h2 . 'color:{brand_color};">Reservation Held</h2>'
					. '<p style="margin:0 0 8px;">Hi {customer_name},</p>'
					. '<p style="margin:0 0 20px;color:#3A3940;">Your pay-at-store reservation is on the operational roster. Please arrive early and pay at the front desk.</p>'
					. '<table role="presentation" style="width:100%;border-collapse:collapse;">'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Confirmation #</div><div style="' . $value . '">{confirmation_number}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">Service</div><div style="' . $value . '">{service_name}</div></td></tr>'
					. '<tr><td style="' . $row . '"><div style="' . $label . '">When</div><div style="' . $value . '">{start_at}</div></td></tr>'
					. '<tr><td style="padding:12px 0;"><div style="' . $label . '">Balance due</div><div style="' . $value . '">${balance_due} {currency}</div></td></tr>'
					. '</table>'
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
			'booking_created'      => 'Booking Received (no payment CTA)',
			'payment_required'     => 'Payment Required (after checkout URL is saved)',
			'pay_in_store_reservation' => 'Pay-at-Store Reservation',
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
