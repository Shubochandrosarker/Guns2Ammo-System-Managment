<?php
/**
 * GET /system/namespaces
 *
 * Dynamic namespace discovery for the dashboard's System Health page. Calls
 * WordPress core's own `rest_get_server()->get_namespaces()` in-process (no
 * HTTP round-trip) to see exactly which REST namespaces are registered on
 * the live install, then flags a small set of third-party plugins the
 * dashboard optionally shows UI for. Nothing here is guessed or hardcoded —
 * every `detected` flag is computed from the real, live namespace list.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\System\Namespace_Detector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Namespaces_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/system/namespaces',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'namespaces' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function namespaces() {
		$namespaces = function_exists( 'rest_get_server' )
			? array_values( (array) rest_get_server()->get_namespaces() )
			: array();

		return $this->ok(
			array(
				'namespaces' => $namespaces,
				'detected'   => Namespace_Detector::detect( $namespaces ),
			)
		);
	}
}
