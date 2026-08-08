<?php
/**
 * POST /public/opt-out
 *
 * Public endpoint (no permission check) used by the unsubscribe link
 * embedded in every automated email. Validates the HMAC token, honours
 * the category the token was minted for, and rate-limits abusive callers.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Ops\Opt_Out_Signer;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;
use WordPressistic\G2ABA\Ops\Rate_Limiter;

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
					'email'    => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_email' ),
					'expires'  => array( 'type' => 'integer', 'required' => true ),
					'token'    => array( 'type' => 'string', 'required' => true ),
					'category' => array( 'type' => 'string' ),
				),
			)
		);
	}

	public function opt_out( \WP_REST_Request $req ) {
		// Rate limit BEFORE any DB writes so a scanner can't fill the store.
		$limiter = new Rate_Limiter( 'public.opt_out', 20, 3600 );
		$check   = $limiter->hit( Rate_Limiter::client_ip() );
		if ( ! $check['allowed'] ) {
			$err = new \WP_Error(
				'g2aba_rate_limited',
				__( 'Too many requests. Try again shortly.', 'g2a-business-api' ),
				array( 'status' => 429 )
			);
			$err->add_data( array( 'Retry-After' => $check['retryAfter'] ), 'g2aba_rate_limited' );
			return $err;
		}

		$email    = (string) $req->get_param( 'email' );
		$expires  = (int) $req->get_param( 'expires' );
		$token    = (string) $req->get_param( 'token' );
		$category = trim( (string) $req->get_param( 'category' ) );
		if ( '' === $category ) {
			$category = Opt_Out_Store::CATEGORY_ALL;
		}

		$verify = Opt_Out_Signer::verify( $email, $expires, $token, $category );
		if ( ! ( $verify['ok'] ?? false ) ) {
			return new \WP_Error(
				'g2aba_opt_out_invalid',
				__( 'Invalid or expired unsubscribe link.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}

		( new Opt_Out_Store() )->record( $verify['email'], $verify['category'], 'user_requested' );
		( new Audit_Log() )->record(
			'opt_out.recorded',
			sprintf( '%s opted out of %s via signed link', $verify['email'], $verify['category'] ),
			array( 'email' => $verify['email'], 'category' => $verify['category'] )
		);

		return $this->ok(
			array(
				'ok'       => true,
				'email'    => $verify['email'],
				'category' => $verify['category'],
			)
		);
	}
}
