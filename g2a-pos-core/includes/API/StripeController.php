<?php

namespace G2A\POS\API;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\OrderRepository;
use G2A\POS\Database\TenderRepository;
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
		$payload  = $req->get_json_params() ?: array();
		$cents    = (int) ( $payload['amount_cents'] ?? 0 );
		$order_id = (int) ( $payload['order_id'] ?? 0 );

		if ( $order_id > 0 ) {
			// Bind the intent amount to the persisted order: never more than
			// the outstanding balance (grand_total minus captured tenders).
			$order = ( new OrderRepository() )->find_with_items( $order_id );
			if ( ! $order ) {
				return new \WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
			}
			$captured   = ( new TenderRepository() )->captured_total( $order_id );
			$owed_cents = max( 0, (int) round( ( (float) $order['grand_total'] - $captured ) * 100 ) );
			if ( $cents <= 0 ) {
				$cents = $owed_cents;
			} elseif ( $cents > $owed_cents ) {
				return new \WP_Error( 'amount_mismatch', 'amount_cents exceeds the outstanding order balance.', array( 'status' => 422 ) );
			}
		} else {
			// Unbound intents (no order) are restricted to register managers and audited.
			if ( ! current_user_can( 'g2a_pos_manage_register' ) ) {
				return new \WP_Error( 'forbidden', 'Creating a payment intent without an order_id requires g2a_pos_manage_register.', array( 'status' => 403 ) );
			}
			( new AuditLogRepository() )->add(
				'stripe.intent.unbound',
				'payment_intent',
				'',
				null,
				array(
					'amount_cents' => $cents,
					'currency'     => (string) ( $payload['currency'] ?? 'usd' ),
				)
			);
		}

		$r = StripeTerminal::create_payment_intent( $cents, (string) ( $payload['currency'] ?? 'usd' ), (array) ( $payload['extra'] ?? array() ) );
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
		$payload      = $req->get_json_params() ?: array();
		$pi_id        = (string) ( $payload['payment_intent_id'] ?? '' );
		$amount_cents = isset( $payload['amount_cents'] ) ? (int) $payload['amount_cents'] : null;

		// If a tender line exists for this payment intent, cap the refund at
		// what is still captured on that line.
		$tender = $pi_id !== '' ? ( new TenderRepository() )->find_by_external_ref( $pi_id ) : null;
		if ( $tender ) {
			$remaining_cents = max( 0, (int) round( ( (float) $tender['amount'] - (float) $tender['refunded_amount'] ) * 100 ) );
			if ( $remaining_cents <= 0 ) {
				return new \WP_Error( 'nothing_to_refund', 'Tender line for this payment intent has no refundable balance.', array( 'status' => 422 ) );
			}
			if ( $amount_cents === null || $amount_cents > $remaining_cents ) {
				$amount_cents = $remaining_cents;
			}
		}

		( new AuditLogRepository() )->add(
			'stripe.refund',
			'payment_intent',
			$pi_id,
			null,
			array(
				'amount_cents' => $amount_cents,
				'reason'       => (string) ( $payload['reason'] ?? 'requested_by_customer' ),
				'tender_id'    => $tender ? (int) $tender['id'] : null,
			)
		);

		$r = StripeTerminal::refund_charge(
			$pi_id,
			$amount_cents,
			(string) ( $payload['reason'] ?? 'requested_by_customer' )
		);
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'stripe', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}
}
