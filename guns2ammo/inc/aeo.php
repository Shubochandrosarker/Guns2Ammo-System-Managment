<?php
/**
 * AEO / GEO / AIO layer — Organization + WebSite schema for every page.
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

/* ---------- llms.txt / llms-full.txt, /sitemap.xml, robots.txt ----------
 * Intentionally NOT handled here. This file used to carry a dead
 * `init`-hooked llms.txt/llms-full.txt handler, a dead `init`-hooked
 * /sitemap.xml handler, and a dead `robots_txt` filter — all three were
 * unreachable because the live implementations (inc/llms.php, inc/sitemap.php,
 * inc/robots.php) each intercept at `parse_request` priority 0, which always
 * runs before `init`/the `robots_txt` filter chain. Having a second,
 * drifting copy of each was a landmine: any future refactor of the
 * intercept order would have silently re-exposed stale, hand-written
 * content (wrong review counts, hardcoded NAP, a richer AI-bot allowlist
 * that never actually applied). The AI-bot allowlist that was only being
 * evaluated here has been merged into the live inc/robots.php.
 *
 * inc/llms.php owns /llms.txt and /llms-full.txt; inc/sitemap.php owns
 * /sitemap.xml; inc/robots.php owns robots.txt. Do not re-add handlers for
 * any of them here — only the Organization/WebSite JSON-LD above belongs
 * in this file.
 */
