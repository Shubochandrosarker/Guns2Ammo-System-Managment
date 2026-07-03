<?php
/**
 * REST endpoints for the Tasks store.
 *
 * GET  /tasks?status=open        (read)
 * POST /tasks                    (admin) — manual task creation
 * POST /tasks/{id}/resolve       (admin)
 * POST /tasks/{id}/dismiss       (admin)
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Tasks\Tasks_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tasks_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/tasks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_tasks' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
					'args'                => array(
						'status' => array( 'type' => 'string' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_task' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
					'args'                => array(
						'title' => array( 'type' => 'string', 'required' => true ),
						'body'  => array( 'type' => 'string' ),
						'owner' => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>task_[a-f0-9]+)/resolve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resolve_task' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/tasks/(?P<id>task_[a-f0-9]+)/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'dismiss_task' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function list_tasks( \WP_REST_Request $req ) {
		$store  = new Tasks_Store();
		$status = (string) $req->get_param( 'status' );
		return $this->ok( 'open' === $status ? $store->open() : $store->all() );
	}

	public function create_task( \WP_REST_Request $req ) {
		$title = trim( (string) $req->get_param( 'title' ) );
		if ( '' === $title ) {
			return new \WP_Error(
				'g2aba_task_title_required',
				__( 'Task title is required.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}
		$body  = (string) $req->get_param( 'body' );
		$owner = (string) $req->get_param( 'owner' );

		$task = ( new Tasks_Store() )->create( $title, $body, Tasks_Store::SOURCE_MANUAL, '', $owner );
		( new Audit_Log() )->record( 'task.created', sprintf( 'Manual task created: %s', $task['id'] ), array( 'taskId' => $task['id'] ) );
		return $this->ok( $task );
	}

	public function resolve_task( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$out = ( new Tasks_Store() )->resolve( $id );
		if ( null === $out ) {
			return $this->not_found();
		}
		( new Audit_Log() )->record( 'task.resolved', sprintf( 'Task %s resolved', $id ), array( 'taskId' => $id ) );
		return $this->ok( $out );
	}

	public function dismiss_task( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$out = ( new Tasks_Store() )->dismiss( $id );
		if ( null === $out ) {
			return $this->not_found();
		}
		( new Audit_Log() )->record( 'task.dismissed', sprintf( 'Task %s dismissed', $id ), array( 'taskId' => $id ) );
		return $this->ok( $out );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error(
			'g2aba_task_not_found',
			__( 'Task not found or already resolved.', 'g2a-business-api' ),
			array( 'status' => 404 )
		);
	}
}
