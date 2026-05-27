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
g2a_sitemap_maybe_serve();
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
		'/sitemap-faqs.xml'         => 'faqs',
	);
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
		case 'faqs':        g2a_sitemap_render_faqs(); break;
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
		'/sitemap-faqs.xml'         => 'faqs',
	);
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
		case 'faqs':        g2a_sitemap_render_faqs(); break;
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

function g2a_sitemap_excluded_page_slugs() {
	return array( 'login', 'account', 'my-account', 'checkout', 'cart', 'thank-you', 'payment-failed', 'staff-dashboard', 'g2a-members-login', 'memberistic-account', 'memberistic-checkout', 'membership-checkout' );
}

function g2a_sitemap_render_pages() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	g2a_sitemap_url_node( home_url( '/' ), gmdate( 'Y-m-d' ), '1.0', 'daily' );
	$exclude = g2a_sitemap_excluded_page_slugs();
	$q = new WP_Query( array(
		'post_type'      => 'page',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	) );
	foreach ( $q->posts as $p ) {
		if ( in_array( $p->post_name, $exclude, true ) ) continue;
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
				$url = get_term_link( $t );
				if ( ! is_wp_error( $url ) ) {
					g2a_sitemap_url_node( $url, '', '0.5', 'weekly' );
				}
			}
		}
	}
	echo '</urlset>';
}

function g2a_sitemap_render_faqs() {
	g2a_sitemap_open();
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
	g2a_sitemap_url_node( home_url( '/faqs/' ), gmdate( 'Y-m-d' ), '0.8', 'monthly' );
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
