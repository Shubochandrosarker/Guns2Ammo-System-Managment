<?php
/**
 * PayPal LIVE gateway — Orders v2 API + webhook verification.
 *
 * Flow:
 *   1. OAuth2 client_credentials → access_token (cached 8 hours)
 *   2. Create Order with intent=CAPTURE → returns approval URL
 *   3. Customer redirects → PayPal → approves
 *   4. PayPal redirects back with ?token={order_id}
 *   5. Server captures order via /v2/checkout/orders/{id}/capture
 *   6. Webhook PAYMENT.CAPTURE.COMPLETED → mark booking paid (verified via PayPal verify-webhook-signature endpoint)
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Gateway_Paypal {

	public function id() { return 'paypal'; }
	public function label() { return 'PayPal'; }
	public function supports() { return array( 'cards', 'wallets', 'refunds' ); }

	public function is_available() {
		return 1 === (int) get_option( 'g2ab_paypal_enabled', 0 ) && '' !== $this->client_id() && '' !== $this->secret();
	}

	private function api_base() {
		return 1 === (int) get_option( 'g2ab_paypal_test_mode', 1 )
			? 'https://api-m.sandbox.paypal.com'
			: 'https://api-m.paypal.com';
	}

	private function client_id() {
		if ( defined( 'G2AB_PAYPAL_CLIENT_ID' ) && G2AB_PAYPAL_CLIENT_ID ) return G2AB_PAYPAL_CLIENT_ID;
		return (string) get_option( 'g2ab_paypal_client_id', '' );
	}

	private function secret() {
		if ( defined( 'G2AB_PAYPAL_SECRET' ) && G2AB_PAYPAL_SECRET ) return G2AB_PAYPAL_SECRET;
		return (string) get_option( 'g2ab_paypal_secret', '' );
	}

	private function webhook_id() {
		if ( defined( 'G2AB_PAYPAL_WEBHOOK_ID' ) && G2AB_PAYPAL_WEBHOOK_ID ) return G2AB_PAYPAL_WEBHOOK_ID;
		return (string) get_option( 'g2ab_paypal_webhook_id', '' );
	}

	/**
	 * Get cached or fresh access token.
	 *
	 * @return string|WP_Error
	 */
	private function access_token() {
		$cache_key = 'g2ab_paypal_token_' . substr( md5( $this->client_id() . $this->api_base() ), 0, 12 );
		$cached = get_transient( $cache_key );
		if ( $cached ) return $cached;

		$resp = wp_remote_post( $this->api_base() . '/v1/oauth2/token', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->client_id() . ':' . $this->secret() ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
				'Accept'        => 'application/json',
			),
			'body' => 'grant_type=client_credentials',
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $json['access_token'] ) ) {
			return new WP_Error( 'paypal_auth_failed', $json['error_description'] ?? 'PayPal OAuth failed' );
		}
		// PayPal tokens last ~9h. Cache for 8h.
		set_transient( $cache_key, $json['access_token'], 8 * HOUR_IN_SECONDS );
		return $json['access_token'];
	}

	public function create_intent( $booking, $amount ) {
		if ( ! $this->is_available() ) return new WP_Error( 'paypal_unconfigured', 'PayPal is not configured.' );

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) return $token;

		$meta = json_decode( (string) ( $booking->metadata ?? '' ), true );
		$confirm_token = is_array( $meta ) ? (string) ( $meta['confirm_token'] ?? '' ) : '';
		$success = add_query_arg(
			array_filter( array( 'g2ab_paid' => $booking->uuid, 'gw' => 'paypal', 'confirm_token' => $confirm_token ) ),
			home_url( '/' )
		);
		$cancel  = add_query_arg(
			array_filter( array( 'g2ab_cancel' => $booking->uuid, 'confirm_token' => $confirm_token ) ),
			home_url( '/' )
		);
		$currency = strtoupper( $booking->currency ?? 'USD' );

		$payload = array(
			'intent' => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => $booking->uuid,
					'amount' => array( 'currency_code' => $currency, 'value' => number_format( (float) $amount, 2, '.', '' ) ),
					'description' => sprintf( '%s — %s', $booking->customer_name, mysql2date( 'M j Y g:i A', $booking->start_at ) ),
					'custom_id' => $booking->uuid,
				),
			),
			'application_context' => array(
				'return_url' => $success,
				'cancel_url' => $cancel,
				'brand_name' => get_option( 'g2ab_business_name', 'G2A Booking' ),
				'user_action' => 'PAY_NOW',
				'shipping_preference' => 'NO_SHIPPING',
			),
		);

		$resp = wp_remote_post( $this->api_base() . '/v2/checkout/orders', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code >= 400 || empty( $json['id'] ) ) {
			return new WP_Error( 'paypal_create_failed', $json['message'] ?? 'PayPal Order creation failed' );
		}

		// Find approve link
		$approve_url = '';
		foreach ( ( $json['links'] ?? array() ) as $link ) {
			if ( ! empty( $link['rel'] ) && 'approve' === $link['rel'] ) { $approve_url = $link['href']; break; }
		}

		// Track pending payment.
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'g2ab_payments', array(
			'booking_id' => (int) $booking->id,
			'gateway' => 'paypal',
			'transaction_id' => $json['id'],
			'amount' => (float) $amount,
			'currency' => $currency,
			'status' => 'pending',
			'payment_method' => 'paypal',
			'gateway_response' => wp_json_encode( $json ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_intent_created', 'paypal', $booking->id, $amount, $json );

		return array(
			'gateway' => 'paypal',
			'gateway_ref' => $json['id'],
			'redirect_url' => $approve_url,
		);
	}

	/**
	 * Capture the order after PayPal redirects back.
	 *
	 * @param string $order_id PayPal Order ID.
	 * @return bool|WP_Error
	 */
	public function confirm_payment( $order_id, $context = array() ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) return $token;

		$resp = wp_remote_post( $this->api_base() . '/v2/checkout/orders/' . urlencode( $order_id ) . '/capture', array(
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
				'PayPal-Request-Id' => 'g2ab-' . $order_id,
			),
			'body' => '{}',
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! empty( $json['status'] ) && in_array( $json['status'], array( 'COMPLETED', 'APPROVED' ), true ) ) {
			return $this->mark_booking_paid( $json );
		}
		return false;
	}

	public function refund( $capture_id, $amount = 0 ) {
		$token = $this->access_token();
		if ( is_wp_error( $token ) ) return $token;
		$body = array();
		if ( $amount > 0 ) $body['amount'] = array( 'value' => number_format( (float) $amount, 2, '.', '' ), 'currency_code' => 'USD' );
		$resp = wp_remote_post( $this->api_base() . '/v2/payments/captures/' . urlencode( $capture_id ) . '/refund', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( wp_remote_retrieve_response_code( $resp ) >= 400 ) return new WP_Error( 'paypal_refund_failed', $json['message'] ?? 'Refund failed' );
		return array( 'success' => true, 'gateway_ref' => $json['id'] ?? '', 'amount' => $amount );
	}

	/**
	 * Verify webhook by calling PayPal's verify-webhook-signature endpoint.
	 *
	 * @param string $body    Raw request body.
	 * @param array  $headers Flat header map.
	 * @return array|WP_Error
	 */
	public function verify_webhook( $body, $headers ) {
		$webhook_id = $this->webhook_id();
		if ( empty( $webhook_id ) ) return new WP_Error( 'no_webhook_id', 'PayPal webhook ID not configured.', array( 'status' => 400 ) );

		$h = array();
		foreach ( $headers as $k => $v ) $h[ strtolower( str_replace( '_', '-', $k ) ) ] = is_array( $v ) ? $v[0] : $v;

		$required = array( 'paypal-auth-algo', 'paypal-cert-url', 'paypal-transmission-id', 'paypal-transmission-sig', 'paypal-transmission-time' );
		foreach ( $required as $r ) {
			if ( empty( $h[ $r ] ) ) return new WP_Error( 'missing_header', 'Missing ' . $r, array( 'status' => 400 ) );
		}

		$token = $this->access_token();
		if ( is_wp_error( $token ) ) return $token;

		$payload = array(
			'auth_algo' => $h['paypal-auth-algo'],
			'cert_url' => $h['paypal-cert-url'],
			'transmission_id' => $h['paypal-transmission-id'],
			'transmission_sig' => $h['paypal-transmission-sig'],
			'transmission_time' => $h['paypal-transmission-time'],
			'webhook_id' => $webhook_id,
			'webhook_event' => json_decode( $body, true ),
		);
		$resp = wp_remote_post( $this->api_base() . '/v1/notifications/verify-webhook-signature', array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ( $json['verification_status'] ?? '' ) !== 'SUCCESS' ) {
			return new WP_Error( 'paypal_verify_failed', 'Signature verification failed', array( 'status' => 400 ) );
		}
		return $payload['webhook_event'];
	}

	public function process_webhook_event( $event ) {
		$type     = $event['event_type'] ?? '';
		$event_id = (string) ( $event['id'] ?? '' );
		$res      = $event['resource'] ?? array();

		// Idempotency: PayPal retries any non-2xx response.
		if ( $event_id && function_exists( 'g2ab_webhook_event_is_new' ) && ! g2ab_webhook_event_is_new( 'paypal', $event_id ) ) {
			return array( 'handled' => true, 'type' => $type, 'reason' => 'duplicate_event', 'event_id' => $event_id );
		}

		if ( 'PAYMENT.CAPTURE.COMPLETED' === $type || 'CHECKOUT.ORDER.APPROVED' === $type || 'CHECKOUT.ORDER.COMPLETED' === $type ) {
			return $this->mark_booking_paid( $res );
		}
		if ( 'PAYMENT.CAPTURE.REFUNDED' === $type ) {
			return $this->mark_booking_refunded( $res );
		}
		return array( 'handled' => false, 'type' => $type );
	}

	private function mark_booking_paid( $res ) {
		global $wpdb;
		$uuid = $res['custom_id'] ?? ( $res['purchase_units'][0]['reference_id'] ?? '' );
		if ( empty( $uuid ) ) return array( 'handled' => false, 'reason' => 'no_uuid' );

		$bt = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) return array( 'handled' => false, 'reason' => 'booking_not_found' );

		if ( in_array( $booking->status, array( 'paid', 'completed' ), true ) ) {
			return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => $booking->status, 'reason' => 'already_paid' );
		}

		$amount = (float) ( $res['amount']['value'] ?? ( $res['purchase_units'][0]['amount']['value'] ?? 0 ) );
		$currency = strtoupper( $res['amount']['currency_code'] ?? ( $res['purchase_units'][0]['amount']['currency_code'] ?? $booking->currency ) );
		$capture_id = $res['id'] ?? '';

		// Amount cross-check.
		if ( function_exists( 'g2ab_validate_payment_amount' ) && ! g2ab_validate_payment_amount( $booking, $amount ) ) {
			$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'payment_amount_mismatch',
				'severity'   => 'warning',
				'message'    => sprintf( 'PayPal amount mismatch: paid=%s expected=%s', number_format( $amount, 2 ), number_format( (float) $booking->total_amount, 2 ) ),
				'context'    => wp_json_encode( array( 'capture_id' => $capture_id ) ),
				'created_at' => current_time( 'mysql' ),
			) );
			return array( 'handled' => false, 'reason' => 'amount_mismatch', 'paid' => $amount, 'expected' => (float) $booking->total_amount );
		}

		$previous_status = (string) $booking->status;

		$wpdb->update( $bt, array( 'status' => 'paid', 'paid_amount' => $amount, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $booking->id ), array( '%s', '%f', '%s' ), array( '%d' ) );

		$pt = $wpdb->prefix . 'g2ab_payments';
		// Match by capture_id when present; otherwise update the latest pending row.
		$existing = $capture_id
			? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE gateway = 'paypal' AND transaction_id = %s LIMIT 1", $capture_id ) )
			: null;
		if ( ! $existing ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE booking_id = %d AND gateway = 'paypal' AND status = 'pending' ORDER BY id DESC LIMIT 1", $booking->id ) );
		}
		if ( $existing ) {
			$wpdb->update( $pt, array(
				'transaction_id' => $capture_id,
				'status' => 'succeeded',
				'amount' => $amount,
				'gateway_response' => wp_json_encode( $res ),
				'processed_at' => current_time( 'mysql' ),
			), array( 'id' => $existing ), array( '%s', '%s', '%f', '%s', '%s' ), array( '%d' ) );
		} else {
			$wpdb->insert( $pt, array(
				'booking_id' => (int) $booking->id,
				'gateway' => 'paypal',
				'transaction_id' => $capture_id,
				'amount' => $amount,
				'currency' => $currency,
				'status' => 'succeeded',
				'payment_method' => 'paypal',
				'gateway_response' => wp_json_encode( $res ),
				'processed_at' => current_time( 'mysql' ),
				'created_at' => current_time( 'mysql' ),
			) );
		}

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking->id, 'event_type' => 'payment_succeeded', 'severity' => 'info',
			'message' => sprintf( 'PayPal payment succeeded: $%s', number_format( $amount, 2 ) ),
			'context' => wp_json_encode( array( 'capture_id' => $capture_id ) ),
			'created_at' => current_time( 'mysql' ),
		) );
		do_action( 'g2ab_payment_succeeded', $booking->id, 'paypal', $res );
		do_action( 'g2ab_booking_status_changed', $booking->id, 'paid', $previous_status );
		return array( 'handled' => true, 'booking_id' => $booking->id, 'status' => 'paid' );
	}

	private function mark_booking_refunded( $res ) {
		global $wpdb;
		$uuid = $res['custom_id'] ?? '';
		if ( empty( $uuid ) ) return array( 'handled' => false );
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) return array( 'handled' => false );

		$amount = (float) ( $res['amount']['value'] ?? 0 );
		$wpdb->update( $bt, array( 'status' => 'refunded', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $booking->id ), array( '%s', '%s' ), array( '%d' ) );
		do_action( 'g2ab_payment_refunded', $booking->id, $amount );
		return array( 'handled' => true, 'booking_id' => $booking->id, 'status' => 'refunded' );
	}
}
