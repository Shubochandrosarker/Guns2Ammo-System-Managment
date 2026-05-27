<?php
/**
 * Branded XML sitemap + human HTML sitemap.
 *
 * Exposes one master sitemap index at /sitemap.xml and per-type
 * sub-sitemaps for pages, posts, products, FAQs, and locations.
 * Mirrors the structure of wordpressistic.com/sitemap.xml but
 * branded for Guns 2 Ammo.
 *
 * Coexistence with RankMath:
 *   - We disable WP core's wp-sitemap.xml output (the theme owns
 *     the sitemap).
 *   - RankMath's own sitemap module is left ALONE (the client says
 *     they're not using it). If RankMath's sitemap is on, this
 *     theme sitemap still serves at /sitemap.xml — that's fine
 *     because RankMath registers at /sitemap_index.xml (different
 *     path); both can coexist. The robots.txt only references the
 *     theme one.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Turn off WP core's auto sitemap — we own /sitemap.xml */
add_filter( 'wp_sitemaps_enabled', '__return_false' );

add_action( 'init', 'g2a_sitemap_register_rewrites' );
function g2a_sitemap_register_rewrites() {
	add_rewrite_rule( '^sitemap\.xml$',                       'index.php?g2a_sitemap=index',    'top' );
	add_rewrite_rule( '^sitemap-pages\.xml$',                 'index.php?g2a_sitemap=pages',    'top' );
	add_rewrite_rule( '^sitemap-posts\.xml$',                 'index.php?g2a_sitemap=posts',    'top' );
	add_rewrite_rule( '^sitemap-products\.xml$',              'index.php?g2a_sitemap=products', 'top' );
	add_rewrite_rule( '^sitemap-product-cats\.xml$',          'index.php?g2a_sitemap=productcats', 'top' );
	add_rewrite_rule( '^sitemap-faqs\.xml$',                  'index.php?g2a_sitemap=faqs',     'top' );
}

add_filter( 'query_vars', function ( $v ) {
	$v[] = 'g2a_sitemap';
	return $v;
} );

add_action( 'template_redirect', 'g2a_sitemap_dispatch' );
function g2a_sitemap_dispatch() {
	$which = get_query_var( 'g2a_sitemap' );
	if ( ! $which ) {
		return;
	}
	header( 'Content-Type: application/xml; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	nocache_headers();

	switch ( $which ) {
		case 'index':       g2a_sitemap_render_index(); break;
		case 'pages':       g2a_sitemap_render_pages(); break;
		case 'posts':       g2a_sitemap_render_posts(); break;
		case 'products':    g2a_sitemap_render_products(); break;
		case 'productcats': g2a_sitemap_render_product_cats(); break;
		case 'faqs':        g2a_sitemap_render_faqs(); break;
		default: status_header( 404 );
	}
	exit;
}

function g2a_sitemap_open() {
	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
}

function g2a_sitemap_render_index() {
	g2a_sitemap_open();
	echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	$base = trailingslashit( home_url() );
	$now  = gmdate( 'Y-m-d\TH:i:s+00:00' );
	foreach ( array(
		'sitemap-pages.xml',
		'sitemap-posts.xml',
		'sitemap-products.xml',
		'sitemap-product-cats.xml',
		'sitemap-faqs.xml',
	) as $slug ) {
		echo '<sitemap><loc>' . esc_url( $base . $slug ) . '</loc><lastmod>' . esc_html( $now ) . '</lastmod></sitemap>';
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
	// Home
	g2a_sitemap_url_node( home_url( '/' ), gmdate( 'Y-m-d', current_time( 'timestamp' ) ), '1.0', 'daily' );
	$exclude_slugs = array( 'login', 'account', 'checkout', 'cart', 'thank-you', 'payment-failed', 'staff-dashboard', 'g2a-members-login', 'memberistic-account', 'memberistic-checkout' );
	$q = new WP_Query( array(
		'post_type'      => 'page',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	) );
	foreach ( $q->posts as $p ) {
		if ( in_array( $p->post_name, $exclude_slugs, true ) ) continue;
		g2a_sitemap_url_node(
			get_permalink( $p ),
			get_post_modified_time( 'Y-m-d', true, $p ),
			'0.7',
			'weekly'
		);
	}
	echo '</urlset>';
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
		g2a_sitemap_url_node(
			get_permalink( $p ),
			get_post_modified_time( 'Y-m-d', true, $p ),
			'0.6',
			'monthly'
		);
	}
	echo '</urlset>';
}

function g2a_sitemap_render_products() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	if ( post_type_exists( 'product' ) ) {
		$q = new WP_Query( array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'no_found_rows'  => true,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		) );
		foreach ( $q->posts as $p ) {
			g2a_sitemap_url_node(
				get_permalink( $p ),
				get_post_modified_time( 'Y-m-d', true, $p ),
				'0.6',
				'weekly'
			);
		}
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
				g2a_sitemap_url_node( get_term_link( $t ), '', '0.5', 'weekly' );
			}
		}
	}
	echo '</urlset>';
}

function g2a_sitemap_render_faqs() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	g2a_sitemap_url_node( home_url( '/faqs/' ), gmdate( 'Y-m-d', current_time( 'timestamp' ) ), '0.8', 'monthly' );
	echo '</urlset>';
}

/* Robots.txt nudge — added by inc/robots.php. */
add_filter( 'g2a_robots_extra_lines', function ( $lines ) {
	$lines[] = 'Sitemap: ' . home_url( '/sitemap.xml' );
	return $lines;
} );

/* Flush on theme activation so /sitemap.xml works immediately. */
add_action( 'after_switch_theme', 'flush_rewrite_rules' );
