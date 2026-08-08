<?php
/**
 * Multi-form builder — a lightweight CPT-backed form manager.
 *
 * Each form is a `wpistic_formistic_form` post; its field definitions and per-form
 * settings live in post meta. Forms are rendered with [wpistic_form id="N"]
 * and submitted through the same admin-post pipeline as the legacy shortcode,
 * so they enjoy the spam stack, attachments, auto-responder and webhooks.
 *
 * @package Wpistic_Formistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form CPT + admin UI + shortcode + submit handler.
 */
class Wpistic_Formistic_Forms {

	/** Post type slug. */
	/** Post type slug. Must stay ≤ 20 chars (WordPress limit). */
	const POST_TYPE = 'formistic_form';

	/** Capability required to manage forms. */
	const CAP = 'manage_options';

	/** Meta key for the serialized field list. */
	const META_FIELDS = '_wpistic_formistic_fields';

	/** Meta key for the per-form settings. */
	const META_SETTINGS = '_wpistic_formistic_settings';

	/**
	 * Whitelist of field types and their human labels.
	 *
	 * @return array<string,string>
	 */
	public static function field_types() {
		return [
			'text'           => __( 'Single line text', 'formistic' ),
			'email'          => __( 'Email',            'formistic' ),
			'tel'            => __( 'Phone',            'formistic' ),
			'url'            => __( 'URL',              'formistic' ),
			'textarea'       => __( 'Paragraph',        'formistic' ),
			'select'         => __( 'Dropdown',         'formistic' ),
			'radio'          => __( 'Radio buttons',    'formistic' ),
			'checkbox_group' => __( 'Checkbox group',   'formistic' ),
			'checkbox'       => __( 'Single checkbox',  'formistic' ),
			'date'           => __( 'Date',             'formistic' ),
			'file'           => __( 'File upload',      'formistic' ),
			'hidden'         => __( 'Hidden',           'formistic' ),
			'consent'        => __( 'GDPR consent',     'formistic' ),
		];
	}

	/**
	 * Register hooks.
	 */
	public function register() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'metaboxes' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save' ], 10, 2 );

		add_shortcode( 'wpistic_form', [ $this, 'render' ] );
		add_action( 'admin_post_wpistic_formistic_submit_form',        [ $this, 'handle_submit' ] );
		add_action( 'admin_post_nopriv_wpistic_formistic_submit_form', [ $this, 'handle_submit' ] );

		// The Form submenu is added explicitly in Wpistic_Formistic_Admin::menu()
		// (show_in_menu is false), so keep the parent menu + submenu highlighted
		// while editing or adding a form.
		add_filter( 'parent_file', [ $this, 'highlight_parent' ] );
		add_filter( 'submenu_file', [ $this, 'highlight_submenu' ] );

		// Custom column on the WP forms list table.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',       [ $this, 'list_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'list_column_value' ], 10, 2 );
	}

	/* ==================================================================
	 * CPT registration
	 * ================================================================== */

	/**
	 * Register the wpistic_formistic_form CPT under our top-level menu.
	 */
	public function register_cpt() {
		register_post_type( self::POST_TYPE, [
			'label'              => __( 'Formistic Forms', 'formistic' ),
			'labels'             => [
				'name'               => __( 'Forms',          'formistic' ),
				'singular_name'      => __( 'Form',           'formistic' ),
				'add_new'            => __( 'Add New',        'formistic' ),
				'add_new_item'       => __( 'Add New Form',   'formistic' ),
				'edit_item'          => __( 'Edit Form',      'formistic' ),
				'new_item'           => __( 'New Form',       'formistic' ),
				'view_item'          => __( 'View Form',      'formistic' ),
				'search_items'       => __( 'Search Forms',   'formistic' ),
				'not_found'          => __( 'No forms found', 'formistic' ),
				'menu_name'          => __( 'Forms',          'formistic' ),
			],
			'public'             => false,
			'show_ui'            => true,
			// The submenu is registered explicitly under the Formistic menu in
			// Wpistic_Formistic_Admin::menu(); false avoids the timing issue
			// where a string parent menu may not exist yet on attach.
			'show_in_menu'       => false,
			'show_in_rest'       => false,
			'supports'           => [ 'title' ],
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
		] );
	}

	/**
	 * Keep the top-level Formistic menu open while on a form screen.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function highlight_parent( $parent_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->post_type ) && self::POST_TYPE === $screen->post_type ) {
			return Wpistic_Formistic_Admin::PAGE;
		}
		return $parent_file;
	}

	/**
	 * Keep the "Form" submenu highlighted while on a form screen.
	 *
	 * @param string $submenu_file Current submenu file.
	 * @return string
	 */
	public function highlight_submenu( $submenu_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->post_type ) && self::POST_TYPE === $screen->post_type ) {
			return 'edit.php?post_type=' . self::POST_TYPE;
		}
		return $submenu_file;
	}

	/**
	 * Custom columns on the forms list screen.
	 *
	 * @param array $cols Default WP columns.
	 * @return array
	 */
	public function list_columns( $cols ) {
		$new = [];
		foreach ( $cols as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['wpistic_formistic_shortcode'] = __( 'Shortcode', 'formistic' );
				$new['wpistic_formistic_fields']    = __( 'Fields',    'formistic' );
			}
		}
		return $new;
	}

	/**
	 * Render value for our custom columns.
	 *
	 * @param string $col Column key.
	 * @param int    $id  Post ID.
	 */
	public function list_column_value( $col, $id ) {
		if ( 'wpistic_formistic_shortcode' === $col ) {
			echo '<code>[wpistic_form id="' . (int) $id . '"]</code>';
		} elseif ( 'wpistic_formistic_fields' === $col ) {
			$fields = self::get_fields( $id );
			echo (int) count( $fields );
		}
	}

	/* ==================================================================
	 * Metaboxes — Fields + Settings
	 * ================================================================== */

	/**
	 * Add the field-editor and settings metaboxes on the Edit Form screen.
	 */
	public function metaboxes() {
		add_meta_box( 'wpistic_formistic_fields_editor', __( 'Form Fields', 'formistic' ),
			[ $this, 'render_fields_metabox' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wpistic_formistic_form_settings', __( 'Notifications & Display', 'formistic' ),
			[ $this, 'render_settings_metabox' ], self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'wpistic_formistic_form_shortcode', __( 'Shortcode', 'formistic' ),
			[ $this, 'render_shortcode_metabox' ], self::POST_TYPE, 'side', 'high' );
	}

	/**
	 * Shortcode metabox (side panel).
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_shortcode_metabox( $post ) {
		?>
		<p><?php esc_html_e( 'Paste this shortcode into any page or post:', 'formistic' ); ?></p>
		<input type="text" readonly class="widefat code" value='[wpistic_form id="<?php echo (int) $post->ID; ?>"]' onclick="this.select();">
		<?php
	}

	/**
	 * Field editor metabox.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_fields_metabox( $post ) {
		wp_nonce_field( 'wpistic_formistic_save_form_' . $post->ID, 'wpistic_formistic_form_nonce' );
		$fields = self::get_fields( $post->ID );
		$types  = self::field_types();
		?>
		<div class="wpistic-formistic-builder" id="wpistic-formistic-builder">
			<div class="wpistic-formistic-builder__editor">
				<div class="wpistic-formistic-fields-editor">
					<div class="wpistic-formistic-fields-editor__rows" id="wpistic-formistic-fields-editor-rows">
						<?php foreach ( $fields as $i => $f ) : ?>
							<?php $this->render_field_row( $i, $f, $types ); ?>
						<?php endforeach; ?>
					</div>
					<p>
						<button type="button" class="button button-primary" id="wpistic-formistic-fields-editor-add"><?php esc_html_e( '+ Add Field', 'formistic' ); ?></button>
						<span class="description" style="margin-left:8px;"><?php esc_html_e( 'Drag the ⠿ handle to reorder fields.', 'formistic' ); ?></span>
					</p>
					<template id="wpistic-formistic-fields-editor-template">
						<?php $this->render_field_row( '__INDEX__', [], $types ); ?>
					</template>
				</div>
			</div>
			<div class="wpistic-formistic-builder__preview">
				<div class="wpistic-formistic-builder__preview-label"><?php esc_html_e( 'Live preview', 'formistic' ); ?></div>
				<div class="wpistic-formistic-preview" id="wpistic-formistic-preview"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render one field row (used for existing rows AND the JS template).
	 *
	 * @param int|string $i     Numeric index, or "__INDEX__" placeholder.
	 * @param array      $f     Field definition (empty for a blank row).
	 * @param array      $types Type whitelist.
	 */
	protected function render_field_row( $i, $f, array $types ) {
		$type     = $f['type']        ?? 'text';
		$label    = $f['label']       ?? '';
		$key      = $f['key']         ?? '';
		$required = ! empty( $f['required'] );
		$ph       = $f['placeholder'] ?? '';
		$opts     = $f['options']     ?? '';
		?>
		<div class="wpistic-formistic-field-row" data-index="<?php echo esc_attr( $i ); ?>" draggable="false">
			<input type="hidden" name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][key]" value="<?php echo esc_attr( $key ); ?>">
			<div class="wpistic-formistic-field-row__main">
				<span class="wpistic-formistic-field-row__drag" title="<?php esc_attr_e( 'Drag to reorder', 'formistic' ); ?>" aria-hidden="true">⠿</span>
				<label class="wpistic-formistic-field-row__label">
					<span><?php esc_html_e( 'Label', 'formistic' ); ?></span>
					<input type="text" name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Your Name', 'formistic' ); ?>">
				</label>
				<label class="wpistic-formistic-field-row__type">
					<span><?php esc_html_e( 'Type', 'formistic' ); ?></span>
					<select name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][type]">
						<?php foreach ( $types as $t => $tlabel ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $tlabel ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="wpistic-formistic-field-row__required">
					<input type="hidden" name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][required]" value="0">
					<input type="checkbox" name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][required]" value="1" <?php checked( $required ); ?>>
					<span><?php esc_html_e( 'Required', 'formistic' ); ?></span>
				</label>
				<button type="button" class="button-link wpistic-formistic-field-row__remove" aria-label="<?php esc_attr_e( 'Remove field', 'formistic' ); ?>">&times;</button>
			</div>
			<div class="wpistic-formistic-field-row__extra">
				<label>
					<span><?php esc_html_e( 'Placeholder', 'formistic' ); ?></span>
					<input type="text" name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][placeholder]" value="<?php echo esc_attr( $ph ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Options (one per line, used by select / radio / checkbox group)', 'formistic' ); ?></span>
					<textarea name="wpistic_formistic_fields[<?php echo esc_attr( $i ); ?>][options]" rows="3"><?php echo esc_textarea( $opts ); ?></textarea>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Form-level settings metabox.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_settings_metabox( $post ) {
		$s = self::get_settings( $post->ID );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="wpistic_formistic_type"><?php esc_html_e( 'Form type', 'formistic' ); ?></label></th>
				<td>
					<select id="wpistic_formistic_type" name="wpistic_formistic_settings[type]">
						<option value="contact" <?php selected( $s['type'], 'contact' ); ?>><?php esc_html_e( 'Contact form — store messages in the Inbox', 'formistic' ); ?></option>
						<option value="newsletter" <?php selected( $s['type'], 'newsletter' ); ?>><?php esc_html_e( 'Newsletter sign-up — store emails in the Newsletter tab', 'formistic' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Newsletter forms add the subscriber to your Newsletter list and never appear in the Inbox. Requires the Newsletter addon.', 'formistic' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_recipients"><?php esc_html_e( 'Notification recipients', 'formistic' ); ?></label></th>
				<td>
					<input type="text" id="wpistic_formistic_recipients" name="wpistic_formistic_settings[recipients]" class="regular-text" value="<?php echo esc_attr( $s['recipients'] ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated email addresses. Leave empty to use the default Settings → General notification address.', 'formistic' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_submit_label"><?php esc_html_e( 'Submit button label', 'formistic' ); ?></label></th>
				<td><input type="text" id="wpistic_formistic_submit_label" name="wpistic_formistic_settings[submit_label]" class="regular-text" value="<?php echo esc_attr( $s['submit_label'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_success"><?php esc_html_e( 'Success message', 'formistic' ); ?></label></th>
				<td><textarea id="wpistic_formistic_success" name="wpistic_formistic_settings[success]" class="large-text" rows="2"><?php echo esc_textarea( $s['success'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_redirect"><?php esc_html_e( 'Redirect after success (optional)', 'formistic' ); ?></label></th>
				<td>
					<input type="url" id="wpistic_formistic_redirect" name="wpistic_formistic_settings[redirect]" class="regular-text" value="<?php echo esc_attr( $s['redirect'] ); ?>" placeholder="https://example.com/thanks">
					<p class="description"><?php esc_html_e( 'If set, the visitor is sent here after a successful submission.', 'formistic' ); ?></p>
				</td>
			</tr>
		</table>

		<h3 style="margin:18px 0 6px;"><?php esc_html_e( 'Form Style', 'formistic' ); ?></h3>
		<table class="form-table wpistic-formistic-style-table" role="presentation">
			<tr>
				<th><label for="wpistic_formistic_accent"><?php esc_html_e( 'Accent / button color', 'formistic' ); ?></label></th>
				<td><input type="color" id="wpistic_formistic_accent" class="wpistic-formistic-style-input" data-style="accent" name="wpistic_formistic_settings[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_button_text"><?php esc_html_e( 'Button text color', 'formistic' ); ?></label></th>
				<td><input type="color" id="wpistic_formistic_button_text" class="wpistic-formistic-style-input" data-style="button_text" name="wpistic_formistic_settings[button_text]" value="<?php echo esc_attr( $s['button_text'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_radius"><?php esc_html_e( 'Corner radius (px)', 'formistic' ); ?></label></th>
				<td><input type="number" min="0" max="40" id="wpistic_formistic_radius" class="small-text wpistic-formistic-style-input" data-style="radius" name="wpistic_formistic_settings[radius]" value="<?php echo esc_attr( $s['radius'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_spacing"><?php esc_html_e( 'Field spacing (px)', 'formistic' ); ?></label></th>
				<td><input type="number" min="6" max="40" id="wpistic_formistic_spacing" class="small-text wpistic-formistic-style-input" data-style="spacing" name="wpistic_formistic_settings[spacing]" value="<?php echo esc_attr( $s['spacing'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_width"><?php esc_html_e( 'Max width (px)', 'formistic' ); ?></label></th>
				<td><input type="number" min="320" max="1200" step="10" id="wpistic_formistic_width" class="small-text wpistic-formistic-style-input" data-style="width" name="wpistic_formistic_settings[width]" value="<?php echo esc_attr( $s['width'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="wpistic_formistic_layout"><?php esc_html_e( 'Layout', 'formistic' ); ?></label></th>
				<td>
					<select id="wpistic_formistic_layout" class="wpistic-formistic-style-input" data-style="layout" name="wpistic_formistic_settings[layout]">
						<?php foreach ( self::layout_options() as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['layout'], $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/* ==================================================================
	 * Save handler
	 * ================================================================== */

	/**
	 * Save metabox values when the form is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function save( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( empty( $_POST['wpistic_formistic_form_nonce'] ) ||
		     ! wp_verify_nonce( wp_unslash( $_POST['wpistic_formistic_form_nonce'] ), 'wpistic_formistic_save_form_' . $post_id ) ) {
			return;
		}

		// --- Fields ---
		$incoming = isset( $_POST['wpistic_formistic_fields'] ) ? (array) wp_unslash( $_POST['wpistic_formistic_fields'] ) : [];
		$types    = array_keys( self::field_types() );
		$clean    = [];
		foreach ( $incoming as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';
			if ( '' === trim( $label ) ) {
				continue;
			}
			$type = isset( $row['type'] ) && in_array( $row['type'], $types, true ) ? $row['type'] : 'text';
			$clean[] = [
				'key'         => $this->slug( $row['key'] ?? '', $label ),
				'label'       => $label,
				'type'        => $type,
				'required'    => ! empty( $row['required'] ),
				'placeholder' => isset( $row['placeholder'] ) ? sanitize_text_field( $row['placeholder'] ) : '',
				'options'     => isset( $row['options'] )     ? sanitize_textarea_field( $row['options'] )  : '',
			];
		}
		update_post_meta( $post_id, self::META_FIELDS, wp_slash( wp_json_encode( $clean ) ) );

		// --- Settings ---
		$incoming = isset( $_POST['wpistic_formistic_settings'] ) ? (array) wp_unslash( $_POST['wpistic_formistic_settings'] ) : [];
		$type     = ( isset( $incoming['type'] ) && 'newsletter' === $incoming['type'] ) ? 'newsletter' : 'contact';
		$layout   = ( isset( $incoming['layout'] ) && array_key_exists( $incoming['layout'], self::layout_options() ) ) ? $incoming['layout'] : 'one';
		$hex       = function ( $v, $fallback ) {
			$v = is_string( $v ) ? trim( $v ) : '';
			return preg_match( '/^#[0-9a-fA-F]{6}$/', $v ) ? $v : $fallback;
		};
		$clamp_int = function ( $v, $min, $max, $fallback ) {
			if ( '' === (string) $v && 0 !== $fallback ) {
				return $fallback;
			}
			$n = (int) $v;
			return (string) max( $min, min( $max, $n ) );
		};
		$settings = [
			'type'         => $type,
			'recipients'   => isset( $incoming['recipients'] )   ? sanitize_text_field( $incoming['recipients'] ) : '',
			'submit_label' => isset( $incoming['submit_label'] ) ? sanitize_text_field( $incoming['submit_label'] ) : '',
			'success'      => isset( $incoming['success'] )      ? sanitize_textarea_field( $incoming['success'] ) : '',
			'redirect'     => isset( $incoming['redirect'] )     ? esc_url_raw( $incoming['redirect'] ) : '',
			'accent'       => $hex( $incoming['accent'] ?? '', '#2563eb' ),
			'button_text'  => $hex( $incoming['button_text'] ?? '', '#ffffff' ),
			'radius'       => $clamp_int( $incoming['radius'] ?? '', 0, 40, 10 ),
			'spacing'      => $clamp_int( $incoming['spacing'] ?? '', 6, 40, 16 ),
			'width'        => $clamp_int( $incoming['width'] ?? '', 320, 1200, 640 ),
			'layout'       => $layout,
		];
		update_post_meta( $post_id, self::META_SETTINGS, wp_slash( wp_json_encode( $settings ) ) );
	}

	/**
	 * Make a stable kebab/snake key from the label, falling back to existing.
	 *
	 * @param string $existing Existing key (preferred if non-empty).
	 * @param string $label    Field label.
	 * @return string
	 */
	protected function slug( $existing, $label ) {
		$existing = preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $existing ) );
		if ( '' !== $existing ) {
			return $existing;
		}
		$slug = sanitize_title( $label );
		return $slug !== '' ? str_replace( '-', '_', $slug ) : 'field';
	}

	/* ==================================================================
	 * Accessors
	 * ================================================================== */

	/**
	 * Get decoded field list for a form.
	 *
	 * @param int $form_id Form post ID.
	 * @return array
	 */
	public static function get_fields( $form_id ) {
		$raw = get_post_meta( (int) $form_id, self::META_FIELDS, true );
		$arr = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
		return is_array( $arr ) ? $arr : [];
	}

	/**
	 * Get decoded settings for a form with defaults applied.
	 *
	 * @param int $form_id Form post ID.
	 * @return array
	 */
	public static function get_settings( $form_id ) {
		$raw = get_post_meta( (int) $form_id, self::META_SETTINGS, true );
		$arr = is_string( $raw ) ? json_decode( $raw, true ) : (array) $raw;
		$arr = is_array( $arr ) ? $arr : [];
		return wp_parse_args( $arr, [
			'type'         => 'contact',
			'recipients'   => '',
			'submit_label' => __( 'Send Message', 'formistic' ),
			'success'      => __( 'Thank you — your message has been sent. We will get back to you shortly.', 'formistic' ),
			'redirect'     => '',
			// Visual style controls.
			'accent'       => '#2563eb',
			'button_text'  => '#ffffff',
			'radius'       => '10',
			'spacing'      => '16',
			'layout'       => 'one',
			'width'        => '640',
		] );
	}

	/**
	 * Allowed style values and their human labels (layout only; colors and
	 * numerics are sanitized directly).
	 *
	 * @return array<string,string>
	 */
	public static function layout_options() {
		return [
			'one' => __( 'Single column', 'formistic' ),
			'two' => __( 'Two columns', 'formistic' ),
		];
	}

	/* ==================================================================
	 * Frontend render
	 * ================================================================== */

	/**
	 * [wpistic_form id="N"] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ) {
		$atts = shortcode_atts( [ 'id' => 0 ], $atts, 'wpistic_form' );
		$id   = (int) $atts['id'];
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}

		wp_enqueue_style( 'wpistic-formistic-form' );

		$fields   = self::get_fields( $id );
		$settings = self::get_settings( $id );
		Wpistic_Formistic_Database::log_impression( (string) get_the_title( $id ) );
		$sent     = isset( $_GET['wpistic_formistic_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['wpistic_formistic_sent'] ) ) : '';
		$has_file = false;
		foreach ( $fields as $f ) {
			if ( 'file' === ( $f['type'] ?? '' ) ) { $has_file = true; break; }
		}
		$enctype = $has_file ? ' enctype="multipart/form-data"' : '';

		$style = sprintf(
			'--wpf-accent:%1$s;--wpf-btn-text:%2$s;--wpf-radius:%3$dpx;--wpf-gap:%4$dpx;--wpf-width:%5$dpx;',
			esc_attr( $settings['accent'] ),
			esc_attr( $settings['button_text'] ),
			(int) $settings['radius'],
			(int) $settings['spacing'],
			(int) $settings['width']
		);
		$skin = 'wpistic-formistic-skin wpistic-formistic-skin--' . ( 'two' === $settings['layout'] ? 'two' : 'one' )
			. ( 'newsletter' === $settings['type'] ? ' wpistic-formistic-skin--newsletter' : '' );

		ob_start();
		?>
		<div class="wpistic-formistic-form-wrap <?php echo esc_attr( $skin ); ?>" id="Wpistic_Formistic" style="<?php echo esc_attr( $style ); ?>">
			<?php if ( '1' === $sent ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--ok"><?php echo esc_html( $settings['success'] ); ?></div>
			<?php elseif ( in_array( $sent, [ 'error', 'spam', 'rate', 'upload', 'consent' ], true ) ) : ?>
				<div class="wpistic-formistic-form-notice wpistic-formistic-form-notice--err">
					<?php echo esc_html( $this->error_label( $sent ) ); ?>
				</div>
			<?php endif; ?>
			<form class="wpistic-formistic-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $enctype; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<input type="hidden" name="action"      value="wpistic_formistic_submit_form">
				<input type="hidden" name="wpistic_formistic_form_id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'wpistic_formistic_submit_form_' . $id, 'wpistic_formistic_nonce' ); ?>
				<p class="wpistic-formistic-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'formistic' ); ?>
						<input type="text" name="wpistic_formistic_hp" tabindex="-1" autocomplete="off">
					</label>
				</p>

				<h3 class="wpistic-formistic-form-title"><?php echo esc_html( get_the_title( $id ) ); ?></h3>

				<?php foreach ( $fields as $f ) {
					echo $this->render_field( $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} ?>

				<?php if ( class_exists( 'Wpistic_Formistic_Spam' ) ) {
					Wpistic_Formistic_Spam::print_turnstile_field();
					Wpistic_Formistic_Spam::print_recaptcha_field();
				} ?>

				<button type="submit" class="wpistic-formistic-form-submit"><?php echo esc_html( $settings['submit_label'] ); ?></button>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render a single field for the frontend form.
	 *
	 * @param array $f Field definition.
	 * @return string
	 */
	protected function render_field( array $f ) {
		$type   = $f['type'] ?? 'text';
		$name   = 'wpistic_formistic_f[' . ( $f['key'] ?? '' ) . ']';
		$label  = $f['label'] ?? '';
		$req    = ! empty( $f['required'] );
		$ph     = $f['placeholder'] ?? '';
		$opts   = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) ( $f['options'] ?? '' ) ) ) );

		ob_start();
		$req_attr = $req ? ' required' : '';
		$req_star = $req ? ' *' : '';

		switch ( $type ) {
			case 'textarea':
				?>
				<label class="wpistic-formistic-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<textarea name="<?php echo esc_attr( $name ); ?>" rows="6" placeholder="<?php echo esc_attr( $ph ); ?>"<?php echo $req_attr; ?>></textarea>
				</label>
				<?php break;
			case 'select':
				?>
				<label class="wpistic-formistic-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; ?>>
						<option value=""><?php esc_html_e( '— Select —', 'formistic' ); ?></option>
						<?php foreach ( $opts as $o ) : ?>
							<option value="<?php echo esc_attr( $o ); ?>"><?php echo esc_html( $o ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php break;
			case 'radio':
				?>
				<fieldset class="wpistic-formistic-field wpistic-formistic-field--group">
					<legend><?php echo esc_html( $label . $req_star ); ?></legend>
					<?php foreach ( $opts as $o ) : ?>
						<label class="wpistic-formistic-opt"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $o ); ?>"<?php echo $req_attr; ?>> <?php echo esc_html( $o ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<?php break;
			case 'checkbox_group':
				?>
				<fieldset class="wpistic-formistic-field wpistic-formistic-field--group">
					<legend><?php echo esc_html( $label . $req_star ); ?></legend>
					<?php foreach ( $opts as $o ) : ?>
						<label class="wpistic-formistic-opt"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $o ); ?>"> <?php echo esc_html( $o ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<?php break;
			case 'checkbox':
			case 'consent':
				?>
				<label class="wpistic-formistic-consent">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo $req_attr; ?>>
					<span><?php echo esc_html( $label . $req_star ); ?></span>
				</label>
				<?php break;
			case 'file':
				$exts = class_exists( 'Wpistic_Formistic_Attachments' ) ? Wpistic_Formistic_Attachments::allowed_extensions() : [];
				$accept = $exts ? implode( ',', array_map( function ( $e ) { return '.' . $e; }, $exts ) ) : '';
				?>
				<label class="wpistic-formistic-field wpistic-formistic-field--file">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<input type="file" name="<?php echo esc_attr( 'wpistic_formistic_files_' . ( $f['key'] ?? '' ) ); ?>"<?php if ( $accept ) echo ' accept="' . esc_attr( $accept ) . '"'; ?><?php echo $req_attr; ?>>
				</label>
				<?php break;
			case 'hidden':
				?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $ph ); ?>">
				<?php break;
			case 'date':
				?>
				<label class="wpistic-formistic-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<input type="date" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; ?>>
				</label>
				<?php break;
			default:
				// text / email / tel / url
				$html_type = in_array( $type, [ 'email', 'tel', 'url' ], true ) ? $type : 'text';
				?>
				<label class="wpistic-formistic-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<input type="<?php echo esc_attr( $html_type ); ?>" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $ph ); ?>"<?php echo $req_attr; ?>>
				</label>
				<?php
		}
		return ob_get_clean();
	}

	/**
	 * Human-readable error label for a redirect status.
	 *
	 * @param string $code Status code.
	 * @return string
	 */
	protected function error_label( $code ) {
		switch ( $code ) {
			case 'spam':    return __( 'Your submission was blocked by our spam filter.', 'formistic' );
			case 'rate':    return __( 'Too many submissions from your network. Please wait a while and try again.', 'formistic' );
			case 'upload':  return __( 'There was a problem with one of your file uploads.', 'formistic' );
			case 'consent': return __( 'Please tick the required consent checkbox to continue.', 'formistic' );
			default:        return __( 'Sorry, something went wrong. Please try again.', 'formistic' );
		}
	}

	/* ==================================================================
	 * Submit handler
	 * ================================================================== */

	/**
	 * Handle a [wpistic_form] submission.
	 */
	public function handle_submit() {
		$back = wp_get_referer() ?: home_url( '/' );

		// Honeypot.
		if ( ! empty( $_POST['wpistic_formistic_hp'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$id = isset( $_POST['wpistic_formistic_form_id'] ) ? (int) $_POST['wpistic_formistic_form_id'] : 0;
		if ( ! $id ) {
			$this->redirect( $back, 'error' );
		}

		$nonce = isset( $_POST['wpistic_formistic_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['wpistic_formistic_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wpistic_formistic_submit_form_' . $id ) ) {
			$this->redirect( $back, 'error' );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			$this->redirect( $back, 'error' );
		}

		// CAPTCHA.
		if ( class_exists( 'Wpistic_Formistic_Spam' ) ) {
			if ( is_wp_error( Wpistic_Formistic_Spam::verify_recaptcha() ) || is_wp_error( Wpistic_Formistic_Spam::verify_turnstile() ) ) {
				$this->redirect( $back, 'spam' );
			}
		}

		$defs   = self::get_fields( $id );
		$inputs = isset( $_POST['wpistic_formistic_f'] ) ? (array) wp_unslash( $_POST['wpistic_formistic_f'] ) : [];
		$fields = [];

		foreach ( $defs as $f ) {
			$key   = $f['key'] ?? '';
			$type  = $f['type'] ?? 'text';
			$label = $f['label'] ?? $key;
			$req   = ! empty( $f['required'] );

			if ( 'file' === $type ) {
				continue; // handled separately below.
			}

			$raw = $inputs[ $key ] ?? '';
			if ( is_array( $raw ) ) {
				$value = implode( ', ', array_map( 'sanitize_text_field', $raw ) );
			} else {
				$value = ( 'textarea' === $type )
					? sanitize_textarea_field( (string) $raw )
					: sanitize_text_field( (string) $raw );
			}

			if ( $req && '' === trim( (string) $value ) ) {
				$this->redirect( $back, ( 'consent' === $type ) ? 'consent' : 'error' );
			}
			if ( 'email' === $type && '' !== $value && ! is_email( $value ) ) {
				$this->redirect( $back, 'error' );
			}
			if ( 'url' === $type && '' !== $value ) {
				$value = esc_url_raw( $value );
			}

			if ( 'consent' === $type ) {
				if ( $value ) {
					$value = class_exists( 'Wpistic_Formistic_Gdpr' ) ? Wpistic_Formistic_Gdpr::consent_record_value() : 'Yes';
				} else {
					$value = '';
				}
			}

			if ( '' !== trim( (string) $value ) ) {
				$fields[ $label ] = $value;
			}
		}

		$form_name = get_the_title( $id );
		$settings = self::get_settings( $id );
		$override = trim( (string) $settings['recipients'] );

		// Newsletter forms never touch the Inbox — they subscribe the email
		// to the dedicated Newsletter list and return success.
		if ( 'newsletter' === $settings['type'] ) {
			$email = '';
			foreach ( $defs as $f ) {
				if ( 'email' === ( $f['type'] ?? '' ) ) {
					$label = $f['label'] ?? '';
					if ( '' !== $label && ! empty( $fields[ $label ] ) ) {
						$email = (string) $fields[ $label ];
						break;
					}
				}
			}
			if ( '' === $email || ! is_email( $email ) ) {
				$this->redirect( $back, 'error' );
			}
			// Store to the subscribers list. process() writes directly to the
			// table (created on activation), so sign-ups are never lost even
			// if the Newsletter addon's screens are currently hidden.
			if ( class_exists( 'Wpistic_Formistic_Newsletter' ) ) {
				Wpistic_Formistic_Newsletter::process( $email, 'form:' . substr( (string) $form_name, 0, 40 ) );
			}
			if ( ! empty( $settings['redirect'] ) ) {
				wp_redirect( wp_validate_redirect( esc_url_raw( $settings['redirect'] ), home_url( '/' ) ) );
				exit;
			}
			$this->redirect( $back, '1' );
		}

		// Validate required file fields before any DB write to avoid orphan submissions.
		if ( class_exists( 'Wpistic_Formistic_Attachments' ) && Wpistic_Formistic_Attachments::enabled() ) {
			foreach ( $defs as $f ) {
				if ( 'file' !== ( $f['type'] ?? '' ) || empty( $f['required'] ) ) {
					continue;
				}
				$input = 'wpistic_formistic_files_' . ( $f['key'] ?? '' );
				if ( empty( $_FILES[ $input ] ) || ! isset( $_FILES[ $input ]['error'] ) ) {
					$this->redirect( $back, 'upload' );
				}
				$errors = (array) $_FILES[ $input ]['error'];
				$has_ok = in_array( 0, array_map( 'intval', $errors ), true );
				if ( ! $has_ok ) {
					$this->redirect( $back, 'upload' );
				}
			}
		}

		$capture   = new Wpistic_Formistic_Capture();
		$sub_id    = $capture->store( $form_name, $fields, '' === $override );
		if ( ! $sub_id ) {
			$this->redirect( $back, 'spam' );
		}

		// Per-field file uploads.
		if ( class_exists( 'Wpistic_Formistic_Attachments' ) && Wpistic_Formistic_Attachments::enabled() ) {
			$upload_errors = false;
			$any_stored    = false;
			foreach ( $defs as $f ) {
				if ( 'file' !== ( $f['type'] ?? '' ) ) {
					continue;
				}
				$input = 'wpistic_formistic_files_' . ( $f['key'] ?? '' );
				if ( empty( $_FILES[ $input ] ) ) {
					continue;
				}
				$result = Wpistic_Formistic_Attachments::ingest_post_files( $input, $sub_id );
				if ( $result['stored'] ) {
					$any_stored = true;
				} elseif ( $result['errors'] ) {
					$upload_errors = true;
				}
			}
			if ( $upload_errors && ! $any_stored ) {
				$this->redirect( $back, 'upload' );
			}
		}

		// Per-form recipient override.
		if ( '' !== $override ) {
			$recipients = array_filter( array_map( 'trim', explode( ',', $override ) ), 'is_email' );
			if ( $recipients ) {
				$subject = sprintf( __( '[%1$s] New "%2$s" submission', 'formistic' ), get_bloginfo( 'name' ), $form_name );
				$body    = "";
				foreach ( $fields as $l => $v ) {
					$body .= $l . ': ' . $v . "\n";
				}
				$body .= "\n" . __( 'View & reply:', 'formistic' ) . ' ' . admin_url( 'admin.php?page=formistic&view=' . (int) $sub_id );
				Wpistic_Formistic_Capture::send_internal( $recipients, $subject, $body );
			}
		}

		// Optional custom redirect.
		if ( ! empty( $settings['redirect'] ) ) {
			$target = esc_url_raw( $settings['redirect'] );
			wp_redirect( wp_validate_redirect( $target, home_url( '/' ) ) );
			exit;
		}

		$this->redirect( $back, '1' );
	}

	/**
	 * Redirect back to the form page with a status flag.
	 *
	 * @param string $back   Origin URL.
	 * @param string $status Status code.
	 */
	protected function redirect( $back, $status ) {
		$url = add_query_arg( 'wpistic_formistic_sent', $status, remove_query_arg( 'wpistic_formistic_sent', $back ) ) . '#Wpistic_Formistic';
		wp_safe_redirect( $url );
		exit;
	}
}
