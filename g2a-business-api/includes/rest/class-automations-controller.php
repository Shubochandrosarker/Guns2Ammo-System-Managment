<?php
/**
 * GET  /automations
 * POST /automations/{id}/toggle
 *
 * Reads and mutates through Automation_Store, and calls Cron_Scheduler to
 * apply schedule side effects so the toggle actually changes WP-Cron state.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Automation\Automation_Store;
use WordPressistic\G2ABA\Automation\Cron_Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Automations_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/automations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/automations/(?P<id>[a-z0-9_-]+)/toggle',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'toggle' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return $this->ok( ( new Automation_Store() )->all() );
	}

	public function toggle( \WP_REST_Request $req ) {
		$slug    = (string) $req->get_param( 'id' );
		$enabled = (bool) $req->get_param( 'enabled' );
		$store   = new Automation_Store();

		$updated = $store->set_status( $slug, $enabled ? Automation_Store::STATUS_ACTIVE : Automation_Store::STATUS_PAUSED );
		if ( null === $updated ) {
			return new \WP_Error(
				'g2aba_automation_not_found',
				__( 'Unknown automation slug.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		Cron_Scheduler::apply( $updated );
		return $this->ok( $updated );
	}
}
