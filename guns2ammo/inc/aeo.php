<?php
/**
 * AEO / GEO / AIO layer  Organization + WebSite schema, the
 * llms.txt / llms-full.txt endpoints, a detailed robots.txt, and a
 * local-business-friendly XML sitemap that help search engines and AI
 * answer engines understand, localize, and cite Guns 2 Ammo.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- Organization + WebSite JSON-LD (every page) ---------- */
add_action( 'wp_head', function () {
	// Single source of truth for NAP — see inc/business-info.php.
	$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
	$home  = home_url( '/' );
	$phone = $g2a_biz['phone'] ?? '(602) 715-2677';
	$logo  = g2a_seo_image() ?: home_url( '/wp-content/uploads/g2a-logo.png' );

	g2a_emit_jsonld( [
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'@id'      => $home . '#organization',
		'name'     => $g2a_biz['name'] ?? 'Guns 2 Ammo',
		'url'      => $home,
		'logo'     => $logo,
		'image'    => $logo,
		'telephone'=> $phone,
		'description' => "Guns 2 Ammo is a Mesa, Arizona indoor shooting range, FFL-licensed firearm store, and NRA-certified training facility. We sell and buy firearms, run CCW certification courses, and offer range memberships.",
		'address'  => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103',
			'addressLocality' => $g2a_biz['city'] ?? 'Mesa',
			'addressRegion'   => $g2a_biz['region'] ?? 'AZ',
			'postalCode'      => $g2a_biz['postal'] ?? '85205',
			'addressCountry'  => $g2a_biz['country'] ?? 'US',
		],
		'contactPoint' => [
			'@type'       => 'ContactPoint',
			'telephone'   => $phone,
			'contactType' => 'customer service',
			'areaServed'  => 'US',
			'availableLanguage' => 'English',
		],
		'sameAs' => array_values( array_filter( [
			get_theme_mod( 'g2a_social_fb' ),
			get_theme_mod( 'g2a_social_ig' ),
			get_theme_mod( 'g2a_social_x' ),
			get_theme_mod( 'g2a_social_yt' ),
		] ) ),
	] );

	g2a_emit_jsonld( [
		'@context' => 'https://schema.org',
		'@type'    => 'WebSite',
		'@id'      => $home . '#website',
		'url'      => $home,
		'name'     => 'Guns 2 Ammo',
		'publisher'=> [ '@id' => $home . '#organization' ],
		'potentialAction' => [
			'@type'       => 'SearchAction',
			'target'      => [
				'@type'       => 'EntryPoint',
				'urlTemplate' => $home . '?s={search_term_string}',
			],
			'query-input' => 'required name=search_term_string',
		],
	] );
}, 7 );

/* ---------- llms.txt / llms-full.txt ----------
 * Intentionally NOT handled here. This file used to intercept these paths
 * on `init` (which always fires before `parse_request`), which meant it
 * silently won the request over inc/llms.php's `parse_request`-hooked
 * handler no matter what — every AI crawler was served this file's static,
 * hand-written text (with a stale review count and hardcoded NAP/hours)
 * instead of inc/llms.php's live, g2a_biz()-driven, content-DB-backed
 * version, which is the one actually documented and meant to be canonical.
 * inc/llms.php owns /llms.txt and /llms-full.txt; do not re-add a handler
 * for them here.
 */

/* ====================================================================
   LOCAL-BUSINESS XML SITEMAP    served at /sitemap.xml
   Curated to mirror the HTML sitemap, optimized for local SEO and the
   Guns 2 Ammo Google Business Profile. Pages, posts and products are
   included with sensible priority + change frequency.
   ==================================================================== */

/**
 * Curated, local-business-priority URL map. Mirrors the HTML sitemap
 * (the "Our Team" page is intentionally excluded per business request).
 */
function g2a_sitemap_urls() {
	// PERF: cache the assembled URL list for an hour. Before this, every
	// crawl hit re-ran get_posts() twice (200 posts + 500 products) plus a
	// large static-array assemble — measurable load under bot traffic.
	$cached = get_transient( 'g2a_sitemap_urls' );
	if ( is_array( $cached ) ) {
		return $cached;
	}
	$h = untrailingslashit( home_url( '/' ) );

	// path => [ priority, changefreq ]
	$static = [
		'/'                              => [ '1.0', 'daily' ],
		'/book-a-lane/'                  => [ '0.9', 'daily' ],
		'/memberships/'                  => [ '0.9', 'weekly' ],
		'/pricing/'                      => [ '0.8', 'weekly' ],
		'/ladies-tuesday/'               => [ '0.7', 'weekly' ],
		'/range-safety/'                 => [ '0.6', 'monthly' ],
		'/training/'                     => [ '0.9', 'weekly' ],
		'/arizona-ccw-certification/'    => [ '0.9', 'weekly' ],
		'/arizona-ccw-syllabus/'         => [ '0.6', 'monthly' ],
		'/training/basic-handgun/'       => [ '0.7', 'monthly' ],
		'/training/california-ccw/'      => [ '0.7', 'monthly' ],
		'/training/church-security/'     => [ '0.6', 'monthly' ],
		'/training/womens-intro/'        => [ '0.6', 'monthly' ],
		'/training/defensive-pistol/'    => [ '0.7', 'monthly' ],
		'/training/rifle-fundamentals/'  => [ '0.6', 'monthly' ],
		'/training/refuse-to-be-a-victim/' => [ '0.6', 'monthly' ],
		'/training/youth-firearm-safety/'  => [ '0.6', 'monthly' ],
		'/private-instruction/'          => [ '0.8', 'monthly' ],
		'/machine-gun/'                  => [ '0.9', 'weekly' ],
		'/machine-gun/mp5/'              => [ '0.6', 'monthly' ],
		'/machine-gun/m16/'              => [ '0.6', 'monthly' ],
		'/machine-gun/ak-47/'            => [ '0.6', 'monthly' ],
		'/free-ccw-class/'               => [ '0.6', 'monthly' ],
		'/shop/'                         => [ '0.9', 'daily' ],
		'/collections/'                  => [ '0.7', 'weekly' ],
		'/collections/handguns/'         => [ '0.7', 'weekly' ],
		'/collections/rifles/'           => [ '0.7', 'weekly' ],
		'/collections/ammunition/'       => [ '0.7', 'weekly' ],
		'/collections/magazines/'        => [ '0.7', 'weekly' ],
		'/transfers/'                    => [ '0.8', 'monthly' ],
		'/transfer-request/'             => [ '0.7', 'monthly' ],
		'/ffl-services/'                 => [ '0.7', 'monthly' ],
		'/sell-your-gun/'                => [ '0.8', 'weekly' ],
		'/blog/'                         => [ '0.7', 'daily' ],
		'/about/'                        => [ '0.7', 'monthly' ],
		'/contact/'                      => [ '0.8', 'monthly' ],
		'/get-support/'                  => [ '0.6', 'monthly' ],
		'/sitemap/'                      => [ '0.3', 'monthly' ],
		'/privacy-policy/'               => [ '0.2', 'yearly' ],
		'/terms-and-conditions/'         => [ '0.2', 'yearly' ],
		'/refund-and-returns-policy/'    => [ '0.2', 'yearly' ],
	];

	$urls = [];
	foreach ( $static as $path => $meta ) {
		$urls[] = [
			'loc'        => $h . $path,
			'priority'   => $meta[0],
			'changefreq' => $meta[1],
			'lastmod'    => '',
		];
	}

	// Published blog posts.
	foreach ( get_posts( [ 'numberposts' => 200, 'post_status' => 'publish' ] ) as $p ) {
		$urls[] = [
			'loc'        => get_permalink( $p ),
			'priority'   => '0.6',
			'changefreq' => 'monthly',
			'lastmod'    => get_post_modified_time( DATE_W3C, true, $p ),
		];
	}

	// Published WooCommerce products.
	if ( post_type_exists( 'product' ) ) {
		foreach ( get_posts( [ 'post_type' => 'product', 'numberposts' => 500, 'post_status' => 'publish' ] ) as $p ) {
			$urls[] = [
				'loc'        => get_permalink( $p ),
				'priority'   => '0.6',
				'changefreq' => 'weekly',
				'lastmod'    => get_post_modified_time( DATE_W3C, true, $p ),
			];
		}
	}

	$urls = apply_filters( 'g2a_sitemap_urls', $urls );
	set_transient( 'g2a_sitemap_urls', $urls, HOUR_IN_SECONDS );
	return $urls;
}

/* Invalidate sitemap cache when posts/pages/products change. */
add_action( 'save_post', function ( $post_id ) {
	if ( wp_is_post_revision( $post_id ) ) return;
	delete_transient( 'g2a_sitemap_urls' );
} );
add_action( 'deleted_post', function () { delete_transient( 'g2a_sitemap_urls' ); } );

/* ---------- Serve /sitemap.xml ---------- */
add_action( 'init', function () {
	$path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( 'sitemap.xml' !== $path ) return;

	header( 'Content-Type: application/xml; charset=utf-8' );
	header( 'X-Robots-Tag: noindex, follow' );

	$home    = untrailingslashit( home_url( '/' ) );
	$default = gmdate( DATE_W3C );

	// Single source of truth for NAP — see inc/business-info.php.
	$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( home_url( '/wp-sitemap.xsl' ) ) . '"?>' . "\n";
	echo "<!-- Guns 2 Ammo  Mesa, AZ indoor shooting range, gun store & training facility. -->\n";
	echo '<!-- Local business sitemap. NAP: ' . ( $g2a_biz['name'] ?? 'Guns 2 Ammo' ) . ', ' . ( $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' ) . ', ' . ( $g2a_biz['addr2'] ?? 'Mesa, AZ 85205' ) . ', ' . ( $g2a_biz['phone'] ?? '(602) 715-2677' ) . ". -->\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

	foreach ( g2a_sitemap_urls() as $u ) {
		echo "\t<url>\n";
		echo "\t\t<loc>" . esc_url( $u['loc'] ) . "</loc>\n";
		echo "\t\t<lastmod>" . esc_html( $u['lastmod'] ?: $default ) . "</lastmod>\n";
		echo "\t\t<changefreq>" . esc_html( $u['changefreq'] ) . "</changefreq>\n";
		echo "\t\t<priority>" . esc_html( $u['priority'] ) . "</priority>\n";
		echo "\t</url>\n";
	}

	echo '</urlset>';
	exit;
} );

/* ====================================================================
   DETAILED robots.txt    SEO-optimized, AI-answer-engine friendly, and
   localized for the Guns 2 Ammo Google Business Profile.
   ==================================================================== */
add_filter( 'robots_txt', function ( $output, $public ) {
	$home    = untrailingslashit( home_url( '/' ) );
	$host    = wp_parse_url( $home, PHP_URL_HOST );
	$uploads = wp_upload_dir();
	$up_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ?: '/wp-content/uploads';

	// Site discouraged from search engines  keep WP's restrictive default.
	if ( ! $public ) {
		return $output;
	}

	// Single source of truth for NAP — see inc/business-info.php.
	$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();

	$lines = [];

	$lines[] = '# ======================================================================';
	$lines[] = '# robots.txt  Guns 2 Ammo';
	$lines[] = '# Mesa, Arizona indoor shooting range, FFL gun store & NRA training facility';
	$lines[] = '# ' . ( $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' ) . ', ' . ( $g2a_biz['addr2'] ?? 'Mesa, AZ 85205' ) . '  ' . ( $g2a_biz['phone'] ?? '(602) 715-2677' );
	$lines[] = '# ======================================================================';
	$lines[] = '';

	/* ---- All standard crawlers ---- */
	$lines[] = 'User-agent: *';
	$lines[] = 'Allow: /';
	$lines[] = 'Allow: /wp-admin/admin-ajax.php';
	$lines[] = 'Allow: ' . $up_path . '/';
	$lines[] = 'Allow: /wp-content/uploads/';
	$lines[] = 'Allow: /*.css$';
	$lines[] = 'Allow: /*.js$';
	$lines[] = '';
	$lines[] = '# Admin, includes and system paths';
	$lines[] = 'Disallow: /wp-admin/';
	$lines[] = 'Disallow: /wp-includes/';
	$lines[] = 'Disallow: /wp-login.php';
	$lines[] = 'Disallow: /wp-register.php';
	$lines[] = 'Disallow: /xmlrpc.php';
	$lines[] = 'Disallow: /wp-json/';
	$lines[] = 'Disallow: /trackback/';
	$lines[] = 'Disallow: /readme.html';
	$lines[] = 'Disallow: /license.txt';
	$lines[] = '';
	$lines[] = '# Transactional & account pages  no SEO value, keep them private';
	$lines[] = 'Disallow: /cart/';
	$lines[] = 'Disallow: /checkout/';
	$lines[] = 'Disallow: /my-account/';
	$lines[] = 'Disallow: /account/';
	$lines[] = 'Disallow: /login/';
	$lines[] = 'Disallow: /renewal/';
	$lines[] = 'Disallow: /thank-you/';
	$lines[] = 'Disallow: /payment-failed/';
	$lines[] = 'Disallow: /*add-to-cart=*';
	$lines[] = 'Disallow: /*orderby=*';
	$lines[] = '';
	$lines[] = '# Search results & internal query strings';
	$lines[] = 'Disallow: /?s=';
	$lines[] = 'Disallow: /search/';
	$lines[] = 'Disallow: /*?*replytocom=';
	$lines[] = 'Disallow: /*?p=*';
	$lines[] = '';

	/* ---- Major search engines: explicit allow + assets ---- */
	foreach ( [ 'Googlebot', 'Googlebot-Image', 'Bingbot', 'DuckDuckBot', 'Applebot', 'YandexBot' ] as $bot ) {
		$lines[] = 'User-agent: ' . $bot;
		$lines[] = 'Allow: /';
		$lines[] = 'Disallow: /wp-admin/';
		$lines[] = 'Allow: /wp-admin/admin-ajax.php';
		$lines[] = 'Disallow: /cart/';
		$lines[] = 'Disallow: /checkout/';
		$lines[] = 'Disallow: /account/';
		$lines[] = '';
	}

	/* ---- AI answer engines: explicitly welcomed for GEO / AI ranking ---- */
	$lines[] = '# AI answer engines  explicitly allowed so Guns 2 Ammo can be';
	$lines[] = '# discovered, summarized and cited by AI search & chat assistants.';
	foreach ( [ 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-Web', 'Anthropic-AI', 'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'CCBot', 'Amazonbot', 'cohere-ai', 'Bytespider', 'Meta-ExternalAgent' ] as $bot ) {
		$lines[] = 'User-agent: ' . $bot;
		$lines[] = 'Allow: /';
		$lines[] = 'Disallow: /cart/';
		$lines[] = 'Disallow: /checkout/';
		$lines[] = 'Disallow: /account/';
		$lines[] = '';
	}

	/* ---- Aggressive SEO scrapers  throttle to protect the server ---- */
	$lines[] = '# Aggressive crawlers  throttled (not blocked)';
	foreach ( [ 'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'rogerbot' ] as $bot ) {
		$lines[] = 'User-agent: ' . $bot;
		$lines[] = 'Crawl-delay: 10';
		$lines[] = 'Allow: /';
		$lines[] = 'Disallow: /cart/';
		$lines[] = 'Disallow: /checkout/';
		$lines[] = '';
	}

	/* ---- AI / LLM guidance + sitemaps ---- */
	$lines[] = '# Local business & AI guidance';
	$lines[] = 'LLM-Content: ' . $home . '/llms.txt';
	$lines[] = 'LLM-Full-Content: ' . $home . '/llms-full.txt';
	$lines[] = '';
	$lines[] = '# Sitemaps';
	$lines[] = 'Sitemap: ' . $home . '/sitemap.xml';
	$lines[] = 'Sitemap: ' . $home . '/wp-sitemap.xml';
	$lines[] = '';
	if ( $host ) {
		$lines[] = 'Host: ' . $host;
	}

	return implode( "\n", $lines ) . "\n";
}, 10, 2 );
