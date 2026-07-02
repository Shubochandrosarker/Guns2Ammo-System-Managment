<?php
/**
 * GET /ai/insights
 *
 * Insights are stored as an option and refreshed by the Insight Generator.
 * We deliberately do NOT run an LLM on every request — that's a huge cost
 * amplifier for a hot path.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Insights_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/ai/insights',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$raw = get_option( 'g2aba_insights_cache', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return $this->ok( array_values( $raw ) );
	}
}
