<?php
/**
 * Shared public/private and indexability policy.
 *
 * Sitemaps, meta robots, robots.txt and AI-facing indexes must make the same
 * decision about utility, transactional and public content.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress page slugs that are transactional, authenticated, operational or
 * template placeholders rather than public search landing pages.
 *
 * @return array<int, string>
 */
function g2a_private_page_slugs() {
	$slugs = array(
		'account',
		'my-account',
		'cart',
		'g2a-cart',
		'checkout',
		'g2a-checkout',
		'login',
		'g2a-members-login',
		'g2a-admin-login',
		'thank-you',
		'payment-failed',
		'staff',
		'staff-dashboard',
		'memberistic-staff-dashboard',
		'memberistic-account',
		'memberistic-checkout',
		'membership-checkout',
		'membership-checkout-page',
		'renew-membership',
		'renewal',
		'dealer-portal',
		'check-in',
		'check-in-2',
		'single-product',
		'post',
	);

	return array_values( array_unique( array_map( 'sanitize_title', (array) apply_filters( 'g2a_private_page_slugs', $slugs ) ) ) );
}

/**
 * Path/query patterns to keep away from crawlers. This is crawl guidance, not
 * access control; the underlying routes must still enforce authentication.
 *
 * @return array<int, string>
 */
function g2a_private_robots_paths() {
	$paths = array(
		'/wp-admin/',
		'/wp-login.php',
		'/wp-json/',
		'/xmlrpc.php',
		'/?s=',
		'/?add-to-cart=',
		'/?remove_item=',
		'/wp-content/uploads/wc-logs/',
		'/wp-content/uploads/woocommerce_uploads/',
	);
	foreach ( g2a_private_page_slugs() as $slug ) {
		$paths[] = '/' . $slug . '/';
	}

	return array_values( array_unique( (array) apply_filters( 'g2a_private_robots_paths', $paths ) ) );
}

/**
 * Whether a post/page may appear in public indexes and machine-readable feeds.
 *
 * @param WP_Post|int|null $post Post object or ID.
 * @return bool
 */
function g2a_post_is_public_indexable( $post = null ) {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
		return false;
	}

	if ( 'page' === $post->post_type && in_array( $post->post_name, g2a_private_page_slugs(), true ) ) {
		return false;
	}

	$robots = (array) get_post_meta( $post->ID, 'rank_math_robots', true );
	if ( in_array( 'noindex', $robots, true ) ) {
		return false;
	}

	$url = get_permalink( $post );
	if ( ! $url ) {
		return false;
	}
	if ( function_exists( 'g2a_sitemap_is_redirected' ) && g2a_sitemap_is_redirected( $url ) ) {
		return false;
	}

	return (bool) apply_filters( 'g2a_post_is_public_indexable', true, $post );
}

/**
 * Reliable content freshness timestamp. Scheduled/imported content can carry a
 * modified time older than its publication time, so never publish an inverted
 * date sequence in schema or sitemaps.
 *
 * @param WP_Post|int|null $post Post object or ID.
 * @return int Unix timestamp in UTC.
 */
function g2a_post_lastmod_timestamp( $post = null ) {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post ) {
		return 0;
	}
	return max(
		(int) get_post_time( 'U', true, $post ),
		(int) get_post_modified_time( 'U', true, $post )
	);
}

/**
 * Whether a public taxonomy archive may be indexed.
 *
 * @param WP_Term|int $term     Term object or ID.
 * @param string      $taxonomy Taxonomy when an ID is supplied.
 * @return bool
 */
function g2a_term_is_public_indexable( $term, $taxonomy = '' ) {
	$term = get_term( $term, $taxonomy );
	if ( is_wp_error( $term ) || ! ( $term instanceof WP_Term ) || (int) $term->count < 1 ) {
		return false;
	}
	$robots = (array) get_term_meta( $term->term_id, 'rank_math_robots', true );
	if ( in_array( 'noindex', $robots, true ) ) {
		return false;
	}
	$url = get_term_link( $term );
	if ( is_wp_error( $url ) || ! $url ) {
		return false;
	}
	if ( function_exists( 'g2a_sitemap_is_redirected' ) && g2a_sitemap_is_redirected( $url ) ) {
		return false;
	}
	return (bool) apply_filters( 'g2a_term_is_public_indexable', true, $term );
}

/**
 * Current request is a recognized commerce filter/sort variant.
 *
 * @return bool
 */
function g2a_is_filtered_commerce_archive() {
	$is_commerce_archive = ( function_exists( 'is_shop' ) && is_shop() )
		|| ( function_exists( 'is_product_category' ) && is_product_category() )
		|| ( function_exists( 'is_product_tag' ) && is_product_tag() )
		|| is_tax( 'product_brand' );
	if ( ! $is_commerce_archive ) {
		return false;
	}

	$filter_keys = array( 'stock', 'min_price', 'max_price', 'orderby', 'rating_filter' );
	foreach ( array_keys( $_GET ) as $key ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only request classification.
		$key = (string) $key;
		if ( in_array( $key, $filter_keys, true ) || 0 === strpos( $key, 'filter_' ) || 0 === strpos( $key, 'query_type_' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Current page is a known private/transactional route.
 *
 * @return bool
 */
function g2a_is_private_request() {
	if ( is_page() ) {
		$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
		if ( in_array( $slug, g2a_private_page_slugs(), true ) ) {
			return true;
		}
	}
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return true;
	}
	return false;
}
