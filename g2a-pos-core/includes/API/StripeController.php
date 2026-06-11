<?php

namespace G2A\POS\API;

use G2A\POS\Payments\StripeTerminal;
use WP_REST_Request;

final class StripeController {

	public static function connection_token( WP_REST_Request $req ) {
		if ( ! StripeTerminal::configured() ) {
			return new \WP_Error( 'not_configured', 'Stripe not configured.', array( 'status' => 503 ) );
		}
		return StripeTerminal::connection_token();
	}

	public static function create_intent( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		$cents   = (int) ( $payload['amount_cents'] ?? 0 );
		$r       = StripeTerminal::create_payment_intent( $cents, (string) ( $payload['currency'] ?? 'usd' ), (array) ( $payload['extra'] ?? array() ) );
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'stripe', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}

	public static function capture( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		$r       = StripeTerminal::capture_payment_intent( (string) ( $payload['payment_intent_id'] ?? '' ) );
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'stripe', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}

	public static function refund( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		$r       = StripeTerminal::refund_charge(
			(string) ( $payload['payment_intent_id'] ?? '' ),
			isset( $payload['amount_cents'] ) ? (int) $payload['amount_cents'] : null,
			(string) ( $payload['reason'] ?? 'requested_by_customer' )
		);
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'stripe', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}
}
