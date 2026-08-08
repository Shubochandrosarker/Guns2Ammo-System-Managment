<?php
/**
 * AI Auto-Reply — module bootstrap.
 *
 * Registers REST endpoint POST /ai/draft and admin settings.
 *
 * @package G2AB\Modules\AIAutoReply
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_AI_AutoReply {

	private static $instance = null;
	private $engine = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		$this->engine = new G2AB_AutoReply_Engine();

		if ( is_admin() ) new G2AB_AutoReply_Settings();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function engine() { return $this->engine; }

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/ai/draft', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_draft' ),
			'permission_callback' => array( $this, 'rest_permissions' ),
			'args'                => array(
				'message' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
				'context' => array(
					'required' => false,
					'type'     => 'object',
				),
			),
		) );

		register_rest_route( G2AB_REST_NAMESPACE, '/ai/test', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'rest_test' ),
			'permission_callback' => array( $this, 'rest_permissions' ),
		) );
	}

	public function rest_permissions() {
		return current_user_can( 'manage_options' );
	}

	public function rest_draft( $request ) {
		$message = $request->get_param( 'message' );
		$context = $request->get_param( 'context' ) ?: array();

		$result = $this->engine->generate_draft( $message, $context );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array(
				'success' => false,
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			), 400 );
		}

		return new WP_REST_Response( array(
			'success' => true,
			'data'    => array(
				'draft' => $result,
				'model' => $this->engine->client()->model(),
			),
		), 200 );
	}

	public function rest_test( $request ) {
		$result = $this->engine->client()->test_connection();
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => $result->get_error_message() ), 400 );
		}
		return new WP_REST_Response( array( 'success' => true, 'data' => array( 'response' => $result ) ), 200 );
	}
}
