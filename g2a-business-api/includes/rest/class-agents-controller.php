<?php
/**
 * GET  /agents
 * POST /agents/{id}/run
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agents_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/agents',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/agents/(?P<id>[a-z0-9_-]+)/run',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$raw = get_option( 'g2aba_agents', array() );
		return $this->ok( is_array( $raw ) ? array_values( $raw ) : array() );
	}

	public function run( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = get_option( 'g2aba_agents', array() );

		if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
			return new \WP_Error(
				'g2aba_agent_not_found',
				__( 'Unknown agent id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		$raw[ $id ]['lastRun']    = gmdate( 'c' );
		$raw[ $id ]['lastOutput'] = __( 'Run scheduled — output will appear when the agent completes.', 'g2a-business-api' );
		update_option( 'g2aba_agents', $raw, false );

		// The generator runs asynchronously via WP-Cron so we don't block the
		// dashboard's fetch on an LLM roundtrip.
		wp_schedule_single_event( time() + 5, 'g2aba_run_agent', array( $id ) );

		return $this->ok( array( 'ok' => true, 'queued' => true ) );
	}
}
