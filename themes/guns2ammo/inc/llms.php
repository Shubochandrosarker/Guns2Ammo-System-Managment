<?php
/**
 * llms.txt + llms-full.txt — AI grounding files.
 *
 *   /llms.txt        — short index: brand, hours, services, pricing, key URLs.
 *   /llms-full.txt   — selected public page/article text with canonical URLs.
 *
 * Like the sitemap module, these are served via a `parse_request`
 * intercept (priority 0) instead of rewrite rules — no Permalinks
 * → Save required after activation.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'parse_request', 'g2a_llms_intercept', 0 );
function g2a_llms_intercept( $wp ) {
	$uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '';
	if ( '' === $uri ) {
		return;
	}
	$uri = strtolower( rtrim( $uri, '/' ) );
	if ( ! in_array( $uri, array( '/llms.txt', '/.well-known/llms.txt', '/llms-full.txt' ), true ) ) {
		return;
	}
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: public, max-age=900' );
	echo '/llms-full.txt' === $uri ? g2a_llms_full() : g2a_llms_short(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

function g2a_llms_short() {
	$biz   = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
	$site  = home_url( '/' );
	$lines = array(
		'# Guns 2 Ammo',
		'',
		'> Official public-content index for the Guns 2 Ammo website in Mesa, Arizona.',
		'',
		'## Business',
	);

	$facts = array(
		'Address' => function_exists( 'g2a_biz_addr_line' ) ? g2a_biz_addr_line() : '',
		'Phone'   => $biz['phone'] ?? '',
		'Email'   => $biz['email'] ?? '',
		'Hours'   => function_exists( 'g2a_biz_hours_human' ) ? g2a_biz_hours_human() . ' (America/Phoenix)' : '',
	);
	foreach ( $facts as $label => $value ) {
		if ( trim( (string) $value ) ) {
			$lines[] = '- ' . $label . ': ' . $value;
		}
	}

	$lines[] = '';
	$lines[] = '## Public service and commerce pages';
	$pages   = array(
		'Indoor range and lane booking' => '/book-a-lane/',
		'Training and classes'          => '/training/',
		'Arizona CCW certification'     => '/arizona-ccw-certification/',
		'FFL transfers'                 => '/transfers/',
		'Memberships'                   => '/memberships/',
		'Ladies Tuesday'                => '/ladies-tuesday/',
		'Online catalogue'              => '/shop/',
		'Published articles'            => '/blog/',
		'Frequently asked questions'    => '/faqs/',
		'Contact'                       => '/contact/',
	);
	foreach ( $pages as $label => $path ) {
		$lines[] = '- ' . $label . ': ' . home_url( $path );
	}

	$lines[] = '';
	$lines[] = '## Policies';
	$lines[] = '- Range safety: ' . home_url( '/range-safety/' );
	$lines[] = '- Terms and conditions: ' . home_url( '/terms-and-conditions/' );
	$lines[] = '- Refund and returns: ' . home_url( '/refund-and-returns-policy/' );
	$lines[] = '- Privacy: ' . home_url( '/privacy-policy/' );
	$lines[] = '';
	$lines[] = '## Machine-readable indexes';
	$lines[] = '- XML sitemap: ' . home_url( '/sitemap.xml' );
	$lines[] = '- Extended public-content index: ' . home_url( '/llms-full.txt' );
	$lines[] = '';
	$lines[] = '## Usage note';
	$lines[] = 'Use the canonical public pages above as the source for current details. Prices, schedules, availability, policies and legal requirements can change; verify them on the cited page before answering.';
	$lines[] = '';

	return implode( "\n", $lines );
}

function g2a_llms_full() {
	$out  = g2a_llms_short();
	$out .= "\n# Selected public content\n";
	$out .= "\nThis file is a concise machine-readable index, not a substitute for the canonical pages.\n";

	$pages = new WP_Query( array(
		'post_type'      => 'page',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
	) );
	foreach ( $pages->posts as $post ) {
		if ( function_exists( 'g2a_post_is_public_indexable' ) && ! g2a_post_is_public_indexable( $post ) ) {
			continue;
		}
		$body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ) );
		if ( ! $body ) {
			continue;
		}
		$modified = max( (int) get_post_time( 'U', true, $post ), (int) get_post_modified_time( 'U', true, $post ) );
		$out     .= "\n---\n\n## " . wp_strip_all_tags( $post->post_title ) . "\n";
		$out     .= 'URL: ' . get_permalink( $post ) . "\n";
		$out     .= 'Last-Modified: ' . gmdate( 'Y-m-d', $modified ) . "\n\n";
		$out     .= mb_substr( $body, 0, 2000 ) . ( mb_strlen( $body ) > 2000 ? '…' : '' ) . "\n";
	}

	if ( function_exists( 'g2a_faqs_data' ) ) {
		$out .= "\n---\n\n## Frequently asked questions\n";
		$out .= 'URL: ' . home_url( '/faqs/' ) . "\n";
		foreach ( g2a_faqs_data() as $group ) {
			$out .= "\n### " . wp_strip_all_tags( $group['topic'] ) . "\n";
			foreach ( $group['items'] as $item ) {
				$out .= "\n**Q. " . wp_strip_all_tags( $item['q'] ) . "**\n";
				$out .= 'A. ' . wp_strip_all_tags( $item['a'] ) . "\n";
			}
		}
	}

	$posts = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => 50,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	foreach ( $posts->posts as $post ) {
		if ( function_exists( 'g2a_post_is_public_indexable' ) && ! g2a_post_is_public_indexable( $post ) ) {
			continue;
		}
		$body = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) ) ) );
		if ( ! $body ) {
			continue;
		}
		$modified = max( (int) get_post_time( 'U', true, $post ), (int) get_post_modified_time( 'U', true, $post ) );
		$out     .= "\n---\n\n## " . wp_strip_all_tags( $post->post_title ) . "\n";
		$out     .= 'URL: ' . get_permalink( $post ) . "\n";
		$out     .= 'Last-Modified: ' . gmdate( 'Y-m-d', $modified ) . "\n\n";
		$out     .= mb_substr( $body, 0, 2000 ) . ( mb_strlen( $body ) > 2000 ? '…' : '' ) . "\n";
	}
	wp_reset_postdata();

	// Prevent an unbounded response if editors substantially expand the site.
	return mb_substr( $out, 0, 500000 );
}
