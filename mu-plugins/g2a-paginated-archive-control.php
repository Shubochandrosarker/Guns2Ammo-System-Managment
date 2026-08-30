<?php
/**
 * Plugin Name: G2A Paginated Archive Control
 * Description: Keeps page 2+ of shop, category and brand archives out of the index while staying crawlable. Making 9,451 products indexable also made ~3,224 paginated grid URLs indexable, every one of them sharing page 1's title. Product discovery is fully covered by /sitemap-products.xml, so these pages cost crawl budget on a host that 502s and add nothing to the index. Titles are also made unique as a second line of defence.
 * Version:     1.0.0
 * Author:      WordPressistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Is this a paginated commerce archive (page 2 or later)?
 *
 * @return bool
 */
function g2a_is_paged_commerce_archive() {
	if ( is_admin() || ! is_paged() ) {
		return false;
	}

	if ( function_exists( 'is_shop' ) && is_shop() ) {
		return true;
	}

	if ( function_exists( 'is_product_category' ) && is_product_category() ) {
		return true;
	}

	if ( function_exists( 'is_product_tag' ) && is_product_tag() ) {
		return true;
	}

	if ( is_tax( 'product_brand' ) ) {
		return true;
	}

	// Blog and taxonomy archives beyond page 1 are the same story.
	return is_home() || is_category() || is_tag() || is_author() || is_date();
}

/**
 * noindex, follow on page 2+. "follow" matters: links out of these pages
 * must still pass equity to the products they list.
 */
add_filter(
	'rank_math/frontend/robots',
	function ( $robots ) {
		if ( ! g2a_is_paged_commerce_archive() ) {
			return $robots;
		}

		unset( $robots['index'] );
		$robots['noindex'] = 'noindex';
		$robots['follow']  = 'follow';

		return $robots;
	},
	30
);

// Core fallback if Rank Math is ever disabled.
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( ! g2a_is_paged_commerce_archive() ) {
			return $robots;
		}

		$robots['noindex'] = true;
		$robots['follow']  = true;

		return $robots;
	},
	30
);

/**
 * Unique title per page. The theme's curated map keys on the exact path, so
 * /shop/page/2/ silently fell back to the generic archive title — leaving
 * hundreds of pages sharing one <title>.
 *
 * Runs at 60, after the theme's map filter at 50.
 */
add_filter(
	'pre_get_document_title',
	function ( $title ) {
		if ( ! g2a_is_paged_commerce_archive() ) {
			return $title;
		}

		$page = (int) max( get_query_var( 'paged' ), get_query_var( 'page' ), 1 );
		if ( $page < 2 ) {
			return $title;
		}

		global $wp_query;
		$total = isset( $wp_query->max_num_pages ) ? (int) $wp_query->max_num_pages : 0;

		$suffix = $total > 1
			? sprintf( ' — Page %d of %d', $page, $total )
			: sprintf( ' — Page %d', $page );

		// Insert before the brand separator so the suffix is never truncated
		// away ahead of the page number.
		if ( false !== strpos( $title, ' | ' ) ) {
			$parts = explode( ' | ', $title, 2 );
			return $parts[0] . $suffix . ' | ' . $parts[1];
		}

		return $title . $suffix;
	},
	60
);
