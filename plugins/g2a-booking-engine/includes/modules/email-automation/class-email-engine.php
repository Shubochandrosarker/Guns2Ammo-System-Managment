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
 *   {booking_id}, {uuid}, {confirmation_number}, {resource_name},
 *   {service_name}, {start_at}, {end_at}, {duration}, {party_size},
 *   {amount}, {amount_formatted}, {due_now}, {due_now_formatted},
 *   {balance_due}, {balance_due_formatted}, {paid_amount},
 *   {paid_amount_formatted}, {currency},
 *   {business_name}, {business_phone}, {business_address},
 *   {invoice_url}, {pay_url}, {complete_payment_url}, {view_booking_url},
 *   {cancel_url}, {reschedule_url}, {review_url},
 *   {site_url}, {date_now}, {brand_color}, {brand_logo_url}
 *
 * Every send is written to {$wpdb->prefix}g2ab_email_log first (idempotency
 * key UNIQUE — a duplicate key means this exact email already went out and
 * the send is skipped silently), then dispatched, then the row is updated
 * to sent/failed. Failed rows ≤24h old are retried by the cron.
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
	const OPTION_REVIEW_URL = 'g2ab_review_url';

	const LOG_DB_VERSION = '1.0';

	/**
	 * Plain-text AltBody for the message currently being dispatched.
	 * Static because the settings screen instantiates its own engine —
	 * the phpmailer_init hook is registered once, class-wide.
	 *
	 * @var string
	 */
	private static $alt_body = '';

	/**
	 * Last wp_mail_failed error message (captured per send).
	 *
	 * @var string
	 */
	private static $last_mail_error = '';

	/** @var bool */
	private static $mail_hooks_registered = false;

	/**
	 * Send an event email. Looks up template, merges tags, sends to customer
	 * + admin per template config with per-recipient idempotency + logging.
	 *
	 * @param string       $event   Template/event id.
	 * @param object|array|int $booking Booking row or id.
	 * @param array        $context Optional hook context.
	 * @return array|false Per-recipient results, or false when skipped.
	 */
	public function send_event( $event, $booking, $context = array() ) {
		if ( is_numeric( $booking ) ) {
			$booking = $this->fetch_booking( (int) $booking );
			if ( ! $booking ) return false;
		}

		$tpl = $this->get_template( $event );
		if ( empty( $tpl ) || empty( $tpl['enabled'] ) ) return false;

		$tags = $this->build_tags( $booking, $context );

		// The "payment required" email must never go out without a usable
		// checkout URL (live session or stable action page fallback).
		if ( 'payment_required' === $event && empty( $tags['pay_url'] ) ) {
			do_action( 'g2ab_email_precondition_failed', $event, $booking, 'missing_pay_url' );
			return false;
		}

		$subject       = $this->merge( $tpl['subject'], $tags );
		$body_template = (string) $tpl['body_html'];

		// Hide CTAs whose destination could not be built (no live pay URL,
		// invoice signer unavailable, review URL not configured).
		foreach ( array( 'pay_url', 'invoice_url', 'review_url' ) as $url_tag ) {
			if ( empty( $tags[ $url_tag ] ) && false !== strpos( $body_template, '{' . $url_tag . '}' ) ) {
				$body_template = $this->strip_missing_url_cta( $body_template, $url_tag );
			}
		}

		// Customer-sourced values are escaped before substitution into the HTML
		// body so stored markup in a booking can't inject HTML into inboxes.
		$body = $this->merge( $body_template, $this->escape_customer_tags( $tags ) );
		$html = $this->wrap_html( $body, $subject );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name(), $this->from_addr() ),
		);

		$attachments = apply_filters( 'g2ab_email_attachments', array(), $event, $booking, $context );

		$booking_id   = isset( $tags['booking_id'] ) ? (int) $tags['booking_id'] : 0;
		$source_event = sanitize_key( (string) ( $context['source_event'] ?? ( function_exists( 'current_action' ) ? (string) current_action() : '' ) ) );
		if ( '' === $source_event ) {
			$source_event = sanitize_key( $event );
		}
		$source_id = (string) ( $context['source_event_id']
			?? ( $context['checkout_session_id']
			?? ( $context['transaction_id']
			?? ( $context['capture_id']
			?? ( $context['wc_order_id'] ?? '' ) ) ) ) );

		$meta = array(
			'booking_id'      => $booking_id,
			'template'        => sanitize_key( $event ),
			'lifecycle_event' => sanitize_key( $event ),
			'source_event'    => $source_event,
			'source_id'       => $source_id,
		);

		$results = array();

		// Customer.
		if ( ! empty( $tpl['recipient_customer'] ) && ! empty( $tags['customer_email'] ) ) {
			$results['customer'] = $this->deliver( $tags['customer_email'], $subject, $html, $headers, $attachments, $meta );
		}

		// Admin — an empty saved option must fall back to the site admin
		// address explicitly (get_option's default only applies when the
		// option row is missing, not when it holds an empty string).
		if ( ! empty( $tpl['recipient_admin'] ) ) {
			$admin_email = $this->admin_email();
			if ( $admin_email ) {
				$results['admin'] = $this->deliver( $admin_email, '[Admin] ' . $subject, $html, $headers, $attachments, $meta );
			}
		}

		do_action( 'g2ab_email_sent', $event, $booking, $tpl, $results );
		return $results;
	}

	/**
	 * Admin notification address with an explicit empty-string fallback.
	 *
	 * @return string
	 */
	public function admin_email() {
		$admin_email = trim( (string) get_option( self::OPTION_ADMIN_TO, '' ) );
		if ( '' === $admin_email || ! is_email( $admin_email ) ) {
			$admin_email = (string) get_option( 'admin_email' );
		}
		return is_email( $admin_email ) ? $admin_email : '';
	}

	/**
	 * Remove a CTA whose {tag} URL could not be populated. Handles both a
	 * paragraph-wrapped anchor and a bare anchor, plus table-based buttons.
	 */
	private function strip_missing_url_cta( $html, $tag = 'pay_url' ) {
		$tag  = preg_quote( $tag, '#' );
		$html = preg_replace( '#<table\b[^>]*>(?:(?!</table>).)*?href=["\']\{' . $tag . '\}["\'](?:(?!</table>).)*?</table>#is', '', $html );
		$html = preg_replace( '#<p\b[^>]*>.*?<a\b[^>]*href=["\']\{' . $tag . '\}["\'][^>]*>.*?</a>.*?</p>#is', '', $html );
		$html = preg_replace( '#<a\b[^>]*href=["\']\{' . $tag . '\}["\'][^>]*>.*?</a>#is', '', $html );
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
	 * Send a custom email (used by test sends + AI auto-reply). No
	 * idempotency (there is no booking context) but still branded and
	 * multipart.
	 */
	public function send_custom( $to, $subject, $body, $attachments = array() ) {
		$html    = $this->wrap_html( $body, $subject );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $this->from_name(), $this->from_addr() ),
		);
		self::register_mail_hooks();
		self::$alt_body = class_exists( 'G2AB_Email_Branding' ) ? G2AB_Email_Branding::plain_text( $html ) : '';
		$sent = wp_mail( $to, $subject, $html, $headers, $attachments );
		self::$alt_body = '';
		return $sent;
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
		foreach ( array( 'invoice_url', 'pay_url', 'payment_resume_url', 'complete_payment_url', 'view_booking_url', 'cancel_url', 'reschedule_url', 'review_url', 'site_url', 'directions_url', 'brand_logo_url' ) as $k ) {
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

		$uuid = isset( $booking['uuid'] ) ? (string) $booking['uuid'] : '';

		// Signed, purpose-bound action URLs (stable pages; never raw
		// ?g2ab_cancel= links, never unsigned).
		$view_url = $cancel_url = $reschedule_url = $complete_payment_url = '';
		if ( $uuid && class_exists( 'G2AB_Email_Actions' ) ) {
			$view_url             = G2AB_Email_Actions::url( $uuid, G2AB_Email_Actions::ACTION_VIEW );
			$cancel_url           = G2AB_Email_Actions::url( $uuid, G2AB_Email_Actions::ACTION_CANCEL );
			$reschedule_url       = G2AB_Email_Actions::url( $uuid, G2AB_Email_Actions::ACTION_RESCHEDULE );
			$complete_payment_url = G2AB_Email_Actions::url( $uuid, G2AB_Email_Actions::ACTION_PAY );
		}

		// Invoice link — signed exactly like the pdf-invoices module builds
		// its viewer URLs. When the signer is unavailable the tag stays
		// empty and the invoice CTA is hidden.
		$invoice_url = '';
		if ( $uuid && function_exists( 'g2ab_invoice_sign_token' ) ) {
			$invoice_url = add_query_arg(
				array(
					'g2ab_invoice' => $uuid,
					't'            => g2ab_invoice_sign_token( $uuid ),
				),
				$site_url
			);
		}

		// {pay_url}: ONLY a live checkout URL. Either the checkout_ready
		// context carries one, or a stored gateway_response URL whose
		// checkout_expires_at is verifiably in the future. Anything else
		// falls back to the stable complete-payment action page, which
		// redirects to a live session or safely mints a fresh one.
		$live_pay_url = '';
		if ( ! empty( $context['pay_url'] ) ) {
			$live_pay_url = esc_url_raw( (string) $context['pay_url'] );
		} elseif ( ! empty( $booking['latest_payment_response'] ) ) {
			$response = is_string( $booking['latest_payment_response'] ) ? json_decode( $booking['latest_payment_response'], true ) : $booking['latest_payment_response'];
			$stored   = is_array( $response ) && ! empty( $response['url'] ) ? esc_url_raw( (string) $response['url'] ) : '';
			$expires  = ! empty( $booking['checkout_expires_at'] ) ? strtotime( (string) $booking['checkout_expires_at'] ) : 0;
			if ( $stored && $expires && $expires > time() + 60 ) {
				$live_pay_url = $stored;
			}
		}
		$pay_url = $live_pay_url ?: $complete_payment_url;

		// Review URL — rendered only when a valid http(s) URL is configured.
		$review_url = esc_url_raw( (string) get_option( self::OPTION_REVIEW_URL, '' ) );
		if ( '' !== $review_url && ! preg_match( '#^https?://#i', $review_url ) ) {
			$review_url = '';
		}

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
		$expires_at   = (string) ( $context['checkout_expires_at'] ?? ( $booking['checkout_expires_at'] ?? '' ) );

		// Resource label with event/booking-type fallback: event bookings
		// have no resource row, so fall through to the event title, then
		// the booking-type (service) name.
		$resource_name = trim( (string) ( $booking['resource_name'] ?? ( $context['resource_name'] ?? '' ) ) );
		if ( '' === $resource_name ) {
			$resource_name = trim( (string) ( $booking['event_title'] ?? '' ) );
		}
		if ( '' === $resource_name ) {
			$resource_name = trim( (string) ( $booking['type_name'] ?? '' ) );
		}

		$service_name = trim( (string) ( $booking['event_title'] ?? '' ) );
		if ( '' === $service_name ) {
			$service_name = $resource_name;
		}
		if ( '' === $service_name ) {
			$service_name = trim( (string) ( $booking['type_name'] ?? '' ) );
		}

		$fmt = static function ( $amount ) use ( $currency ) {
			return function_exists( 'g2ab_format_currency' )
				? g2ab_format_currency( (float) $amount, $currency )
				: $currency . ' ' . number_format( (float) $amount, 2 );
		};

		return array(
			'customer_name'    => $customer_name ?: 'Guest',
			'customer_email'   => $customer_email,
			'customer_phone'   => $customer_phone,
			'booking_id'       => isset( $booking['id'] ) ? (int) $booking['id'] : 0,
			'uuid'             => $uuid,
			'confirmation_number' => $uuid,
			'resource_name'    => $resource_name,
			'service_name'     => $service_name,
			'event_title'      => $booking['event_title'] ?? '',
			'booking_type'     => $booking['type_name'] ?? '',
			'start_at'         => isset( $booking['start_at'] ) ? $this->format_dt( $booking['start_at'] ) : '',
			'end_at'           => isset( $booking['end_at'] ) ? $this->format_dt( $booking['end_at'] ) : '',
			'duration'         => isset( $booking['duration_min'] ) ? (int) $booking['duration_min'] : 60,
			'party_size'       => isset( $booking['party_size'] ) ? (int) $booking['party_size'] : 1,
			'amount'           => number_format( $total, 2 ),
			'amount_formatted' => $fmt( $total ),
			'due_now'          => number_format( $due_now, 2 ),
			'due_now_formatted' => $fmt( $due_now ),
			'balance_due'      => number_format( $balance_due, 2 ),
			'balance_due_formatted' => $fmt( $balance_due ),
			'paid_amount'      => number_format( $paid, 2 ),
			'paid_amount_formatted' => $fmt( $paid ),
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
			'pay_url_is_live'  => $live_pay_url ? '1' : '',
			'payment_resume_url' => $pay_url,
			'complete_payment_url' => $complete_payment_url,
			'view_booking_url' => $view_url,
			'cancel_url'       => $cancel_url,
			'reschedule_url'   => $reschedule_url,
			'review_url'       => $review_url,
			'site_url'         => $site_url,
			'directions_url'   => get_option( 'g2ab_business_directions_url', '' ),
			'date_now'         => date_i18n( get_option( 'date_format' ) ),
			'brand_color'      => $brand_color,
			'brand_logo_url'   => $brand_logo,
		);
	}

	private function hydrate_booking_tags_row( $booking ) {
		// A test/preview payload passes id 0 so the fake data is used as-is —
		// never merged with (or colliding into) a real row.
		$id = absint( $booking['id'] ?? 0 );
		if ( ! $id || ! empty( $booking['is_test'] ) ) {
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

	private function fetch_booking( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1",
			absint( $id )
		) );
	}

	private function format_dt( $iso ) {
		$ts = strtotime( $iso );
		if ( ! $ts ) return $iso;
		return date_i18n( get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ), $ts );
	}

	public function from_name() { return get_option( self::OPTION_FROM_NAME, g2ab_business_name() ); }
	public function from_addr() { return get_option( self::OPTION_FROM_ADDR, get_option( 'admin_email' ) ); }

	/**
	 * Wrap inner HTML in the shared branded shell (see G2AB_Email_Branding).
	 */
	public function wrap_html( $inner, $subject ) {
		$footer = wp_kses_post( get_option( self::OPTION_FOOTER, '' ) );
		return G2AB_Email_Branding::wrap( $inner, array(
			'title'       => (string) $subject,
			'footer_html' => $footer,
		) );
	}

	/* ── Delivery log + idempotency ─────────────────────────────────────── */

	/**
	 * Email delivery log table name.
	 */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'g2ab_email_log';
	}

	/**
	 * Create the delivery-log table on demand (dbDelta, version-gated).
	 */
	public static function ensure_log_table() {
		static $checked = false;
		if ( $checked ) return;
		$checked = true;

		if ( self::LOG_DB_VERSION === get_option( 'g2ab_email_log_db_version', '' ) ) {
			return;
		}

		global $wpdb;
		$collate = $wpdb->get_charset_collate();
		$table   = self::log_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
idempotency_key CHAR(64) NOT NULL,
booking_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
recipient VARCHAR(191) NOT NULL DEFAULT '',
template VARCHAR(64) NOT NULL DEFAULT '',
subject VARCHAR(255) NOT NULL DEFAULT '',
status VARCHAR(20) NOT NULL DEFAULT 'failed',
error TEXT NULL,
source_event VARCHAR(191) NOT NULL DEFAULT '',
created_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY idempotency_key (idempotency_key),
KEY idx_booking (booking_id),
KEY idx_status_created (status, created_at)
) {$collate};" );

		update_option( 'g2ab_email_log_db_version', self::LOG_DB_VERSION, false );
	}

	/**
	 * Deliver one message with idempotency: INSERT the log row first (a
	 * duplicate key means this exact email already went out — skip
	 * silently), then send, then update the row to sent/failed with the
	 * captured wp_mail error.
	 *
	 * @return bool True when sent OR already sent (duplicate); false on failure.
	 */
	private function deliver( $recipient, $subject, $html, $headers, $attachments, $meta ) {
		$recipient = sanitize_email( (string) $recipient );
		if ( ! $recipient ) {
			return false;
		}

		self::ensure_log_table();
		self::register_mail_hooks();

		global $wpdb;
		$table = self::log_table();

		$key = hash( 'sha256', implode( '|', array(
			strtolower( $recipient ),
			(string) ( $meta['template'] ?? '' ),
			(string) (int) ( $meta['booking_id'] ?? 0 ),
			(string) ( $meta['lifecycle_event'] ?? ( $meta['template'] ?? '' ) ),
			(string) ( $meta['source_id'] ?? '' ),
		) ) );

		// source_event column keeps "hook|source_id" so a cron retry can
		// recompute the exact same idempotency key.
		$source_store = (string) ( $meta['source_event'] ?? '' );
		if ( '' !== (string) ( $meta['source_id'] ?? '' ) ) {
			$source_store .= '|' . $meta['source_id'];
		}
		$source_store = substr( $source_store, 0, 191 );

		// INSERT IGNORE: affected-rows 0 = duplicate key = already handled.
		$inserted = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$table} (idempotency_key, booking_id, recipient, template, subject, status, error, source_event, created_at) VALUES (%s, %d, %s, %s, %s, 'failed', %s, %s, %s)",
			$key,
			(int) ( $meta['booking_id'] ?? 0 ),
			$recipient,
			(string) ( $meta['template'] ?? '' ),
			substr( (string) $subject, 0, 255 ),
			'Send did not complete.',
			$source_store,
			current_time( 'mysql' )
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( 0 === $inserted ) {
			// Duplicate key — this exact email was already attempted. A row
			// in 'sent' state means delivered (callers can mark and move on);
			// a 'failed' row is left for the retry pass (delete + resend).
			$status = $wpdb->get_var( $wpdb->prepare(
				"SELECT status FROM {$table} WHERE idempotency_key = %s LIMIT 1",
				$key
			) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 'sent' === $status;
		}

		self::$last_mail_error = '';
		self::$alt_body        = class_exists( 'G2AB_Email_Branding' ) ? G2AB_Email_Branding::plain_text( $html ) : '';
		$sent                  = wp_mail( $recipient, $subject, $html, $headers, $attachments );
		self::$alt_body        = '';

		if ( false !== $inserted ) {
			// Update the row we just inserted (logging is best-effort; a
			// failed insert must never block the send itself).
			$wpdb->update(
				$table,
				array(
					'status' => $sent ? 'sent' : 'failed',
					'error'  => $sent ? '' : ( self::$last_mail_error ?: 'wp_mail() returned false.' ),
				),
				array( 'idempotency_key' => $key ),
				array( '%s', '%s' ),
				array( '%s' )
			); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		return (bool) $sent;
	}

	/**
	 * Re-attempt failed sends ≤ $max_age_hours old (delete + resend: the
	 * failed row is removed so deliver() can insert a fresh key; already
	 * -sent recipients on the same event stay deduped by their own rows).
	 *
	 * Called from the reminder cron behind the g2ab_email_retry_failed filter.
	 */
	public function retry_failed( $max_age_hours = 24 ) {
		self::ensure_log_table();
		global $wpdb;
		$table  = self::log_table();
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - (int) $max_age_hours * HOUR_IN_SECONDS );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'failed' AND created_at >= %s ORDER BY id ASC LIMIT 25",
			$cutoff
		) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $rows ) return;

		foreach ( $rows as $row ) {
			$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			if ( empty( $row->booking_id ) || empty( $row->template ) ) {
				continue;
			}

			$source_event = (string) $row->source_event;
			$source_id    = '';
			if ( false !== strpos( $source_event, '|' ) ) {
				list( $source_event, $source_id ) = explode( '|', $source_event, 2 );
			}

			$this->send_event( (string) $row->template, (int) $row->booking_id, array(
				'source_event'    => $source_event,
				'source_event_id' => $source_id,
				'retry'           => true,
			) );
		}
	}

	/**
	 * Register the phpmailer_init (plain-text AltBody) + wp_mail_failed
	 * (error capture) hooks once, class-wide.
	 */
	private static function register_mail_hooks() {
		if ( self::$mail_hooks_registered ) return;
		self::$mail_hooks_registered = true;
		add_action( 'phpmailer_init', array( __CLASS__, 'inject_alt_body' ) );
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
	}

	/**
	 * phpmailer_init: attach the plain-text alternative for the message
	 * currently being dispatched (multipart/alternative).
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer Mailer instance.
	 */
	public static function inject_alt_body( $phpmailer ) {
		if ( '' !== self::$alt_body && empty( $phpmailer->AltBody ) ) {
			$phpmailer->AltBody = self::$alt_body;
		}
	}

	/**
	 * wp_mail_failed: keep the mailer error for the delivery log.
	 *
	 * @param WP_Error $error Mail error.
	 */
	public static function capture_mail_error( $error ) {
		if ( is_wp_error( $error ) ) {
			self::$last_mail_error = substr( (string) $error->get_error_message(), 0, 1000 );
		}
	}

	/* ── Default templates ─────────────────────────────────────────────── */

	/**
	 * Default templates per event. Body fragments only — the shared branded
	 * shell (header, footer, NAP) is applied by wrap_html() at send time.
	 */
	public function default_templates() {
		// Shared building blocks (kept as constants-in-code, not extracted
		// methods, since these are one-time-assembled array literals).
		$h2    = 'font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;margin:0 0 18px;color:#26241F;';
		$p     = 'margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#26241F;';
		$muted = 'margin:24px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#6F6A5E;';
		$row   = 'padding:11px 0;border-bottom:1px solid #E4DFD2;';
		$label = 'font-family:Arial,Helvetica,sans-serif;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#8A8474;';
		$value = 'font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;color:#26241F;';

		$detail = static function ( $label_text, $value_tag, $last = false ) use ( $row, $label, $value ) {
			$td_style = $last ? 'padding:11px 0;' : $row;
			return '<tr><td style="' . $td_style . '"><div style="' . $label . '">' . $label_text . '</div><div style="' . $value . '">' . $value_tag . '</div></td></tr>';
		};

		$btn = static function ( $url_tag, $label_text, $style = 'primary' ) {
			return G2AB_Email_Branding::button( $url_tag, $label_text, $style );
		};

		$confirmation_block = '<p style="font-family:Consolas,Menlo,monospace;font-size:20px;font-weight:bold;background:#FFFFFF;border:1px solid #E4DFD2;border-radius:6px;padding:16px;text-align:center;letter-spacing:.08em;color:#B06316;margin:0 0 20px;">{confirmation_number}</p>';

		return array(
			'booking_created' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation received — {resource_name} on {start_at}',
				'body_html'          => '<h2 style="' . $h2 . '">Reservation Received</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">Thank you — we have received your reservation. Here are the details:</p>'
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
					. $detail( 'Confirmation #', '{confirmation_number}' )
					. $detail( 'Service', '{resource_name}' )
					. $detail( 'When', '{start_at}' )
					. $detail( 'Duration', '{duration} min' )
					. $detail( 'Party size', '{party_size}', true )
					. '</table>'
					. $btn( '{view_booking_url}', 'View your booking' )
					. '<p style="' . $muted . '">If payment is required, a secure payment email will follow shortly. Need to make changes? Reply to this email or call {business_phone}.</p>',
			),
			'payment_required' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Payment required to confirm {service_name}',
				'body_html'          => '<h2 style="' . $h2 . '">Complete Your Payment</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">Your reservation is being held. Complete secure payment to confirm it:</p>'
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
					. $detail( 'Confirmation #', '{confirmation_number}' )
					. $detail( 'Service', '{service_name}' )
					. $detail( 'When', '{start_at}' )
					. $detail( 'Amount due now', '{due_now_formatted}' )
					. '{{#if checkout_expires_at}}' . $detail( 'Checkout expires', '{checkout_expires_at}', true ) . '{{/if}}'
					. '</table>'
					. $btn( '{pay_url}', 'Pay securely now' )
					. '<p style="' . $muted . '">If the button above has expired, use this permanent link to resume payment: <a href="{complete_payment_url}" style="color:#B06316;">Complete payment</a>.</p>'
					. '<p style="' . $muted . '">Your reservation is not confirmed until payment is verified. Questions? Reply to this email or call {business_phone}.</p>',
			),
			'pay_in_store_reservation' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation held — pay at the store — {service_name}',
				'body_html'          => '<h2 style="' . $h2 . '">Reservation Held</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">Your reservation is on our roster. Please arrive a few minutes early and pay at the front desk.</p>'
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
					. $detail( 'Confirmation #', '{confirmation_number}' )
					. $detail( 'Service', '{service_name}' )
					. $detail( 'When', '{start_at}' )
					. $detail( 'Balance due at the counter', '{balance_due_formatted}', true )
					. '</table>'
					. $btn( '{view_booking_url}', 'View your booking' )
					. '<p style="' . $muted . '">Need to make changes? Reply to this email or call {business_phone}.</p>',
			),
			'booking_confirmed' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Reservation confirmed — {resource_name} on {start_at}',
				'body_html'          => '<h2 style="' . $h2 . '">You Are Confirmed</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">Your reservation is locked in. Bring this confirmation number with you:</p>'
					. $confirmation_block
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;margin:0 0 8px;">'
					. $detail( 'Service', '{resource_name}' )
					. $detail( 'When', '{start_at} · {duration} min' )
					. $detail( 'Party size', '{party_size}', true )
					. '</table>'
					. '<p style="' . $p . '">Please arrive 10 minutes early for the safety briefing and bring valid photo ID.</p>'
					. '<p style="margin:0 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6F6A5E;">{business_address}</p>'
					. $btn( '{view_booking_url}', 'View your booking' )
					. $btn( '{reschedule_url}', 'Reschedule', 'secondary' )
					. '<p style="' . $muted . '">Can\'t make it? <a href="{cancel_url}" style="color:#A33B3B;">Cancel this reservation</a>.</p>',
			),
			'booking_paid' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Payment received — {amount_formatted}',
				'body_html'          => '<h2 style="' . $h2 . '">Payment Received</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">We received your payment for reservation <strong>{confirmation_number}</strong>. Thank you.</p>'
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
					. $detail( 'Service', '{resource_name}' )
					. $detail( 'When', '{start_at}' )
					. $detail( 'Amount paid', '{paid_amount_formatted}', true )
					. '</table>'
					. '{{#if invoice_url}}' . $btn( '{invoice_url}', 'View invoice' ) . '{{/if}}'
					. $btn( '{view_booking_url}', 'View your booking', 'secondary' )
					. '<p style="' . $muted . '">Thank you for your business. Questions about this charge? Reply to this email or call {business_phone}.</p>',
			),
			'booking_reminder_24h' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Reminder — your reservation tomorrow, {start_at}',
				'body_html'          => '<h2 style="' . $h2 . '">See You Tomorrow</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">A quick reminder of your upcoming reservation:</p>'
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
					. $detail( 'Service', '{resource_name}' )
					. $detail( 'When', '{start_at} · {duration} min' )
					. $detail( 'Confirmation #', '{confirmation_number}', true )
					. '</table>'
					. '<p style="' . $p . '"><strong>What to bring:</strong> valid photo ID, eye and ear protection (rentals available at the counter), and closed-toe shoes.</p>'
					. $btn( '{view_booking_url}', 'View your booking' )
					. '<p style="' . $muted . '">Need to change your time? <a href="{reschedule_url}" style="color:#B06316;">Reschedule</a> or call {business_phone}.</p>',
			),
			'booking_reminder_2h' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'See you in 2 hours — {resource_name}',
				'body_html'          => '<h2 style="' . $h2 . '">See You Soon</h2>'
					. '<p style="' . $p . '">Hi {customer_name}, your reservation starts in about 2 hours.</p>'
					. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;">'
					. $detail( 'Service', '{resource_name}' )
					. $detail( 'When', '{start_at}' )
					. $detail( 'Confirmation #', '{confirmation_number}', true )
					. '</table>'
					. '<p style="' . $muted . '">{business_address} · {business_phone}</p>',
			),
			'booking_cancelled' => array(
				'enabled'            => 1,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'Reservation cancelled — {confirmation_number}',
				'body_html'          => '<h2 style="' . $h2 . '">Reservation Cancelled</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">Your reservation <strong>{confirmation_number}</strong> for {resource_name} on {start_at} has been cancelled.</p>'
					. '<p style="' . $p . '">If a refund is owed, it will be processed back to your original payment method within 3–5 business days.</p>'
					. $btn( '{site_url}', 'Book a new time' )
					. '<p style="' . $muted . '">Questions? Reply to this email or call {business_phone}.</p>',
			),
			'booking_no_show' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 1,
				'subject'            => 'We missed you — {confirmation_number}',
				'body_html'          => '<h2 style="' . $h2 . '">We Missed You</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">We had your reservation for {resource_name} on {start_at}, but we did not see you at the range.</p>'
					. '<p style="' . $p . '">If something came up, please call {business_phone} so we can find you a new time. Per our policy, a no-show fee may apply.</p>'
					. $btn( '{site_url}', 'Book a new time' ),
			),
			'booking_completed' => array(
				'enabled'            => 0,
				'recipient_customer' => 1,
				'recipient_admin'    => 0,
				'subject'            => 'Thanks for visiting {business_name}',
				'body_html'          => '<h2 style="' . $h2 . '">Thanks for Visiting</h2>'
					. '<p style="' . $p . '">Hi {customer_name},</p>'
					. '<p style="' . $p . '">Thank you for choosing {business_name} for your range time. We hope you had a great session.</p>'
					. '{{#if review_url}}'
					. '<p style="' . $p . '">If you enjoyed your visit, would you take 30 seconds to leave us a review? It really helps.</p>'
					. $btn( '{review_url}', 'Leave a review' )
					. '{{/if}}'
					. '<p style="' . $muted . '">We look forward to seeing you again. Book your next session any time at <a href="{site_url}" style="color:#B06316;">our website</a>.</p>',
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
