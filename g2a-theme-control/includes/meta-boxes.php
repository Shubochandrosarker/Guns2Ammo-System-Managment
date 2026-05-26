<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function g2a_tc_register_meta_box() {
	add_meta_box(
		'g2a_template_fields',
		__( 'Page Content Settings', 'g2a-theme-control' ),
		'g2a_tc_render_meta_box',
		'page',
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes', 'g2a_tc_register_meta_box' );

function g2a_tc_admin_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	wp_enqueue_media();
}
add_action( 'admin_enqueue_scripts', 'g2a_tc_admin_assets' );

function g2a_tc_render_meta_box( $post ) {
	$template_key = g2a_tc_resolve_template_key( $post->ID );
	$sections     = g2a_tc_get_fields_config( $template_key, $post->ID );

	wp_nonce_field( 'g2a_tc_save_fields', 'g2a_tc_nonce' );

	if ( empty( $sections ) ) {
		echo '<p>' . esc_html__( 'No custom fields configured for this template.', 'g2a-theme-control' ) . '</p>';
		return;
	}

	echo '<p style="margin:0 0 10px;color:#666;font-size:12px;">';
	echo esc_html__( 'Template Key:', 'g2a-theme-control' ) . ' <code>' . esc_html( $template_key ) . '</code>';
	echo '</p>';

	echo '<div class="g2a-tc-wrap">';
	foreach ( $sections as $section => $fields ) {
		echo '<div class="g2a-tc-section" style="margin-bottom:20px;padding:16px;border:1px solid #ddd;background:#fff;">';
		echo '<h3 style="margin-top:0;">' . esc_html( $section ) . '</h3>';
		foreach ( $fields as $field ) {
			g2a_tc_render_field( $post->ID, $field );
		}
		echo '</div>';
	}
	echo '</div>';
	?>
	<script>
	jQuery(function($){
		$(document).on('click', '.g2a-tc-upload', function(e){
			e.preventDefault();
			var button = $(this);
			var target = button.prev('input');
			var frame = wp.media({ title: 'Select Image', button: { text: 'Use this image' }, multiple: false });
			frame.on('select', function(){
				var item = frame.state().get('selection').first().toJSON();
				target.val(item.id);
				button.closest('.g2a-tc-field').find('.g2a-tc-preview').html('<img src="'+item.url+'" style="max-width:180px;height:auto;"/>');
			});
			frame.open();
		});

		$(document).on('click', '.g2a-tc-repeater-add', function(e){
			e.preventDefault();
			var wrap = $(this).closest('.g2a-tc-repeater');
			var template = wrap.find('.g2a-tc-repeater-template').first().html();
			var idx = wrap.find('.g2a-tc-repeater-row').length;
			template = template.replace(/__INDEX__/g, idx);
			wrap.find('.g2a-tc-repeater-rows').append(template);
		});

		$(document).on('click', '.g2a-tc-repeater-remove', function(e){
			e.preventDefault();
			$(this).closest('.g2a-tc-repeater-row').remove();
		});
	});
	</script>
	<?php
}

function g2a_tc_render_field( $post_id, $field ) {
	$id    = $field['id'];
	$label = $field['label'];
	$type  = $field['type'];
	$value = get_post_meta( $post_id, $id, true );

	echo '<div class="g2a-tc-field" style="margin-bottom:14px;">';
	echo '<label style="display:block;font-weight:600;margin-bottom:6px;">' . esc_html( $label ) . '</label>';

	switch ( $type ) {
		case 'textarea':
			echo '<textarea name="' . esc_attr( $id ) . '" rows="4" style="width:100%;">' . esc_textarea( (string) $value ) . '</textarea>';
			break;
		case 'image':
			echo '<input type="text" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '" style="width:80%;margin-right:8px;" />';
			echo '<button class="button g2a-tc-upload">Upload</button>';
			echo '<div class="g2a-tc-preview" style="margin-top:8px;">';
			if ( $value ) {
				echo wp_get_attachment_image( (int) $value, 'thumbnail' );
			}
			echo '</div>';
			break;
		case 'repeater':
			$subfields = isset( $field['fields'] ) ? $field['fields'] : [];
			$rows      = is_array( $value ) ? $value : [];
			echo '<div class="g2a-tc-repeater">';
			echo '<div class="g2a-tc-repeater-rows">';
			foreach ( $rows as $index => $row ) {
				g2a_tc_render_repeater_row( $id, $subfields, $index, $row );
			}
			echo '</div>';
			echo '<script type="text/html" class="g2a-tc-repeater-template">';
			g2a_tc_render_repeater_row( $id, $subfields, '__INDEX__', [] );
			echo '</script>';
			echo '<p><button class="button g2a-tc-repeater-add">Add Row</button></p>';
			echo '</div>';
			break;
		default:
			echo '<input type="text" name="' . esc_attr( $id ) . '" value="' . esc_attr( (string) $value ) . '" style="width:100%;" />';
			break;
	}

	echo '</div>';
}

function g2a_tc_render_repeater_row( $id, $subfields, $index, $row ) {
	echo '<div class="g2a-tc-repeater-row" style="padding:10px;border:1px solid #ddd;margin-bottom:8px;">';
	foreach ( $subfields as $sub ) {
		$sub_id = $sub['id'];
		$label  = $sub['label'];
		$val    = isset( $row[ $sub_id ] ) ? $row[ $sub_id ] : '';
		echo '<p><label style="display:block;">' . esc_html( $label ) . '</label>';
		echo '<input type="text" name="' . esc_attr( $id ) . '[' . esc_attr( $index ) . '][' . esc_attr( $sub_id ) . ']" value="' . esc_attr( (string) $val ) . '" style="width:100%;" /></p>';
	}
	echo '<p><button class="button-link-delete g2a-tc-repeater-remove">Remove</button></p>';
	echo '</div>';
}
