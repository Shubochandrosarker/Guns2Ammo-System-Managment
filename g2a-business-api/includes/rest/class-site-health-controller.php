<?php
/**
 * GET /system/site-health
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Providers\Site_Health_Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Health_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/system/site-health',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'summary' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function summary() {
		return $this->ok( ( new Site_Health_Provider() )->summary() );
	}
}
