<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function g2a_tc_meta_text( $post_id, $key, $default = '' ) {
	$val = get_post_meta( $post_id, $key, true );
	return '' !== $val ? $val : $default;
}

function g2a_tc_meta_image( $post_id, $key, $size = 'full', $attrs = [] ) {
	$attachment_id = absint( get_post_meta( $post_id, $key, true ) );
	if ( ! $attachment_id ) {
		return '';
	}
	$attrs['loading'] = 'lazy';
	return wp_get_attachment_image( $attachment_id, $size, false, $attrs );
}

function g2a_tc_meta_repeater( $post_id, $key ) {
	$rows = get_post_meta( $post_id, $key, true );
	return is_array( $rows ) ? $rows : [];
}
