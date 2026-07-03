<?php
/**
 * GET /model-routing
 * PUT /model-routing
 *
 * The GET response is enriched with the purpose labels the frontend
 * renders. The PUT accepts a partial patch — omitting a purpose leaves it
 * unchanged; setting it to null explicitly clears the route.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Routing\Model_Routing_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Routing_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/model-routing',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_routing' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => array( 'PUT', 'POST' ),
					'callback'            => array( $this, 'update_routing' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function get_routing() {
		$store = new Model_Routing_Store();
		return $this->ok(
			array(
				'purposes' => Model_Routing_Store::PURPOSES,
				'labels'   => Model_Routing_Store::labels(),
				'routing'  => $store->all(),
			)
		);
	}

	public function update_routing( \WP_REST_Request $req ) {
		$patch = $req->get_json_params();
		if ( ! is_array( $patch ) ) {
			$patch = array();
		}
		$out = ( new Model_Routing_Store() )->update( $patch );
		if ( isset( $out['ok'] ) && false === $out['ok'] ) {
			return new \WP_Error(
				'g2aba_routing_invalid',
				(string) ( $out['error'] ?? 'Invalid routing patch.' ),
				array( 'status' => 400 )
			);
		}
		( new Audit_Log() )->record(
			'routing.updated',
			'Model routing updated',
			array( 'purposes' => array_keys( $patch ) )
		);
		return $this->ok( $out );
	}
}
