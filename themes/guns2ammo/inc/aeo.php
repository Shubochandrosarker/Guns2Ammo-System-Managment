<?php
/**
 * AEO / GEO / AIO layer — WebSite schema for every page.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ---------- WebSite JSON-LD (every page) ---------- */
add_action( 'wp_head', function () {
	$home = home_url( '/' );

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
 * any of them here — only the WebSite JSON-LD above belongs
 * in this file.
 */
