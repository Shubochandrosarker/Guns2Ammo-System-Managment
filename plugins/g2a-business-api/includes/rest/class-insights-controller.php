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

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Tasks\Insight_Decisions;
use WordPressistic\G2ABA\Tasks\Tasks_Store;

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

		register_rest_route(
			$this->namespace,
			'/ai/insights/(?P<id>[a-z0-9_-]+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/ai/insights/(?P<id>[a-z0-9_-]+)/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$raw = get_option( 'g2aba_insights_cache', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$insights = ( new Insight_Decisions() )->apply( array_values( $raw ) );
		return $this->ok( $insights );
	}

	public function approve( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$insight = $this->find_insight( $id );
		if ( null === $insight ) {
			return $this->not_found();
		}

		$task = ( new Tasks_Store() )->create(
			(string) ( $insight['title'] ?? 'Approved AI insight' ),
			self::compose_task_body( $insight ),
			Tasks_Store::SOURCE_AI_INSIGHT,
			$id
		);
		( new Insight_Decisions() )->approve( $id, (string) ( $task['id'] ?? '' ) );
		( new Audit_Log() )->record(
			'insight.approved',
			sprintf( 'Insight %s approved → task %s', $id, $task['id'] ),
			array( 'insightId' => $id, 'taskId' => $task['id'] )
		);
		return $this->ok( array( 'ok' => true, 'task' => $task ) );
	}

	public function dismiss( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$insight = $this->find_insight( $id );
		if ( null === $insight ) {
			return $this->not_found();
		}
		( new Insight_Decisions() )->dismiss( $id );
		( new Audit_Log() )->record( 'insight.dismissed', sprintf( 'Insight %s dismissed', $id ), array( 'insightId' => $id ) );
		return $this->ok( array( 'ok' => true ) );
	}

	private function find_insight( string $id ): ?array {
		$raw = get_option( 'g2aba_insights_cache', array() );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		foreach ( $raw as $ins ) {
			if ( is_array( $ins ) && ( $ins['id'] ?? '' ) === $id ) {
				return $ins;
			}
		}
		return null;
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_task_body( array $insight ): string {
		$parts = array();
		foreach ( array( 'summary', 'reason', 'action', 'expectedImpact' ) as $k ) {
			if ( ! empty( $insight[ $k ] ) ) {
				$parts[] = ucfirst( $k ) . ': ' . (string) $insight[ $k ];
			}
		}
		return implode( "\n", $parts );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error(
			'g2aba_insight_not_found',
			__( 'Insight not found.', 'g2a-business-api' ),
			array( 'status' => 404 )
		);
	}
}
