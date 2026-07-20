<?php
/**
 * Webhooks REST controller - receives and verifies gateway webhooks.
 *
 * @package G2AB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_REST_Webhooks_Controller {

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/webhooks/stripe', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_stripe' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/webhooks/paypal', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_paypal' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/webhooks/fortis', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_fortis' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/webhooks/authnet', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'handle_authnet' ),
			'permission_callback' => '__return_true',
		) );
	}

	private function dispatch( $gateway_id, WP_REST_Request $request ) {
		if ( ! class_exists( 'G2AB_Gateway_Manager' ) ) {
			return new WP_REST_Response( array( 'error' => 'gateway_manager_missing' ), 503 );
		}

		$gw = G2AB_Gateway_Manager::instance()->get( $gateway_id );
		if ( ! $gw ) {
			return new WP_REST_Response( array( 'error' => 'gateway_not_loaded', 'gateway' => $gateway_id ), 503 );
		}

		$body    = $request->get_body();
		$headers = $request->get_headers();
		$flat    = array();
		foreach ( $headers as $k => $v ) {
			$flat[ $k ] = is_array( $v ) ? implode( ',', $v ) : $v;
		}

		update_option( 'g2ab_' . sanitize_key( $gateway_id ) . '_webhook_last_received_at', current_time( 'mysql' ), false );

		$event = $gw->verify_webhook( $body, $flat );
		if ( is_wp_error( $event ) ) {
			update_option( 'g2ab_' . sanitize_key( $gateway_id ) . '_webhook_last_failed_at', current_time( 'mysql' ), false );
			$this->log( $gateway_id, 'verify_failed', $event->get_error_message(), $body, 'warning' );
			$data   = $event->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 400;
			return new WP_REST_Response( array( 'error' => $event->get_error_message() ), $status );
		}

		update_option( 'g2ab_' . sanitize_key( $gateway_id ) . '_webhook_last_verified_at', current_time( 'mysql' ), false );

		$result = $gw->process_webhook_event( $event );
		$type   = $event['type'] ?? ( $event['event_type'] ?? 'unknown' );

		if ( is_wp_error( $result ) ) {
			update_option( 'g2ab_' . sanitize_key( $gateway_id ) . '_webhook_last_failed_at', current_time( 'mysql' ), false );
			$this->log( $gateway_id, $type, $result->get_error_message(), null, 'error' );
			$data   = $result->get_error_data();
			$status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 500;
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'gateway' => $gateway_id,
					'type'    => $type,
					'error'   => $result->get_error_message(),
				),
				$status
			);
		}

		$handled   = ! empty( $result['handled'] );
		$retryable = ! empty( $result['retryable'] );
		$severity  = $retryable ? 'error' : ( $handled ? 'info' : 'warning' );
		$this->log( $gateway_id, $type, wp_json_encode( $result ), null, $severity );

		if ( $retryable ) {
			update_option( 'g2ab_' . sanitize_key( $gateway_id ) . '_webhook_last_failed_at', current_time( 'mysql' ), false );
			return new WP_REST_Response( array( 'ok' => false, 'gateway' => $gateway_id, 'type' => $type, 'result' => $result ), 503 );
		}

		update_option( 'g2ab_' . sanitize_key( $gateway_id ) . '_webhook_last_processed_at', current_time( 'mysql' ), false );

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'gateway' => $gateway_id,
				'type'    => $type,
				'handled' => $handled,
				'result'  => $result,
			),
			200
		);
	}

	public function handle_stripe( WP_REST_Request $request ) {
		return $this->dispatch( 'stripe', $request );
	}

	public function handle_paypal( WP_REST_Request $request ) {
		return $this->dispatch( 'paypal', $request );
	}

	public function handle_fortis( WP_REST_Request $request ) {
		return $this->dispatch( 'fortis', $request );
	}

	public function handle_authnet( WP_REST_Request $request ) {
		return $this->dispatch( 'authnet', $request );
	}

	private function log( $gateway, $event_type, $message, $body, $severity = 'info' ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'g2ab_logs', array(
			'event_type' => 'webhook_' . sanitize_key( $gateway ) . '_' . sanitize_key( $event_type ),
			'severity'   => sanitize_key( $severity ),
			'message'    => substr( (string) $message, 0, 1000 ),
			'context'    => wp_json_encode( array( 'gateway' => $gateway, 'event' => $event_type, 'body_size' => is_string( $body ) ? strlen( $body ) : 0 ) ),
			'ip_address' => function_exists( 'g2ab_get_client_ip' ) ? g2ab_get_client_ip() : '',
			'created_at' => current_time( 'mysql' ),
		) );
	}
}
