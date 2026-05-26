<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function g2a_tc_save_fields( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['g2a_tc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['g2a_tc_nonce'] ) ), 'g2a_tc_save_fields' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$template = g2a_tc_resolve_template_key( $post_id );
	$sections = g2a_tc_get_fields_config( $template, $post_id );
	if ( empty( $sections ) ) {
		return;
	}

	foreach ( $sections as $fields ) {
		foreach ( $fields as $field ) {
			$id = $field['id'];
			if ( ! isset( $_POST[ $id ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $id ] );
			$san = g2a_tc_sanitize_field( $raw, $field );
			update_post_meta( $post_id, $id, $san );
		}
	}
}
add_action( 'save_post_page', 'g2a_tc_save_fields' );

function g2a_tc_sanitize_field( $value, $field ) {
	switch ( $field['type'] ) {
		case 'textarea':
			return sanitize_textarea_field( $value );
		case 'image':
			return absint( $value );
		case 'repeater':
			$clean = [];
			if ( is_array( $value ) ) {
				foreach ( $value as $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					$item = [];
					foreach ( $field['fields'] as $sub ) {
						$sub_id = $sub['id'];
						$sub_val = isset( $row[ $sub_id ] ) ? $row[ $sub_id ] : '';
						$item[ $sub_id ] = 'url' === $sub['type'] ? esc_url_raw( $sub_val ) : sanitize_text_field( $sub_val );
					}
					$clean[] = $item;
				}
			}
			return $clean;
		default:
			if ( isset( $field['sanitize'] ) && 'url' === $field['sanitize'] ) {
				return esc_url_raw( $value );
			}
			return sanitize_text_field( $value );
	}
}
