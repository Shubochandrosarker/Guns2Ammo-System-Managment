<?php
/**
 * Authorize.Net LIVE gateway - Accept Hosted (hosted payment page).
 *
 * Flow:
 *   1. Server requests a token via getHostedPaymentPageRequest with the booking amount + return URLs
 *   2. Customer redirects to https://accept.authorize.net/payment/payment with the token
 *   3. Customer pays on Authorize.net hosted page
 *   4. Authorize.net redirects back to ?g2ab_paid={uuid} with transaction id in query
 *   5. Silent post webhook hits /wp-json/g2a-booking/v1/webhooks/authnet, verifies signature, and marks booking paid.
 *
 * Auth: API Login ID + Transaction Key (per-merchant credentials)
 * Webhook signature: sha512 HMAC of payload using webhook signature key
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Gateway_Authnet {

	const ENDPOINT_LIVE = 'https://api.authorize.net/xml/v1/request.api';
	const ENDPOINT_TEST = 'https://apitest.authorize.net/xml/v1/request.api';
	const HOSTED_LIVE   = 'https://accept.authorize.net/payment/payment';
	const HOSTED_TEST   = 'https://test.authorize.net/payment/payment';

	public function id() { return 'authnet'; }
	public function label() { return 'Authorize.net'; }
	public function supports() { return array( 'cards', 'tokenization', 'refunds' ); }

	public function is_available() {
		return 1 === (int) get_option( 'g2ab_authnet_enabled', 0 )
			&& '' !== $this->login_id()
			&& '' !== $this->transaction_key();
	}

	private function api_endpoint() {
		return $this->is_test_mode() ? self::ENDPOINT_TEST : self::ENDPOINT_LIVE;
	}

	private function hosted_endpoint() {
		return $this->is_test_mode() ? self::HOSTED_TEST : self::HOSTED_LIVE;
	}

	public function is_test_mode() {
		return 1 === (int) get_option( 'g2ab_authnet_test_mode', 1 );
	}

	private function login_id() {
		if ( defined( 'G2AB_AUTHNET_LOGIN' ) && G2AB_AUTHNET_LOGIN ) return G2AB_AUTHNET_LOGIN;
		return (string) get_option( 'g2ab_authnet_login_id', '' );
	}

	private function transaction_key() {
		if ( defined( 'G2AB_AUTHNET_KEY' ) && G2AB_AUTHNET_KEY ) return G2AB_AUTHNET_KEY;
		return (string) get_option( 'g2ab_authnet_transaction_key', '' );
	}

	private function signature_key() {
		if ( defined( 'G2AB_AUTHNET_SIGNATURE_KEY' ) && G2AB_AUTHNET_SIGNATURE_KEY ) return G2AB_AUTHNET_SIGNATURE_KEY;
		return (string) get_option( 'g2ab_authnet_signature_key', '' );
	}

	/**
	 * Create a hosted payment token + return redirect URL.
	 *
	 * @param object $booking Booking row.
	 * @param float  $amount  Amount to charge.
	 * @return array|WP_Error { redirect_url }
	 */
	public function create_intent( $booking, $amount ) {
		if ( ! $this->is_available() ) {
			return new WP_Error( 'authnet_unconfigured', 'Authorize.net is not configured.' );
		}

		$amount = round( (float) $amount, 2 );
		$meta = json_decode( (string) ( $booking->metadata ?? '' ), true );
		$confirm_token = is_array( $meta ) ? (string) ( $meta['confirm_token'] ?? '' ) : '';
		$success_url = add_query_arg(
			array_filter( array( 'g2ab_paid' => $booking->uuid, 'gw' => 'authnet', 'confirm_token' => $confirm_token ) ),
			home_url( '/' )
		);
		$cancel_url  = add_query_arg(
			array_filter( array( 'g2ab_cancel' => $booking->uuid, 'confirm_token' => $confirm_token ) ),
			home_url( '/' )
		);

		$customer_email = $booking->customer_email ?? '';
		if ( empty( $customer_email ) && ! empty( $booking->form_data ) ) {
			$f = json_decode( $booking->form_data, true );
			if ( is_array( $f ) ) {
				$customer_email = $f['customer_email'] ?? ( $f['email'] ?? '' );
			}
		}

		$payload = array(
			'getHostedPaymentPageRequest' => array(
				'refId'                  => $booking->uuid,
				'merchantAuthentication' => array(
					'name'           => $this->login_id(),
					'transactionKey' => $this->transaction_key(),
				),
				'transactionRequest' => array(
					'transactionType' => 'authCaptureTransaction',
					'amount'          => number_format( $amount, 2, '.', '' ),
					'order'           => array(
						'invoiceNumber' => substr( $booking->uuid, 0, 20 ),
						'description'   => sprintf( '%s booking', get_option( 'g2ab_business_name', 'Range' ) ),
					),
					'customer' => array( 'email' => $customer_email ),
				),
				'hostedPaymentSettings' => array(
					'setting' => array(
						array(
							'settingName'  => 'hostedPaymentReturnOptions',
							'settingValue' => wp_json_encode( array(
								'showReceipt' => true,
								'url'         => $success_url,
								'urlText'     => 'Continue',
								'cancelUrl'   => $cancel_url,
								'cancelUrlText' => 'Cancel',
							) ),
						),
						array(
							'settingName'  => 'hostedPaymentButtonOptions',
							'settingValue' => wp_json_encode( array( 'text' => 'Pay' ) ),
						),
						array(
							'settingName'  => 'hostedPaymentStyleOptions',
							'settingValue' => wp_json_encode( array( 'bgColor' => 'blue' ) ),
						),
						array(
							'settingName'  => 'hostedPaymentPaymentOptions',
							'settingValue' => wp_json_encode( array( 'cardCodeRequired' => true, 'showCreditCard' => true, 'showBankAccount' => false ) ),
						),
						array(
							'settingName'  => 'hostedPaymentSecurityOptions',
							'settingValue' => wp_json_encode( array( 'captcha' => false ) ),
						),
						array(
							'settingName'  => 'hostedPaymentBillingAddressOptions',
							'settingValue' => wp_json_encode( array( 'show' => true, 'required' => true ) ),
						),
						array(
							'settingName'  => 'hostedPaymentOrderOptions',
							'settingValue' => wp_json_encode( array( 'show' => true, 'merchantName' => get_option( 'g2ab_business_name', 'Range' ) ) ),
						),
					),
				),
			),
		);

		$response = wp_remote_post( $this->api_endpoint(), array(
			'timeout'  => 25,
			'headers'  => array( 'Content-Type' => 'application/json' ),
			'body'     => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) return $response;

		// Authorize.net returns JSON with a BOM - strip it.
		$body = wp_remote_retrieve_body( $response );
		$body = ltrim( $body, "\xEF\xBB\xBF" );
		$json = json_decode( $body, true );

		$token = $json['token'] ?? null;
		if ( empty( $token ) ) {
			$err = $json['messages']['message'][0]['text'] ?? 'Authorize.net token request failed.';
			return new WP_Error( 'authnet_token_failed', $err, array( 'response' => $json ) );
		}

		// Save token to booking for back-reference.
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'g2ab_bookings',
			array( 'gateway_intent_id' => 'authnet:' . substr( $token, 0, 32 ) ),
			array( 'id' => (int) $booking->id ),
			array( '%s' ), array( '%d' )
		);

		$wpdb->insert(
			$wpdb->prefix . 'g2ab_payments',
			array(
				'booking_id'       => (int) $booking->id,
				'gateway'          => 'authnet',
				'transaction_id'   => substr( $token, 0, 64 ),
				'amount'           => (float) $amount,
				'currency'         => strtoupper( $booking->currency ?? 'USD' ),
				'status'           => 'pending',
				'payment_method'   => 'card',
				'gateway_response' => wp_json_encode( array( 'token_prefix' => substr( $token, 0, 32 ) ) ),
				'created_at'       => current_time( 'mysql' ),
			)
		);

		// Build redirect URL. Authorize.net hosted page is a POST endpoint, so we wrap it.
		// We need to POST the token. Generate a self-submitting form URL via a tiny query handler.
		// SECURITY: HMAC-sign (token, booking-uuid, expiry) so the wrapper
		// can't be replayed with a stolen token against a different booking.
		$exp = time() + 15 * MINUTE_IN_SECONDS;
		$sig = hash_hmac( 'sha256', $token . '|' . $booking->uuid . '|' . $exp, wp_salt( 'auth' ) );
		$wrapper = add_query_arg( array(
			'g2ab_authnet_redirect' => 1,
			'token'                 => rawurlencode( $token ),
			'uuid'                  => rawurlencode( $booking->uuid ),
			'b'                     => rawurlencode( $booking->uuid ),
			'e'                     => $exp,
			's'                     => $sig,
		), home_url( '/' ) );

		return array(
			'redirect_url' => $wrapper,
		);
	}

	/**
	 * Verify webhook signature and return the parsed event.
	 *
	 * @param string       $body    Raw request body.
	 * @param array|string $headers Request headers or X-ANET-Signature value.
	 * @return array|WP_Error
	 */
	public function verify_webhook( $body, $headers ) {
		$key = $this->signature_key();
		if ( empty( $key ) ) return new WP_Error( 'authnet_no_signature_key', 'Authorize.net signature key is not configured.', array( 'status' => 400 ) );

		$signature = '';
		if ( is_array( $headers ) ) {
			foreach ( $headers as $header => $value ) {
				if ( 'x-anet-signature' === strtolower( str_replace( '_', '-', $header ) ) ) {
					$signature = is_array( $value ) ? (string) reset( $value ) : (string) $value;
					break;
				}
			}
		} else {
			$signature = (string) $headers;
		}
		if ( empty( $signature ) ) return new WP_Error( 'authnet_missing_signature', 'Missing Authorize.net signature header.', array( 'status' => 400 ) );

		$signature = trim( str_replace( 'sha512=', '', strtolower( $signature ) ) );
		$keys = array( $key );
		$hex_key = preg_replace( '/\s+/', '', $key );
		if ( ctype_xdigit( $hex_key ) && 0 === strlen( $hex_key ) % 2 ) {
			$binary_key = @hex2bin( $hex_key );
			if ( false !== $binary_key ) $keys[] = $binary_key;
		}

		$valid = false;
		foreach ( array_unique( $keys ) as $candidate_key ) {
			if ( hash_equals( strtolower( hash_hmac( 'sha512', $body, $candidate_key ) ), $signature ) ) {
				$valid = true;
				break;
			}
		}
		if ( ! $valid ) return new WP_Error( 'authnet_bad_signature', 'Authorize.net signature mismatch.', array( 'status' => 400 ) );

		$event = json_decode( $body, true );
		if ( ! is_array( $event ) ) return new WP_Error( 'authnet_bad_payload', 'Invalid Authorize.net webhook JSON.', array( 'status' => 400 ) );

		return $event;
	}

	/**
	 * Process a verified Authorize.net webhook event.
	 *
	 * @param array $event Verified webhook event.
	 * @return array
	 */
	public function process_webhook_event( $event ) {
		$type            = $event['eventType'] ?? ( $event['type'] ?? '' );
		$event_id        = (string) ( $event['notificationId'] ?? ( $event['id'] ?? '' ) );
		$payload         = $event['payload'] ?? array();
		$transaction_id  = (string) ( $payload['id'] ?? ( $payload['transId'] ?? '' ) );

		if ( empty( $transaction_id ) ) {
			return array( 'handled' => false, 'type' => $type, 'reason' => 'missing_transaction_id' );
		}

		// Idempotency: skip duplicates. Authorize.net retries on non-2xx responses.
		if ( $event_id && function_exists( 'g2ab_webhook_event_is_new' ) && ! g2ab_webhook_event_is_new( 'authnet', $event_id ) ) {
			return array( 'handled' => true, 'type' => $type, 'reason' => 'duplicate_event', 'event_id' => $event_id );
		}

		// SECURITY: match an explicit allow-list of event types instead of
		// substring sniffing — `net.authorize.payment.fraud.declined`
		// previously matched the "payment" branch and was processed as a
		// successful capture.
		$type_norm = strtolower( (string) $type );
		$refund_events = array(
			'net.authorize.payment.refund.created',
			'net.authorize.payment.void.created',
		);
		$success_events = array(
			'net.authorize.payment.authcapture.created',
			'net.authorize.payment.capture.created',
			'net.authorize.payment.priorauthcapture.created',
		);
		if ( in_array( $type_norm, $refund_events, true ) ) {
			return $this->mark_booking_refunded( $transaction_id, $payload );
		}
		if ( in_array( $type_norm, $success_events, true ) ) {
			return $this->mark_booking_paid_from_transaction( $transaction_id, $payload );
		}

		return array( 'handled' => false, 'type' => $type );
	}

	/**
	 * Confirm by transaction ID when a hosted return URL provides one.
	 *
	 * @param string $transaction_id Authorize.net transaction ID.
	 * @return array|bool
	 */
	public function confirm_payment( $transaction_id, $context = array() ) {
		if ( empty( $transaction_id ) ) return false;
		return $this->mark_booking_paid_from_transaction( $transaction_id, array() );
	}

	private function mark_booking_paid_from_transaction( $transaction_id, $payload ) {
		global $wpdb;

		$details = $this->get_transaction_details( $transaction_id );
		if ( is_wp_error( $details ) ) {
			return array( 'handled' => false, 'reason' => $details->get_error_message(), 'transaction_id' => $transaction_id );
		}

		$transaction = $details['transaction'] ?? array();
		$invoice = (string) ( $transaction['order']['invoiceNumber'] ?? ( $payload['invoiceNumber'] ?? '' ) );
		$amount = (float) ( $transaction['authAmount'] ?? ( $transaction['settleAmount'] ?? 0 ) );

		$bookings_table = $wpdb->prefix . 'g2ab_bookings';
		$booking = null;
		if ( $invoice ) {
			$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE uuid LIKE %s LIMIT 1", $wpdb->esc_like( $invoice ) . '%' ) );
		}
		if ( ! $booking ) {
			$payment = $wpdb->get_row( $wpdb->prepare( "SELECT booking_id FROM {$wpdb->prefix}g2ab_payments WHERE gateway = 'authnet' AND transaction_id = %s ORDER BY id DESC LIMIT 1", $transaction_id ) );
			if ( $payment ) {
				$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d", (int) $payment->booking_id ) );
			}
		}
		if ( ! $booking ) {
			return array( 'handled' => false, 'reason' => 'booking_not_found', 'transaction_id' => $transaction_id, 'invoice' => $invoice );
		}

		// Already paid? Skip; webhook may be a duplicate.
		if ( in_array( $booking->status, array( 'paid', 'completed' ), true ) ) {
			return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => $booking->status, 'reason' => 'already_paid' );
		}

		if ( $amount <= 0 ) $amount = (float) $booking->total_amount;

		// Amount cross-check: refuse to mark paid if the gateway-reported amount
		// doesn't match the booking total or configured deposit.
		if ( function_exists( 'g2ab_validate_payment_amount' ) && ! g2ab_validate_payment_amount( $booking, $amount ) ) {
			$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
				'booking_id' => (int) $booking->id,
				'event_type' => 'payment_amount_mismatch',
				'severity'   => 'warning',
				'message'    => sprintf( 'Authorize.net amount mismatch: paid=%s expected=%s', number_format( $amount, 2 ), number_format( (float) $booking->total_amount, 2 ) ),
				'context'    => wp_json_encode( array( 'transaction_id' => $transaction_id ) ),
				'created_at' => current_time( 'mysql' ),
			) );
			return array( 'handled' => false, 'reason' => 'amount_mismatch', 'paid' => $amount, 'expected' => (float) $booking->total_amount );
		}

		$wpdb->update(
			$bookings_table,
			array(
				'status'      => 'paid',
				'paid_amount' => $amount,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking->id ),
			array( '%s', '%f', '%s' ),
			array( '%d' )
		);

		g2ab_payments_upsert_by_transaction( 'authnet', (string) $transaction_id, array(
			'booking_id'       => (int) $booking->id,
			'amount'           => $amount,
			'currency'         => strtoupper( $booking->currency ?? 'USD' ),
			'status'           => 'succeeded',
			'payment_method'   => 'card',
			'gateway_response' => wp_json_encode( $details ),
			'processed_at'     => current_time( 'mysql' ),
		) );

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => (int) $booking->id,
			'event_type' => 'payment_succeeded',
			'severity'   => 'info',
			'message'    => sprintf( 'Authorize.net payment succeeded: $%s', number_format( $amount, 2 ) ),
			'context'    => wp_json_encode( array( 'transaction_id' => $transaction_id ) ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_succeeded', (int) $booking->id, 'authnet', $details );
		return array( 'handled' => true, 'booking_id' => (int) $booking->id, 'status' => 'paid', 'transaction_id' => $transaction_id );
	}

	private function mark_booking_refunded( $transaction_id, $payload ) {
		global $wpdb;

		$payments_table = $wpdb->prefix . 'g2ab_payments';
		$bookings_table = $wpdb->prefix . 'g2ab_bookings';

		// Look up the payment row by the original (or any related) transaction id.
		$payment = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$payments_table} WHERE gateway = 'authnet' AND transaction_id = %s ORDER BY id DESC LIMIT 1",
			$transaction_id
		) );

		// Fall back to the invoice/uuid prefix encoded in the order metadata.
		if ( ! $payment ) {
			$invoice = (string) ( $payload['invoiceNumber'] ?? ( $payload['merchantReferenceId'] ?? '' ) );
			if ( $invoice ) {
				$booking = $wpdb->get_row( $wpdb->prepare(
					"SELECT * FROM {$bookings_table} WHERE uuid LIKE %s LIMIT 1",
					$wpdb->esc_like( $invoice ) . '%'
				) );
				if ( $booking ) {
					$payment = $wpdb->get_row( $wpdb->prepare(
						"SELECT * FROM {$payments_table} WHERE booking_id = %d AND gateway = 'authnet' ORDER BY id DESC LIMIT 1",
						(int) $booking->id
					) );
				}
			}
		}

		if ( ! $payment ) {
			return array( 'handled' => false, 'reason' => 'refund_payment_not_found', 'transaction_id' => $transaction_id );
		}

		$booking = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d", (int) $payment->booking_id ) );
		if ( ! $booking ) {
			return array( 'handled' => false, 'reason' => 'refund_booking_not_found', 'transaction_id' => $transaction_id );
		}

		$refund_amount = (float) ( $payload['authAmount'] ?? ( $payload['settleAmount'] ?? $payment->amount ) );
		$refund_amount = max( 0, round( $refund_amount, 2 ) );
		$is_full       = $refund_amount + 0.01 >= (float) $payment->amount;

		$wpdb->update(
			$payments_table,
			array(
				'status'        => $is_full ? 'refunded' : 'partially_refunded',
				'refund_amount' => round( (float) $payment->refund_amount + $refund_amount, 2 ),
				'processed_at'  => current_time( 'mysql' ),
			),
			array( 'id' => (int) $payment->id ),
			array( '%s', '%f', '%s' ),
			array( '%d' )
		);

		$wpdb->update(
			$bookings_table,
			array(
				'status'     => $is_full ? 'refunded' : $booking->status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $booking->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'booking_id' => (int) $booking->id,
			'event_type' => 'payment_refunded',
			'severity'   => 'info',
			'message'    => sprintf( 'Authorize.net refunded $%s (transaction %s)', number_format( $refund_amount, 2 ), $transaction_id ),
			'context'    => wp_json_encode( array( 'transaction_id' => $transaction_id, 'full' => $is_full ) ),
			'created_at' => current_time( 'mysql' ),
		) );

		do_action( 'g2ab_payment_refunded', (int) $booking->id, $refund_amount, 'authnet', $payload );

		return array(
			'handled'        => true,
			'booking_id'     => (int) $booking->id,
			'status'         => $is_full ? 'refunded' : 'partially_refunded',
			'transaction_id' => $transaction_id,
			'refund_amount'  => $refund_amount,
		);
	}

	private function get_transaction_details( $transaction_id ) {
		$payload = array(
			'getTransactionDetailsRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $this->login_id(),
					'transactionKey' => $this->transaction_key(),
				),
				'transId' => $transaction_id,
			),
		);

		$response = wp_remote_post( $this->api_endpoint(), array(
			'timeout' => 25,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) return $response;

		$body = ltrim( wp_remote_retrieve_body( $response ), "\xEF\xBB\xBF" );
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'authnet_details_bad_json', 'Authorize.net transaction details returned invalid JSON.' );
		}
		if ( empty( $json['transaction'] ) ) {
			$message = $json['messages']['message'][0]['text'] ?? 'Authorize.net transaction details not found.';
			return new WP_Error( 'authnet_details_failed', $message, array( 'response' => $json ) );
		}
		return $json;
	}

	/**
	 * Refund a transaction via createTransactionRequest with refundTransaction.
	 *
	 * Authorize.net requires the last 4 of the card to refund a settled
	 * transaction. We pull that from getTransactionDetailsRequest first.
	 *
	 * @param string $transaction_id Original Authorize.net transaction id.
	 * @param float  $amount         Amount to refund. <=0 means full original amount.
	 * @return array|WP_Error
	 */
	public function refund( $transaction_id, $amount ) {
		$transaction_id = (string) $transaction_id;
		if ( '' === $transaction_id ) {
			return new WP_Error( 'authnet_refund_missing_txid', __( 'Missing transaction ID.', 'g2a-booking' ) );
		}

		$details = $this->get_transaction_details( $transaction_id );
		if ( is_wp_error( $details ) ) {
			return $details;
		}
		$tx = $details['transaction'] ?? array();

		$last4 = (string) ( $tx['payment']['creditCard']['cardNumber'] ?? '' );
		// Authorize.net masks the card; the last 4 are the trailing digits.
		if ( '' !== $last4 ) {
			$last4 = substr( preg_replace( '/[^0-9]/', '', $last4 ), -4 );
		}
		$settled_amount = (float) ( $tx['settleAmount'] ?? ( $tx['authAmount'] ?? 0 ) );
		$amount         = round( (float) $amount, 2 );
		if ( $amount <= 0 || $amount > $settled_amount ) {
			$amount = $settled_amount;
		}
		if ( $amount <= 0 ) {
			return new WP_Error( 'authnet_refund_amount_invalid', __( 'Invalid refund amount.', 'g2a-booking' ) );
		}

		$payload = array(
			'createTransactionRequest' => array(
				'merchantAuthentication' => array(
					'name'           => $this->login_id(),
					'transactionKey' => $this->transaction_key(),
				),
				'transactionRequest' => array(
					'transactionType' => 'refundTransaction',
					'amount'          => number_format( $amount, 2, '.', '' ),
					'payment'         => array(
						'creditCard' => array(
							'cardNumber'     => $last4 ?: '0000',
							'expirationDate' => 'XXXX',
						),
					),
					'refTransId' => $transaction_id,
				),
			),
		);

		$response = wp_remote_post( $this->api_endpoint(), array(
			'timeout' => 25,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = ltrim( wp_remote_retrieve_body( $response ), "\xEF\xBB\xBF" );
		$json = json_decode( $body, true );
		$ok   = is_array( $json ) && 'Ok' === ( $json['messages']['resultCode'] ?? '' );

		if ( ! $ok ) {
			$msg = $json['messages']['message'][0]['text']
				?? ( $json['transactionResponse']['errors'][0]['errorText'] ?? __( 'Authorize.net refund failed.', 'g2a-booking' ) );
			return new WP_Error( 'authnet_refund_failed', $msg, array( 'response' => $json ) );
		}

		// Update our DB to reflect the refund (do not rely on the webhook firing).
		$this->mark_booking_refunded( $transaction_id, array(
			'authAmount'         => $amount,
			'merchantReferenceId' => $tx['order']['invoiceNumber'] ?? '',
		) );

		return array(
			'success'   => true,
			'amount'    => $amount,
			'reference' => $json['transactionResponse']['transId'] ?? '',
		);
	}
}

/* ------------------------------------------------------------------ */
/*  Hosted-page redirect wrapper                                       */
/* ------------------------------------------------------------------ */
/* Authorize.net hosted page is POST-only. We emit a tiny self-submit
 * form on this query handler so the customer hits the hosted page
 * without needing a JS redirect chain on every site.                  */
/* ------------------------------------------------------------------ */
add_action( 'init', function () {
	if ( empty( $_GET['g2ab_authnet_redirect'] ) ) return;
	$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
	$booking = isset( $_GET['b'] ) ? sanitize_text_field( wp_unslash( $_GET['b'] ) ) : '';
	$exp     = isset( $_GET['e'] ) ? (int) $_GET['e'] : 0;
	$sig     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	if ( empty( $token ) || empty( $booking ) || empty( $sig ) ) wp_die( 'Missing parameters.' );
	if ( $exp < time() ) wp_die( 'Payment link expired. Start over.' );

	// SECURITY: redirect-link must be HMAC-signed by the server when the
	// payment intent was created. This binds the token to the issuing
	// booking and to a short expiry window — preventing an attacker who
	// somehow learns a token from replaying it against the hosted page
	// to apply a charge to the wrong card-on-file.
	$expected = hash_hmac( 'sha256', $token . '|' . $booking . '|' . $exp, wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected, $sig ) ) wp_die( 'Invalid signature.' );

	// Light per-IP rate-limit on the redirect handler itself.
	$ip  = function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : ( $_SERVER['REMOTE_ADDR'] ?? '' );
	$bkt = 'g2ab_authnet_rd_' . md5( (string) $ip );
	$hit = (int) get_transient( $bkt );
	if ( $hit > 30 ) wp_die( 'Too many redirect attempts.' );
	set_transient( $bkt, $hit + 1, MINUTE_IN_SECONDS );

	$test = 1 === (int) get_option( 'g2ab_authnet_test_mode', 1 );
	$action = $test ? G2AB_Gateway_Authnet::HOSTED_TEST : G2AB_Gateway_Authnet::HOSTED_LIVE;

	?><!doctype html>
<html><head><meta charset="utf-8"><title>Redirecting to secure payment...</title>
<style>body{font-family:Arial,sans-serif;background:#0F1115;color:#E8E8E8;padding:60px 20px;text-align:center;}h1{font-family:Impact,sans-serif;letter-spacing:.06em;}.s{margin-top:30px;color:#8A95A5;font-size:13px;}</style></head>
<body>
<h1>REDIRECTING TO SECURE PAYMENT</h1>
<p class="s">If you are not redirected within 3 seconds, click below.</p>
<form id="f" method="post" action="<?php echo esc_url( $action ); ?>">
	<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
	<button type="submit" style="margin-top:20px;background:#D2691E;color:#fff;border:0;padding:14px 28px;font-weight:bold;text-transform:uppercase;letter-spacing:.06em;cursor:pointer;">Continue to Payment</button>
</form>
<script>document.getElementById('f').submit();</script>
</body></html><?php
	exit;
} );
