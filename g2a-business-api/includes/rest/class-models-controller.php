<?php
/**
 * GET  /model-connections
 * POST /model-connections/{id}/test
 *
 * Provider API keys are stored via the Secrets class and never leave PHP.
 * The REST payload replaces them with a static mask so the dashboard has
 * something to render.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Models_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/model-connections',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/model-connections/(?P<id>[a-z0-9_-]+)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$raw = get_option( 'g2aba_models', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$out = array();
		foreach ( $raw as $id => $record ) {
			$record         = is_array( $record ) ? $record : array();
			$record['id']   = (string) $id;
			$record['keyMasked'] = Secrets::has( 'model:' . $id ) ? Secrets::mask( 'model:' . $id ) : '(not set)';
			// Never leak the plaintext key even by accident.
			unset( $record['apiKey'], $record['apiKeyPlain'] );
			$out[] = $record;
		}
		return $this->ok( $out );
	}

	public function test( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = get_option( 'g2aba_models', array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
			return new \WP_Error(
				'g2aba_model_not_found',
				__( 'Unknown model id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		$plain = Secrets::get( 'model:' . $id );
		if ( null === $plain ) {
			return $this->ok(
				array(
					'ok'    => false,
					'error' => __( 'No API key stored for this model connection.', 'g2a-business-api' ),
				)
			);
		}

		$record = $raw[ $id ];
		$url    = isset( $record['apiBaseUrl'] ) ? (string) $record['apiBaseUrl'] : '';
		if ( '' === $url ) {
			return $this->ok(
				array( 'ok' => false, 'error' => 'Missing apiBaseUrl' )
			);
		}

		$start = microtime( true );
		$res   = wp_remote_get(
			esc_url_raw( trailingslashit( $url ) ),
			array(
				'timeout'     => 6,
				'redirection' => 2,
				'headers'     => array(
					// Sending Auth here is a smoke test only. Real per-provider
					// health lives in each provider's client — added later.
					'Authorization' => 'Bearer ' . $plain,
				),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return $this->ok(
				array( 'ok' => false, 'error' => $res->get_error_message() )
			);
		}
		return $this->ok(
			array( 'ok' => true, 'latencyMs' => $ms )
		);
	}
}
