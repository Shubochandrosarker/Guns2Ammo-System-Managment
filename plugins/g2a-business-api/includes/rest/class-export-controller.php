<?php
/**
 * Export endpoints — CSV for tabular data, text for report deliveries.
 *
 * GET /export/tasks.csv         (read)
 * GET /export/audit-log.csv     (read)
 * GET /export/reports/{id}.txt  (read)
 *
 * Returns raw text with a Content-Disposition: attachment header so the
 * browser downloads the file rather than rendering it inline. WordPress'
 * default REST wrapper wraps every response in JSON — we override with a
 * WP_HTTP_Response returning `application/csv` / `text/plain` directly.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Export\Export_Renderer;
use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Reports\Reports_Store;
use WordPressistic\G2ABA\Tasks\Tasks_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Export_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/export/tasks.csv',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tasks_csv' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/export/audit-log.csv',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit_csv' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/export/reports/(?P<id>[a-z0-9_-]+)\.txt',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'report_txt' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);
	}

	public function tasks_csv() {
		$rows = ( new Tasks_Store() )->all();
		$body = Export_Renderer::to_csv(
			array( 'id', 'title', 'body', 'source', 'sourceId', 'owner', 'status', 'createdAt', 'resolvedAt' ),
			$rows,
			array(
				'sourceId'   => 'Source ID',
				'createdAt'  => 'Created at',
				'resolvedAt' => 'Resolved at',
			)
		);
		return self::download( $body, 'tasks.csv', 'text/csv' );
	}

	public function audit_csv() {
		$rows = ( new Audit_Log() )->all();
		$body = Export_Renderer::to_csv(
			array( 'ts', 'kind', 'summary', 'actor', 'meta' ),
			$rows,
			array( 'ts' => 'Timestamp', 'actor' => 'Actor user ID' )
		);
		return self::download( $body, 'audit-log.csv', 'text/csv' );
	}

	public function report_txt( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$store   = new Reports_Store();
		$def     = $store->find( $id );
		if ( null === $def ) {
			return new \WP_Error(
				'g2aba_report_not_found',
				__( 'Unknown report id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}
		$latest = $store->latest( $id );
		if ( null === $latest ) {
			return new \WP_Error(
				'g2aba_report_no_delivery',
				__( 'No delivery recorded yet — run this report first.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}
		$payload = array_merge( $latest, array( 'label' => (string) ( $def['label'] ?? $id ) ) );
		$body    = Export_Renderer::to_report_text( $payload );
		return self::download( $body, $id . '.txt', 'text/plain' );
	}

	/**
	 * Build a raw-body WP_REST_Response with an attachment filename.
	 * REST_Server serialises to JSON by default; setting headers here + a
	 * pre-serialised body sidesteps that.
	 */
	private static function download( string $body, string $filename, string $mime ): \WP_REST_Response {
		$res = new \WP_REST_Response( $body );
		$res->header( 'Content-Type', $mime . '; charset=utf-8' );
		$res->header( 'Content-Disposition', sprintf( 'attachment; filename="%s"', $filename ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
