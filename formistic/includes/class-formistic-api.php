<?php
/**
 * Public integration API — let ANY form (custom HTML forms, bespoke handlers,
 * other plugins, headless/JS front-ends) push data into Formistic.
 *
 * Three ways to connect:
 *
 * 1. PHP helper functions (call from your form handler):
 *       formistic_capture_contact( [ 'Name' => $n, 'Email' => $e, 'Message' => $m ] );
 *       formistic_add_subscriber( $email );
 *
 * 2. Action hooks (fire-and-forget, e.g. from a theme):
 *       do_action( 'formistic_capture', [ 'Email' => $e, 'Message' => $m ] );
 *       do_action( 'formistic_subscribe', $email );
 *
 * 3. REST endpoints (for JavaScript / external systems):
 *       POST /wp-json/formistic/v1/capture     { form_name, fields:{...} }
 *       POST /wp-json/formistic/v1/newsletter   { email, source }
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capture a contact submission into the Formistic inbox.
 *
 * Pass an associative array of human field labels to values. Sender name,
 * email, phone and message are detected automatically from the labels
 * (e.g. a value that is a valid email becomes the sender email).
 *
 * @param array $fields Map of label => value (value may be string or array).
 * @param array $args   Optional: 'form_name' (string), 'notify' (bool, default true).
 * @return int Submission ID, or 0 if not stored (blocked/spam/error).
 */
function formistic_capture_contact( array $fields, array $args = [] ) {
	if ( ! class_exists( 'Wpistic_Formistic_Capture' ) || empty( $fields ) ) {
		return 0;
	}

	$form_name = isset( $args['form_name'] ) && '' !== trim( (string) $args['form_name'] )
		? sanitize_text_field( (string) $args['form_name'] )
		: __( 'Custom Form', 'formistic' );
	$notify = array_key_exists( 'notify', $args ) ? (bool) $args['notify'] : true;

	// Sanitize the incoming label => value map.
	$clean = [];
	foreach ( $fields as $label => $value ) {
		$label = sanitize_text_field( (string) $label );
		if ( '' === $label ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
		} else {
			$value = sanitize_textarea_field( (string) $value );
		}
		if ( '' !== trim( (string) $value ) ) {
			$clean[ $label ] = $value;
		}
	}
	if ( empty( $clean ) ) {
		return 0;
	}

	$capture = new Wpistic_Formistic_Capture();
	return (int) $capture->store( $form_name, $clean, $notify );
}

/**
 * Add an email to the Formistic newsletter list.
 *
 * Works regardless of whether the Newsletter addon screens are enabled — the
 * subscriber is stored so it appears once the addon is turned on.
 *
 * @param string $email  Subscriber email.
 * @param string $source Where the sign-up came from (free label).
 * @return bool True when stored or already subscribed, false on invalid input.
 */
function formistic_add_subscriber( $email, $source = 'custom' ) {
	if ( ! class_exists( 'Wpistic_Formistic_Newsletter' ) ) {
		return false;
	}
	$result = Wpistic_Formistic_Newsletter::process( (string) $email, (string) $source );
	return is_array( $result ) && in_array( $result['status'], [ 'ok', 'duplicate' ], true );
}

/**
 * Registers the action-hook adapters and REST endpoints for the public API.
 */
class Wpistic_Formistic_API {

	/**
	 * Wire hooks.
	 */
	public function register() {
		add_action( 'formistic_capture', [ $this, 'on_capture' ], 10, 2 );
		add_action( 'formistic_subscribe', [ $this, 'on_subscribe' ], 10, 2 );
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/**
	 * Adapter: do_action( 'formistic_capture', $fields, $args ).
	 *
	 * @param array $fields Label => value map.
	 * @param array $args   Optional args (form_name, notify).
	 */
	public function on_capture( $fields, $args = [] ) {
		if ( is_array( $fields ) ) {
			formistic_capture_contact( $fields, is_array( $args ) ? $args : [] );
		}
	}

	/**
	 * Adapter: do_action( 'formistic_subscribe', $email, $source ).
	 *
	 * @param string $email  Subscriber email.
	 * @param string $source Source label.
	 */
	public function on_subscribe( $email, $source = 'custom' ) {
		formistic_add_subscriber( $email, $source ? $source : 'custom' );
	}

	/**
	 * Register the contact-capture REST route. (Newsletter has its own route
	 * registered by Wpistic_Formistic_Newsletter.)
	 */
	public function routes() {
		register_rest_route( 'formistic/v1', '/capture', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'rest_capture' ],
			'permission_callback' => [ $this, 'permission' ],
			'args'                => [
				'form_name' => [ 'type' => 'string', 'required' => false ],
				'fields'    => [ 'type' => 'object', 'required' => true ],
			],
		] );
	}

	/**
	 * Public endpoint guarded by the standard WordPress REST nonce. Same-site
	 * JavaScript that uses wp_create_nonce( 'wp_rest' ) (or the X-WP-Nonce
	 * header) is accepted; the spam stack still runs inside store().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' );
		if ( ! $nonce || ! wp_verify_nonce( (string) $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Invalid or missing nonce.', 'formistic' ), [ 'status' => 403 ] );
		}
		return true;
	}

	/**
	 * REST: capture a contact submission.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_capture( $request ) {
		$fields = $request->get_param( 'fields' );
		if ( ! is_array( $fields ) || empty( $fields ) ) {
			return new WP_REST_Response( [ 'stored' => false, 'message' => __( 'No fields provided.', 'formistic' ) ], 400 );
		}
		$id = formistic_capture_contact( $fields, [ 'form_name' => (string) $request->get_param( 'form_name' ) ] );
		return new WP_REST_Response(
			[
				'stored' => (bool) $id,
				'id'     => (int) $id,
			],
			$id ? 201 : 400
		);
	}
}
