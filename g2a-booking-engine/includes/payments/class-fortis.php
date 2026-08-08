<?php
/**
 * Fortis Pay LIVE gateway — PayForm hosted page + webhook verification.
 *
 * Auth: 3 headers — user-id, user-api-key, developer-id
 *
 * Flow:
 *   1. Server POST /v2/payform with auth headers + transaction details
 *   2. Returns hosted PayForm URL + session
 *   3. Customer redirects → enters card on Fortis page → returns to success URL
 *   4. Webhook fires transaction event → verify HMAC → mark booking paid
 *
 * Sandbox base: https://api-cert.fortis.tech
 * Production base: https://api.fortis.tech
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Gateway_Fortis {

	public function id() { return 'fortis'; }
	public function label() { return 'Fortis Pay'; }
	public function supports() { return array( 'cards', 'ach', 'refunds' ); }

	public function is_available() {
		return 1 === (int) get_option( 'g2ab_fortis_enabled', 0 )
			&& '' !== $this->user_id()
			&& '' !== $this->user_api_key()
			&& '' !== $this->developer_id();
	}

	private function api_base() {
		return 1 === (int) get_option( 'g2ab_fortis_test_mode', 1 )
			? 'https://api-cert.fortis.tech'
			: 'https://api.fortis.tech';
	}

	private function user_id() {
		if ( defined( 'G2AB_FORTIS_USER_ID' ) && G2AB_FORTIS_USER_ID ) return G2AB_FORTIS_USER_ID;
		return (string) get_option( 'g2ab_fortis_user_id', '' );
	}
	private function user_api_key() {
		if ( defined( 'G2AB_FORTIS_USER_API_KEY' ) && G2AB_FORTIS_USER_API_KEY ) return G2AB_FORTIS_USER_API_KEY;
		return (string) get_option( 'g2ab_fortis_user_api_key', '' );
	}
	private function developer_id() {
		if ( defined( 'G2AB_FORTIS_DEVELOPER_ID' ) && G2AB_FORTIS_DEVELOPER_ID ) return G2AB_FORTIS_DEVELOPER_ID;
		return (string) get_option( 'g2ab_fortis_developer_id', '' );
	}
	private function webhook_secret() {
		if ( defined( 'G2AB_FORTIS_WEBHOOK_SECRET' ) && G2AB_FORTIS_WEBHOOK_SECRET ) return G2AB_FORTIS_WEBHOOK_SECRET;
		return (string) get_option( 'g2ab_fortis_webhook_secret', '' );
	}

	private function auth_headers() {
		return array(
			'user-id'        => $this->user_id(),
			'user-api-key'   => $this->user_api_key(),
			'developer-id'   => $this->developer_id(),
			'Content-Type'   => 'application/json',
			'Accept'         => 'application/json',
		);
	}

	/**
	 * Create PayForm session — Fortis-hosted payment page.
	 *
	 * @param object $booking Booking row.
	 * @param float  $amount  Charge amount.
	 * @return array|WP_Error
	 */
	public function create_intent( $booking, $amount ) {
		if ( ! $this->is_available() ) return new WP_Error( 'fortis_unconfigured', 'Fortis Pay is not configured.' );

		$amount_cents = (int) round( $amount * 100 );
		$success = add_query_arg( array( 'g2ab_paid' => $booking->uuid, 'gw' => 'fortis' ), home_url( '/' ) );
		$cancel  = add_query_arg( array( 'g2ab_cancel' => $booking->uuid ), home_url( '/' ) );

		$payload = array(
			'transaction_amount' => $amount_cents,
			'description' => sprintf( '%s - %s', $booking->customer_name, mysql2date( 'M j Y g:i A', $booking->start_at ) ),
			'order_number' => $booking->uuid,
			'transaction_api_id' => $booking->uuid,
			// PayForm config — return URL after payment.
			'redirect_url' => $success,
			'cancel_url'   => $cancel,
			// Optional customer data.
			'contact' => array(
				'first_name' => $this->first_name( $booking->customer_name ),
				'last_name'  => $this->last_name( $booking->customer_name ),
				'email'      => $booking->customer_email,
				'cell_phone' => $booking->customer_phone ?: '',
			),
		);

		$resp = wp_remote_post( $this->api_base() . '/v2/payform', array(
			'timeout' => 20,
			'headers' => $this->auth_headers(),
			'body' => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		$code = wp_remote_retrieve_response_code( $resp );
		if ( $code >= 400 ) {
			return new WP_Error( 'fortis_create_failed', $json['error'] ?? ( $json['message'] ?? 'Fortis PayForm creation failed' ) );
		}

		$data = $json['data'] ?? $json;
		$session_id = $data['id'] ?? ( $data['payform_id'] ?? '' );
		$payform_url = $data['url'] ?? ( $data['payform_url'] ?? '' );
		// Some Fortis responses give a token to embed in URL; fall back to constructing if needed.
		if ( empty( $payform_url ) && ! empty( $data['token'] ) ) {
			$payform_url = $this->api_base() . '/v2/payform/' . urlencode( $data['token'] );
		}

		if ( empty( $payform_url ) ) {
			return new WP_Error( 'fortis_no_url', 'Fortis returned no PayForm URL.' );
		}

		// Track pending payment.
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'g2ab_payments', array(
			'booking_id' => (int) $booking->id,
			'gateway' => 'fortis',
			'transaction_id' => $session_id,
			'amount' => (float) $amount,
			'currency' => strtoupper( $booking->currency ?? 'USD' ),
			'status' => 'pending',
			'payment_method' => 'card',
			'gateway_response' => wp_json_encode( $json ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_intent_created', 'fortis', $booking->id, $amount, $json );

		return array(
			'gateway' => 'fortis',
			'gateway_ref' => $session_id,
			'redirect_url' => $payform_url,
		);
	}

	/**
	 * Fallback verify by retrieving the transaction.
	 *
	 * @param string $transaction_id Fortis transaction ID.
	 * @return bool|WP_Error
	 */
	public function confirm_payment( $transaction_id, $context = array() ) {
		$resp = wp_remote_get( $this->api_base() . '/v2/transactions/' . urlencode( $transaction_id ), array(
			'timeout' => 15,
			'headers' => $this->auth_headers(),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json    = json_decode( wp_remote_retrieve_body( $resp ), true );
		$tx      = $json['data'] ?? $json;
		$status  = strtolower( (string) ( $tx['status_code'] ?? '' ) );
		$tx_type = strtolower( (string) ( $tx['transaction_type'] ?? ( $tx['type'] ?? '' ) ) );

		$is_success = in_array( $status, array( '101', 'approved', 'success', 'completed', 'captured' ), true );
		$is_sale    = '' === $tx_type || in_array( $tx_type, array( 'sale', 'authcapture', 'capture', 'auth_capture' ), true );

		if ( $is_success && $is_sale ) {
			return $this->mark_booking_paid( $tx );
		}
		return false;
	}

	public function refund( $transaction_id, $amount = 0 ) {
		$body = array();
		if ( $amount > 0 ) $body['transaction_amount'] = (int) round( $amount * 100 );
		$resp = wp_remote_post( $this->api_base() . '/v2/transactions/' . urlencode( $transaction_id ) . '/refund', array(
			'timeout' => 20,
			'headers' => $this->auth_headers(),
			'body' => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) return $resp;
		$json = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( wp_remote_retrieve_response_code( $resp ) >= 400 ) return new WP_Error( 'fortis_refund_failed', $json['error'] ?? 'Refund failed' );
		return array( 'success' => true, 'gateway_ref' => $json['data']['id'] ?? '', 'amount' => $amount );
	}

	/**
	 * Verify Fortis webhook signature via HMAC-SHA256.
	 *
	 * Header expected: X-Fortis-Signature: {hmac-sha256(body, webhook_secret)}
	 *
	 * @param string $body    Raw body.
	 * @param array  $headers Flat header map.
	 * @return array|WP_Error
	 */
	public function verify_webhook( $body, $headers ) {
		$secret = $this->webhook_secret();
		if ( empty( $secret ) ) return new WP_Error( 'no_secret', 'Fortis webhook secret not configured.', array( 'status' => 400 ) );

		$h = array();
		foreach ( $headers as $k => $v ) $h[ strtolower( str_replace( '_', '-', $k ) ) ] = is_array( $v ) ? $v[0] : $v;

		$sig = $h['x-fortis-signature'] ?? ( $h['fortis-signature'] ?? '' );
		if ( empty( $sig ) ) return new WP_Error( 'no_signature', 'Missing Fortis signature header.', array( 'status' => 400 ) );

		$expected = hash_hmac( 'sha256', $body, $secret );
		if ( ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'bad_signature', 'Fortis signature mismatch.', array( 'status' => 400 ) );
		}

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) return new WP_Error( 'bad_payload', 'Invalid JSON.', array( 'status' => 400 ) );
		return $event;
	}

	public function process_webhook_event( $event ) {
		$type     = strtolower( (string) ( $event['event_type'] ?? ( $event['type'] ?? '' ) ) );
		$event_id = (string) ( $event['id'] ?? ( $event['event_id'] ?? '' ) );
		$tx       = $event['data'] ?? ( $event['transaction'] ?? array() );

		if ( $event_id && function_exists( 'g2ab_webhook_event_claim' ) ) {
			$claim = g2ab_webhook_event_claim( 'fortis', $event_id, $type, wp_json_encode( $event ) );
			if ( is_wp_error( $claim ) ) {
				return $claim;
			}
			if ( 'duplicate' === $claim ) {
				return array( 'handled' => true, 'type' => $type, 'reason' => 'duplicate_event', 'event_id' => $event_id );
			}
		}

		// Event-type whitelist: previously we trusted any payload with a "success-looking"
		// status code, which let auth-only or test events flip a booking to paid.
		$paid_event_types = array(
			'transaction.completed',
			'transaction.captured',
			'transaction.created',
			'transaction.payment_succeeded',
			'payment.completed',
			'payment.succeeded',
		);
		$refund_event_types = array(
			'transaction.refunded',
			'transaction.voided',
			'payment.refunded',
		);

		$status = strtolower( (string) ( $tx['status_code'] ?? ( $tx['status'] ?? '' ) ) );
		$tx_type = strtolower( (string) ( $tx['transaction_type'] ?? ( $tx['type'] ?? '' ) ) );

		$result = null;
		if ( in_array( $type, $refund_event_types, true ) || false !== stripos( $type, 'refund' ) || false !== stripos( $type, 'void' ) || 'refund' === $tx_type ) {
			$result = $this->mark_booking_refunded( $tx );
		} else {
			// Paid branch: event-type AND a success status AND an explicit sale/capture type.
			$is_paid_event   = in_array( $type, $paid_event_types, true );
			$is_success_code = in_array( $status, array( 'approved', 'success', '101', 'completed', 'captured' ), true );
			$is_sale_type    = '' === $tx_type || in_array( $tx_type, array( 'sale', 'authcapture', 'capture', 'auth_capture' ), true );

			if ( $is_paid_event && $is_success_code && $is_sale_type ) {
				$result = $this->mark_booking_paid( $tx );
			} else {
				$result = array( 'handled' => true, 'type' => $type, 'reason' => 'verified_event_ignored' );
			}
		}

		if ( is_wp_error( $result ) ) {
			if ( $event_id && function_exists( 'g2ab_webhook_event_mark_failed' ) ) {
				g2ab_webhook_event_mark_failed( 'fortis', $event_id, $result->get_error_message() );
			}
			return $result;
		}

		if ( ! empty( $result['retryable'] ) ) {
			if ( $event_id && function_exists( 'g2ab_webhook_event_mark_failed' ) ) {
				g2ab_webhook_event_mark_failed( 'fortis', $event_id, wp_json_encode( $result ) );
			}
			return $result;
		}

		if ( $event_id && function_exists( 'g2ab_webhook_event_mark_processed' ) ) {
			g2ab_webhook_event_mark_processed( 'fortis', $event_id );
		}

		return $result;
	}

	private function mark_booking_paid( $tx ) {
		global $wpdb;
		$uuid = $tx['transaction_api_id'] ?? ( $tx['order_number'] ?? '' );
		if ( empty( $uuid ) ) return array( 'handled' => false, 'reason' => 'no_uuid' );

		$bt = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) return array( 'handled' => false, 'reason' => 'booking_not_found' );

		// Skip already-paid bookings to make webhook processing idempotent.
		if ( in_array( $booking->status, array( 'paid', 'completed' ), true ) ) {
			return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => $booking->status, 'reason' => 'already_paid' );
		}

		$previous_status = (string) $booking->status;
		$amount = isset( $tx['transaction_amount'] ) ? ( (int) $tx['transaction_amount'] ) / 100.0 : 0;
		if ( $amount <= 0 ) $amount = (float) $booking->total_amount;
		$tx_id = $tx['id'] ?? '';

		// Amount cross-check.
		if ( function_exists( 'g2ab_validate_payment_amount' ) && ! g2ab_validate_payment_amount( $booking, $amount ) ) {
			$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'payment_amount_mismatch',
				'severity'   => 'warning',
				'message'    => sprintf( 'Fortis amount mismatch: paid=%s expected=%s', number_format( $amount, 2 ), number_format( (float) $booking->total_amount, 2 ) ),
				'context'    => wp_json_encode( array( 'transaction_id' => $tx_id ) ),
				'created_at' => current_time( 'mysql' ),
			) );
			return array( 'handled' => false, 'reason' => 'amount_mismatch', 'paid' => $amount, 'expected' => (float) $booking->total_amount );
		}

		$updated = $wpdb->update( $bt, array( 'status' => 'paid', 'paid_amount' => $amount, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $booking->id ), array( '%s', '%f', '%s' ), array( '%d' ) );
		if ( false === $updated ) {
			return array( 'handled' => false, 'retryable' => true, 'reason' => 'booking_update_failed' );
		}

		$pt = $wpdb->prefix . 'g2ab_payments';
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$pt} WHERE booking_id = %d AND gateway = 'fortis' ORDER BY id DESC LIMIT 1", $booking->id ) );
		if ( $existing ) {
			$wpdb->update( $pt, array(
				'transaction_id' => $tx_id,
				'status' => 'succeeded',
				'amount' => $amount,
				'gateway_response' => wp_json_encode( $tx ),
				'processed_at' => current_time( 'mysql' ),
			), array( 'id' => $existing ), array( '%s', '%s', '%f', '%s', '%s' ), array( '%d' ) );
		}

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking->id, 'event_type' => 'payment_succeeded', 'severity' => 'info',
			'message' => sprintf( 'Fortis Pay payment succeeded: $%s', number_format( $amount, 2 ) ),
			'context' => wp_json_encode( array( 'transaction_id' => $tx_id ) ),
			'created_at' => current_time( 'mysql' ),
		) );
		if ( function_exists( 'g2ab_queue_booking_paid_side_effects' ) ) {
			$queued = g2ab_queue_booking_paid_side_effects( $booking->id, 'fortis', $previous_status, $tx );
		} else {
			$queued = false;
		}
		if ( ! $queued ) {
			do_action( 'g2ab_payment_succeeded', $booking->id, 'fortis', $tx );
			do_action( 'g2ab_booking_paid', $booking, array( 'gateway' => 'fortis', 'transaction_id' => $tx_id ) );
			do_action( 'g2ab_booking_status_changed', $booking->id, 'paid', $previous_status );
		}
		return array( 'handled' => true, 'booking_id' => $booking->id, 'status' => 'paid' );
	}

	private function mark_booking_refunded( $tx ) {
		global $wpdb;
		$uuid = $tx['transaction_api_id'] ?? ( $tx['order_number'] ?? '' );
		if ( empty( $uuid ) ) return array( 'handled' => false );
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bt} WHERE uuid = %s", $uuid ) );
		if ( ! $booking ) return array( 'handled' => false );

		// Idempotent — a webhook retry must not double-process the same refund.
		if ( 'refunded' === $booking->status ) {
			return array( 'handled' => true, 'booking_id' => $booking->id, 'status' => 'refunded', 'reason' => 'already_refunded' );
		}

		$refund_amount = isset( $tx['transaction_amount'] ) ? ( (int) $tx['transaction_amount'] ) / 100.0 : (float) $booking->paid_amount;

		// Partial-aware: a partial refund keeps the booking attending
		// (partially_refunded); only a refund covering the full paid amount
		// releases it to refunded. Ledger first so the transition invariant
		// sees the refund evidence.
		$is_full         = $refund_amount >= round( (float) $booking->paid_amount, 2 ) - 0.01 || (float) $booking->paid_amount <= 0;
		$payment_status  = $is_full ? 'refunded' : 'partial_refund';
		$booking_status  = $is_full ? 'refunded' : 'partially_refunded';

		$pt = $wpdb->prefix . 'g2ab_payments';
		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$pt} WHERE booking_id = %d AND gateway = 'fortis' ORDER BY id DESC LIMIT 1", $booking->id ) );
		if ( $payment ) {
			$wpdb->update( $pt, array(
				'status'        => $payment_status,
				'refund_amount' => $refund_amount,
			), array( 'id' => $payment->id ), array( '%s', '%f' ), array( '%d' ) );
		}

		if ( class_exists( 'G2AB_Booking_Transitions' ) ) {
			G2AB_Booking_Transitions::transition( (int) $booking->id, $booking_status, array(
				'source'     => 'gateway',
				'reason'     => 'fortis_refund_webhook',
				'payment_id' => $payment ? (int) $payment->id : 0,
			) );
		} else {
			$wpdb->update( $bt, array( 'status' => $booking_status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $booking->id ), array( '%s', '%s' ), array( '%d' ) );
		}

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => $booking->id, 'event_type' => 'payment_refunded', 'severity' => 'info',
			'message' => sprintf( 'Fortis Pay refund processed: $%s', number_format( $refund_amount, 2 ) ),
			'context' => wp_json_encode( array( 'transaction_id' => $tx['id'] ?? '' ) ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_refunded', $booking->id, $refund_amount, 'fortis', $tx );
		return array( 'handled' => true, 'booking_id' => $booking->id, 'status' => $booking_status );
	}

	private function first_name( $full ) { $p = preg_split( '/\s+/', trim( (string) $full ) ); return $p[0] ?? ''; }
	private function last_name( $full ) { $p = preg_split( '/\s+/', trim( (string) $full ) ); array_shift( $p ); return implode( ' ', $p ); }
}
