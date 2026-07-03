<?php
/**
 * POST /bridgistic/ask
 * GET  /bridgistic/actions
 * POST /bridgistic/actions/{id}/approve
 * POST /bridgistic/actions/{id}/reject
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\BridGistic\Action_Queue;
use WordPressistic\G2ABA\BridGistic\Classifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BridGistic_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/bridgistic/ask',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ask' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'query' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bridgistic/actions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'actions' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bridgistic/actions/(?P<id>act_[a-f0-9]+)/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/bridgistic/actions/(?P<id>act_[a-f0-9]+)/reject',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function ask( \WP_REST_Request $req ) {
		$query = trim( (string) $req->get_param( 'query' ) );
		if ( '' === $query ) {
			return new \WP_Error(
				'g2aba_bridgistic_empty',
				__( 'Query is required.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		$category = ( new Classifier() )->classify( $query );

		$payload = array(
			'query'    => $query,
			'category' => $category,
		);

		if ( Classifier::CATEGORY_ACTION === $category ) {
			$entry = ( new Action_Queue() )->enqueue( $query, $category, (int) get_current_user_id() );
			$payload['requiresApproval'] = true;
			$payload['actionId']         = $entry['id'];
			$payload['answer']           = __(
				'Action detected. Queued for owner approval before BridGistic will execute it.',
				'g2a-business-api'
			);
		} elseif ( Classifier::CATEGORY_DRAFT === $category ) {
			$payload['requiresApproval'] = true;
			$payload['answer']           = __(
				'Draft prepared. Review + approve before sending — nothing has been sent.',
				'g2a-business-api'
			);
		} else {
			$payload['requiresApproval'] = false;
			$payload['answer']           = __(
				'Read-only query — served without any state change.',
				'g2a-business-api'
			);
		}

		return $this->ok( $payload );
	}

	public function actions( \WP_REST_Request $req ) {
		$status = (string) $req->get_param( 'status' );
		$queue  = new Action_Queue();
		if ( 'pending' === $status ) {
			return $this->ok( $queue->pending() );
		}
		return $this->ok( $queue->all() );
	}

	public function approve( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$entry   = ( new Action_Queue() )->resolve( $id, Action_Queue::STATUS_APPROVED, 'approved by owner' );
		if ( null === $entry ) {
			return new \WP_Error(
				'g2aba_bridgistic_not_found',
				__( 'Action not found or already resolved.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		// Fire an action so other plugins/handlers can perform the actual work.
		do_action( 'g2aba_bridgistic_action_approved', $entry );

		return $this->ok( $entry );
	}

	public function reject( \WP_REST_Request $req ) {
		$id    = (string) $req->get_param( 'id' );
		$entry = ( new Action_Queue() )->resolve( $id, Action_Queue::STATUS_REJECTED, 'rejected by owner' );
		if ( null === $entry ) {
			return new \WP_Error(
				'g2aba_bridgistic_not_found',
				__( 'Action not found or already resolved.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}
		return $this->ok( $entry );
	}
}
