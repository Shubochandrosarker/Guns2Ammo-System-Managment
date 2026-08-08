<?php
/**
 * GET /brain/query
 * GET /brain/stats
 *
 * Read-only window onto g2a-pos-core's shared AI Brain (see
 * `Brain_Client`), so the dashboard's future agents (Email Manager, Sales
 * Agent, Store Manager, Booking Agent) can be built against a stable REST
 * surface instead of reaching across plugins directly.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ai\Brain_Client;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Brain_Controller extends REST_Controller {
	private const DEFAULT_K              = 5;
	private const MAX_K                  = 20;
	private const MIN_INGEST_BODY_LENGTH = 10;

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/brain/query',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'query' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'q'     => array(
						'type'     => 'string',
						'required' => true,
					),
					'k'     => array( 'type' => 'integer' ),
					'scope' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/brain/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'stats' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'scope' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/brain/ingest',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'label' => array(
						'type'     => 'string',
						'required' => true,
					),
					'body'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'tags'  => array( 'type' => 'string' ),
					'scope' => array( 'type' => 'string' ),
				),
			)
		);
	}

	public function query( \WP_REST_Request $req ) {
		$q = trim( (string) $req->get_param( 'q' ) );
		if ( '' === $q ) {
			return new \WP_Error(
				'g2aba_brain_query_empty',
				__( 'q is required.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		$k = (int) $req->get_param( 'k' );
		if ( $k <= 0 ) {
			$k = self::DEFAULT_K;
		}
		$k = min( self::MAX_K, $k );

		$scope = $req->get_param( 'scope' );
		$scope = ( null !== $scope && '' !== $scope ) ? (string) $scope : null;

		return $this->ok( ( new Brain_Client() )->retrieve( $q, $k, $scope ) );
	}

	public function stats( \WP_REST_Request $req ) {
		$scope = $req->get_param( 'scope' );
		$scope = ( null !== $scope && '' !== $scope ) ? (string) $scope : null;

		return $this->ok( ( new Brain_Client() )->stats( $scope ) );
	}

	public function ingest( \WP_REST_Request $req ) {
		$label = trim( (string) $req->get_param( 'label' ) );
		$body  = trim( (string) $req->get_param( 'body' ) );

		if ( '' === $label || '' === $body || strlen( $body ) < self::MIN_INGEST_BODY_LENGTH ) {
			return new \WP_Error(
				'g2aba_brain_ingest_invalid',
				__( 'label and body are required, and body must be at least 10 characters.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		$tags  = $req->get_param( 'tags' );
		$scope = $req->get_param( 'scope' );

		$opts = array(
			'tags'  => ( null !== $tags && '' !== $tags ) ? (string) $tags : null,
			'scope' => ( null !== $scope && '' !== $scope ) ? (string) $scope : null,
		);

		return $this->ok( ( new Brain_Client() )->ingest( $label, $body, $opts ) );
	}
}
