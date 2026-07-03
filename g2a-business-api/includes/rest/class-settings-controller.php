<?php
/**
 * REST endpoints for editable dashboard settings.
 *
 * GET  /settings   (read)  — full settings snapshot
 * PUT  /settings   (admin) — partial or full patch; returns fully-hydrated state
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Settings\Settings_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					// Accept both PUT and POST so the frontend can `fetch` with `method:'PUT'`
					// while WP's core routing (which historically preferred POST) still works.
					'methods'             => array( 'PUT', 'POST' ),
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function get_settings() {
		return $this->ok( ( new Settings_Store() )->all() );
	}

	public function update_settings( \WP_REST_Request $req ) {
		$patch = $req->get_json_params();
		if ( ! is_array( $patch ) ) {
			$patch = array();
		}
		$out = ( new Settings_Store() )->update( $patch );
		( new Audit_Log() )->record(
			'settings.updated',
			'Dashboard settings updated',
			array( 'keys' => array_keys( $patch ) )
		);
		return $this->ok( $out );
	}
}
