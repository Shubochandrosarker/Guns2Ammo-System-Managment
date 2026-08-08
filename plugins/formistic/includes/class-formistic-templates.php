<?php
/**
 * Reply Templates — saved canned replies the reply modal can pull from.
 *
 * Stored as a single option (`wpistic_formistic_reply_templates`) holding a JSON-encoded
 * array of `{ id, name, subject, body }` records. Managed under
 * Settings → Reply Templates and exposed to the reply modal via AJAX.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reply-templates CRUD + AJAX.
 */
class Wpistic_Formistic_Templates {

	/** Option key. */
	const OPTION = 'wpistic_formistic_reply_templates';

	/** Capability required. */
	const CAP = 'manage_options';

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'admin_post_wpistic_formistic_save_template',   [ $this, 'save_template' ] );
		add_action( 'admin_post_wpistic_formistic_delete_template', [ $this, 'delete_template' ] );
		add_action( 'wp_ajax_wpistic_formistic_list_templates',     [ $this, 'ajax_list' ] );
	}

	/**
	 * Get all templates.
	 *
	 * @return array<int,array{id:string,name:string,subject:string,body:string}>
	 */
	public static function all() {
		$raw  = get_option( self::OPTION, '' );
		$list = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
		return is_array( $list ) ? $list : [];
	}

	/**
	 * Replace placeholders in a template string.
	 *
	 * @param string $str  Template string.
	 * @param object $row  Submission row.
	 * @return string
	 */
	public static function apply_placeholders( $str, $row ) {
		$map = [
			'{name}'      => (string) $row->sender_name,
			'{form}'      => (string) $row->form_name,
			'{message}'   => (string) $row->message,
			'{subject}'   => (string) $row->subject,
			'{site_name}' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{site_url}'  => home_url( '/' ),
			'{date}'      => date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		];
		return strtr( (string) $str, $map );
	}

	/* ------------------------------------------------------------------
	 * Admin save / delete
	 * ------------------------------------------------------------------ */

	/**
	 * Persist one template (add or update). Form submits to admin-post.php.
	 */
	public function save_template() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		check_admin_referer( 'wpistic_formistic_templates' );

		$id      = isset( $_POST['template_id'] ) ? sanitize_key( $_POST['template_id'] ) : '';
		$name    = isset( $_POST['template_name'] )    ? sanitize_text_field( wp_unslash( $_POST['template_name'] ) )    : '';
		$subject = isset( $_POST['template_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['template_subject'] ) ) : '';
		$body    = isset( $_POST['template_body'] )    ? wp_kses_post( wp_unslash( $_POST['template_body'] ) )           : '';

		if ( '' === $name || '' === $body ) {
			$this->redirect_back( 'template_invalid' );
		}

		$list  = self::all();
		$found = false;
		if ( '' !== $id ) {
			foreach ( $list as &$t ) {
				if ( isset( $t['id'] ) && $t['id'] === $id ) {
					$t = [ 'id' => $id, 'name' => $name, 'subject' => $subject, 'body' => $body ];
					$found = true;
					break;
				}
			}
			unset( $t );
		}
		if ( ! $found ) {
			$list[] = [
				'id'      => wp_generate_password( 10, false, false ),
				'name'    => $name,
				'subject' => $subject,
				'body'    => $body,
			];
		}
		update_option( self::OPTION, wp_json_encode( array_values( $list ) ) );
		$this->redirect_back( 'template_saved' );
	}

	/**
	 * Delete a template by ID.
	 */
	public function delete_template() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Permission denied.', 'formistic' ), 403 );
		}
		$id = isset( $_GET['template_id'] ) ? sanitize_key( $_GET['template_id'] ) : '';
		check_admin_referer( 'wpistic_formistic_delete_template_' . $id );

		$list  = self::all();
		$after = array_values( array_filter( $list, function ( $t ) use ( $id ) {
			return isset( $t['id'] ) && $t['id'] !== $id;
		} ) );
		update_option( self::OPTION, wp_json_encode( $after ) );

		$this->redirect_back( 'template_deleted' );
	}

	/**
	 * Redirect back to the templates settings tab with a notice flag.
	 *
	 * @param string $notice Notice slug.
	 */
	protected function redirect_back( $notice ) {
		$url = add_query_arg( [
			'page'        => 'formistic-settings',
			'tab'         => 'templates',
			'wpistic_formistic_notice' => $notice,
		], admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/* ------------------------------------------------------------------
	 * AJAX — populate the reply modal's template dropdown
	 * ------------------------------------------------------------------ */

	/**
	 * Return the template list as JSON.
	 */
	public function ajax_list() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'formistic' ) ], 403 );
		}
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpistic_formistic_admin' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'formistic' ) ], 403 );
		}
		wp_send_json_success( [ 'templates' => self::all() ] );
	}
}
