<?php
/**
 * GET   /leads                    — filtered, paginated lead listing
 * GET   /leads/stats               — counts by category/status + time windows
 * GET   /leads/(?P<id>\d+)         — single lead
 * PATCH /leads/(?P<id>\d+)         — { status?, assigned_agent? }
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Leads\Leads_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Leads_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/leads',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_leads' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'category'  => array( 'type' => 'string' ),
					'status'    => array( 'type' => 'string' ),
					'source'    => array( 'type' => 'string' ),
					'search'    => array( 'type' => 'string' ),
					'date_from' => array( 'type' => 'string' ),
					'date_to'   => array( 'type' => 'string' ),
					'page'      => array( 'type' => 'integer' ),
					'per_page'  => array( 'type' => 'integer' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/leads/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/leads/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_lead' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => array( 'PATCH', 'PUT' ),
					'callback'            => array( $this, 'update_lead' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);
	}

	public function list_leads( \WP_REST_Request $req ) {
		$args = array(
			'category'  => (string) $req->get_param( 'category' ),
			'status'    => (string) $req->get_param( 'status' ),
			'source'    => (string) $req->get_param( 'source' ),
			'search'    => (string) $req->get_param( 'search' ),
			'date_from' => (string) $req->get_param( 'date_from' ),
			'date_to'   => (string) $req->get_param( 'date_to' ),
			'page'      => (int) $req->get_param( 'page' ),
			'per_page'  => (int) $req->get_param( 'per_page' ),
		);
		return $this->ok( ( new Leads_Repository() )->query( $args ) );
	}

	public function stats() {
		return $this->ok( ( new Leads_Repository() )->stats() );
	}

	public function get_lead( \WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$lead = ( new Leads_Repository() )->find( $id );
		if ( null === $lead ) {
			return $this->not_found();
		}
		return $this->ok( $lead );
	}

	public function update_lead( \WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$repo = new Leads_Repository();

		if ( null === $repo->find( $id ) ) {
			return $this->not_found();
		}

		$patch = $req->get_json_params();
		if ( ! is_array( $patch ) ) {
			$patch = array();
		}

		if ( array_key_exists( 'status', $patch ) ) {
			$status = (string) $patch['status'];
			if ( ! in_array( $status, Leads_Repository::STATUSES, true ) ) {
				return new \WP_Error(
					'g2aba_lead_invalid_status',
					__( 'Invalid lead status.', 'g2a-business-api' ),
					array( 'status' => 400 )
				);
			}
			$repo->update_status( $id, $status );
		}

		if ( array_key_exists( 'assigned_agent', $patch ) ) {
			$agent = $patch['assigned_agent'];
			$repo->assign( $id, null === $agent ? null : (string) $agent );
		}

		return $this->ok( $repo->find( $id ) );
	}

	private function not_found(): \WP_Error {
		return new \WP_Error(
			'g2aba_lead_not_found',
			__( 'Lead not found.', 'g2a-business-api' ),
			array( 'status' => 404 )
		);
	}
}
