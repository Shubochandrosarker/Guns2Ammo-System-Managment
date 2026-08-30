<?php
/**
 * Plugin Name: G2A Canonical Consistency
 * Description: Provides a core canonical fallback for singular content while the theme remains the sole rendered canonical owner.
 * Version:     1.1.0
 * Author:      WordPressistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The single correct address for a singular object.
 *
 * @return string Canonical URL, or '' when not applicable.
 */
function g2a_canonical_url() {
	if ( ! is_singular() || is_front_page() ) {
		return '';
	}

	$id = get_queried_object_id();
	if ( ! $id ) {
		return '';
	}

	$permalink = get_permalink( $id );

	return $permalink ? $permalink : '';
}

// The theme emits the canonical tag. This filter only protects WordPress core
// consumers that call get_canonical_url() directly.
add_filter(
	'get_canonical_url',
	function ( $canonical, $post ) {
		if ( ! $post ) {
			return $canonical;
		}

		$permalink = get_permalink( $post->ID );

		return $permalink ? $permalink : $canonical;
	},
	20,
	2
);
