<?php
/**
 * Multi-form builder — a lightweight CPT-backed form manager.
 *
 * Each form is a `WPISTIC_CF_form` post; its field definitions and per-form
 * settings live in post meta. Forms are rendered with [wpistic_form id="N"]
 * and submitted through the same admin-post pipeline as the legacy shortcode,
 * so they enjoy the spam stack, attachments, auto-responder and webhooks.
 *
 * @package WPISTIC_CF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Form CPT + admin UI + shortcode + submit handler.
 */
class WPISTIC_CF_Forms {

	/** Post type slug. */
	const POST_TYPE = 'WPISTIC_CF_form';

	/** Capability required to manage forms. */
	const CAP = 'manage_options';

	/** Meta key for the serialized field list. */
	const META_FIELDS = '_WPISTIC_CF_fields';

	/** Meta key for the per-form settings. */
	const META_SETTINGS = '_WPISTIC_CF_settings';

	/**
	 * Whitelist of field types and their human labels.
	 *
	 * @return array<string,string>
	 */
	public static function field_types() {
		return [
			'text'           => __( 'Single line text', 'wpistic-contact-form' ),
			'email'          => __( 'Email',            'wpistic-contact-form' ),
			'tel'            => __( 'Phone',            'wpistic-contact-form' ),
			'url'            => __( 'URL',              'wpistic-contact-form' ),
			'textarea'       => __( 'Paragraph',        'wpistic-contact-form' ),
			'select'         => __( 'Dropdown',         'wpistic-contact-form' ),
			'radio'          => __( 'Radio buttons',    'wpistic-contact-form' ),
			'checkbox_group' => __( 'Checkbox group',   'wpistic-contact-form' ),
			'checkbox'       => __( 'Single checkbox',  'wpistic-contact-form' ),
			'date'           => __( 'Date',             'wpistic-contact-form' ),
			'file'           => __( 'File upload',      'wpistic-contact-form' ),
			'hidden'         => __( 'Hidden',           'wpistic-contact-form' ),
			'consent'        => __( 'GDPR consent',     'wpistic-contact-form' ),
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
		add_action( 'admin_post_WPISTIC_CF_submit_form',        [ $this, 'handle_submit' ] );
		add_action( 'admin_post_nopriv_WPISTIC_CF_submit_form', [ $this, 'handle_submit' ] );

		// Custom column on the WP forms list table.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',       [ $this, 'list_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'list_column_value' ], 10, 2 );
	}

	/* ==================================================================
	 * CPT registration
	 * ================================================================== */

	/**
	 * Register the WPISTIC_CF_form CPT under our top-level menu.
	 */
	public function register_cpt() {
		register_post_type( self::POST_TYPE, [
			'label'              => __( 'WPistic Forms', 'wpistic-contact-form' ),
			'labels'             => [
				'name'               => __( 'Forms',          'wpistic-contact-form' ),
				'singular_name'      => __( 'Form',           'wpistic-contact-form' ),
				'add_new'            => __( 'Add New',        'wpistic-contact-form' ),
				'add_new_item'       => __( 'Add New Form',   'wpistic-contact-form' ),
				'edit_item'          => __( 'Edit Form',      'wpistic-contact-form' ),
				'new_item'           => __( 'New Form',       'wpistic-contact-form' ),
				'view_item'          => __( 'View Form',      'wpistic-contact-form' ),
				'search_items'       => __( 'Search Forms',   'wpistic-contact-form' ),
				'not_found'          => __( 'No forms found', 'wpistic-contact-form' ),
				'menu_name'          => __( 'Forms',          'wpistic-contact-form' ),
			],
			'public'             => false,
			'show_ui'            => true,
			'show_in_menu'       => 'wpistic-contact',
			'show_in_rest'       => false,
			'supports'           => [ 'title' ],
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'menu_position'      => 27,
		] );
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
				$new['WPISTIC_CF_shortcode'] = __( 'Shortcode', 'wpistic-contact-form' );
				$new['WPISTIC_CF_fields']    = __( 'Fields',    'wpistic-contact-form' );
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
		if ( 'WPISTIC_CF_shortcode' === $col ) {
			echo '<code>[wpistic_form id="' . (int) $id . '"]</code>';
		} elseif ( 'WPISTIC_CF_fields' === $col ) {
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
		add_meta_box( 'WPISTIC_CF_fields_editor', __( 'Form Fields', 'wpistic-contact-form' ),
			[ $this, 'render_fields_metabox' ], self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'WPISTIC_CF_form_settings', __( 'Notifications & Display', 'wpistic-contact-form' ),
			[ $this, 'render_settings_metabox' ], self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'WPISTIC_CF_form_shortcode', __( 'Shortcode', 'wpistic-contact-form' ),
			[ $this, 'render_shortcode_metabox' ], self::POST_TYPE, 'side', 'high' );
	}

	/**
	 * Shortcode metabox (side panel).
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_shortcode_metabox( $post ) {
		?>
		<p><?php esc_html_e( 'Paste this shortcode into any page or post:', 'wpistic-contact-form' ); ?></p>
		<input type="text" readonly class="widefat code" value='[wpistic_form id="<?php echo (int) $post->ID; ?>"]' onclick="this.select();">
		<?php
	}

	/**
	 * Field editor metabox.
	 *
	 * @param WP_Post $post Form post.
	 */
	public function render_fields_metabox( $post ) {
		wp_nonce_field( 'WPISTIC_CF_save_form_' . $post->ID, 'WPISTIC_CF_form_nonce' );
		$fields = self::get_fields( $post->ID );
		$types  = self::field_types();
		?>
		<div class="WPISTIC_CF-fields-editor">
			<div class="WPISTIC_CF-fields-editor__rows" id="WPISTIC_CF-fields-editor-rows">
				<?php foreach ( $fields as $i => $f ) : ?>
					<?php $this->render_field_row( $i, $f, $types ); ?>
				<?php endforeach; ?>
			</div>
			<p>
				<button type="button" class="button" id="WPISTIC_CF-fields-editor-add"><?php esc_html_e( '+ Add Field', 'wpistic-contact-form' ); ?></button>
			</p>
			<template id="WPISTIC_CF-fields-editor-template">
				<?php $this->render_field_row( '__INDEX__', [], $types ); ?>
			</template>
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
		<div class="WPISTIC_CF-field-row" data-index="<?php echo esc_attr( $i ); ?>">
			<input type="hidden" name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][key]" value="<?php echo esc_attr( $key ); ?>">
			<div class="WPISTIC_CF-field-row__main">
				<label class="WPISTIC_CF-field-row__label">
					<span><?php esc_html_e( 'Label', 'wpistic-contact-form' ); ?></span>
					<input type="text" name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Your Name', 'wpistic-contact-form' ); ?>">
				</label>
				<label class="WPISTIC_CF-field-row__type">
					<span><?php esc_html_e( 'Type', 'wpistic-contact-form' ); ?></span>
					<select name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][type]">
						<?php foreach ( $types as $t => $tlabel ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $tlabel ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="WPISTIC_CF-field-row__required">
					<input type="hidden" name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][required]" value="0">
					<input type="checkbox" name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][required]" value="1" <?php checked( $required ); ?>>
					<span><?php esc_html_e( 'Required', 'wpistic-contact-form' ); ?></span>
				</label>
				<button type="button" class="button-link WPISTIC_CF-field-row__remove" aria-label="<?php esc_attr_e( 'Remove field', 'wpistic-contact-form' ); ?>">&times;</button>
			</div>
			<div class="WPISTIC_CF-field-row__extra">
				<label>
					<span><?php esc_html_e( 'Placeholder', 'wpistic-contact-form' ); ?></span>
					<input type="text" name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][placeholder]" value="<?php echo esc_attr( $ph ); ?>">
				</label>
				<label>
					<span><?php esc_html_e( 'Options (one per line, used by select / radio / checkbox group)', 'wpistic-contact-form' ); ?></span>
					<textarea name="WPISTIC_CF_fields[<?php echo esc_attr( $i ); ?>][options]" rows="3"><?php echo esc_textarea( $opts ); ?></textarea>
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
				<th><label for="WPISTIC_CF_recipients"><?php esc_html_e( 'Notification recipients', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="text" id="WPISTIC_CF_recipients" name="WPISTIC_CF_settings[recipients]" class="regular-text" value="<?php echo esc_attr( $s['recipients'] ); ?>">
					<p class="description"><?php esc_html_e( 'Comma-separated email addresses. Leave empty to use the default Settings → General notification address.', 'wpistic-contact-form' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="WPISTIC_CF_submit_label"><?php esc_html_e( 'Submit button label', 'wpistic-contact-form' ); ?></label></th>
				<td><input type="text" id="WPISTIC_CF_submit_label" name="WPISTIC_CF_settings[submit_label]" class="regular-text" value="<?php echo esc_attr( $s['submit_label'] ); ?>"></td>
			</tr>
			<tr>
				<th><label for="WPISTIC_CF_success"><?php esc_html_e( 'Success message', 'wpistic-contact-form' ); ?></label></th>
				<td><textarea id="WPISTIC_CF_success" name="WPISTIC_CF_settings[success]" class="large-text" rows="2"><?php echo esc_textarea( $s['success'] ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="WPISTIC_CF_redirect"><?php esc_html_e( 'Redirect after success (optional)', 'wpistic-contact-form' ); ?></label></th>
				<td>
					<input type="url" id="WPISTIC_CF_redirect" name="WPISTIC_CF_settings[redirect]" class="regular-text" value="<?php echo esc_attr( $s['redirect'] ); ?>" placeholder="https://example.com/thanks">
					<p class="description"><?php esc_html_e( 'If set, the visitor is sent here after a successful submission.', 'wpistic-contact-form' ); ?></p>
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
		if ( empty( $_POST['WPISTIC_CF_form_nonce'] ) ||
		     ! wp_verify_nonce( wp_unslash( $_POST['WPISTIC_CF_form_nonce'] ), 'WPISTIC_CF_save_form_' . $post_id ) ) {
			return;
		}

		// --- Fields ---
		$incoming = isset( $_POST['WPISTIC_CF_fields'] ) ? (array) wp_unslash( $_POST['WPISTIC_CF_fields'] ) : [];
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
		$incoming = isset( $_POST['WPISTIC_CF_settings'] ) ? (array) wp_unslash( $_POST['WPISTIC_CF_settings'] ) : [];
		$settings = [
			'recipients'   => isset( $incoming['recipients'] )   ? sanitize_text_field( $incoming['recipients'] ) : '',
			'submit_label' => isset( $incoming['submit_label'] ) ? sanitize_text_field( $incoming['submit_label'] ) : '',
			'success'      => isset( $incoming['success'] )      ? sanitize_textarea_field( $incoming['success'] ) : '',
			'redirect'     => isset( $incoming['redirect'] )     ? esc_url_raw( $incoming['redirect'] ) : '',
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
			'recipients'   => '',
			'submit_label' => __( 'Send Message', 'wpistic-contact-form' ),
			'success'      => __( 'Thank you — your message has been sent. We will get back to you shortly.', 'wpistic-contact-form' ),
			'redirect'     => '',
		] );
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

		wp_enqueue_style( 'WPISTIC_CF-form' );

		$fields   = self::get_fields( $id );
		$settings = self::get_settings( $id );
		WPISTIC_CF_Database::log_impression( (string) get_the_title( $id ) );
		$sent     = isset( $_GET['WPISTIC_CF_sent'] ) ? sanitize_text_field( wp_unslash( $_GET['WPISTIC_CF_sent'] ) ) : '';
		$has_file = false;
		foreach ( $fields as $f ) {
			if ( 'file' === ( $f['type'] ?? '' ) ) { $has_file = true; break; }
		}
		$enctype = $has_file ? ' enctype="multipart/form-data"' : '';

		ob_start();
		?>
		<div class="WPISTIC_CF-form-wrap" id="WPISTIC_CF">
			<?php if ( '1' === $sent ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--ok"><?php echo esc_html( $settings['success'] ); ?></div>
			<?php elseif ( in_array( $sent, [ 'error', 'spam', 'rate', 'upload', 'consent' ], true ) ) : ?>
				<div class="WPISTIC_CF-form-notice WPISTIC_CF-form-notice--err">
					<?php echo esc_html( $this->error_label( $sent ) ); ?>
				</div>
			<?php endif; ?>
			<form class="WPISTIC_CF-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo $enctype; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<input type="hidden" name="action"      value="WPISTIC_CF_submit_form">
				<input type="hidden" name="WPISTIC_CF_form_id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'WPISTIC_CF_submit_form_' . $id, 'WPISTIC_CF_nonce' ); ?>
				<p class="WPISTIC_CF-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this field empty', 'wpistic-contact-form' ); ?>
						<input type="text" name="WPISTIC_CF_hp" tabindex="-1" autocomplete="off">
					</label>
				</p>

				<h3 class="WPISTIC_CF-form-title"><?php echo esc_html( get_the_title( $id ) ); ?></h3>

				<?php foreach ( $fields as $f ) {
					echo $this->render_field( $f ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} ?>

				<?php if ( class_exists( 'WPISTIC_CF_Spam' ) ) {
					WPISTIC_CF_Spam::print_turnstile_field();
					WPISTIC_CF_Spam::print_recaptcha_field();
				} ?>

				<button type="submit" class="WPISTIC_CF-form-submit"><?php echo esc_html( $settings['submit_label'] ); ?></button>
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
		$name   = 'WPISTIC_CF_f[' . ( $f['key'] ?? '' ) . ']';
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
				<label class="WPISTIC_CF-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<textarea name="<?php echo esc_attr( $name ); ?>" rows="6" placeholder="<?php echo esc_attr( $ph ); ?>"<?php echo $req_attr; ?>></textarea>
				</label>
				<?php break;
			case 'select':
				?>
				<label class="WPISTIC_CF-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<select name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; ?>>
						<option value=""><?php esc_html_e( '— Select —', 'wpistic-contact-form' ); ?></option>
						<?php foreach ( $opts as $o ) : ?>
							<option value="<?php echo esc_attr( $o ); ?>"><?php echo esc_html( $o ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php break;
			case 'radio':
				?>
				<fieldset class="WPISTIC_CF-field WPISTIC_CF-field--group">
					<legend><?php echo esc_html( $label . $req_star ); ?></legend>
					<?php foreach ( $opts as $o ) : ?>
						<label class="WPISTIC_CF-opt"><input type="radio" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $o ); ?>"<?php echo $req_attr; ?>> <?php echo esc_html( $o ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<?php break;
			case 'checkbox_group':
				?>
				<fieldset class="WPISTIC_CF-field WPISTIC_CF-field--group">
					<legend><?php echo esc_html( $label . $req_star ); ?></legend>
					<?php foreach ( $opts as $o ) : ?>
						<label class="WPISTIC_CF-opt"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[]" value="<?php echo esc_attr( $o ); ?>"> <?php echo esc_html( $o ); ?></label>
					<?php endforeach; ?>
				</fieldset>
				<?php break;
			case 'checkbox':
			case 'consent':
				?>
				<label class="WPISTIC_CF-consent">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1"<?php echo $req_attr; ?>>
					<span><?php echo esc_html( $label . $req_star ); ?></span>
				</label>
				<?php break;
			case 'file':
				$exts = class_exists( 'WPISTIC_CF_Attachments' ) ? WPISTIC_CF_Attachments::allowed_extensions() : [];
				$accept = $exts ? implode( ',', array_map( function ( $e ) { return '.' . $e; }, $exts ) ) : '';
				?>
				<label class="WPISTIC_CF-field WPISTIC_CF-field--file">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<input type="file" name="<?php echo esc_attr( 'WPISTIC_CF_files_' . ( $f['key'] ?? '' ) ); ?>"<?php if ( $accept ) echo ' accept="' . esc_attr( $accept ) . '"'; ?><?php echo $req_attr; ?>>
				</label>
				<?php break;
			case 'hidden':
				?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $ph ); ?>">
				<?php break;
			case 'date':
				?>
				<label class="WPISTIC_CF-field">
					<span><?php echo esc_html( $label . $req_star ); ?></span>
					<input type="date" name="<?php echo esc_attr( $name ); ?>"<?php echo $req_attr; ?>>
				</label>
				<?php break;
			default:
				// text / email / tel / url
				$html_type = in_array( $type, [ 'email', 'tel', 'url' ], true ) ? $type : 'text';
				?>
				<label class="WPISTIC_CF-field">
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
			case 'spam':    return __( 'Your submission was blocked by our spam filter.', 'wpistic-contact-form' );
			case 'rate':    return __( 'Too many submissions from your network. Please wait a while and try again.', 'wpistic-contact-form' );
			case 'upload':  return __( 'There was a problem with one of your file uploads.', 'wpistic-contact-form' );
			case 'consent': return __( 'Please tick the required consent checkbox to continue.', 'wpistic-contact-form' );
			default:        return __( 'Sorry, something went wrong. Please try again.', 'wpistic-contact-form' );
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
		if ( ! empty( $_POST['WPISTIC_CF_hp'] ) ) {
			wp_safe_redirect( $back );
			exit;
		}

		$id = isset( $_POST['WPISTIC_CF_form_id'] ) ? (int) $_POST['WPISTIC_CF_form_id'] : 0;
		if ( ! $id ) {
			$this->redirect( $back, 'error' );
		}

		$nonce = isset( $_POST['WPISTIC_CF_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['WPISTIC_CF_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'WPISTIC_CF_submit_form_' . $id ) ) {
			$this->redirect( $back, 'error' );
		}

		$post = get_post( $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			$this->redirect( $back, 'error' );
		}

		// CAPTCHA.
		if ( class_exists( 'WPISTIC_CF_Spam' ) ) {
			if ( is_wp_error( WPISTIC_CF_Spam::verify_recaptcha() ) || is_wp_error( WPISTIC_CF_Spam::verify_turnstile() ) ) {
				$this->redirect( $back, 'spam' );
			}
		}

		$defs   = self::get_fields( $id );
		$inputs = isset( $_POST['WPISTIC_CF_f'] ) ? (array) wp_unslash( $_POST['WPISTIC_CF_f'] ) : [];
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
					$value = class_exists( 'WPISTIC_CF_Gdpr' ) ? WPISTIC_CF_Gdpr::consent_record_value() : 'Yes';
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

		// Validate required file fields before any DB write to avoid orphan submissions.
		if ( class_exists( 'WPISTIC_CF_Attachments' ) && WPISTIC_CF_Attachments::enabled() ) {
			foreach ( $defs as $f ) {
				if ( 'file' !== ( $f['type'] ?? '' ) || empty( $f['required'] ) ) {
					continue;
				}
				$input = 'WPISTIC_CF_files_' . ( $f['key'] ?? '' );
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

		$capture   = new WPISTIC_CF_Capture();
		$sub_id    = $capture->store( $form_name, $fields, '' === $override );
		if ( ! $sub_id ) {
			$this->redirect( $back, 'spam' );
		}

		// Per-field file uploads.
		if ( class_exists( 'WPISTIC_CF_Attachments' ) && WPISTIC_CF_Attachments::enabled() ) {
			$upload_errors = false;
			$any_stored    = false;
			foreach ( $defs as $f ) {
				if ( 'file' !== ( $f['type'] ?? '' ) ) {
					continue;
				}
				$input = 'WPISTIC_CF_files_' . ( $f['key'] ?? '' );
				if ( empty( $_FILES[ $input ] ) ) {
					continue;
				}
				$result = WPISTIC_CF_Attachments::ingest_post_files( $input, $sub_id );
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
				$subject = sprintf( __( '[%1$s] New "%2$s" submission', 'wpistic-contact-form' ), get_bloginfo( 'name' ), $form_name );
				$body    = "";
				foreach ( $fields as $l => $v ) {
					$body .= $l . ': ' . $v . "\n";
				}
				$body .= "\n" . __( 'View & reply:', 'wpistic-contact-form' ) . ' ' . admin_url( 'admin.php?page=wpistic-contact&view=' . (int) $sub_id );
				WPISTIC_CF_Capture::send_internal( $recipients, $subject, $body );
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
		$url = add_query_arg( 'WPISTIC_CF_sent', $status, remove_query_arg( 'WPISTIC_CF_sent', $back ) ) . '#WPISTIC_CF';
		wp_safe_redirect( $url );
		exit;
	}
}
