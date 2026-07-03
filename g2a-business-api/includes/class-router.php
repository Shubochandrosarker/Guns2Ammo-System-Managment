<?php
/**
 * Registers every REST controller with WordPress.
 *
 * Kept as a plain factory list so the router is trivially testable —
 * unit tests can enumerate the expected route table without spinning up WP.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

use WordPressistic\G2ABA\REST\Agents_Controller;
use WordPressistic\G2ABA\REST\Analytics_Controller;
use WordPressistic\G2ABA\REST\Auth_Controller;
use WordPressistic\G2ABA\REST\Automations_Controller;
use WordPressistic\G2ABA\REST\BridGistic_Controller;
use WordPressistic\G2ABA\REST\Gaps_Controller;
use WordPressistic\G2ABA\REST\Health_Controller;
use WordPressistic\G2ABA\REST\Insights_Controller;
use WordPressistic\G2ABA\REST\Models_Controller;
use WordPressistic\G2ABA\REST\Ops_Controller;
use WordPressistic\G2ABA\REST\Public_Controller;
use WordPressistic\G2ABA\REST\Tasks_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Router {
	/**
	 * List of controller class names the plugin registers.
	 *
	 * @return array<int, class-string>
	 */
	public static function controllers(): array {
		return array(
			Auth_Controller::class,
			Analytics_Controller::class,
			Insights_Controller::class,
			Gaps_Controller::class,
			Automations_Controller::class,
			Agents_Controller::class,
			Models_Controller::class,
			Health_Controller::class,
			BridGistic_Controller::class,
			Ops_Controller::class,
			Public_Controller::class,
			Tasks_Controller::class,
		);
	}

	public function register(): void {
		foreach ( self::controllers() as $class ) {
			$controller = new $class();
			$controller->register_routes();
		}
	}
}
