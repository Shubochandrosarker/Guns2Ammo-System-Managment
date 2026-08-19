<?php
/**
 * Shared payment validation helpers.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Payment_Validator {

	private const ZERO_DECIMAL_CURRENCIES = array(
		'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF',
		'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
	);

	public static function normalize_currency( $currency ) {
		$currency = strtoupper( sanitize_text_field( (string) $currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return '';
		}
		$allowed = (array) apply_filters( 'g2ab_allowed_currencies', array( 'USD' ) );
		$allowed = array_map( 'strtoupper', array_map( 'sanitize_text_field', $allowed ) );
		return in_array( $currency, $allowed, true ) ? $currency : '';
	}

	public static function amount_to_minor( $amount, $currency ) {
		if ( ! is_numeric( $amount ) ) {
			return null;
		}
		$amount = (float) $amount;
		if ( ! is_finite( $amount ) || $amount < 0 ) {
			return null;
		}
		$currency = self::normalize_currency( $currency );
		if ( '' === $currency ) {
			return null;
		}
		$multiplier = in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
		return (int) round( $amount * $multiplier );
	}

	public static function minor_to_amount( $minor, $currency ) {
		$currency = self::normalize_currency( $currency );
		if ( '' === $currency || ! is_numeric( $minor ) ) {
			return null;
		}
		$multiplier = in_array( $currency, self::ZERO_DECIMAL_CURRENCIES, true ) ? 1 : 100;
		return round( (int) $minor / $multiplier, 2 );
	}

	public static function expected_due_now_minor( $booking ) {
		if ( ! is_object( $booking ) ) {
			return null;
		}
		$currency = self::normalize_currency( $booking->currency ?? '' );
		if ( '' === $currency ) {
			return null;
		}
		$meta    = json_decode( (string) ( $booking->metadata ?? '' ), true );
		$meta    = is_array( $meta ) ? $meta : array();
		$due_now = array_key_exists( 'due_now', $meta ) ? $meta['due_now'] : ( $booking->total_amount ?? null );
		return self::amount_to_minor( $due_now, $currency );
	}

	public static function sanitize_stripe_session( array $session ) {
		return array_filter(
			array(
				'id'             => isset( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '',
				'payment_intent' => isset( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : '',
				'payment_status' => isset( $session['payment_status'] ) ? sanitize_key( (string) $session['payment_status'] ) : '',
				'status'         => isset( $session['status'] ) ? sanitize_key( (string) $session['status'] ) : '',
				'mode'           => isset( $session['mode'] ) ? sanitize_key( (string) $session['mode'] ) : '',
				'amount_total'   => isset( $session['amount_total'] ) ? (int) $session['amount_total'] : null,
				'currency'       => isset( $session['currency'] ) ? self::normalize_currency( $session['currency'] ) : '',
				'created'        => isset( $session['created'] ) ? (int) $session['created'] : null,
				'expires_at'     => isset( $session['expires_at'] ) ? (int) $session['expires_at'] : null,
				'livemode'       => isset( $session['livemode'] ) ? (bool) $session['livemode'] : null,
				'url'            => isset( $session['url'] ) ? esc_url_raw( (string) $session['url'] ) : '',
			),
			static function ( $value ) {
				return null !== $value && '' !== $value;
			}
		);
	}

	public static function validate_stripe_checkout_session( array $session, $booking, $payment = null ) {
		if ( ! is_object( $booking ) ) {
			return new WP_Error( 'g2ab_payment_booking_missing', __( 'Booking not found for payment.', 'g2a-booking' ), array( 'status' => 404, 'permanent' => true ) );
		}

		$session_id = isset( $session['id'] ) ? sanitize_text_field( (string) $session['id'] ) : '';
		if ( ! preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $session_id ) ) {
			return new WP_Error( 'g2ab_payment_bad_session', __( 'Invalid checkout session.', 'g2a-booking' ), array( 'status' => 400, 'permanent' => true ) );
		}
		if ( 'payment' !== (string) ( $session['mode'] ?? '' ) ) {
			return new WP_Error( 'g2ab_payment_bad_mode', __( 'Invalid checkout mode.', 'g2a-booking' ), array( 'status' => 400, 'permanent' => true ) );
		}
		if ( 'paid' !== (string) ( $session['payment_status'] ?? '' ) || 'complete' !== (string) ( $session['status'] ?? '' ) ) {
			return new WP_Error( 'g2ab_payment_not_complete', __( 'Payment is not complete.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => false ) );
		}

		$metadata = isset( $session['metadata'] ) && is_array( $session['metadata'] ) ? $session['metadata'] : array();
		if ( (string) ( $session['client_reference_id'] ?? '' ) !== (string) $booking->uuid ) {
			return new WP_Error( 'g2ab_payment_reference_mismatch', __( 'Payment reference mismatch.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}
		if ( (string) ( $metadata['booking_id'] ?? '' ) !== (string) (int) $booking->id || (string) ( $metadata['booking_uuid'] ?? '' ) !== (string) $booking->uuid ) {
			return new WP_Error( 'g2ab_payment_metadata_mismatch', __( 'Payment metadata mismatch.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}

		$currency = self::normalize_currency( $session['currency'] ?? '' );
		if ( '' === $currency || $currency !== self::normalize_currency( $booking->currency ?? '' ) ) {
			return new WP_Error( 'g2ab_payment_currency_mismatch', __( 'Payment currency mismatch.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}

		if ( ! isset( $session['amount_total'] ) || ! is_numeric( $session['amount_total'] ) || (int) $session['amount_total'] < 0 ) {
			return new WP_Error( 'g2ab_payment_amount_missing', __( 'Payment amount missing.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}
		$expected_minor = self::expected_due_now_minor( $booking );
		if ( null === $expected_minor || (int) $session['amount_total'] !== $expected_minor ) {
			return new WP_Error( 'g2ab_payment_amount_mismatch', __( 'Payment amount mismatch.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}

		// Which environment are we in? The configured key's prefix is
		// authoritative — `g2ab_stripe_test_mode` defaults to 1, so a site that
		// never saved the payments screen would reject every genuine LIVE
		// payment here (money taken, booking left unpaid). Fall back to the
		// option only when the key cannot answer.
		$test_mode = class_exists( 'G2AB_Gateway_Stripe' )
			? G2AB_Gateway_Stripe::is_test_mode()
			: 1 === (int) get_option( 'g2ab_stripe_test_mode', 1 );
		if ( isset( $session['livemode'] ) && (bool) $session['livemode'] === $test_mode ) {
			return new WP_Error( 'g2ab_payment_mode_mismatch', __( 'Stripe environment mismatch.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}

		if ( is_object( $payment ) ) {
			$stored_session = '';
			if ( isset( $payment->checkout_session_id ) ) {
				$stored_session = (string) $payment->checkout_session_id;
			}
			if ( '' === $stored_session ) {
				$stored_session = (string) ( $payment->transaction_id ?? '' );
			}
			if ( '' !== $stored_session && $stored_session !== $session_id ) {
				return new WP_Error( 'g2ab_payment_attempt_mismatch', __( 'Payment attempt mismatch.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
			}
		}

		if ( in_array( (string) $booking->status, array( 'cancelled', 'refunded', 'partially_refunded', 'completed', 'no_show' ), true ) ) {
			return new WP_Error( 'g2ab_payment_state_ineligible', __( 'Booking is not eligible for payment activation.', 'g2a-booking' ), array( 'status' => 409, 'permanent' => true ) );
		}

		return array(
			'session_id'       => $session_id,
			'payment_intent'   => isset( $session['payment_intent'] ) ? sanitize_text_field( (string) $session['payment_intent'] ) : '',
			'amount_minor'     => (int) $session['amount_total'],
			'amount'           => self::minor_to_amount( (int) $session['amount_total'], $currency ),
			'currency'         => $currency,
			'expected_minor'   => $expected_minor,
			'sanitized_session'=> self::sanitize_stripe_session( $session ),
		);
	}
}
