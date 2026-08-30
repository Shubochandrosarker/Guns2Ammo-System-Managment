<?php
/**
 * Branded XML sitemap + human HTML sitemap.
 *
 * Coexistence model: this file is the SINGLE OWNER of /sitemap.xml
 * on the public site. To make sure that's true regardless of
 * RankMath / Yoast / WP-core sitemap state, we:
 *
 *   1. Disable WP core's wp_sitemaps_enabled (the `/wp-sitemap.xml`).
 *   2. Disable RankMath's sitemap module via its own filter.
 *   3. Intercept the URL DIRECTLY in `parse_request` priority 0 —
 *      not through `add_rewrite_rule`. Rewrite rules require a
 *      manual Permalinks → Save to flush, and the client just
 *      reported the URL showing a white page (rewrites hadn't
 *      flushed). The direct intercept needs zero setup and ignores
 *      whatever rewrite state the site is in.
 *
 * Exposes:
 *   /sitemap.xml              — master index
 *   /sitemap-pages.xml        — published pages (no account/cart/etc.)
 *   /sitemap-posts.xml        — blog posts
 *   /sitemap-products.xml     — WooCommerce products
 *   /sitemap-product-cats.xml — product categories
 *   /sitemap-faqs.xml         — /faqs/
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ============================================================
 * Earliest possible intercept.
 *
 * Runs when the theme's functions.php require_once's this file —
 * before parse_request, before init, before anything else WP might
 * hook. Catches the case where a competing rewrite rule (.htaccess
 * or a plugin) would otherwise eat /sitemap.xml or send it through
 * a different handler.
 *
 * Only fires for the sitemap URLs; every other request returns
 * immediately so this costs effectively nothing.
 * ============================================================ */
/*
 * NOTE (WordPressistic, 2026-08-20): the include-time call was removed.
 * inc/sitemap.php is required from functions.php, which runs BEFORE `init`.
 * WooCommerce registers the `product` post type and `product_cat` taxonomy on
 * `init`, so at include time post_type_exists('product') was false and both
 * /sitemap-products.xml and /sitemap-product-cats.xml rendered an EMPTY urlset
 * and exited — which also pre-empted the parse_request intercept below.
 * The parse_request handler fires after init and renders these correctly.
 */
// g2a_sitemap_maybe_serve(); // disabled — see note above.
function g2a_sitemap_maybe_serve() {
	$uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '';
	if ( '' === $uri ) {
		return;
	}
	$uri = strtolower( rtrim( $uri, '/' ) );
	$map = array(
		'/sitemap.xml'              => 'index',
		'/sitemap-pages.xml'        => 'pages',
		'/sitemap-posts.xml'        => 'posts',
		'/sitemap-products.xml'     => 'products',
		'/sitemap-product-cats.xml' => 'productcats',
		'/sitemap-brands.xml'       => 'brands',
		'/sitemap-faqs.xml'         => 'faqs',
	);
	$map = array_merge( $map, g2a_sitemap_product_cat_routes() );
	if ( ! isset( $map[ $uri ] ) ) {
		return;
	}
	// WP may not be fully bootstrapped yet — load enough for our
	// queries to run. is_user_logged_in / get_permalink etc.
	// require the full WP environment, but functions.php only loads
	// after WP_Query is set up, so this is safe to call here.
	header( 'Content-Type: application/xml; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: public, max-age=3600' );
	switch ( $map[ $uri ] ) {
		case 'index':       g2a_sitemap_render_index(); break;
		case 'pages':       g2a_sitemap_render_pages(); break;
		case 'posts':       g2a_sitemap_render_posts(); break;
		case 'products':    g2a_sitemap_render_products(); break;
		case 'productcats': g2a_sitemap_render_product_cats(); break;
		case 'brands':      g2a_sitemap_render_brands(); break;
		case 'faqs':        g2a_sitemap_render_faqs(); break;
		default:
			if ( 0 === strpos( (string) $map[ $uri ], 'products:' ) ) {
				g2a_sitemap_render_products( substr( (string) $map[ $uri ], 9 ) );
			}
			break;
	}
	exit;
}

/* Turn off WP core's wp-sitemap.xml — we own /sitemap.xml */
add_filter( 'wp_sitemaps_enabled', '__return_false' );

/* Turn off RankMath's sitemap module if RM is active. */
add_filter( 'rank_math/sitemap/enable', '__return_false', 99 );
add_filter( 'rank_math/sitemap/index/entry', '__return_empty_array', 99 );

/* Direct URL intercept — fires before any rewrite / template
   resolution happens, so no permalink flush required. */
add_action( 'parse_request', 'g2a_sitemap_intercept', 0 );
function g2a_sitemap_intercept( $wp ) {
	$uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '';
	if ( '' === $uri ) {
		return;
	}
	$uri = strtolower( rtrim( $uri, '/' ) );

	$map = array(
		'/sitemap.xml'              => 'index',
		'/sitemap-pages.xml'        => 'pages',
		'/sitemap-posts.xml'        => 'posts',
		'/sitemap-products.xml'     => 'products',
		'/sitemap-product-cats.xml' => 'productcats',
		'/sitemap-brands.xml'       => 'brands',
		'/sitemap-faqs.xml'         => 'faqs',
	);
	$map = array_merge( $map, g2a_sitemap_product_cat_routes() );
	if ( ! isset( $map[ $uri ] ) ) {
		return;
	}

	header( 'Content-Type: application/xml; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: public, max-age=3600' );

	switch ( $map[ $uri ] ) {
		case 'index':       g2a_sitemap_render_index(); break;
		case 'pages':       g2a_sitemap_render_pages(); break;
		case 'posts':       g2a_sitemap_render_posts(); break;
		case 'products':    g2a_sitemap_render_products(); break;
		case 'productcats': g2a_sitemap_render_product_cats(); break;
		case 'brands':      g2a_sitemap_render_brands(); break;
		case 'faqs':        g2a_sitemap_render_faqs(); break;
		default:
			if ( 0 === strpos( (string) $map[ $uri ], 'products:' ) ) {
				g2a_sitemap_render_products( substr( (string) $map[ $uri ], 9 ) );
			}
			break;
	}
	exit;
}

function g2a_sitemap_open() {
	// `<` and `?xml` are split so the PHP tokenizer can never read
	// the bytes as an open-tag marker even on hosts with
	// short_open_tag=On (which kills the site otherwise).
	echo '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' . "\n";
	echo '<' . '?xml-stylesheet type="text/xsl" href="' . esc_url( home_url( '/?g2a_sitemap_xsl=1' ) ) . '"?' . '>' . "\n";
}

/**
 * Real "last content change" timestamp for one sitemap slice, instead of
 * stamping every sub-sitemap with the current request time regardless of
 * whether anything in it actually changed. Empty content types omit lastmod
 * rather than pretending they changed at request time.
 * Cached for an hour alongside the other sitemap transients.
 */
function g2a_sitemap_last_modified( $post_type ) {
	global $wpdb;

	$cache_key = 'g2a_sitemap_lastmod_' . $post_type;
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$latest = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(GREATEST(post_date_gmt, post_modified_gmt))
			 FROM {$wpdb->posts}
			 WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		)
	);
	$lastmod = $latest ? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $latest ) ) : '';

	set_transient( $cache_key, $lastmod, HOUR_IN_SECONDS );
	return $lastmod;
}

/* Invalidate the per-post-type freshness cache as soon as content actually
   changes, rather than waiting up to an hour for a stale lastmod to clear. */
add_action( 'save_post', function ( $post_id ) {
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	delete_transient( 'g2a_sitemap_lastmod_' . get_post_type( $post_id ) );
} );
add_action( 'deleted_post', function ( $post_id ) {
	delete_transient( 'g2a_sitemap_lastmod_' . get_post_type( $post_id ) );
} );

function g2a_sitemap_render_index() {
	g2a_sitemap_open();
	echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	$base = trailingslashit( home_url() );
	$faq_page = get_page_by_path( 'faqs' );
	$faq_lastmod = ( $faq_page && function_exists( 'g2a_post_lastmod_timestamp' ) )
		? gmdate( 'Y-m-d\TH:i:s+00:00', g2a_post_lastmod_timestamp( $faq_page ) )
		: '';
	$product_lastmod = post_type_exists( 'product' ) ? g2a_sitemap_last_modified( 'product' ) : '';
	$lastmods = array(
		'sitemap-pages.xml'        => g2a_sitemap_last_modified( 'page' ),
		'sitemap-posts.xml'        => g2a_sitemap_last_modified( 'post' ),
		// Products are split per top-level category (2026-08-20) so Search
		// Console reports coverage per category instead of one opaque
		// "9,449 submitted" figure. A sitemap index may not contain another
		// index, so the children are listed here directly.
		'sitemap-product-cats.xml' => $product_lastmod,
		'sitemap-brands.xml'       => $product_lastmod,
		'sitemap-faqs.xml'         => $faq_lastmod,
	);
	if ( post_type_exists( 'product' ) ) {
		foreach ( g2a_sitemap_product_cat_slugs() as $cat_slug ) {
			$lastmods[ 'sitemap-products-' . $cat_slug . '.xml' ] = g2a_sitemap_product_slice_lastmod( $cat_slug );
		}
	}

	foreach ( $lastmods as $slug => $lastmod ) {
		echo '<sitemap><loc>' . esc_url( $base . $slug ) . '</loc>';
		if ( $lastmod ) {
			echo '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';
		}
		echo '</sitemap>';
	}
	echo '</sitemapindex>';
}

function g2a_sitemap_url_node( $loc, $lastmod = '', $priority = '', $changefreq = '' ) {
	echo '<url><loc>' . esc_url( $loc ) . '</loc>';
	if ( $lastmod )    echo '<lastmod>' . esc_html( $lastmod ) . '</lastmod>';
	if ( $changefreq ) echo '<changefreq>' . esc_html( $changefreq ) . '</changefreq>';
	if ( $priority )   echo '<priority>' . esc_html( $priority ) . '</priority>';
	echo '</url>';
}

function g2a_sitemap_render_pages() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	$front_id = (int) get_option( 'page_on_front' );
	$front    = $front_id ? get_post( $front_id ) : null;
	$front_ts = ( $front && function_exists( 'g2a_post_lastmod_timestamp' ) ) ? g2a_post_lastmod_timestamp( $front ) : 0;
	g2a_sitemap_url_node( home_url( '/' ), $front_ts ? gmdate( 'Y-m-d', $front_ts ) : '', '1.0', 'daily' );
	$q = new WP_Query( array(
		'post_type'      => 'page',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	) );
	foreach ( $q->posts as $p ) {
		if ( $front_id === (int) $p->ID ) {
			continue;
		}
		if ( function_exists( 'g2a_post_is_public_indexable' ) && ! g2a_post_is_public_indexable( $p ) ) {
			continue;
		}
		$timestamp = function_exists( 'g2a_post_lastmod_timestamp' )
			? g2a_post_lastmod_timestamp( $p )
			: (int) get_post_modified_time( 'U', true, $p );
		g2a_sitemap_url_node(
			get_permalink( $p ),
			$timestamp ? gmdate( 'Y-m-d', $timestamp ) : '',
			'0.7',
			'weekly'
		);
	}
	echo '</urlset>';
}

/**
 * True when a URL is the SOURCE of an active redirect.
 *
 * Added 2026-08-20. Four posts consolidated yesterday were still listed in
 * sitemap-posts.xml while 301-ing elsewhere — telling Google to crawl a URL
 * and then bouncing it. Sitemaps must only ever contain 200-status,
 * canonical, indexable URLs.
 *
 * Reads Rank Math's redirection table directly and caches for an hour;
 * the cache is dropped whenever a redirect is saved.
 */
function g2a_sitemap_redirect_sources() {
	$cached = get_transient( 'g2a_sitemap_redirect_srcs' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;
	$table = $wpdb->prefix . 'rank_math_redirections';
	$srcs  = array();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- table name is not user input.
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$rows = $wpdb->get_col( "SELECT sources FROM {$table} WHERE status = 'active'" );
		foreach ( (array) $rows as $blob ) {
			$parsed = maybe_unserialize( $blob );
			foreach ( (array) $parsed as $s ) {
				if ( ! empty( $s['pattern'] ) && ( empty( $s['comparison'] ) || 'exact' === $s['comparison'] ) ) {
					$srcs[ trim( strtolower( (string) $s['pattern'] ), '/' ) ] = true;
				}
			}
		}
	}

	// The theme keeps its own literal redirect map alongside Rank Math's.
	if ( function_exists( 'apply_filters' ) ) {
		foreach ( (array) apply_filters( 'g2a_redirect_map', array() ) as $from => $to ) {
			$srcs[ trim( strtolower( (string) $from ), '/' ) ] = true;
		}
	}

	set_transient( 'g2a_sitemap_redirect_srcs', $srcs, HOUR_IN_SECONDS );
	return $srcs;
}

add_action( 'rank_math/redirection/saved', 'g2a_sitemap_flush_redirect_srcs' );
function g2a_sitemap_flush_redirect_srcs() {
	delete_transient( 'g2a_sitemap_redirect_srcs' );
}

function g2a_sitemap_is_redirected( $url ) {
	$srcs = g2a_sitemap_redirect_sources();
	if ( empty( $srcs ) ) {
		return false;
	}
	$path = trim( strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );
	return isset( $srcs[ $path ] );
}
function g2a_sitemap_render_posts() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	$q = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	) );
	foreach ( $q->posts as $p ) {
		if ( function_exists( 'g2a_post_is_public_indexable' ) && ! g2a_post_is_public_indexable( $p ) ) {
			continue;
		}
		$g2a_loc   = get_permalink( $p );
		$timestamp = function_exists( 'g2a_post_lastmod_timestamp' )
			? g2a_post_lastmod_timestamp( $p )
			: (int) get_post_modified_time( 'U', true, $p );
		g2a_sitemap_url_node(
			$g2a_loc,
			$timestamp ? gmdate( 'Y-m-d', $timestamp ) : '',
			'0.6',
			'monthly'
		);
	}
	echo '</urlset>';
}

/**
 * Top-level product-category slugs that currently hold indexable products.
 *
 * Backed by the `_g2a_sitemap_cat` post meta written by the index gate, so this
 * is a single indexed lookup rather than a taxonomy walk on every request.
 * Cached for an hour; the gate busts it when a product is saved.
 *
 * @return array<int, string>
 */
function g2a_sitemap_product_cat_slugs() {
	$cached = get_transient( 'g2a_sitemap_prodcats' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	global $wpdb;

	$slugs = $wpdb->get_col(
		"SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_g2a_sitemap_cat' AND meta_value <> '' ORDER BY meta_value ASC"
	);
	$slugs = array_values( array_filter( (array) $slugs ) );

	set_transient( 'g2a_sitemap_prodcats', $slugs, HOUR_IN_SECONDS );

	return $slugs;
}

/**
 * Route map fragment: /sitemap-products-<slug>.xml => 'products:<slug>'.
 *
 * @return array<string, string>
 */
function g2a_sitemap_product_cat_routes() {
	$routes = array();

	foreach ( g2a_sitemap_product_cat_slugs() as $slug ) {
		$routes[ '/sitemap-products-' . $slug . '.xml' ] = 'products:' . $slug;
	}

	return $routes;
}

/**
 * Newest product modification date inside a term, as a freshness signal.
 *
 * WP core does not track term modification dates, but "when did anything in
 * this category last change" is the signal a crawler actually wants.
 *
 * @param string $taxonomy Taxonomy name.
 * @param int    $term_id  Term ID.
 * @return string Y-m-d or ''.
 */
function g2a_sitemap_term_lastmod( $taxonomy, $term_id ) {
	global $wpdb;

	$date = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(p.post_modified_gmt) FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tt.taxonomy = %s AND tt.term_id = %d
			   AND p.post_type = 'product' AND p.post_status = 'publish'",
			$taxonomy,
			$term_id
		)
	);

	return $date ? gmdate( 'Y-m-d', strtotime( $date ) ) : '';
}

/**
 * Newest real modification time for one gated product-sitemap slice.
 *
 * @param string $cat Top-level category slug.
 * @return string ISO-8601 UTC timestamp or empty string.
 */
function g2a_sitemap_product_slice_lastmod( $cat ) {
	global $wpdb;

	$date = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(GREATEST(p.post_date_gmt, p.post_modified_gmt))
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} ix
			   ON ix.post_id = p.ID AND ix.meta_key = '_g2a_indexable' AND ix.meta_value = '1'
			 INNER JOIN {$wpdb->postmeta} sc
			   ON sc.post_id = p.ID AND sc.meta_key = '_g2a_sitemap_cat' AND sc.meta_value = %s
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'",
			$cat
		)
	);

	return $date ? gmdate( 'Y-m-d\TH:i:s+00:00', strtotime( $date ) ) : '';
}

/**
 * Product sitemap, optionally scoped to one top-level category.
 *
 * Carries <image:image> for the main product image. Google reads image data
 * from the product sitemap itself — a separate image sitemap is not required
 * and would duplicate the URL set.
 *
 * Image URLs are resolved with one bulk query rather than an attachment lookup
 * per product; at ~3,200 products in the largest category that is the
 * difference between a fast response and a gateway timeout on this host.
 *
 * @param string $cat Top-level product-category slug, or '' for everything.
 * @return void
 */
/* ============================================================
 * Sitemap disk cache (WordPressistic, 2026-08-20)
 *
 * Why: the product sitemaps are the only expensive routes on the
 * site — /sitemap-products-rifles.xml builds 3,191 permalinks per
 * cold hit (~1.2s) and the deprecated flat file ~10,500 (~3.2s).
 * This host returns intermittent gateway errors under load, and
 * Search Console reported "Couldn't fetch" on the largest child
 * while every smaller sibling succeeded. The XML itself is valid;
 * the exposure is simply how long each response holds a PHP
 * worker open. Caching to disk turns a cold hit into a readfile().
 * ============================================================ */
function g2a_sitemap_cache_dir() {
	$dir = WP_CONTENT_DIR . '/g2a-sitemap-cache';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		// Never linked from anywhere; deny direct HTTP access so the
		// cache can never become a duplicate of the canonical route.
		@file_put_contents(
			$dir . '/.htaccess',
			"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
		);
	}
	return $dir;
}

function g2a_sitemap_cache_ttl() {
	// Backstop only — the hourly warmer below is the primary refresher.
	return (int) apply_filters( 'g2a_sitemap_cache_ttl', DAY_IN_SECONDS );
}

function g2a_sitemap_cache_keys() {
	$keys = array( 'all' );
	foreach ( g2a_sitemap_product_cat_slugs() as $slug ) {
		$keys[] = sanitize_key( $slug );
	}
	return array_values( array_unique( array_filter( $keys ) ) );
}

function g2a_sitemap_cache_file( $cat = '' ) {
	$key = ( '' === $cat ) ? 'all' : sanitize_key( $cat );
	return g2a_sitemap_cache_dir() . '/products-' . $key . '.xml';
}

/**
 * Build the XML for one product sitemap and write it to disk atomically.
 * Returns the XML on success, empty string on failure.
 */
function g2a_sitemap_cache_write( $cat = '' ) {
	ob_start();
	g2a_sitemap_build_products( $cat );
	$xml = (string) ob_get_clean();

	// Never cache a truncated or empty render — a bad cache file would
	// be served for a full day and silently deindex a whole category.
	if ( strlen( $xml ) < 120 || false === strpos( $xml, '</urlset>' ) ) {
		return '';
	}

	$file = g2a_sitemap_cache_file( $cat );
	$tmp  = $file . '.' . wp_generate_password( 8, false ) . '.tmp';

	if ( false !== @file_put_contents( $tmp, $xml ) ) {
		if ( ! @rename( $tmp, $file ) ) {
			@unlink( $tmp );
		}
	} else {
		@unlink( $tmp );
	}

	return $xml;
}

function g2a_sitemap_cache_purge() {
	foreach ( glob( g2a_sitemap_cache_dir() . '/products-*.xml' ) as $f ) {
		@unlink( $f );
	}
}

// Product edits change both gate membership and per-category freshness.
add_action( 'save_post_product', function ( $post_id ) {
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	delete_transient( 'g2a_sitemap_prodcats' );
	delete_transient( 'g2a_sitemap_lastmod_product' );
	g2a_sitemap_cache_purge();
}, 99 );

/**
 * Cache-aware front door. Signature is unchanged, so every existing
 * caller in the route maps keeps working untouched.
 */
function g2a_sitemap_render_products( $cat = '' ) {
	$file = g2a_sitemap_cache_file( $cat );

	if ( is_readable( $file ) && filesize( $file ) > 120
		&& ( time() - (int) filemtime( $file ) ) < g2a_sitemap_cache_ttl() ) {
		readfile( $file );
		return;
	}

	// Stampede guard: if a build is already in flight, serve the stale
	// copy rather than opening a second multi-thousand-permalink build
	// on a host that is already the constraint.
	$lock = 'g2a_smlock_' . ( ( '' === $cat ) ? 'all' : sanitize_key( $cat ) );

	if ( get_transient( $lock ) ) {
		if ( is_readable( $file ) && filesize( $file ) > 120 ) {
			readfile( $file );
			return;
		}
		g2a_sitemap_build_products( $cat );
		return;
	}

	set_transient( $lock, 1, 3 * MINUTE_IN_SECONDS );
	$xml = g2a_sitemap_cache_write( $cat );
	delete_transient( $lock );

	if ( '' !== $xml ) {
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped XML.
		return;
	}

	// Write failed (read-only disk, quota). Serve live rather than 500.
	g2a_sitemap_build_products( $cat );
}

/* ---- Warmer: refresh the single stalest file, hourly ----
 * One file per run keeps every execution ~1-3s, well inside the 30s
 * max_execution_time, and never stacks 13 builds into one request.
 * With 13 files that is a full refresh cycle every ~13 hours against
 * a 24h TTL, so Googlebot should never pay for a build.        */
add_action( 'init', 'g2a_sitemap_cache_schedule' );
function g2a_sitemap_cache_schedule() {
	if ( ! wp_next_scheduled( 'g2a_sitemap_cache_warm' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'g2a_sitemap_cache_warm' );
	}
}

add_action( 'g2a_sitemap_cache_warm', 'g2a_sitemap_cache_warm_stalest' );
function g2a_sitemap_cache_warm_stalest() {
	$stalest = null;
	$oldest  = PHP_INT_MAX;

	foreach ( g2a_sitemap_cache_keys() as $key ) {
		$cat  = ( 'all' === $key ) ? '' : $key;
		$file = g2a_sitemap_cache_file( $cat );
		$mtime = ( is_readable( $file ) && filesize( $file ) > 120 ) ? (int) filemtime( $file ) : 0;

		if ( $mtime < $oldest ) {
			$oldest  = $mtime;
			$stalest = $cat;
		}
	}

	if ( null !== $stalest ) {
		g2a_sitemap_cache_write( $stalest );
	}
}

function g2a_sitemap_build_products( $cat = '' ) {
	global $wpdb;

	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
		. ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">';

	if ( ! post_type_exists( 'product' ) ) {
		echo '</urlset>';
		return;
	}

	$sql = "SELECT DISTINCT p.ID, p.post_title, p.post_date_gmt, p.post_modified_gmt
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} ix ON ix.post_id = p.ID AND ix.meta_key = '_g2a_indexable' AND ix.meta_value = '1'";

	if ( '' !== $cat ) {
		$sql .= $wpdb->prepare(
			" INNER JOIN {$wpdb->postmeta} sc ON sc.post_id = p.ID AND sc.meta_key = '_g2a_sitemap_cat' AND sc.meta_value = %s",
			$cat
		);
	}

	$sql .= " WHERE p.post_type = 'product' AND p.post_status = 'publish' ORDER BY p.post_modified_gmt DESC";

	$rows = $wpdb->get_results( $sql );

	if ( ! $rows ) {
		echo '</urlset>';
		return;
	}

	// Bulk-resolve main images: post_id -> upload-relative file path.
	$ids     = wp_list_pluck( $rows, 'ID' );
	$in      = implode( ',', array_map( 'absint', $ids ) );
	$images  = array();
	$uploads = wp_get_upload_dir();

	if ( '' !== $in ) {
		$img_rows = $wpdb->get_results(
			"SELECT th.post_id AS product_id, af.meta_value AS file
			 FROM {$wpdb->postmeta} th
			 INNER JOIN {$wpdb->postmeta} af ON af.post_id = th.meta_value AND af.meta_key = '_wp_attached_file'
			 WHERE th.meta_key = '_thumbnail_id' AND th.post_id IN ({$in})"
		);

		foreach ( $img_rows as $img ) {
			$images[ (int) $img->product_id ] = (string) $img->file;
		}
	}

	// Products with an empty post_name have no URL of their own —
	// get_permalink() falls back to the shop base. Emitting that would
	// put /shop/ into every affected category sitemap.
	$shop_base = function_exists( 'wc_get_page_permalink' )
		? untrailingslashit( (string) wc_get_page_permalink( 'shop' ) )
		: '';
	$seen = array();

	foreach ( $rows as $row ) {
		$id  = (int) $row->ID;
		$loc = get_permalink( $id );

		if ( ! $loc ) {
			continue;
		}

		if ( '' !== $shop_base && untrailingslashit( $loc ) === $shop_base ) {
			continue;
		}

		if ( isset( $seen[ $loc ] ) ) {
			continue;
		}
		$seen[ $loc ] = true;

		echo '<url><loc>' . esc_url( $loc ) . '</loc>';
		$lastmod = max( strtotime( $row->post_date_gmt ), strtotime( $row->post_modified_gmt ) );
		if ( $lastmod ) {
			echo '<lastmod>' . esc_html( gmdate( 'Y-m-d', $lastmod ) ) . '</lastmod>';
		}
		echo '<changefreq>weekly</changefreq><priority>0.6</priority>';

		if ( ! empty( $images[ $id ] ) ) {
			$img_url = trailingslashit( $uploads['baseurl'] ) . ltrim( $images[ $id ], '/' );
			echo '<image:image><image:loc>' . esc_url( $img_url ) . '</image:loc>'
				. '<image:title>' . esc_html( wp_strip_all_tags( $row->post_title ) ) . '</image:title>'
				. '</image:image>';
		}

		echo '</url>';
	}

	echo '</urlset>';
}
function g2a_sitemap_render_product_cats() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( function_exists( 'g2a_term_is_public_indexable' ) && ! g2a_term_is_public_indexable( $t ) ) {
					continue;
				}
				$url = get_term_link( $t );
				if ( ! is_wp_error( $url ) ) {
					g2a_sitemap_url_node( $url, g2a_sitemap_term_lastmod( 'product_cat', (int) $t->term_id ), '0.5', 'weekly' );
				}
			}
		}
	}
	echo '</urlset>';
}

/**
 * Brand archives — the national landing layer for product search.
 * Added 2026-08-20 (WordPressistic).
 */
function g2a_sitemap_render_brands() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	if ( taxonomy_exists( 'product_brand' ) ) {
		$terms = get_terms( array( 'taxonomy' => 'product_brand', 'hide_empty' => true ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( function_exists( 'g2a_term_is_public_indexable' ) && ! g2a_term_is_public_indexable( $t ) ) {
					continue;
				}
				$url = get_term_link( $t );
				if ( ! is_wp_error( $url ) ) {
					g2a_sitemap_url_node( $url, g2a_sitemap_term_lastmod( 'product_brand', (int) $t->term_id ), '0.6', 'weekly' );
				}
			}
		}
	}
	echo '</urlset>';
}

function g2a_sitemap_render_faqs() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	$page = get_page_by_path( 'faqs' );
	if ( $page && ( ! function_exists( 'g2a_post_is_public_indexable' ) || g2a_post_is_public_indexable( $page ) ) ) {
		$timestamp = function_exists( 'g2a_post_lastmod_timestamp' )
			? g2a_post_lastmod_timestamp( $page )
			: (int) get_post_modified_time( 'U', true, $page );
		g2a_sitemap_url_node( get_permalink( $page ), $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '', '0.8', 'monthly' );
	}
	echo '</urlset>';
}

/* Lightweight XSL stylesheet so the raw XML renders as a readable
   "WordPressistic-style" sitemap table in the browser instead of
   showing as plain XML. Served at /?g2a_sitemap_xsl=1 */
add_action( 'init', function () {
	if ( isset( $_GET['g2a_sitemap_xsl'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		header( 'Content-Type: application/xslt+xml; charset=utf-8' );
		echo g2a_sitemap_xsl(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
} );

function g2a_sitemap_xsl() {
	/*
	 * IMPORTANT: this function MUST NOT mix PHP mode and XML using
	 * ASP-style alternation (close-PHP + raw XML + reopen-PHP).
	 * On hosts with short_open_tag On (still common shared hosting),
	 * the parser exits PHP at the closer, enters HTML, sees the XML
	 * prolog, treats it as a PHP open tag, and chokes on
	 * `version="1.0"` as invalid PHP. Whole site 500s because this
	 * file is included via functions.php on every request.
	 *
	 * Build the XSL as a concatenated string. PHP never leaves the
	 * language; the angle-bracket-question-mark bytes only exist
	 * inside string literals where the tokenizer ignores them.
	 *
	 * (Note: even // line comments terminate at a close-PHP-tag,
	 * so this docblock is a slash-star block — safer.)
	 */
	$brand = esc_html( get_bloginfo( 'name' ) );
	$home  = esc_url( home_url( '/' ) );
	$llms_short = esc_url( home_url( '/llms.txt' ) );
	$llms_full  = esc_url( home_url( '/llms-full.txt' ) );

	$css = 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#0F1115;color:#fff;margin:0;padding:32px;}'
		. '.wrap{max-width:1100px;margin:0 auto;}'
		. '.brand{font-family:Impact,"Bebas Neue",sans-serif;letter-spacing:.04em;font-size:14px;color:#C9A84C;text-transform:uppercase;background:rgba(201,168,76,.12);display:inline-block;padding:6px 14px;border:1px solid #C9A84C;border-radius:999px;margin-bottom:18px;}'
		. 'h1{font-family:Impact,"Bebas Neue",sans-serif;font-size:46px;color:#fff;margin:0 0 8px;letter-spacing:.02em;}'
		. 'h1 span{color:#C9A84C;}'
		. 'p{color:#8E8D96;max-width:60ch;line-height:1.6;}'
		. '.count{color:#C9A84C;font-family:\'Space Mono\',monospace;font-size:13px;letter-spacing:.18em;text-transform:uppercase;margin:18px 0;}'
		. 'table{width:100%;border-collapse:separate;border-spacing:0;background:#1A1F26;border:1px solid #2A323D;border-radius:8px;overflow:hidden;}'
		. 'th{background:#0F1115;color:#8E8D96;text-align:left;padding:14px 18px;font-family:\'Space Mono\',monospace;font-size:11px;letter-spacing:.18em;text-transform:uppercase;border-bottom:1px solid #2A323D;}'
		. 'td{padding:13px 18px;border-bottom:1px solid #2A323D;font-size:14px;color:#CBCAD2;word-break:break-all;}'
		. 'tr:last-child td{border-bottom:0;}'
		. 'tr:hover td{background:rgba(201,168,76,.04);}'
		. 'a{color:#E3C06A;text-decoration:none;}'
		. 'a:hover{color:#fff;text-decoration:underline;}'
		. '.foot{margin-top:24px;color:#5A6371;font-size:12px;letter-spacing:.06em;}'
		. '.foot a{color:#C9A84C;}';

	$xsl  = '<' . '?xml version="1.0" encoding="UTF-8"?' . '>' . "\n";
	$xsl .= '<xsl:stylesheet version="1.0"'
		. ' xmlns:xsl="http://www.w3.org/1999/XSL/Transform"'
		. ' xmlns:sm="http://www.sitemaps.org/schemas/sitemap/0.9">'
		. "\n";
	$xsl .= '<xsl:output method="html" encoding="UTF-8" indent="yes"/>' . "\n";
	$xsl .= '<xsl:template match="/">' . "\n";
	$xsl .= '<html lang="en"><head><meta charset="utf-8"/>';
	$xsl .= '<title>' . $brand . ' — Sitemap</title>';
	$xsl .= '<meta name="robots" content="noindex,follow"/>';
	$xsl .= '<style>' . $css . '</style></head><body>';
	$xsl .= '<div class="wrap">';
	$xsl .= '<span class="brand">XML Sitemap</span>';
	$xsl .= '<h1>' . $brand . ' <span>Sitemap.</span></h1>';
	$xsl .= '<p>This is the master sitemap index for ' . $brand . '. Search engines and AI assistants use it to discover every public URL on the site. Built for humans here, machine-readable underneath.</p>';
	$xsl .= '<xsl:choose>';
	$xsl .= '<xsl:when test="sm:sitemapindex">';
	$xsl .= '<div class="count"><xsl:value-of select="count(sm:sitemapindex/sm:sitemap)"/> sub-sitemaps</div>';
	$xsl .= '<table><thead><tr><th>Sitemap</th><th>Last modified</th></tr></thead><tbody>';
	$xsl .= '<xsl:for-each select="sm:sitemapindex/sm:sitemap">';
	$xsl .= '<tr><td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td><td><xsl:value-of select="substring(sm:lastmod,1,10)"/></td></tr>';
	$xsl .= '</xsl:for-each>';
	$xsl .= '</tbody></table>';
	$xsl .= '</xsl:when>';
	$xsl .= '<xsl:otherwise>';
	$xsl .= '<div class="count"><xsl:value-of select="count(sm:urlset/sm:url)"/> URLs</div>';
	$xsl .= '<table><thead><tr><th>URL</th><th>Last modified</th><th>Priority</th></tr></thead><tbody>';
	$xsl .= '<xsl:for-each select="sm:urlset/sm:url">';
	$xsl .= '<tr><td><a href="{sm:loc}"><xsl:value-of select="sm:loc"/></a></td><td><xsl:value-of select="substring(sm:lastmod,1,10)"/></td><td><xsl:value-of select="sm:priority"/></td></tr>';
	$xsl .= '</xsl:for-each>';
	$xsl .= '</tbody></table>';
	$xsl .= '</xsl:otherwise>';
	$xsl .= '</xsl:choose>';
	$xsl .= '<div class="foot">Generated by the <a href="' . $home . '">' . $brand . '</a> theme · <a href="' . $llms_short . '">llms.txt</a> · <a href="' . $llms_full . '">llms-full.txt</a></div>';
	$xsl .= '</div></body></html>';
	$xsl .= '</xsl:template></xsl:stylesheet>';
	return $xsl;
}

/* Flush on theme activation so anything that depends on rewrites
   (the rest of the theme) starts working immediately. The sitemap
   itself no longer depends on rewrites — kept just for habit. */
add_action( 'after_switch_theme', 'flush_rewrite_rules' );
