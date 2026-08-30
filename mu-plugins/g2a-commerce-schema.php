<?php
/**
 * Plugin Name: G2A Commerce Schema
 * Description: Product/Offer JSON-LD for gated, indexable products. The theme disables Rank Math schema wholesale (inc/seo.php), which left the catalogue with no Product markup at all — no price, availability or GTIN in search results. Emits only fields that genuinely exist; never fabricates ratings or stock.
 * Version:     1.1.0
 * Author:      WordPressistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pull the UPC out of the spec table already rendered in the description.
 *
 * @param string $html Product description.
 * @return string 12/13/14-digit GTIN or ''.
 */
function g2a_schema_upc( $html ) {
	if ( ! preg_match( '#<th[^>]*scope="row"[^>]*>\s*UPC\s*</th>\s*<td[^>]*>(.*?)</td>#si', $html, $m ) ) {
		return '';
	}

	$upc = preg_replace( '/\D+/', '', wp_strip_all_tags( $m[1] ) );

	return in_array( strlen( $upc ), array( 12, 13, 14 ), true ) ? $upc : '';
}

/*
 * NOTE: the Product block that lived here was removed on 2026-08-20.
 * The theme (inc/woocommerce.php) already emits Product JSON-LD in <head>;
 * two Product blocks on one page is worse than one. The theme's copy was
 * patched instead — real brand, @id, url, gtin and category — so there is
 * exactly one, and it is the richer one.
 */

/**
 * CollectionPage schema on brand and product-category archives — these are the
 * national landing pages, and they carried no structured data at all.
 */
add_action(
	'wp_head',
	function () {
		if ( ! is_tax( array( 'product_brand', 'product_cat' ) ) ) {
			return;
		}

		$term = get_queried_object();
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'CollectionPage',
			'name'     => wp_strip_all_tags( $term->name ),
			'url'      => get_term_link( $term ),
			'isPartOf' => array( '@id' => home_url( '/#website' ) ),
		);
		$description = trim( wp_strip_all_tags( (string) $term->description ) );
		if ( $description ) {
			$data['description'] = $description;
		}

		echo "\n" . '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	},
	25
);
