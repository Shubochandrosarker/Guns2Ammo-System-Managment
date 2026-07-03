<?php
/**
 * GET  /agents
 * POST /agents/{id}/run
 * GET  /agents/{id}/history
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Agents\Agent_History;
use WordPressistic\G2ABA\Agents\Agent_Runner;
use WordPressistic\G2ABA\Agents\Agent_Store;

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

		register_rest_route(
			$this->namespace,
			'/agents/(?P<id>[a-z0-9_-]+)/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/agents/(?P<id>[a-z0-9_-]+)/prompt',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_prompt' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'template' => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	public function set_prompt( \WP_REST_Request $req ) {
		$id       = (string) $req->get_param( 'id' );
		$template = (string) $req->get_param( 'template' );

		$result = ( new Agent_Store() )->set_prompt( $id, $template );
		if ( ! ( $result['ok'] ?? false ) ) {
			$status = ( 'Unknown agent id.' === ( $result['error'] ?? '' ) ) ? 404 : 400;
			return new \WP_Error(
				'g2aba_agent_prompt_invalid',
				(string) ( $result['error'] ?? 'Invalid prompt.' ),
				array( 'status' => $status )
			);
		}
		return $this->ok( $result );
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return $this->ok( ( new Agent_Store() )->all() );
	}

	public function run( \WP_REST_Request $req ) {
		$id    = (string) $req->get_param( 'id' );
		$store = new Agent_Store();
		if ( null === $store->find( $id ) ) {
			return new \WP_Error(
				'g2aba_agent_not_found',
				__( 'Unknown agent id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		// Kick the runner via WP-Cron so the REST call doesn't block on the
		// model roundtrip. Agent_Runner::register() wires the hook.
		wp_schedule_single_event( time() + 5, Agent_Runner::CRON_HOOK, array( $id ) );
		return $this->ok( array( 'ok' => true, 'queued' => true ) );
	}

	public function history( \WP_REST_Request $req ) {
		$id = (string) $req->get_param( 'id' );
		if ( null === ( new Agent_Store() )->find( $id ) ) {
			return new \WP_Error(
				'g2aba_agent_not_found',
				__( 'Unknown agent id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}
		return $this->ok( Agent_History::for_agent( $id ) );
	}
}
