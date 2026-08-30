<?php
/**
 * Plugin Name: G2A Product Index Gate
 * Description: Promotes only quality-gated products to index and explicitly noindexes every product that does not pass.
 * Version:     1.1.0
 * Author:      WordPressistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'G2A_IDX_META', '_g2a_indexable' );

/**
 * Decide whether one product is good enough to be indexed.
 *
 * Deliberately strict. Indexing thin, duplicated vendor-feed pages at scale is
 * the classic way to depress sitewide quality signals, so a product has to earn
 * its place: real stock, a real price, an image, its own short description and
 * a title and description shared with nothing else in the catalogue.
 *
 * @param int $post_id Product ID.
 * @return bool
 */
function g2a_idx_is_indexable( $post_id ) {
	global $wpdb;

	$post_id = (int) $post_id;
	$post    = get_post( $post_id );

	if ( ! $post || 'product' !== $post->post_type || 'publish' !== $post->post_status ) {
		return false;
	}

	// Must carry its own short description.
	$excerpt = trim( (string) $post->post_excerpt );
	if ( mb_strlen( $excerpt ) < 80 ) {
		return false;
	}

	// Must have a real price.
	$price = get_post_meta( $post_id, '_price', true );
	if ( '' === $price || (float) $price <= 0 ) {
		return false;
	}

	// Must be purchasable.
	$stock = get_post_meta( $post_id, '_stock_status', true );
	if ( 'instock' !== $stock && 'onbackorder' !== $stock ) {
		return false;
	}

	// Must have a main image.
	if ( ! get_post_meta( $post_id, '_thumbnail_id', true ) ) {
		return false;
	}

	// Title must be unique across published products.
	$title_dupes = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_title=%s",
			$post->post_title
		)
	);
	if ( $title_dupes > 1 ) {
		return false;
	}

	// Short description must be unique too.
	$excerpt_dupes = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' AND post_excerpt=%s",
			$post->post_excerpt
		)
	);
	if ( $excerpt_dupes > 1 ) {
		return false;
	}

	/**
	 * Filter the final indexability verdict for a product.
	 *
	 * @param bool $ok      Whether the product may be indexed.
	 * @param int  $post_id Product ID.
	 */
	return (bool) apply_filters( 'g2a_product_is_indexable', true, $post_id );
}

/**
 * Recompute and store the flag for one product.
 *
 * @param int $post_id Product ID.
 * @return bool The stored verdict.
 */
function g2a_idx_refresh( $post_id ) {
	$ok = g2a_idx_is_indexable( $post_id );

	if ( $ok ) {
		update_post_meta( $post_id, G2A_IDX_META, '1' );
	} else {
		delete_post_meta( $post_id, G2A_IDX_META );
	}

	return $ok;
}

// Keep the flag honest when a product is edited.
add_action( 'save_post_product', 'g2a_idx_refresh', 20, 1 );
add_action( 'woocommerce_update_product', 'g2a_idx_refresh', 20, 1 );

/**
 * Fail-safe robots policy. Products must earn indexability; the behavior does
 * not depend on a Rank Math global setting that an administrator could change.
 */
add_filter(
	'rank_math/frontend/robots',
	function ( $robots ) {
		if ( ! is_singular( 'product' ) ) {
			return $robots;
		}

		$id = get_queried_object_id();
		if ( '1' !== (string) get_post_meta( $id, G2A_IDX_META, true ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = 'noindex';
			$robots['follow']  = 'follow';
			return $robots;
		}

		unset( $robots['noindex'] );
		$robots['index']             = 'index';
		$robots['follow']            = 'follow';
		$robots['max-image-preview'] = 'max-image-preview:large';
		$robots['max-snippet']       = 'max-snippet:-1';

		return $robots;
	},
	20
);

/**
 * Core fallback and second line of defence when Rank Math is disabled.
 */
add_filter(
	'wp_robots',
	function ( $robots ) {
		if ( ! is_singular( 'product' ) ) {
			return $robots;
		}

		$id = get_queried_object_id();
		if ( '1' !== (string) get_post_meta( $id, G2A_IDX_META, true ) ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
			return $robots;
		}

		unset( $robots['noindex'] );
		$robots['index']             = true;
		$robots['follow']            = true;
		$robots['max-image-preview'] = 'large';
		$robots['max-snippet']       = -1;
		return $robots;
	},
	20
);

/**
 * The theme serves /sitemap-products.xml from a plain WP_Query. Restrict that
 * query to indexable products so the sitemap and the robots tag agree — a
 * sitemap full of noindex URLs is a contradictory signal and wasted crawl.
 */
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() || 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $uri, 'sitemap-products' ) ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array();
		}
		$meta_query[] = array(
			'key'     => G2A_IDX_META,
			'value'   => '1',
			'compare' => '=',
		);
		$query->set( 'meta_query', $meta_query );
	},
	20
);

/**
 * Batch recompute. Returns [processed, indexable_in_batch, next_offset].
 *
 * @param int $offset Offset.
 * @param int $limit  Batch size.
 * @return array
 */
function g2a_idx_batch( $offset = 0, $limit = 500 ) {
	global $wpdb;

	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT %d OFFSET %d",
			$limit,
			$offset
		)
	);

	$ok = 0;
	foreach ( $ids as $id ) {
		if ( g2a_idx_refresh( (int) $id ) ) {
			$ok++;
		}
	}

	return array( count( $ids ), $ok, $offset + count( $ids ) );
}

/* ------------------------------------------------------------------
 * Keep _g2a_sitemap_cat in step with the product's categories.
 *
 * Added 2026-08-20. The per-category product sitemaps key off this
 * meta, but nothing maintained it after the initial backfill — so any
 * product added by the vendor feed would land in the flat sitemap and
 * in NO category sitemap. The category sitemaps are what is submitted
 * to Search Console, so those products would never be discovered.
 * ------------------------------------------------------------------ */
if ( ! defined( 'G2A_IDX_SITEMAP_CAT_META' ) ) {
	define( 'G2A_IDX_SITEMAP_CAT_META', '_g2a_sitemap_cat' );
}

/**
 * Resolve a product's primary TOP-LEVEL product_cat slug.
 * Prefers Rank Math's primary-category selection when one is set.
 */
function g2a_idx_primary_top_cat( $post_id ) {
	$terms = wp_get_post_terms( (int) $post_id, 'product_cat', array( 'fields' => 'all' ) );

	if ( is_wp_error( $terms ) || empty( $terms ) ) {
		return '';
	}

	$primary = (int) get_post_meta( $post_id, 'rank_math_primary_product_cat', true );
	$chosen  = null;

	if ( $primary ) {
		foreach ( $terms as $t ) {
			if ( (int) $t->term_id === $primary ) {
				$chosen = $t;
				break;
			}
		}
	}

	if ( ! $chosen ) {
		$chosen = $terms[0];
	}

	// Walk to the top of the tree — sitemaps are split by top-level category.
	$guard = 0;
	while ( $chosen && (int) $chosen->parent && $guard < 10 ) {
		$parent = get_term( (int) $chosen->parent, 'product_cat' );
		if ( ! $parent || is_wp_error( $parent ) ) {
			break;
		}
		$chosen = $parent;
		$guard++;
	}

	return ( $chosen && ! empty( $chosen->slug ) ) ? (string) $chosen->slug : '';
}

function g2a_idx_sync_sitemap_cat( $post_id ) {
	$post_id = (int) $post_id;
	$slug    = g2a_idx_primary_top_cat( $post_id );

	if ( '' === $slug ) {
		delete_post_meta( $post_id, G2A_IDX_SITEMAP_CAT_META );
		return '';
	}

	// update_post_meta collapses to a single row, so a duplicated meta
	// row can never reappear and double-list a product in a sitemap.
	update_post_meta( $post_id, G2A_IDX_SITEMAP_CAT_META, $slug );

	return $slug;
}

add_action( 'save_post_product', 'g2a_idx_sync_sitemap_cat', 21, 1 );
add_action( 'woocommerce_update_product', 'g2a_idx_sync_sitemap_cat', 21, 1 );
add_action( 'set_object_terms', 'g2a_idx_sync_sitemap_cat_on_terms', 10, 6 );

function g2a_idx_sync_sitemap_cat_on_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
	if ( 'product_cat' !== $taxonomy ) {
		return;
	}
	if ( 'product' !== get_post_type( $object_id ) ) {
		return;
	}
	g2a_idx_sync_sitemap_cat( $object_id );
}
