<?php
/**
 * GET /insights/business-gaps
 *
 * The gap engine cross-references analytics to surface concrete leaks.
 * Right now it evaluates a small handful of rules the client already asked
 * for (renewal rate < 65%, CCW conversion < 8%). New rules should be added
 * one at a time with a comment linking to the business justification.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Gaps_Provider;
use WordPressistic\G2ABA\Providers\GBP_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Tasks\Tasks_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gaps_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/insights/business-gaps',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/insights/business-gaps/(?P<id>[a-z0-9_-]+)/create-task',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_task' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function create_task( \WP_REST_Request $req ) {
		$id   = (string) $req->get_param( 'id' );
		$gaps = $this->compute_gaps();
		$gap  = null;
		foreach ( $gaps as $g ) {
			if ( ( $g['id'] ?? '' ) === $id ) {
				$gap = $g;
				break;
			}
		}
		if ( null === $gap ) {
			return new \WP_Error(
				'g2aba_gap_not_found',
				__( 'Gap not found.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		$task = ( new Tasks_Store() )->create(
			(string) ( $gap['problem'] ?? 'Business gap' ),
			self::compose_body( $gap ),
			Tasks_Store::SOURCE_BUSINESS_GAP,
			$id,
			(string) ( $gap['owner'] ?? '' )
		);
		( new Audit_Log() )->record(
			'gap.task_created',
			sprintf( 'Gap %s → task %s', $id, $task['id'] ),
			array( 'gapId' => $id, 'taskId' => $task['id'] )
		);
		return $this->ok( array( 'ok' => true, 'task' => $task ) );
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_body( array $gap ): string {
		$parts = array();
		foreach ( array( 'evidence' => 'Evidence', 'impact' => 'Impact', 'fix' => 'Fix', 'priority' => 'Priority', 'owner' => 'Owner' ) as $k => $label ) {
			if ( ! empty( $gap[ $k ] ) ) {
				$parts[] = $label . ': ' . (string) $gap[ $k ];
			}
		}
		return implode( "\n", $parts );
	}

	private function compute_gaps(): array {
		$range       = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		$bookings    = ( new Booking_Provider() )->analytics( $range );
		$memberships = ( new Membership_Provider() )->analytics( $range );
		$store       = ( new Store_Provider() )->analytics( $range );
		$seo         = ( new SEO_Provider() )->analytics( $range );
		$gbp         = ( new GBP_Provider() )->performance( $range );
		return ( new Gaps_Provider() )->detect( $bookings, $memberships, $store, $seo, $gbp );
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		return $this->ok( $this->compute_gaps() );
	}
}
