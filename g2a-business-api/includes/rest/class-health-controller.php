<?php
/**
 * GET /system/health
 * GET /system/integrations
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Providers\Health_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Health_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/system/health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'checks' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
		register_rest_route(
			$this->namespace,
			'/system/integrations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'integrations' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function checks() {
		return $this->ok( ( new Health_Provider() )->checks() );
	}

	public function integrations() {
		return $this->ok( ( new Health_Provider() )->integrations() );
	}
}
