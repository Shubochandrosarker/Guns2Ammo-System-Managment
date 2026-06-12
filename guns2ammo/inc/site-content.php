<?php
/**
 * Client content editing system — "Site Content".
 *
 * Lets the client edit hand-picked strings + images that live in theme
 * templates WITHOUT touching code. Templates call:
 *
 *   g2a_content( 'home_hero_headline', 'DEFAULT…', 'text', 'Homepage — Hero headline' )
 *   g2a_content_img( 'about_tour_01', g2a_asset( 'img/gallery/…' ), 'Alt text', 'About — Tour photo 01' )
 *
 * The helper returns the saved override (option `g2a_site_content`) or the
 * default, escaped for output per type:
 *
 *   text     → esc_html()
 *   textarea → wp_kses_post() (basic HTML allowed: br, strong, span…)
 *   image    → esc_url()
 *
 * Every call self-registers the key (default, type, label, group) into a
 * per-request registry; when a logged-in admin browses the frontend, newly
 * seen keys are persisted into option `g2a_site_content_registry` so the
 * Appearance → Site Content screen can list every field without needing a
 * fresh page visit each time.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ */
/* Runtime registry                                                    */
/* ------------------------------------------------------------------ */

/**
 * Per-request registry of every content key seen while rendering.
 * Shape: key => [ 'default' => …, 'type' => …, 'label' => …, 'group' => … ]
 *
 * @param array|null $add Entry to merge (key => meta), null to read.
 * @return array
 */
function g2a_content_runtime_registry( $add = null ) {
	static $registry = array();
	if ( is_array( $add ) ) {
		$registry = array_merge( $registry, $add );
	}
	return $registry;
}

/**
 * Best-effort label for "where am I rendering" — used as the section
 * grouping on the admin screen when no explicit label prefix is given.
 *
 * @return string
 */
function g2a_content_current_group() {
	if ( ! did_action( 'wp' ) ) {
		return 'Global';
	}
	if ( is_front_page() ) {
		return 'Homepage';
	}
	if ( is_page() ) {
		$title = get_the_title();
		return $title ? $title : 'Pages';
	}
	if ( is_singular() ) {
		return 'Posts';
	}
	return 'Global';
}

/* ------------------------------------------------------------------ */
/* Public helpers                                                      */
/* ------------------------------------------------------------------ */

/**
 * Return the client override for a content key, or the supplied default.
 * Output is escaped per type — echo the return value directly.
 *
 * @param string $key     Unique key, e.g. 'home_hero_headline'.
 * @param string $default Default value (the current hardcoded string).
 * @param string $type    'text' | 'textarea' | 'image'.
 * @param string $label   Human label for the admin screen, e.g.
 *                        'Homepage — Hero headline'. The part before the
 *                        em-dash becomes the section group.
 * @return string Escaped, output-ready value.
 */
function g2a_content( $key, $default = '', $type = 'text', $label = '' ) {
	$key  = sanitize_key( $key );
	$type = in_array( $type, array( 'text', 'textarea', 'image' ), true ) ? $type : 'text';

	// Derive the admin grouping: explicit "Group — Label" prefix wins,
	// otherwise fall back to the page being rendered.
	$group = g2a_content_current_group();
	if ( $label && false !== strpos( $label, '—' ) ) {
		$parts = explode( '—', $label, 2 );
		$group = trim( $parts[0] );
	}

	g2a_content_runtime_registry( array(
		$key => array(
			'default' => (string) $default,
			'type'    => $type,
			'label'   => $label ? $label : $key,
			'group'   => $group ? $group : 'Global',
		),
	) );

	$saved = get_option( 'g2a_site_content', array() );
	$value = ( is_array( $saved ) && isset( $saved[ $key ] ) && '' !== trim( (string) $saved[ $key ] ) )
		? (string) $saved[ $key ]
		: (string) $default;

	switch ( $type ) {
		case 'textarea':
			return wp_kses_post( $value );
		case 'image':
			return esc_url( $value );
		default:
			return esc_html( $value );
	}
}

/**
 * Render a full, lazy-loading <img> for an editable image slot.
 * Returns '' when both the override and default are empty, so callers can
 * keep their placeholder markup as a graceful fallback.
 *
 * @param string $key         Content key.
 * @param string $default_url Default image URL (usually g2a_asset(...)).
 * @param string $alt         Alt text.
 * @param string $label       Admin label, e.g. 'About — Tour photo 01'.
 * @param string $class       Optional class attribute.
 * @return string <img> HTML or ''.
 */
function g2a_content_img( $key, $default_url, $alt = '', $label = '', $class = '' ) {
	$url = g2a_content( $key, $default_url, 'image', $label );
	if ( '' === $url ) {
		return '';
	}
	return sprintf(
		'<img src="%s" alt="%s"%s loading="lazy" decoding="async" />',
		esc_url( $url ),
		esc_attr( $alt ),
		$class ? ' class="' . esc_attr( $class ) . '"' : ''
	);
}

/* ------------------------------------------------------------------ */
/* Persist the registry from frontend renders                          */
/* ------------------------------------------------------------------ */

/**
 * After a frontend page renders for a user who can manage the theme,
 * merge any newly seen keys into the persistent registry option so the
 * admin screen lists them without requiring another visit.
 */
add_action( 'shutdown', function () {
	if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$seen = g2a_content_runtime_registry();
	if ( empty( $seen ) ) {
		return;
	}
	$stored = get_option( 'g2a_site_content_registry', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$merged = array_merge( $stored, $seen );
	if ( $merged !== $stored ) {
		update_option( 'g2a_site_content_registry', $merged, false );
	}
} );

/* ------------------------------------------------------------------ */
/* Admin screen: Appearance → Site Content                             */
/* ------------------------------------------------------------------ */

add_action( 'admin_menu', function () {
	add_theme_page(
		__( 'Site Content', 'guns2ammo' ),
		__( 'Site Content', 'guns2ammo' ),
		'edit_theme_options',
		'g2a-site-content',
		'g2a_content_render_admin_page'
	);
} );

/* Media library for the image-URL pickers + a tiny inline opener. */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( 'appearance_page_g2a-site-content' !== $hook ) {
		return;
	}
	wp_enqueue_media();
	$js = <<<'JS'
(function(){
	document.addEventListener('click', function(e){
		var btn = e.target.closest('.g2a-sc-media');
		if (!btn || typeof wp === 'undefined' || !wp.media) return;
		e.preventDefault();
		var input = document.getElementById(btn.getAttribute('data-target'));
		var frame = wp.media({ title: 'Choose Image', library: { type: 'image' }, multiple: false });
		frame.on('select', function(){
			var att = frame.state().get('selection').first().toJSON();
			if (input) {
				input.value = att.url;
				var prev = document.getElementById(input.id + '-preview');
				if (prev) prev.src = att.url;
			}
		});
		frame.open();
	});
})();
JS;
	wp_add_inline_script( 'media-editor', $js );
} );

/**
 * Handle the save (admin-post so we can redirect back cleanly).
 */
add_action( 'admin_post_g2a_save_site_content', function () {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to manage site content.', 'guns2ammo' ) );
	}
	check_admin_referer( 'g2a_site_content' );

	$registry = get_option( 'g2a_site_content_registry', array() );
	$registry = is_array( $registry ) ? $registry : array();
	$saved    = get_option( 'g2a_site_content', array() );
	$saved    = is_array( $saved ) ? $saved : array();

	$incoming = isset( $_POST['g2a_content'] ) && is_array( $_POST['g2a_content'] ) ? wp_unslash( $_POST['g2a_content'] ) : array();
	$resets   = isset( $_POST['g2a_reset'] ) && is_array( $_POST['g2a_reset'] ) ? array_map( 'sanitize_key', array_keys( $_POST['g2a_reset'] ) ) : array();

	foreach ( $registry as $key => $meta ) {
		$key = sanitize_key( $key );

		// "Reset to default" wins: drop the override entirely.
		if ( in_array( $key, $resets, true ) ) {
			unset( $saved[ $key ] );
			continue;
		}
		if ( ! array_key_exists( $key, $incoming ) ) {
			continue;
		}

		$raw  = (string) $incoming[ $key ];
		$type = isset( $meta['type'] ) ? $meta['type'] : 'text';
		switch ( $type ) {
			case 'textarea':
				$clean = wp_kses_post( $raw );
				break;
			case 'image':
				$clean = esc_url_raw( trim( $raw ) );
				break;
			default:
				$clean = sanitize_text_field( $raw );
		}

		// Storing the default verbatim is pointless — treat as "no override"
		// so future default copy edits in code still flow through.
		if ( '' === trim( $clean ) || $clean === (string) ( $meta['default'] ?? '' ) ) {
			unset( $saved[ $key ] );
		} else {
			$saved[ $key ] = $clean;
		}
	}

	update_option( 'g2a_site_content', $saved, false );

	wp_safe_redirect( add_query_arg(
		array( 'page' => 'g2a-site-content', 'updated' => '1' ),
		admin_url( 'themes.php' )
	) );
	exit;
} );

/**
 * Render the Appearance → Site Content screen.
 */
function g2a_content_render_admin_page() {
	if ( ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}

	$registry = get_option( 'g2a_site_content_registry', array() );
	$registry = is_array( $registry ) ? $registry : array();
	$saved    = get_option( 'g2a_site_content', array() );
	$saved    = is_array( $saved ) ? $saved : array();

	// Group keys by section for display.
	$groups = array();
	foreach ( $registry as $key => $meta ) {
		$group = ! empty( $meta['group'] ) ? (string) $meta['group'] : 'Global';
		$groups[ $group ][ $key ] = $meta;
	}
	ksort( $groups );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Site Content', 'guns2ammo' ); ?></h1>

		<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Site content saved.', 'guns2ammo' ); ?></p></div>
		<?php endif; ?>

		<div class="notice notice-info">
			<p><?php esc_html_e( 'Browse any page of the site while logged in to register its editable fields here.', 'guns2ammo' ); ?></p>
		</div>

		<?php if ( empty( $groups ) ) : ?>
			<p><?php esc_html_e( 'No editable fields registered yet. Open the homepage (or any other page) in a new tab while logged in, then reload this screen.', 'guns2ammo' ); ?></p>
	</div>
	<?php
		return;
	endif;
	?>

		<style>
			.g2a-sc-section { background:#fff; border:1px solid #c3c4c7; margin:16px 0; padding:4px 20px 14px; max-width:920px; }
			.g2a-sc-section h2 { border-bottom:1px solid #eee; padding-bottom:8px; }
			.g2a-sc-field { margin:18px 0; }
			.g2a-sc-field > label.g2a-sc-label { display:block; font-weight:600; margin-bottom:6px; }
			.g2a-sc-field input[type=text], .g2a-sc-field input[type=url], .g2a-sc-field textarea { width:100%; max-width:760px; }
			.g2a-sc-reset { margin-left:12px; font-weight:400; color:#646970; white-space:nowrap; }
			.g2a-sc-img-row { display:flex; gap:10px; align-items:flex-start; max-width:760px; }
			.g2a-sc-img-row input { flex:1; }
			.g2a-sc-preview { max-width:120px; max-height:80px; border:1px solid #c3c4c7; object-fit:cover; display:block; margin-top:8px; }
			.g2a-sc-default { color:#646970; font-size:12px; margin-top:4px; word-break:break-word; }
		</style>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="g2a_save_site_content" />
			<?php wp_nonce_field( 'g2a_site_content' ); ?>

			<?php foreach ( $groups as $group => $fields ) : ?>
				<div class="g2a-sc-section">
					<h2><?php echo esc_html( $group ); ?></h2>
					<?php foreach ( $fields as $key => $meta ) :
						$type        = isset( $meta['type'] ) ? $meta['type'] : 'text';
						$default     = (string) ( $meta['default'] ?? '' );
						$label       = (string) ( $meta['label'] ?? $key );
						$has_over    = isset( $saved[ $key ] ) && '' !== trim( (string) $saved[ $key ] );
						$value       = $has_over ? (string) $saved[ $key ] : $default;
						$field_id    = 'g2a-sc-' . sanitize_html_class( $key );
						$field_name  = 'g2a_content[' . esc_attr( $key ) . ']';
					?>
					<div class="g2a-sc-field">
						<label class="g2a-sc-label" for="<?php echo esc_attr( $field_id ); ?>">
							<?php echo esc_html( $label ); ?>
							<?php if ( $has_over ) : ?>
								<span style="color:#2271b1;">●</span>
							<?php endif; ?>
							<label class="g2a-sc-reset">
								<input type="checkbox" name="g2a_reset[<?php echo esc_attr( $key ); ?>]" value="1" />
								<?php esc_html_e( 'Reset to default', 'guns2ammo' ); ?>
							</label>
						</label>

						<?php if ( 'textarea' === $type ) : ?>
							<textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $field_name; // phpcs:ignore ?>" rows="4"><?php echo esc_textarea( $value ); ?></textarea>
						<?php elseif ( 'image' === $type ) : ?>
							<div class="g2a-sc-img-row">
								<input type="url" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $field_name; // phpcs:ignore ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="https://…" />
								<button type="button" class="button g2a-sc-media" data-target="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Media Library', 'guns2ammo' ); ?></button>
							</div>
							<?php if ( $value ) : ?>
								<img class="g2a-sc-preview" id="<?php echo esc_attr( $field_id ); ?>-preview" src="<?php echo esc_url( $value ); ?>" alt="" loading="lazy" />
							<?php endif; ?>
						<?php else : ?>
							<input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo $field_name; // phpcs:ignore ?>" value="<?php echo esc_attr( $value ); ?>" />
						<?php endif; ?>

						<?php if ( $has_over && $default ) : ?>
							<p class="g2a-sc-default"><?php esc_html_e( 'Default:', 'guns2ammo' ); ?> <?php echo esc_html( wp_html_excerpt( $default, 140, '…' ) ); ?></p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save Site Content', 'guns2ammo' ) ); ?>
		</form>
	</div>
	<?php
}
