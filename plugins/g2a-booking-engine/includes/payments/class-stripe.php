<?php
/**
 * Stripe LIVE gateway — Stripe Checkout sessions + webhook signature verification.
 *
 * Uses Stripe Checkout (hosted page) for PCI scope reduction. No JS Elements bundle needed.
 * Pure wp_remote_post against api.stripe.com — no Composer dependency.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Gateway_Stripe {

	const API_BASE = 'https://api.stripe.com/v1';

	public function id() { return 'stripe'; }
	public function label() { return 'Stripe'; }
	public function supports() { return array( 'cards', 'wallets', 'refunds' ); }

	public function is_available() {
		return 1 === (int) get_option( 'g2ab_stripe_enabled', 0 ) && '' !== $this->secret_key();
	}

	private function secret_key() {
		// Allow wp-config constant override for production hardening.
		if ( defined( 'G2AB_STRIPE_SECRET' ) && G2AB_STRIPE_SECRET ) return G2AB_STRIPE_SECRET;
		return (string) get_option( 'g2ab_stripe_secret_key', '' );
	}

	private function publishable_key() {
		if ( defined( 'G2AB_STRIPE_PUBLISHABLE' ) && G2AB_STRIPE_PUBLISHABLE ) return G2AB_STRIPE_PUBLISHABLE;
		return (string) get_option( 'g2ab_stripe_publishable_key', '' );
	}

	private function webhook_secret() {
		if ( defined( 'G2AB_STRIPE_WEBHOOK_SECRET' ) && G2AB_STRIPE_WEBHOOK_SECRET ) return G2AB_STRIPE_WEBHOOK_SECRET;
		return (string) get_option( 'g2ab_stripe_webhook_secret', '' );
	}

	private function payment_table_columns() {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_payments';
		$cols  = array();
		foreach ( array( 'checkout_session_id', 'payment_intent_id', 'idempotency_key_hash', 'attempt_number', 'amount_minor', 'due_now_minor', 'refunded_amount_minor', 'checkout_expires_at', 'updated_at' ) as $col ) {
			$cols[ $col ] = function_exists( 'g2ab_column_exists' ) && g2ab_column_exists( $table, $col );
		}
		return $cols;
	}

	private function add_payment_field( array &$data, array &$formats, array $cols, $key, $value, $format ) {
		if ( ! empty( $cols[ $key ] ) ) {
			$data[ $key ] = $value;
			$formats[]    = $format;
		}
	}

	private function mysql_from_timestamp( $timestamp ) {
		$timestamp = (int) $timestamp;
		return $timestamp > 0 ? gmdate( 'Y-m-d H:i:s', $timestamp + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) : null;
	}

	private function record_payment_issue( $booking_id, $code, array $context = array() ) {
		global $wpdb;
		$safe = array();
		foreach ( $context as $key => $value ) {
			$safe[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => absint( $booking_id ),
			'event_type' => 'payment_manual_review',
			'severity'   => 'warning',
			'message'    => sanitize_key( (string) $code ),
			'context'    => wp_json_encode( $safe ),
			'created_at' => current_time( 'mysql' ),
		) );
	}

	private function header_value( $headers, $name ) {
		$target = strtolower( str_replace( '-', '_', (string) $name ) );
		foreach ( (array) $headers as $key => $value ) {
			$normalized = strtolower( str_replace( '-', '_', (string) $key ) );
			if ( $normalized !== $target ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			return sanitize_text_field( (string) $value );
		}
		return '';
	}

	/**
	 * Create a Stripe Checkout Session. Returns a URL the customer redirects to.
	 *
	 * @param object $booking Booking row from wp_g2ab_bookings.
	 * @param float  $amount  Amount to charge.
	 * @return array|WP_Error
	 */
	public function create_intent( $booking, $amount, $context = array() ) {
		if ( ! $this->is_available() ) return new WP_Error( 'stripe_unconfigured', 'Stripe is not configured.' );

		$currency = class_exists( 'G2AB_Payment_Validator' )
			? G2AB_Payment_Validator::normalize_currency( $booking->currency ?? 'USD' )
			: strtoupper( sanitize_text_field( (string) ( $booking->currency ?? 'USD' ) ) );
		$amount_minor = class_exists( 'G2AB_Payment_Validator' )
			? G2AB_Payment_Validator::amount_to_minor( $amount, $currency )
			: (int) round( (float) $amount * 100 );
		if ( '' === $currency || null === $amount_minor || $amount_minor <= 0 ) {
			return new WP_Error( 'stripe_bad_amount', __( 'Invalid payment amount.', 'g2a-booking' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$pt   = $wpdb->prefix . 'g2ab_payments';
		$cols = $this->payment_table_columns();
		$lock = 'g2ab_stripe_attempt_' . (int) $booking->id;
		if ( ! function_exists( 'g2ab_mysql_lock' ) || ! g2ab_mysql_lock( $lock, 5 ) ) {
			return new WP_Error( 'stripe_attempt_locked', __( 'Payment setup is already in progress. Please retry shortly.', 'g2a-booking' ), array( 'status' => 503 ) );
		}

		try {
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT * FROM {$pt}
				 WHERE booking_id = %d
				   AND gateway = 'stripe'
				   AND status IN ('creating','pending','open','failed')
				 ORDER BY id DESC
				 LIMIT 1",
				(int) $booking->id
			) );
			if ( $existing ) {
				$stored_session = ! empty( $existing->checkout_session_id ) ? (string) $existing->checkout_session_id : (string) $existing->transaction_id;
				$saved          = json_decode( (string) $existing->gateway_response, true );
				$url            = is_array( $saved ) ? esc_url_raw( (string) ( $saved['url'] ?? '' ) ) : '';
				$expires_ok     = empty( $existing->checkout_expires_at ) || strtotime( (string) $existing->checkout_expires_at ) > ( time() + 60 );
				if ( 0 === strpos( $stored_session, 'cs_' ) && $url && $expires_ok ) {
					do_action( 'g2ab_payment_checkout_ready', (int) $booking->id, array(
						'gateway'             => 'stripe',
						'pay_url'             => $url,
						'payment_resume_url'  => $url,
						'checkout_session_id' => $stored_session,
						'checkout_expires_at' => (string) $existing->checkout_expires_at,
						'due_now'             => (float) $existing->amount,
						'currency'            => (string) $existing->currency,
						'idempotent_replay'   => true,
					) );
					return array(
						'gateway'           => 'stripe',
						'gateway_ref'       => $stored_session,
						'redirect_url'      => $url,
						'publishable_key'   => $this->publishable_key(),
						'idempotent_replay' => true,
					);
				}
			}

			$attempt_number = $existing && ! empty( $existing->attempt_number ) ? max( 1, (int) $existing->attempt_number ) : 0;
			if ( ! $attempt_number ) {
				$attempt_number = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(attempt_number),0) + 1 FROM {$pt} WHERE booking_id = %d AND gateway = 'stripe'", (int) $booking->id ) );
				$attempt_number = max( 1, $attempt_number );
			}
			$idempotency_key  = substr( 'g2ab_booking_' . (int) $booking->id . '_attempt_' . $attempt_number, 0, 120 );
			$idempotency_hash = hash( 'sha256', $idempotency_key );
			$attempt_id       = $existing ? (int) $existing->id : 0;

			if ( ! $attempt_id ) {
				$data = array(
					'booking_id'       => (int) $booking->id,
					'gateway'          => 'stripe',
					'transaction_id'   => 'attempt_' . $idempotency_hash,
					'amount'           => (float) $amount,
					'currency'         => $currency,
					'status'           => 'creating',
					'payment_method'   => 'card',
					'gateway_response' => null,
					'metadata'         => wp_json_encode( array( 'attempt_number' => $attempt_number ) ),
					'created_at'       => current_time( 'mysql' ),
				);
				$formats = array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' );
				$this->add_payment_field( $data, $formats, $cols, 'idempotency_key_hash', $idempotency_hash, '%s' );
				$this->add_payment_field( $data, $formats, $cols, 'attempt_number', $attempt_number, '%d' );
				$this->add_payment_field( $data, $formats, $cols, 'amount_minor', $amount_minor, '%d' );
				$this->add_payment_field( $data, $formats, $cols, 'due_now_minor', $amount_minor, '%d' );
				$this->add_payment_field( $data, $formats, $cols, 'updated_at', current_time( 'mysql' ), '%s' );
				$attempt_id = function_exists( 'g2ab_insert_or_update_payment' ) ? g2ab_insert_or_update_payment( $pt, $data, $formats ) : 0;
			}
		} finally {
			if ( function_exists( 'g2ab_mysql_unlock' ) ) {
				g2ab_mysql_unlock( $lock );
			}
		}
		if ( ! $attempt_id ) {
			return new WP_Error( 'stripe_attempt_failed', __( 'Could not create payment attempt.', 'g2a-booking' ), array( 'status' => 500 ) );
		}

		$success = add_query_arg( array( 'g2ab_paid' => $booking->uuid ), home_url( '/' ) );
		$cancel  = add_query_arg( array( 'g2ab_cancel' => $booking->uuid ), home_url( '/' ) );
		if ( ! empty( $context['confirm_token'] ) ) {
			$success = add_query_arg( array( 'g2ab_token' => rawurlencode( (string) $context['confirm_token'] ) ), $success );
		}

		$body = array(
			'mode' => 'payment',
			'payment_method_types[]' => 'card',
			'success_url' => $success . '&session_id={CHECKOUT_SESSION_ID}',
			'cancel_url'  => $cancel,
			'customer_email' => $booking->customer_email,
			'customer_creation' => 'always',
			'phone_number_collection[enabled]' => 'true',
			'client_reference_id' => $booking->uuid,
			'line_items[0][quantity]' => 1,
			'line_items[0][price_data][currency]' => strtolower( $currency ),
			'line_items[0][price_data][unit_amount]' => $amount_minor,
			'line_items[0][price_data][product_data][name]' => sprintf( 'G2A booking - %s', mysql2date( 'M j, Y g:i A', $booking->start_at ) ),
			'metadata[booking_id]' => (int) $booking->id,
			'metadata[booking_uuid]' => $booking->uuid,
			'metadata[payment_attempt_id]' => (int) $attempt_id,
		);

		$resp = wp_remote_post( self::API_BASE . '/checkout/sessions', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization'   => 'Bearer ' . $this->secret_key(),
				'Content-Type'    => 'application/x-www-form-urlencoded',
				'Idempotency-Key' => $idempotency_key,
			),
			'body' => $body,
		) );

		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 || empty( $json['url'] ) || empty( $json['id'] ) ) {
			$wpdb->update( $pt, array(
				'status'           => 'failed',
				'gateway_response' => wp_json_encode( array( 'error_code' => sanitize_key( (string) ( $json['error']['code'] ?? 'stripe_create_failed' ) ) ) ),
			), array( 'id' => (int) $attempt_id ), array( '%s', '%s' ), array( '%d' ) );
			return new WP_Error( 'stripe_create_failed', __( 'Stripe Checkout creation failed.', 'g2a-booking' ), array( 'status' => 502 ) );
		}

		$sanitized = class_exists( 'G2AB_Payment_Validator' ) ? G2AB_Payment_Validator::sanitize_stripe_session( $json ) : array( 'id' => $json['id'], 'url' => $json['url'] );
		$update    = array(
			'transaction_id'   => ! empty( $json['payment_intent'] ) ? sanitize_text_field( (string) $json['payment_intent'] ) : sanitize_text_field( (string) $json['id'] ),
			'status'           => 'open',
			'amount'           => (float) $amount,
			'currency'         => $currency,
			'gateway_response' => wp_json_encode( $sanitized ),
			'metadata'         => wp_json_encode( array( 'attempt_number' => $attempt_number ) ),
		);
		$formats = array( '%s', '%s', '%f', '%s', '%s', '%s' );
		$this->add_payment_field( $update, $formats, $cols, 'checkout_session_id', sanitize_text_field( (string) $json['id'] ), '%s' );
		$this->add_payment_field( $update, $formats, $cols, 'payment_intent_id', ! empty( $json['payment_intent'] ) ? sanitize_text_field( (string) $json['payment_intent'] ) : '', '%s' );
		$this->add_payment_field( $update, $formats, $cols, 'amount_minor', $amount_minor, '%d' );
		$this->add_payment_field( $update, $formats, $cols, 'due_now_minor', $amount_minor, '%d' );
		$this->add_payment_field( $update, $formats, $cols, 'checkout_expires_at', $this->mysql_from_timestamp( $json['expires_at'] ?? 0 ), '%s' );
		$this->add_payment_field( $update, $formats, $cols, 'updated_at', current_time( 'mysql' ), '%s' );
		$wpdb->update( $pt, $update, array( 'id' => (int) $attempt_id ), $formats, array( '%d' ) );

		do_action( 'g2ab_payment_intent_created', 'stripe', $booking->id, $amount, $sanitized );
		do_action( 'g2ab_payment_checkout_ready', (int) $booking->id, array(
			'gateway'             => 'stripe',
			'pay_url'             => esc_url_raw( (string) $json['url'] ),
			'payment_resume_url'  => esc_url_raw( (string) $json['url'] ),
			'checkout_session_id' => sanitize_text_field( (string) $json['id'] ),
			'checkout_expires_at' => $this->mysql_from_timestamp( $json['expires_at'] ?? 0 ),
			'due_now'             => (float) $amount,
			'currency'            => $currency,
		) );

		return array(
			'gateway'         => 'stripe',
			'gateway_ref'     => sanitize_text_field( (string) $json['id'] ),
			'redirect_url'    => esc_url_raw( (string) $json['url'] ),
			'publishable_key' => $this->publishable_key(),
		);
	}

	/**
	 * Confirm payment by retrieving session. Used as fallback when webhook hasn't fired yet.
	 *
	 * @param string $session_id Checkout Session ID.
	 * @param array  $context    Extra context.
	 * @return bool|WP_Error
	 */
	public function confirm_payment( $session_id, $context = array() ) {
		$resp = wp_remote_get( self::API_BASE . '/checkout/sessions/' . urlencode( $session_id ), array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $this->secret_key() ),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! empty( $context['uuid'] ) && (string) ( $json['client_reference_id'] ?? '' ) !== (string) $context['uuid'] ) {
			return new WP_Error( 'stripe_session_booking_mismatch', __( 'Payment session does not belong to this booking.', 'g2a-booking' ), array( 'status' => 409 ) );
		}
		if ( ! empty( $json['payment_status'] ) && 'paid' === $json['payment_status'] ) {
			return $this->mark_booking_paid( $json );
		}
		return false;
	}

	/**
	 * Refund a payment.
	 *
	 * @param string $payment_intent_id Stripe payment_intent ID.
	 * @param float  $amount            Amount to refund.
	 * @return array|WP_Error
	 */
	public function refund( $payment_intent_id, $amount = 0 ) {
		$body = array( 'payment_intent' => $payment_intent_id );
		if ( $amount > 0 ) $body['amount'] = (int) round( $amount * 100 );

		$resp = wp_remote_post( self::API_BASE . '/refunds', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body' => $body,
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( wp_remote_retrieve_response_code( $resp ) >= 400 ) {
			return new WP_Error( 'stripe_refund_failed', $json['error']['message'] ?? 'Refund failed' );
		}
		return array( 'success' => true, 'gateway_ref' => $json['id'], 'amount' => $amount );
	}

	/**
	 * Verify Stripe webhook signature.
	 *
	 * @param string $body    Raw request body.
	 * @param array  $headers Request headers.
	 * @return array|WP_Error Parsed event payload or error.
	 */
	public function verify_webhook( $body, $headers ) {
		$secret = $this->webhook_secret();
		if ( empty( $secret ) ) return new WP_Error( 'no_webhook_secret', 'Webhook secret not configured.', array( 'status' => 400 ) );

		$sig_header = $this->header_value( $headers, 'stripe-signature' );
		if ( '' === $sig_header && ! empty( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) {
			$sig_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) );
		}
		if ( empty( $sig_header ) ) return new WP_Error( 'no_signature', 'Missing Stripe-Signature header.', array( 'status' => 400 ) );

		// Parse "t=...,v1=..."
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $kv ) {
			$kv = trim( $kv );
			$pos = strpos( $kv, '=' );
			if ( false !== $pos ) {
				$key = substr( $kv, 0, $pos );
				$val = substr( $kv, $pos + 1 );
				if ( 'v1' === $key ) {
					$parts['v1'][] = $val;
				} else {
					$parts[ $key ] = $val;
				}
			}
		}
		$timestamp = $parts['t'] ?? '';
		$v1 = isset( $parts['v1'] ) && is_array( $parts['v1'] ) ? $parts['v1'] : array();
		if ( empty( $timestamp ) || empty( $v1 ) ) return new WP_Error( 'bad_signature', 'Malformed signature header.', array( 'status' => 400 ) );

		// Tolerance: 5 minutes, past only — future-dated stamps are suspicious.
		$tolerance = (int) apply_filters( 'g2ab_stripe_webhook_tolerance', 300 );
		$tolerance = max( 60, min( DAY_IN_SECONDS, $tolerance ) );
		if ( abs( time() - (int) $timestamp ) > $tolerance ) return new WP_Error( 'signature_expired', 'Signature timestamp outside tolerance.', array( 'status' => 400 ) );

		$signed_payload = $timestamp . '.' . $body;
		$expected = hash_hmac( 'sha256', $signed_payload, $secret );
		$matched = false;
		foreach ( $v1 as $candidate ) {
			if ( hash_equals( $expected, (string) $candidate ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) return new WP_Error( 'bad_signature', 'Signature mismatch.', array( 'status' => 400 ) );

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) return new WP_Error( 'bad_payload', 'Invalid JSON.', array( 'status' => 400 ) );

		return $event;
	}

	/**
	 * Process a verified Stripe webhook event.
	 *
	 * @param array $event Verified Stripe event.
	 * @return array
	 */
	public function process_webhook_event( $event ) {
		$type     = $event['type'] ?? '';
		$event_id = (string) ( $event['id'] ?? '' );
		$obj      = $event['data']['object'] ?? array();

		if ( $event_id && function_exists( 'g2ab_webhook_event_claim' ) ) {
			$claim = g2ab_webhook_event_claim( 'stripe', $event_id, $type, wp_json_encode( $event ) );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
			if ( 'duplicate' === $claim ) {
				return array( 'handled' => true, 'type' => $type, 'reason' => 'duplicate_event', 'event_id' => $event_id );
			}
		}

		$result = null;
		if ( 'checkout.session.completed' === $type && 'paid' === ( $obj['payment_status'] ?? '' ) ) {
			$result = $this->mark_booking_paid( $obj );
		} elseif ( 'charge.refunded' === $type ) {
			$result = $this->mark_booking_refunded( $obj );
		} elseif ( 'payment_intent.succeeded' === $type ) {
			$result = array( 'handled' => true, 'type' => $type, 'reason' => 'acknowledged_payment_intent' );
		} else {
			$result = array( 'handled' => true, 'type' => $type, 'reason' => 'verified_event_ignored' );
		}

		if ( is_wp_error( $result ) ) {
			if ( $event_id && function_exists( 'g2ab_webhook_event_mark_failed' ) ) {
				g2ab_webhook_event_mark_failed( 'stripe', $event_id, $result->get_error_message() );
			}
			return $result;
		}

		if ( ! empty( $result['retryable'] ) ) {
			if ( $event_id && function_exists( 'g2ab_webhook_event_mark_failed' ) ) {
				g2ab_webhook_event_mark_failed( 'stripe', $event_id, wp_json_encode( $result ) );
			}
			return $result;
		}

		if ( $event_id && function_exists( 'g2ab_webhook_event_mark_processed' ) ) {
			g2ab_webhook_event_mark_processed( 'stripe', $event_id );
		}

		return $result;
	}

	/**
	 * Mark booking paid + insert payment row.
	 *
	 * @param array $session Stripe Checkout session object.
	 * @return array
	 */
	private function mark_booking_paid( $session ) {
		global $wpdb;
		$uuid = $session['client_reference_id'] ?? ( $session['metadata']['booking_uuid'] ?? '' );
		if ( empty( $uuid ) ) return array( 'handled' => false, 'reason' => 'no_uuid' );

		$bt = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) return array( 'handled' => false, 'reason' => 'booking_not_found' );

		// Idempotent — skip if already paid.
		if ( in_array( $booking->status, array( 'paid', 'completed' ), true ) ) {
			return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => $booking->status, 'reason' => 'already_paid' );
		}

		$session_id        = isset( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '';
		$payment_intent_id = isset( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : '';
		$pt                = $wpdb->prefix . 'g2ab_payments';
		$cols              = $this->payment_table_columns();
		$payment           = null;
		if ( ! empty( $session['metadata']['payment_attempt_id'] ) ) {
			$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE id = %d AND booking_id = %d AND gateway = 'stripe' LIMIT 1", (int) $session['metadata']['payment_attempt_id'], (int) $booking->id ) );
		}
		if ( ! $payment && ! empty( $cols['checkout_session_id'] ) ) {
			$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE gateway = 'stripe' AND booking_id = %d AND checkout_session_id = %s LIMIT 1", (int) $booking->id, $session_id ) );
		}
		if ( ! $payment ) {
			$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE gateway = 'stripe' AND booking_id = %d AND transaction_id = %s LIMIT 1", (int) $booking->id, $session_id ) );
		}
		if ( ! $payment ) {
			$this->record_payment_issue( (int) $booking->id, 'stripe_payment_attempt_missing', array( 'session_id' => $session_id ) );
			return array( 'handled' => false, 'reason' => 'payment_attempt_missing', 'retryable' => false );
		}

		$validation = class_exists( 'G2AB_Payment_Validator' )
			? G2AB_Payment_Validator::validate_stripe_checkout_session( $session, $booking, $payment )
			: true;
		if ( is_wp_error( $validation ) ) {
			$this->record_payment_issue( (int) $booking->id, $validation->get_error_code(), array( 'session_id' => $session_id ) );
			$data = $validation->get_error_data();
			return array(
				'handled'   => false,
				'reason'    => $validation->get_error_code(),
				'retryable' => is_array( $data ) && empty( $data['permanent'] ),
			);
		}

		$duplicate_pi = '';
		if ( $payment_intent_id && ! empty( $cols['payment_intent_id'] ) ) {
			$duplicate_pi = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE gateway = 'stripe' AND payment_intent_id = %s AND booking_id <> %d LIMIT 1", $payment_intent_id, (int) $booking->id ) );
		} elseif ( $payment_intent_id ) {
			$duplicate_pi = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE gateway = 'stripe' AND transaction_id = %s AND booking_id <> %d LIMIT 1", $payment_intent_id, (int) $booking->id ) );
		}
		if ( $duplicate_pi ) {
			$this->record_payment_issue( (int) $booking->id, 'stripe_payment_intent_reused', array( 'payment_intent_id' => $payment_intent_id ) );
			return array( 'handled' => false, 'reason' => 'payment_intent_reused', 'retryable' => false );
		}

		$amount          = (float) $validation['amount'];
		$currency        = (string) $validation['currency'];
		$previous_status = (string) $booking->status;
		$sanitized       = $validation['sanitized_session'];

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return array( 'handled' => false, 'retryable' => true, 'reason' => 'transaction_start_failed' );
		}
		$locked_booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE id = %d FOR UPDATE", (int) $booking->id ) );
		$locked_payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE id = %d FOR UPDATE", (int) $payment->id ) );
		if ( ! $locked_booking || ! $locked_payment ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'handled' => false, 'retryable' => true, 'reason' => 'lock_failed' );
		}
		if ( in_array( (string) $locked_booking->status, array( 'paid', 'completed' ), true ) ) {
			$wpdb->query( 'COMMIT' );
			return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => $locked_booking->status, 'reason' => 'already_paid' );
		}

		$payment_update = array(
			'transaction_id'   => $payment_intent_id ? $payment_intent_id : $session_id,
			'amount'           => $amount,
			'currency'         => $currency,
			'status'           => 'succeeded',
			'payment_method'   => 'card',
			'gateway_response' => wp_json_encode( $sanitized ),
			'metadata'         => wp_json_encode( array( 'checkout_session_id' => $session_id, 'payment_intent_id' => $payment_intent_id ) ),
			'processed_at'     => current_time( 'mysql' ),
		);
		$payment_formats = array( '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' );
		$this->add_payment_field( $payment_update, $payment_formats, $cols, 'checkout_session_id', $session_id, '%s' );
		$this->add_payment_field( $payment_update, $payment_formats, $cols, 'payment_intent_id', $payment_intent_id, '%s' );
		$this->add_payment_field( $payment_update, $payment_formats, $cols, 'amount_minor', (int) $validation['amount_minor'], '%d' );
		$this->add_payment_field( $payment_update, $payment_formats, $cols, 'due_now_minor', (int) $validation['expected_minor'], '%d' );
		$this->add_payment_field( $payment_update, $payment_formats, $cols, 'updated_at', current_time( 'mysql' ), '%s' );
		$payment_updated = $wpdb->update( $pt, $payment_update, array( 'id' => (int) $payment->id ), $payment_formats, array( '%d' ) );
		if ( false === $payment_updated ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'handled' => false, 'retryable' => true, 'reason' => 'payment_update_failed' );
		}

		$booking_updated = $wpdb->update( $bt, array(
			'status'            => 'paid',
			'paid_amount'       => $amount,
			'gateway_intent_id' => $payment_intent_id,
			'updated_at'        => current_time( 'mysql' ),
		), array( 'id' => (int) $booking->id ), array( '%s', '%f', '%s', '%s' ), array( '%d' ) );
		if ( false === $booking_updated ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'handled' => false, 'retryable' => true, 'reason' => 'booking_update_failed' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return array( 'handled' => false, 'retryable' => true, 'reason' => 'commit_failed' );
		}

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => (int) $booking->id,
			'event_type' => 'payment_succeeded',
			'severity'   => 'info',
			'message'    => sprintf( 'Stripe payment succeeded: $%s', number_format( $amount, 2 ) ),
			'context'    => wp_json_encode( array( 'session_id' => $session_id, 'payment_intent_id' => $payment_intent_id ) ),
			'created_at' => current_time( 'mysql' ),
		) );

		if ( function_exists( 'g2ab_queue_booking_paid_side_effects' ) ) {
			g2ab_queue_booking_paid_side_effects( (int) $booking->id, 'stripe', $previous_status, $sanitized );
		}

		return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => 'paid' );
	}

	private function mark_booking_refunded( $charge ) {
		global $wpdb;
		$pi_id = $charge['payment_intent'] ?? '';
		if ( ! $pi_id ) return array( 'handled' => false, 'reason' => 'no_payment_intent' );

		// Find related booking via payment row.
		$pt = $wpdb->prefix . 'g2ab_payments';
		$cols    = $this->payment_table_columns();
		$payment = ! empty( $cols['payment_intent_id'] )
			? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE gateway = 'stripe' AND payment_intent_id = %s LIMIT 1", $pi_id ) )
			: null;
		if ( ! $payment ) {
			$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE gateway = 'stripe' AND transaction_id = %s LIMIT 1", $pi_id ) );
		}
		if ( ! $payment ) return array( 'handled' => false, 'reason' => 'payment_not_found', 'payment_intent' => $pi_id );

		$refund_amount = ( $charge['amount_refunded'] ?? 0 ) / 100.0;
		$currency      = class_exists( 'G2AB_Payment_Validator' ) ? G2AB_Payment_Validator::normalize_currency( $payment->currency ?? 'USD' ) : strtoupper( (string) ( $payment->currency ?? 'USD' ) );
		$refunded_minor = isset( $charge['amount_refunded'] ) ? (int) $charge['amount_refunded'] : null;
		$paid_minor     = class_exists( 'G2AB_Payment_Validator' ) ? G2AB_Payment_Validator::amount_to_minor( (float) $payment->amount, $currency ) : (int) round( (float) $payment->amount * 100 );
		$is_full        = null !== $refunded_minor && null !== $paid_minor && $refunded_minor >= $paid_minor;
		$payment_status = $is_full ? 'refunded' : 'partial_refund';
		$booking_status = $is_full ? 'refunded' : 'partially_refunded';
		if ( (float) $payment->refund_amount >= $refund_amount && in_array( (string) $payment->status, array( 'refunded', 'partial_refund', 'partially_refunded' ), true ) ) {
			return array( 'handled' => true, 'booking_id' => $payment->booking_id, 'status' => (string) $payment->status, 'reason' => 'duplicate_refund' );
		}

		$cols   = $this->payment_table_columns();
		$update = array(
			'status'        => $payment_status,
			'refund_amount' => $refund_amount,
		);
		$formats = array( '%s', '%f' );
		$this->add_payment_field( $update, $formats, $cols, 'refunded_amount_minor', (int) $refunded_minor, '%d' );
		$this->add_payment_field( $update, $formats, $cols, 'updated_at', current_time( 'mysql' ), '%s' );
		$wpdb->update( $pt, $update, array( 'id' => $payment->id ), $formats, array( '%d' ) );

		// The ledger row above IS the refund evidence, so the transition
		// service's invariant passes; it also records previous status in the
		// audit trail and fires status hooks with correct context.
		if ( class_exists( 'G2AB_Booking_Transitions' ) ) {
			G2AB_Booking_Transitions::transition( (int) $payment->booking_id, $booking_status, array(
				'source'     => 'gateway',
				'reason'     => 'stripe_charge_refunded',
				'payment_id' => (int) $payment->id,
			) );
		} else {
			$wpdb->update( $wpdb->prefix . 'g2ab_bookings', array(
				'status' => $booking_status,
				'updated_at' => current_time( 'mysql' ),
			), array( 'id' => $payment->booking_id ), array( '%s', '%s' ), array( '%d' ) );
		}

		do_action( 'g2ab_payment_refunded', $payment->booking_id, $refund_amount, 'stripe', $charge );
		return array( 'handled' => true, 'booking_id' => $payment->booking_id, 'status' => $booking_status, 'refund_amount' => $refund_amount );
	}
}
