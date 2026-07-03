<?php
/**
 * POST /public/opt-out
 *
 * Public endpoint (no permission check) used by the unsubscribe link
 * embedded in every automated email. Validates the HMAC token and either
 * records or restores the opt-out.
 *
 * Deliberately kept out of Ops_Controller so this file's public surface
 * is easy to audit.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Opt_Out_Signer;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Public_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/public/opt-out',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'opt_out' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'   => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
					'expires' => array( 'type' => 'integer', 'required' => true ),
					'token'   => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	public function opt_out( \WP_REST_Request $req ) {
		$email   = (string) $req->get_param( 'email' );
		$expires = (int) $req->get_param( 'expires' );
		$token   = (string) $req->get_param( 'token' );

		$check = Opt_Out_Signer::verify( $email, $expires, $token );
		if ( ! ( $check['ok'] ?? false ) ) {
			return new \WP_Error(
				'g2aba_opt_out_invalid',
				__( 'Invalid or expired unsubscribe link.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		( new Opt_Out_Store() )->record( $check['email'], 'user_requested' );
		( new Audit_Log() )->record(
			'opt_out.recorded',
			sprintf( '%s opted out via signed link', $check['email'] ),
			array( 'email' => $check['email'] )
		);

		return $this->ok( array( 'ok' => true, 'email' => $check['email'] ) );
	}
}
