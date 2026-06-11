<?php

namespace G2A\POS\API;

use G2A\POS\Database\OrderRepository;
use G2A\POS\Domain\OrderEngine;
use WP_REST_Request;

final class OrderController {

	public static function index( WP_REST_Request $request ) {
		$items = ( new OrderRepository() )->list(
			array(
				'status'           => $request->get_param( 'status' ),
				'payment_status'   => $request->get_param( 'payment_status' ),
				'compliance_state' => $request->get_param( 'compliance_state' ),
				'customer_id'      => $request->get_param( 'customer_id' ),
				'limit'            => (int) ( $request->get_param( 'limit' ) ?: 50 ),
			)
		);
		return array( 'items' => $items );
	}

	public static function get( WP_REST_Request $request ) {
		$order_id = (int) $request->get_param( 'id' );
		if ( $order_id <= 0 ) {
			return new \WP_Error( 'invalid_order', 'Invalid order id.', array( 'status' => 400 ) );
		}

		$repo  = new \G2A\POS\Database\OrderRepository();
		$order = $repo->find_with_items( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		return array(
			'order' => $order,
		);
	}

	public static function create( WP_REST_Request $request ) {
		$payload = $request->get_json_params();

		foreach ( array( 'subtotal', 'grand_total', 'items' ) as $required ) {
			if ( ! isset( $payload[ $required ] ) ) {
				return new \WP_Error( 'missing_field', sprintf( '%s is required.', $required ), array( 'status' => 400 ) );
			}
		}

		$result = OrderEngine::create( $payload );

		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'order_error', $result['error'], array( 'status' => 422 ) );
		}

		return $result;
	}

	public static function update_states( WP_REST_Request $request ) {
		$order_id = (int) $request->get_param( 'id' );
		if ( $order_id <= 0 ) {
			return new \WP_Error( 'invalid_order', 'Invalid order id.', array( 'status' => 400 ) );
		}

		$payload = $request->get_json_params();
		$repo    = new \G2A\POS\Database\OrderRepository();
		$order   = $repo->find_with_items( $order_id );
		if ( ! $order ) {
			return new \WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		$compliance  = sanitize_key( (string) ( $payload['compliance_state'] ?? $order['compliance_state'] ) );
		$payment     = sanitize_key( (string) ( $payload['payment_status'] ?? $order['payment_status'] ) );
		$has_firearm = false;
		foreach ( ( $order['items'] ?? array() ) as $item ) {
			if ( ( $item['item_type'] ?? '' ) === 'firearm' ) {
				$has_firearm = true;
				break;
			}
		}
		if ( $has_firearm && in_array( $payment, array( 'paid', 'completed' ), true ) && ! in_array( $compliance, array( 'approved', 'cleared' ), true ) ) {
			return new \WP_Error( 'blocked', 'Firearm order cannot be finalized until compliance is approved.', array( 'status' => 422 ) );
		}

		$ok = $repo->update_states(
			$order_id,
			array(
				'status'           => $payload['status'] ?? $order['status'],
				'payment_status'   => $payment,
				'compliance_state' => $compliance,
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'update_failed', 'Order update failed.', array( 'status' => 500 ) );
		}

		$updated = $repo->find_with_items( $order_id );
		self::sync_wc_state( $updated );
		return array( 'order' => $updated );
	}

	private static function sync_wc_state( ?array $order ): void {
		if ( ! $order || empty( $order['wc_order_id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$wc_order = wc_get_order( (int) $order['wc_order_id'] );
		if ( ! $wc_order ) {
			return;
		}

		$payment    = sanitize_key( (string) ( $order['payment_status'] ?? 'pending' ) );
		$compliance = sanitize_key( (string) ( $order['compliance_state'] ?? 'pending_review' ) );
		$status     = sanitize_key( (string) ( $order['status'] ?? 'open' ) );

		if ( in_array( $status, array( 'cancelled', 'void' ), true ) ) {
			$wc_order->update_status( 'cancelled', 'POS order cancelled.' );
			return;
		}

		if ( in_array( $payment, array( 'paid', 'completed' ), true ) && in_array( $compliance, array( 'approved', 'cleared', 'n/a' ), true ) ) {
			$wc_order->payment_complete();
		}
	}
}
