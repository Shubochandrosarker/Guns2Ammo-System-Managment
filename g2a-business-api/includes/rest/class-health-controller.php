<?php
/**
 * GET /system/health       — Phase-C envelope: plugins/cron/sessions/audit/
 *                            integrations (booleans + counts only, no
 *                            secrets or paths). Replaced the pre-0.3.0 bare
 *                            SystemHealthCheck[] payload at the same path;
 *                            the dashboard frontend was rewired to this
 *                            contract in the same release.
 * GET /system/integrations — unchanged boolean map (legacy consumers).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Providers\Analytics\System_Health_Provider;
use WordPressistic\G2ABA\Providers\Health_Provider;
use WordPressistic\G2ABA\Response_Envelope;

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
		$provider = new System_Health_Provider();
		$report   = $provider->report();

		// The report is point-in-time; strip provider plumbing keys the
		// /system/health contract doesn't carry.
		unset( $report['available'], $report['range'] );
		$last_updated = $report['lastUpdatedAt'] ?? Response_Envelope::now_iso();
		unset( $report['lastUpdatedAt'] );

		return $this->ok(
			Response_Envelope::success(
				$report,
				array( $provider->source_slug() ),
				array(
					'status'        => Response_Envelope::FRESH,
					'lastUpdatedAt' => $last_updated,
				)
			)
		);
	}

	public function integrations() {
		return $this->ok( ( new Health_Provider() )->integrations() );
	}
}
