<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function g2a_tc_fields_config() {
	return include G2A_TC_PATH . 'includes/fields-config.php';
}

function g2a_tc_resolve_template_key( $post_id ) {
	$template = get_page_template_slug( $post_id );
	if ( empty( $template ) ) {
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id > 0 && $front_id === (int) $post_id ) {
			return 'front-page.php';
		}
		return '__default__';
	}
	return $template;
}

function g2a_tc_get_fields_config( $template, $post_id = 0 ) {
	$config = g2a_tc_fields_config();
	$base   = isset( $config['__default__'] ) ? $config['__default__'] : [];

	if ( ! empty( $post_id ) ) {
		$template = g2a_tc_resolve_template_key( $post_id );
	}

	if ( isset( $config[ $template ] ) && is_array( $config[ $template ] ) ) {
		foreach ( $config[ $template ] as $section => $fields ) {
			if ( isset( $base[ $section ] ) && is_array( $base[ $section ] ) ) {
				$base[ $section ] = array_merge( $base[ $section ], $fields );
			} else {
				$base[ $section ] = $fields;
			}
		}
	}

	return $base;
}

function g2a_tc_get_value( $post_id, $field_id, $default = '' ) {
	$value = get_post_meta( $post_id, $field_id, true );
	return '' !== $value && null !== $value ? $value : $default;
}
