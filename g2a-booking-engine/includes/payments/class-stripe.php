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

	/**
	 * Create a Stripe Checkout Session. Returns a URL the customer redirects to.
	 *
	 * @param object $booking Booking row from wp_g2ab_bookings.
	 * @param float  $amount  Amount to charge.
	 * @return array|WP_Error
	 */
	public function create_intent( $booking, $amount ) {
		if ( ! $this->is_available() ) return new WP_Error( 'stripe_unconfigured', 'Stripe is not configured.' );

		$amount_cents = (int) round( $amount * 100 );
		$currency = strtolower( $booking->currency ?? 'usd' );
		$success = add_query_arg( array( 'g2ab_paid' => $booking->uuid ), home_url( '/' ) );
		$cancel  = add_query_arg( array( 'g2ab_cancel' => $booking->uuid ), home_url( '/' ) );

		// The confirm-payment REST fallback refuses to act without the
		// confirm_token issued at booking creation, so the return page must
		// carry it. The customer already holds this token (it's in the
		// create-booking response), so the URL adds no new exposure.
		$meta = json_decode( (string) ( $booking->metadata ?? '' ), true );
		if ( ! empty( $meta['confirm_token'] ) ) {
			$success = add_query_arg( array( 'g2ab_token' => rawurlencode( (string) $meta['confirm_token'] ) ), $success );
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
			'line_items[0][price_data][currency]' => $currency,
			'line_items[0][price_data][unit_amount]' => $amount_cents,
			'line_items[0][price_data][product_data][name]' => sprintf( '%s — %s', $booking->customer_name, mysql2date( 'M j, Y g:i A', $booking->start_at ) ),
			'metadata[booking_id]' => (int) $booking->id,
			'metadata[booking_uuid]' => $booking->uuid,
			'metadata[customer_name]' => $booking->customer_name,
			'metadata[customer_email]' => $booking->customer_email,
			'metadata[customer_phone]' => $booking->customer_phone,
		);

		$resp = wp_remote_post( self::API_BASE . '/checkout/sessions', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->secret_key(),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body' => $body,
		) );

		if ( is_wp_error( $resp ) ) return $resp;
		$code = wp_remote_retrieve_response_code( $resp );
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 400 || empty( $json['url'] ) ) {
			return new WP_Error( 'stripe_create_failed', $json['error']['message'] ?? 'Stripe Checkout creation failed', array( 'status' => 502 ) );
		}

		// Track the pending payment intent.
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'g2ab_payments', array(
			'booking_id' => (int) $booking->id,
			'gateway' => 'stripe',
			'transaction_id' => $json['id'],
			'amount' => (float) $amount,
			'currency' => strtoupper( $currency ),
			'status' => 'pending',
			'payment_method' => 'card',
			'gateway_response' => wp_json_encode( $json ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_intent_created', 'stripe', $booking->id, $amount, $json );

		return array(
			'gateway' => 'stripe',
			'gateway_ref' => $json['id'],
			'redirect_url' => $json['url'],
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

		$sig_header = '';
		foreach ( $headers as $k => $v ) {
			if ( 'stripe-signature' === strtolower( $k ) ) {
				$sig_header = is_array( $v ) ? $v[0] : $v;
				break;
			}
		}
		if ( empty( $sig_header ) ) return new WP_Error( 'no_signature', 'Missing Stripe-Signature header.', array( 'status' => 400 ) );

		// Parse "t=...,v1=..."
		$parts = array();
		foreach ( explode( ',', $sig_header ) as $kv ) {
			$kv = trim( $kv );
			$pos = strpos( $kv, '=' );
			if ( $pos !== false ) $parts[ substr( $kv, 0, $pos ) ] = substr( $kv, $pos + 1 );
		}
		$timestamp = $parts['t'] ?? '';
		$v1 = $parts['v1'] ?? '';
		if ( empty( $timestamp ) || empty( $v1 ) ) return new WP_Error( 'bad_signature', 'Malformed signature header.', array( 'status' => 400 ) );

		// Tolerance: 5 minutes, past only — future-dated stamps are suspicious.
		$delta = time() - (int) $timestamp;
		if ( $delta < -10 || $delta > 300 ) return new WP_Error( 'signature_expired', 'Signature timestamp outside tolerance.', array( 'status' => 400 ) );

		$signed_payload = $timestamp . '.' . $body;
		$expected = hash_hmac( 'sha256', $signed_payload, $secret );
		if ( ! hash_equals( $expected, $v1 ) ) return new WP_Error( 'bad_signature', 'Signature mismatch.', array( 'status' => 400 ) );

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

		// Idempotency: Stripe retries any non-2xx response. Skip duplicates.
		if ( $event_id && function_exists( 'g2ab_webhook_event_is_new' ) && ! g2ab_webhook_event_is_new( 'stripe', $event_id ) ) {
			return array( 'handled' => true, 'type' => $type, 'reason' => 'duplicate_event', 'event_id' => $event_id );
		}

		if ( 'checkout.session.completed' === $type && 'paid' === ( $obj['payment_status'] ?? '' ) ) {
			return $this->mark_booking_paid( $obj );
		}
		if ( 'charge.refunded' === $type ) {
			return $this->mark_booking_refunded( $obj );
		}
		if ( 'payment_intent.succeeded' === $type ) {
			// Alternative path if not using Checkout Sessions.
			return array( 'handled' => true, 'type' => $type );
		}
		return array( 'handled' => false, 'type' => $type );
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

		// Amount.
		$amount_cents = (int) ( $session['amount_total'] ?? 0 );
		$amount = $amount_cents / 100.0;
		$currency = strtoupper( $session['currency'] ?? $booking->currency );

		// Amount cross-check: refuse to flip status if reported amount doesn't match.
		if ( function_exists( 'g2ab_validate_payment_amount' ) && ! g2ab_validate_payment_amount( $booking, $amount ) ) {
			$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'payment_amount_mismatch',
				'severity'   => 'warning',
				'message'    => sprintf( 'Stripe amount mismatch: paid=%s expected=%s', number_format( $amount, 2 ), number_format( (float) $booking->total_amount, 2 ) ),
				'context'    => wp_json_encode( array( 'session_id' => $session['id'] ?? '' ) ),
				'created_at' => current_time( 'mysql' ),
			) );
			return array( 'handled' => false, 'reason' => 'amount_mismatch', 'paid' => $amount, 'expected' => (float) $booking->total_amount );
		}

		// Capture previous status so listeners get correct context.
		$previous_status = (string) $booking->status;

		$wpdb->update( $bt, array(
			'status' => 'paid',
			'paid_amount' => $amount,
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $booking->id ), array( '%s', '%f', '%s' ), array( '%d' ) );

		// Update or insert payment row.
		$pt = $wpdb->prefix . 'g2ab_payments';
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE transaction_id = %s LIMIT 1", $session['id'] ) );
		if ( $existing ) {
			$wpdb->update( $pt, array(
				'status' => 'succeeded',
				'amount' => $amount,
				'gateway_response' => wp_json_encode( $session ),
				'processed_at' => current_time( 'mysql' ),
			), array( 'id' => $existing ), array( '%s', '%f', '%s', '%s' ), array( '%d' ) );
		} else {
			g2ab_insert_or_update_payment( $pt, array(
				'booking_id' => $booking->id,
				'gateway' => 'stripe',
				'transaction_id' => $session['id'],
				'amount' => $amount,
				'currency' => $currency,
				'status' => 'succeeded',
				'payment_method' => 'card',
				'gateway_response' => wp_json_encode( $session ),
				'processed_at' => current_time( 'mysql' ),
				'created_at' => current_time( 'mysql' ),
			), array( '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}

		// Audit log.
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking->id,
			'event_type' => 'payment_succeeded',
			'severity' => 'info',
			'message' => sprintf( 'Stripe payment succeeded: $%s', number_format( $amount, 2 ) ),
			'context' => wp_json_encode( array( 'session_id' => $session['id'] ) ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_succeeded', $booking->id, 'stripe', $session );
		do_action( 'g2ab_booking_status_changed', $booking->id, 'paid', $previous_status );

		return array( 'handled' => true, 'booking_id' => $booking->id, 'status' => 'paid' );
	}

	private function mark_booking_refunded( $charge ) {
		global $wpdb;
		$pi_id = $charge['payment_intent'] ?? '';
		if ( ! $pi_id ) return array( 'handled' => false );

		// Find related booking via payment row.
		$pt = $wpdb->prefix . 'g2ab_payments';
		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pt} WHERE transaction_id LIKE %s LIMIT 1", '%' . $wpdb->esc_like( $pi_id ) . '%' ) );
		if ( ! $payment ) return array( 'handled' => false );

		$refund_amount = ( $charge['amount_refunded'] ?? 0 ) / 100.0;

		$wpdb->update( $pt, array(
			'status' => 'refunded',
			'refund_amount' => $refund_amount,
		), array( 'id' => $payment->id ), array( '%s', '%f' ), array( '%d' ) );

		$wpdb->update( $wpdb->prefix . 'g2ab_bookings', array(
			'status' => 'refunded',
			'updated_at' => current_time( 'mysql' ),
		), array( 'id' => $payment->booking_id ), array( '%s', '%s' ), array( '%d' ) );

		do_action( 'g2ab_payment_refunded', $payment->booking_id, $refund_amount );
		return array( 'handled' => true, 'booking_id' => $payment->booking_id, 'status' => 'refunded' );
	}
}
