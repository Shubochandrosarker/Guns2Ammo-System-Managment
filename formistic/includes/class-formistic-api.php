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
	 * Register the contact-capture REST route plus the read-only routes used
	 * by the operations dashboard. (Newsletter's public subscribe route is
	 * registered by Wpistic_Formistic_Newsletter.)
	 *
	 * Reads are guarded by current_user_can( 'manage_options' ) — NOT the
	 * public write nonce — so they work with WordPress Application Passwords
	 * (Basic auth) and logged-in cookie auth, and never leak to anonymous
	 * visitors.
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

		register_rest_route( 'formistic/v1', '/submissions', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_list_submissions' ],
			'permission_callback' => [ $this, 'admin_permission' ],
			'args'                => [
				'page'      => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
				'per_page'  => [ 'type' => 'integer', 'required' => false, 'default' => 20 ],
				'status'    => [ 'type' => 'string',  'required' => false ],
				'form_name' => [ 'type' => 'string',  'required' => false ],
				'search'    => [ 'type' => 'string',  'required' => false ],
				'date_from' => [ 'type' => 'string',  'required' => false ],
				'date_to'   => [ 'type' => 'string',  'required' => false ],
			],
		] );

		register_rest_route( 'formistic/v1', '/submissions/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_get_submission' ],
			'permission_callback' => [ $this, 'admin_permission' ],
			'args'                => [
				'id' => [ 'type' => 'integer', 'required' => true ],
			],
		] );

		register_rest_route( 'formistic/v1', '/subscribers', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_list_subscribers' ],
			'permission_callback' => [ $this, 'admin_permission' ],
			'args'                => [
				'page'     => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
				'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 25 ],
				'status'   => [ 'type' => 'string',  'required' => false ],
				'search'   => [ 'type' => 'string',  'required' => false ],
			],
		] );

		register_rest_route( 'formistic/v1', '/stats', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'rest_stats' ],
			'permission_callback' => [ $this, 'admin_permission' ],
		] );
	}

	/**
	 * Permission for the read-only dashboard routes. Requires an
	 * authenticated administrator (cookie auth or Application Passwords).
	 *
	 * @return true|WP_Error
	 */
	public function admin_permission() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to read Formistic data.', 'formistic' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
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

	/* ==================================================================
	 * Read-only routes (dashboard integration)
	 * ================================================================== */

	/**
	 * REST: paginated submission list with filters.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_list_submissions( $request ) {
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$paged    = max( 1, (int) $request->get_param( 'page' ) );

		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $status, [ 'new', 'read', 'replied' ], true ) ) {
			$status = '';
		}

		$result = Wpistic_Formistic_Database::query_submissions( [
			'status'    => $status,
			'form'      => sanitize_text_field( (string) $request->get_param( 'form_name' ) ),
			'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'date_from' => $this->sanitize_date( $request->get_param( 'date_from' ) ),
			'date_to'   => $this->sanitize_date( $request->get_param( 'date_to' ) ),
			'paged'     => $paged,
			'per_page'  => $per_page,
		] );

		$items = [];
		foreach ( (array) $result['items'] as $row ) {
			$items[] = $this->format_submission( $row );
		}

		return new WP_REST_Response( [
			'items'       => $items,
			'total'       => (int) $result['total'],
			'page'        => $paged,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( (int) $result['total'] / $per_page ),
		], 200 );
	}

	/**
	 * REST: single submission with replies + notes.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_get_submission( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$row = Wpistic_Formistic_Database::get_submission( $id );
		if ( ! $row ) {
			return new WP_REST_Response( [ 'message' => __( 'Submission not found.', 'formistic' ) ], 404 );
		}

		$item = $this->format_submission( $row );

		$item['replies'] = [];
		foreach ( Wpistic_Formistic_Database::get_replies( $id ) as $reply ) {
			$item['replies'][] = [
				'id'      => (int) $reply->id,
				'subject' => (string) $reply->reply_subject,
				'body'    => (string) $reply->reply_body,
				'sent_by' => (int) $reply->sent_by,
				'sent_at' => (string) $reply->sent_at,
			];
		}

		$item['notes'] = [];
		foreach ( Wpistic_Formistic_Database::get_notes( $id ) as $note ) {
			$item['notes'][] = [
				'id'         => (int) $note->id,
				'body'       => (string) $note->note_body,
				'tags'       => (string) $note->tags,
				'created_by' => (int) $note->created_by,
				'author'     => (string) ( $note->display_name ?? '' ),
				'created_at' => (string) $note->created_at,
			];
		}

		$ai = Wpistic_Formistic_Database::get_ai_meta( $id );
		$item['ai_meta'] = $ai ? [
			'spam_score' => (int) $ai->spam_score,
			'tags'       => (string) $ai->ai_tags,
			'reply'      => (string) $ai->ai_reply,
			'provider'   => (string) $ai->source_provider,
			'updated_at' => (string) $ai->updated_at,
		] : null;

		return new WP_REST_Response( $item, 200 );
	}

	/**
	 * REST: paginated newsletter subscriber list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rest_list_subscribers( $request ) {
		if ( ! class_exists( 'Wpistic_Formistic_Newsletter' ) ) {
			return new WP_REST_Response( [ 'message' => __( 'Newsletter module unavailable.', 'formistic' ) ], 501 );
		}
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$paged    = max( 1, (int) $request->get_param( 'page' ) );

		$result = Wpistic_Formistic_Newsletter::query_subscribers( [
			'status'   => sanitize_key( (string) $request->get_param( 'status' ) ),
			'search'   => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			'paged'    => $paged,
			'per_page' => $per_page,
		] );

		$items = [];
		foreach ( (array) $result['items'] as $row ) {
			$items[] = [
				'id'              => (int) $row->id,
				'email'           => (string) $row->email,
				'source'          => (string) $row->source,
				'source_url'      => (string) $row->source_url,
				'status'          => (string) $row->status,
				'consent_at'      => (string) $row->consent_at,
				'unsubscribed_at' => $row->unsubscribed_at ? (string) $row->unsubscribed_at : null,
			];
		}

		return new WP_REST_Response( [
			'items'       => $items,
			'total'       => (int) $result['total'],
			'page'        => $paged,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( (int) $result['total'] / $per_page ),
		], 200 );
	}

	/**
	 * REST: headline stats — counts by status, today/7d/30d volumes,
	 * subscriber counts, and known form names.
	 *
	 * @return WP_REST_Response
	 */
	public function rest_stats() {
		$by_day_30 = Wpistic_Formistic_Database::submissions_by_day( 30 );
		$last_7    = array_sum( array_slice( $by_day_30, -7, 7, true ) );
		$last_30   = array_sum( $by_day_30 );

		$subscribers = class_exists( 'Wpistic_Formistic_Newsletter' )
			? Wpistic_Formistic_Newsletter::subscriber_counts()
			: [ 'active' => 0, 'unsubscribed' => 0, 'total' => 0 ];

		return new WP_REST_Response( [
			'status_counts' => Wpistic_Formistic_Database::status_counts(),
			'today'         => Wpistic_Formistic_Database::today_count(),
			'last_7_days'   => (int) $last_7,
			'last_30_days'  => (int) $last_30,
			'by_day'        => $by_day_30,
			'subscribers'   => $subscribers,
			'forms'         => Wpistic_Formistic_Database::form_names(),
		], 200 );
	}

	/**
	 * Shared row formatter for the read endpoints.
	 *
	 * @param object $row Submission row.
	 * @return array
	 */
	protected function format_submission( $row ) {
		$fields = json_decode( (string) $row->fields, true );
		return [
			'id'           => (int) $row->id,
			'form_name'    => (string) $row->form_name,
			'sender_name'  => (string) $row->sender_name,
			'sender_email' => (string) $row->sender_email,
			'sender_phone' => (string) $row->sender_phone,
			'subject'      => (string) $row->subject,
			'message'      => (string) $row->message,
			'fields'       => is_array( $fields ) ? $fields : [],
			'source_url'   => (string) $row->source_url,
			'ip_address'   => (string) $row->ip_address,
			'status'       => (string) $row->status,
			'created_at'   => (string) $row->created_at,
		];
	}

	/**
	 * Accept only YYYY-MM-DD date filter values.
	 *
	 * @param mixed $value Raw param.
	 * @return string Sanitized date or ''.
	 */
	protected function sanitize_date( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}
}
