<?php

namespace G2A\POS\API;

use G2A\POS\Database\AuditLogRepository;
use G2A\POS\Database\GiftCardRepository;
use G2A\POS\Database\LoyaltyRepository;
use WP_REST_Request;

final class LoyaltyController {

	public static function balance( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return ( new LoyaltyRepository() )->balance( $id );
	}

	public static function adjust( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$body   = $request->get_json_params() ?: array();
		$result = ( new LoyaltyRepository() )->adjust(
			$id,
			(string) ( $body['tx_type'] ?? 'adjust' ),
			(int) ( $body['delta_points'] ?? 0 ),
			(int) ( $body['delta_credit_cents'] ?? 0 ),
			$body
		);
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['error'], $result['error'], array( 'status' => 422 ) );
		}
		( new AuditLogRepository() )->add(
			'loyalty.adjust',
			'loyalty_account',
			(string) $id,
			null,
			array(
				'tx_type'            => (string) ( $body['tx_type'] ?? 'adjust' ),
				'delta_points'       => (int) ( $body['delta_points'] ?? 0 ),
				'delta_credit_cents' => (int) ( $body['delta_credit_cents'] ?? 0 ),
				'reason'             => sanitize_text_field( (string) ( $body['reason'] ?? '' ) ),
				'points_after'       => $result['points'] ?? null,
				'credit_cents_after' => $result['credit_cents'] ?? null,
			)
		);
		return $result;
	}

	public static function history( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return array( 'items' => ( new LoyaltyRepository() )->history( $id, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
	}

	public static function gift_card_list( WP_REST_Request $request ) {
		return array( 'items' => ( new GiftCardRepository() )->list( (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
	}

	public static function gift_card_issue( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: array();
		$result = ( new GiftCardRepository() )->issue( $body );
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['error'], $result['error'], array( 'status' => 422 ) );
		}
		( new AuditLogRepository() )->add(
			'gift_card.issue',
			'gift_card',
			(string) $result['id'],
			null,
			array(
				'balance_cents' => (int) $result['balance_cents'],
				'customer_id'   => isset( $body['customer_id'] ) ? (int) $body['customer_id'] : null,
				'expires_at'    => $body['expires_at'] ?? null,
			)
		);
		return $result;
	}

	public static function gift_card_balance( WP_REST_Request $request ) {
		$code = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$card = ( new GiftCardRepository() )->find_by_code( $code );
		if ( ! $card ) {
			return new \WP_Error( 'not_found', 'Gift card not found', array( 'status' => 404 ) );
		}
		return array(
			'id'            => (int) $card['id'],
			'balance_cents' => (int) $card['balance_cents'],
			'currency'      => $card['currency'],
			'status'        => $card['status'],
			'expires_at'    => $card['expires_at'],
		);
	}

	public static function gift_card_redeem( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: array();
		$result = ( new GiftCardRepository() )->redeem(
			(string) ( $body['code'] ?? '' ),
			(int) ( $body['amount_cents'] ?? 0 ),
			isset( $body['pos_order_id'] ) ? (int) $body['pos_order_id'] : null
		);
		if ( ! $result['ok'] ) {
			return new \WP_Error( $result['error'], $result['error'], array( 'status' => 422 ) );
		}
		return $result;
	}
}
