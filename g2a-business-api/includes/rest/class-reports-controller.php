<?php
/**
 * REST endpoints for the Reports engine.
 *
 * GET  /reports                    (read)  — catalog + last delivered
 * POST /reports/{id}/run-now       (admin) — regenerate, persist, return latest
 * GET  /reports/{id}/latest        (read)  — return the last recorded delivery
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Reports\Report_Generator;
use WordPressistic\G2ABA\Reports\Reports_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reports_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/reports',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_reports' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/reports/(?P<id>[a-z0-9_-]+)/run-now',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_now' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/reports/(?P<id>[a-z0-9_-]+)/latest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'latest' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function list_reports() {
		return $this->ok( ( new Reports_Store() )->all() );
	}

	public function run_now( \WP_REST_Request $req ) {
		$id    = (string) $req->get_param( 'id' );
		$store = new Reports_Store();
		$def   = $store->find( $id );
		if ( null === $def ) {
			return new \WP_Error(
				'g2aba_report_not_found',
				__( 'Unknown report id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		$handler = (string) ( $def['handlerSlug'] ?? '' );
		if ( '' === $handler ) {
			return new \WP_Error(
				'g2aba_report_no_handler',
				__( 'This report has no handler wired.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		$payload = ( new Report_Generator() )->generate( $handler );
		$entry   = $store->record_delivery( $id, $payload );
		( new Audit_Log() )->record(
			'report.generated',
			sprintf( 'Report %s generated on demand', $id ),
			array( 'reportId' => $id )
		);
		return $this->ok( $entry );
	}

	public function latest( \WP_REST_Request $req ) {
		$id   = (string) $req->get_param( 'id' );
		$last = ( new Reports_Store() )->latest( $id );
		if ( null === $last ) {
			return new \WP_Error(
				'g2aba_report_no_delivery',
				__( 'No delivery recorded yet — run this report first.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}
		return $this->ok( $last );
	}
}
