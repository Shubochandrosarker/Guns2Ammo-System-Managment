<?php
/**
 * Base REST controller. Owns the shared permission callbacks and helpers.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Capabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class REST_Controller extends \WP_REST_Controller {
	/**
	 * REST namespace. Kept untyped for WP_REST_Controller compatibility.
	 *
	 * @var string
	 */
	protected $namespace = G2ABA_REST_NAMESPACE;

	/**
	 * @return bool|\WP_Error
	 */
	public function read_permissions_check() {
		if ( Capabilities::current_user_can_read() ) {
			return true;
		}
		return $this->forbidden();
	}

	/**
	 * @return bool|\WP_Error
	 */
	public function admin_permissions_check() {
		if ( Capabilities::current_user_can_admin() ) {
			return true;
		}
		return $this->forbidden();
	}

	protected function forbidden(): \WP_Error {
		return new \WP_Error(
			'g2aba_rest_forbidden',
			__( 'You are not allowed to access this Guns2Ammo dashboard endpoint.', 'g2a-business-api' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	protected function ok( $data, int $status = 200 ): \WP_REST_Response {
		$res = new \WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'private, max-age=15' );
		return $res;
	}

	/**
	 * 429 with a real `Retry-After` header. Built as a WP_REST_Response
	 * (WP_Error data never becomes headers) but shaped like WP's standard
	 * error body so clients handle it uniformly.
	 */
	protected function too_many_requests( int $retry_after ): \WP_REST_Response {
		$retry_after = max( 1, $retry_after );
		$res         = new \WP_REST_Response(
			array(
				'code'    => 'g2aba_rate_limited',
				'message' => __( 'Too many requests. Try again shortly.', 'g2a-business-api' ),
				'data'    => array( 'status' => 429 ),
			),
			429
		);
		$res->header( 'Retry-After', (string) $retry_after );
		return $res;
	}
}
