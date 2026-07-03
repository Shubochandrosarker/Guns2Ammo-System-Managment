<?php
/**
 * Top-level plugin bootstrap. Wires the router into rest_api_init.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	public function run(): void {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Cron hooks must be registered on every request (not just admin), so
		// that WP-Cron can invoke them when a scheduled event fires.
		\WordPressistic\G2ABA\AI\Insight_Generator::register_cron();
	}

	public function register_rest_routes(): void {
		( new Router() )->register();
	}
}
