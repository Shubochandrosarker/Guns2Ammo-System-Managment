<?php
/**
 * REST: forms + form fields CRUD.
 *
 * Endpoints:
 *   GET    /forms                  — list all forms
 *   GET    /forms/{id}             — get one form with all fields
 *   POST   /forms                  — create new form
 *   POST   /forms/{id}             — update form + replace fields (bulk save)
 *   DELETE /forms/{id}             — delete form (only if no booking types reference it)
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_REST_Forms_Controller {

	const FIELD_TYPES = array( 'text', 'email', 'phone', 'number', 'date', 'time', 'dropdown', 'radio', 'checkbox', 'textarea', 'hidden', 'waiver', 'terms', 'heading', 'divider', 'file', 'signature' );

	public function register_routes() {
		register_rest_route( G2AB_REST_NAMESPACE, '/forms', array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'list_forms' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			),
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'create_form' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			),
		) );
		register_rest_route( G2AB_REST_NAMESPACE, '/forms/(?P<id>\d+)', array(
			array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array( $this, 'get_form' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			),
			array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'update_form' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			),
			array(
				'methods' => WP_REST_Server::DELETABLE,
				'callback' => array( $this, 'delete_form' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			),
		) );
	}

	public function permission_manage( WP_REST_Request $request ) {
		if ( ! current_user_can( 'manage_g2ab_forms' ) ) return new WP_Error( 'no_perm', 'No permission.', array( 'status' => 403 ) );
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) return new WP_Error( 'bad_nonce', 'Bad nonce.', array( 'status' => 403 ) );
		return true;
	}

	public function list_forms() {
		global $wpdb;
		$ft = $wpdb->prefix . 'g2ab_forms';
		$ff = $wpdb->prefix . 'g2ab_form_fields';
		$rows = $wpdb->get_results( "SELECT f.*, (SELECT COUNT(*) FROM {$ff} WHERE form_id = f.id) AS field_count FROM {$ft} f ORDER BY f.id ASC" );
		return rest_ensure_response( array( 'success' => true, 'data' => $rows ) );
	}

	public function get_form( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request['id'];
		$ft = $wpdb->prefix . 'g2ab_forms';
		$ff = $wpdb->prefix . 'g2ab_form_fields';
		$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ft} WHERE id = %d", $id ) );
		if ( ! $form ) return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );
		$fields = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$ff} WHERE form_id = %d ORDER BY sort_order ASC", $id ) );
		// Decode JSON columns for JS consumption.
		foreach ( $fields as $f ) {
			$f->options    = json_decode( (string) $f->options, true ) ?: array();
			$f->validation = json_decode( (string) $f->validation, true ) ?: array();
			$f->conditional= json_decode( (string) $f->conditional, true ) ?: array();
		}
		$form->settings = json_decode( (string) $form->settings, true ) ?: array();
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'form' => $form, 'fields' => $fields ) ) );
	}

	public function create_form( WP_REST_Request $request ) {
		global $wpdb;
		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		if ( '' === $name ) return new WP_Error( 'bad_name', 'Name required.', array( 'status' => 400 ) );
		if ( '' === $slug ) $slug = sanitize_title( $name );
		$slug = $this->ensure_unique_slug( $slug, 0 );
		$now = current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'g2ab_forms', array(
			'name' => $name, 'slug' => $slug, 'description' => '', 'schema_version' => 1,
			'settings' => wp_json_encode( array() ), 'is_active' => 1,
			'created_at' => $now, 'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		do_action( 'g2ab_form_created', $id );
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'id' => $id, 'slug' => $slug ) ) );
	}

	public function update_form( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request['id'];
		$ft = $wpdb->prefix . 'g2ab_forms';
		$ff = $wpdb->prefix . 'g2ab_form_fields';
		$form = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ft} WHERE id = %d", $id ) );
		if ( ! $form ) return new WP_Error( 'not_found', 'Form not found.', array( 'status' => 404 ) );

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$slug = sanitize_title( (string) $request->get_param( 'slug' ) );
		$desc = sanitize_textarea_field( (string) $request->get_param( 'description' ) );
		$is_active = (int) (bool) $request->get_param( 'is_active' );
		$settings = $request->get_param( 'settings' );
		$settings = is_array( $settings ) ? $settings : array();

		if ( '' === $name ) return new WP_Error( 'bad_name', 'Name required.', array( 'status' => 400 ) );
		if ( '' === $slug ) $slug = sanitize_title( $name );
		$slug = $this->ensure_unique_slug( $slug, $id );

		$now = current_time( 'mysql' );
		$wpdb->update( $ft, array(
			'name' => $name, 'slug' => $slug, 'description' => $desc, 'is_active' => $is_active,
			'settings' => wp_json_encode( $settings ), 'updated_at' => $now,
			'schema_version' => (int) $form->schema_version + 1,
		), array( 'id' => $id ), array( '%s','%s','%s','%d','%s','%s','%d' ), array( '%d' ) );

		// Replace fields.
		$incoming_fields = $request->get_param( 'fields' );
		if ( is_array( $incoming_fields ) ) {
			$wpdb->delete( $ff, array( 'form_id' => $id ), array( '%d' ) );
			$order = 0;
			foreach ( $incoming_fields as $f ) {
				$f = is_array( $f ) ? $f : (array) $f;
				$type = sanitize_key( $f['field_type'] ?? 'text' );
				if ( ! in_array( $type, self::FIELD_TYPES, true ) ) $type = 'text';
				$key = sanitize_key( $f['field_key'] ?? '' );
				if ( '' === $key ) $key = 'field_' . wp_rand( 1000, 9999 );
				$wpdb->insert( $ff, array(
					'form_id' => $id,
					'field_key' => $key,
					'field_type' => $type,
					'label' => sanitize_text_field( $f['label'] ?? '' ),
					'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
					'help_text' => sanitize_textarea_field( $f['help_text'] ?? '' ),
					'options' => wp_json_encode( is_array( $f['options'] ?? null ) ? $f['options'] : array() ),
					'validation' => wp_json_encode( is_array( $f['validation'] ?? null ) ? $f['validation'] : array() ),
					'conditional' => wp_json_encode( is_array( $f['conditional'] ?? null ) ? $f['conditional'] : array() ),
					'sort_order' => ++$order,
					'is_required' => ! empty( $f['is_required'] ) ? 1 : 0,
				) );
			}
		}

		do_action( 'g2ab_form_updated', $id );
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'id' => $id, 'fields_saved' => is_array( $incoming_fields ) ? count( $incoming_fields ) : 0 ) ) );
	}

	public function delete_form( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request['id'];
		$btt = $wpdb->prefix . 'g2ab_booking_types';
		$ref = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$btt} WHERE form_id = %d", $id ) );
		if ( $ref > 0 ) return new WP_Error( 'in_use', sprintf( 'Form is used by %d booking type(s). Reassign before deleting.', $ref ), array( 'status' => 409 ) );
		$wpdb->delete( $wpdb->prefix . 'g2ab_form_fields', array( 'form_id' => $id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'g2ab_forms', array( 'id' => $id ), array( '%d' ) );
		do_action( 'g2ab_form_deleted', $id );
		return rest_ensure_response( array( 'success' => true ) );
	}

	private function ensure_unique_slug( $slug, $exclude ) {
		global $wpdb;
		$ft = $wpdb->prefix . 'g2ab_forms';
		$base = $slug; $i = 2;
		while ( true ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$ft} WHERE slug = %s AND id != %d LIMIT 1", $slug, $exclude ) );
			if ( ! $existing ) return $slug;
			$slug = $base . '-' . $i; $i++;
			if ( $i > 100 ) return $slug;
		}
	}
}
