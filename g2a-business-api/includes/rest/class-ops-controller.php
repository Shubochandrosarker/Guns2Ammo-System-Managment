<?php
/**
 * REST controller for operational review queues.
 *
 * GET  /email-drafts
 * POST /email-drafts/{id}/send
 * POST /email-drafts/{id}/discard
 *
 * GET  /cancellations
 * POST /cancellations/{id}/mark-completed
 * POST /cancellations/{id}/drop
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Cancellation_Queue;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Email_Sender;

// Route: GET /audit-log — read the bounded audit log the ops subsystem writes to.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ops_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/email-drafts',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_email_drafts' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/email-drafts/(?P<id>em_[a-f0-9]+)/send',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_email_draft' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'to'      => array( 'type' => 'string' ),
					'subject' => array( 'type' => 'string' ),
					'body'    => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/email-drafts/(?P<id>em_[a-f0-9]+)/discard',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'discard_email_draft' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cancellations',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_cancellations' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cancellations/(?P<id>cx_[a-f0-9]+)/mark-completed',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_cancellation_completed' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'notes' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/cancellations/(?P<id>cx_[a-f0-9]+)/drop',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'drop_cancellation' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/audit-log',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'audit_log' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'limit' => array( 'type' => 'integer' ),
				),
			)
		);
	}

	public function audit_log( \WP_REST_Request $req ) {
		$limit = (int) $req->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 100;
		}
		$limit = min( 500, $limit );
		return $this->ok( array_slice( ( new Audit_Log() )->all(), 0, $limit ) );
	}

	public function list_email_drafts( \WP_REST_Request $req ) {
		$store = new Email_Draft_Store();
		return $this->ok( 'pending' === (string) $req->get_param( 'status' ) ? $store->pending() : $store->all() );
	}

	public function send_email_draft( \WP_REST_Request $req ) {
		$id    = (string) $req->get_param( 'id' );
		$store = new Email_Draft_Store();
		$draft = $store->find( $id );
		if ( null === $draft ) {
			return $this->not_found();
		}
		if ( ( $draft['status'] ?? '' ) !== Email_Draft_Store::STATUS_PENDING ) {
			return new \WP_Error(
				'g2aba_email_not_pending',
				__( 'Only pending drafts can be sent.', 'g2a-business-api' ),
				array( 'status' => 409 )
			);
		}

		// Optional overrides from the reviewer.
		foreach ( array( 'to', 'subject', 'body' ) as $field ) {
			$override = $req->get_param( $field );
			if ( is_string( $override ) && '' !== trim( $override ) ) {
				$draft[ $field ] = $override;
			}
		}

		$result = ( new Email_Sender() )->send( $draft );
		return $this->ok( $result );
	}

	public function discard_email_draft( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$store   = new Email_Draft_Store();
		$updated = $store->transition( $id, Email_Draft_Store::STATUS_DISCARDED, 'Discarded by owner' );
		if ( null === $updated ) {
			return $this->not_found();
		}
		( new Audit_Log() )->record( 'email.discarded', 'Discarded draft ' . $id, array( 'draftId' => $id ) );
		return $this->ok( $updated );
	}

	public function list_cancellations( \WP_REST_Request $req ) {
		$queue = new Cancellation_Queue();
		return $this->ok( 'awaiting' === (string) $req->get_param( 'status' ) ? $queue->awaiting() : $queue->all() );
	}

	public function mark_cancellation_completed( \WP_REST_Request $req ) {
		$id    = (string) $req->get_param( 'id' );
		$notes = trim( (string) $req->get_param( 'notes' ) );
		$queue = new Cancellation_Queue();
		$updated = $queue->mark_completed( $id, $notes );
		if ( null === $updated ) {
			return $this->not_found();
		}
		( new Audit_Log() )->record( 'cancellation.completed', 'Cancellation ' . $id . ' marked completed', array( 'cancellationId' => $id ) );
		return $this->ok( $updated );
	}

	public function drop_cancellation( \WP_REST_Request $req ) {
		$id      = (string) $req->get_param( 'id' );
		$queue   = new Cancellation_Queue();
		$updated = $queue->drop( $id, 'Dropped by owner' );
		if ( null === $updated ) {
			return $this->not_found();
		}
		( new Audit_Log() )->record( 'cancellation.dropped', 'Cancellation ' . $id . ' dropped', array( 'cancellationId' => $id ) );
		return $this->ok( $updated );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error(
			'g2aba_ops_not_found',
			__( 'Item not found or already resolved.', 'g2a-business-api' ),
			array( 'status' => 404 )
		);
	}
}
