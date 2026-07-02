<?php
/**
 * GET  /automations
 * POST /automations/{id}/toggle
 *
 * Automations live in the `g2aba_automations` option — each is a small record
 * describing a trigger + action + status + last-run bookkeeping.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Automations_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/automations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/automations/(?P<id>[a-z0-9_-]+)/toggle',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$raw = get_option( 'g2aba_automations', array() );
		return $this->ok( is_array( $raw ) ? array_values( $raw ) : array() );
	}

	public function toggle( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$enabled = (bool) $req->get_param( 'enabled' );

		$raw = get_option( 'g2aba_automations', array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
			return new \WP_Error(
				'g2aba_automation_not_found',
				__( 'Unknown automation id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		$raw[ $id ]['status'] = $enabled ? 'active' : 'paused';
		update_option( 'g2aba_automations', $raw, false );

		return $this->ok( array( 'ok' => true ) );
	}
}
