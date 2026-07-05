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
use WordPressistic\G2ABA\REST\Brain_Controller;
use WordPressistic\G2ABA\REST\BridGistic_Controller;
use WordPressistic\G2ABA\REST\Content_Controller;
use WordPressistic\G2ABA\REST\Email_Overview_Controller;
use WordPressistic\G2ABA\REST\Export_Controller;
use WordPressistic\G2ABA\REST\Gaps_Controller;
use WordPressistic\G2ABA\REST\Health_Controller;
use WordPressistic\G2ABA\REST\Insights_Controller;
use WordPressistic\G2ABA\REST\Leads_Controller;
use WordPressistic\G2ABA\REST\Models_Controller;
use WordPressistic\G2ABA\REST\Namespaces_Controller;
use WordPressistic\G2ABA\REST\Ops_Controller;
use WordPressistic\G2ABA\REST\Public_Controller;
use WordPressistic\G2ABA\REST\Reports_Controller;
use WordPressistic\G2ABA\REST\Routing_Controller;
use WordPressistic\G2ABA\REST\Settings_Controller;
use WordPressistic\G2ABA\REST\Site_Health_Controller;
use WordPressistic\G2ABA\REST\System_Controller;
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
			Namespaces_Controller::class,
			Health_Controller::class,
			Brain_Controller::class,
			BridGistic_Controller::class,
			Content_Controller::class,
			Email_Overview_Controller::class,
			Ops_Controller::class,
			Public_Controller::class,
			Reports_Controller::class,
			Routing_Controller::class,
			Settings_Controller::class,
			Site_Health_Controller::class,
			System_Controller::class,
			Tasks_Controller::class,
			Export_Controller::class,
			Leads_Controller::class,
		);
	}

	public function register(): void {
		foreach ( self::controllers() as $class ) {
			$controller = new $class();
			$controller->register_routes();
		}
	}
}
