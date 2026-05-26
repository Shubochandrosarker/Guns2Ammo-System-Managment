<?php
/**
 * SEO + Schema.org JSON-LD
 *
 * Plays nice with Rank Math / Yoast: if either is active, we skip our basic meta
 * (they handle title/description/canonical/og), and only output Schema.org JSON-LD
 * for LocalBusiness, BreadcrumbList, FAQPage, Product, Article  context-aware.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function g2a_seo_plugin_active() {
	return defined( 'RANK_MATH_VERSION' )
		|| defined( 'WPSEO_VERSION' )
		|| class_exists( 'RankMath' )
		|| class_exists( 'WPSEO_Frontend' );
}

/* ---------- Basic meta (only if no SEO plugin) ---------- */
add_action( 'wp_head', function () {
	if ( g2a_seo_plugin_active() ) return;

	$title = wp_get_document_title();
	$desc  = g2a_seo_description();
	$url   = g2a_current_url();
	$site  = get_bloginfo( 'name' );
	$image = g2a_seo_image();

	echo "\n<!-- Guns 2 Ammo SEO -->\n";
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">' . "\n";
	echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( $site ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	if ( $image ) echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
	if ( $image ) echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
}, 5 );

function g2a_current_url() {
	// Build from the site's configured host (home_url) rather than the
	// attacker-controllable Host header; only the path comes from the request.
	$path = isset( $_SERVER['REQUEST_URI'] )
		? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '/';
	return home_url( $path ? $path : '/' );
}

function g2a_seo_description() {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		if ( $post && ! empty( $post->post_content ) ) {
			return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 32 );
		}
	}
	if ( is_home() || is_front_page() ) {
		return get_theme_mod( 'g2a_meta_home', "Mesa's premier indoor shooting range, FFL-licensed firearms store, and NRA-certified training facility. Book a lane, shop firearms, or train with us." );
	}
	if ( is_archive() ) {
		return trim( wp_strip_all_tags( get_the_archive_description() ) ) ?: get_bloginfo( 'description' );
	}
	return get_bloginfo( 'description' );
}

function g2a_seo_image() {
	if ( is_singular() && has_post_thumbnail() ) {
		return get_the_post_thumbnail_url( null, 'large' );
	}
	return get_theme_mod( 'g2a_og_image', '' );
}

/* ---------- JSON-LD: always-on LocalBusiness on every page ---------- */
add_action( 'wp_head', function () {
	$rating  = (float) get_theme_mod( 'g2a_rating', '4.7' );
	$reviews = (int) preg_replace( '/[^0-9]/', '', get_theme_mod( 'g2a_review_count', '449' ) );
	$phone   = get_theme_mod( 'g2a_phone', '(602) 715-2677' );

	$ld = [
		'@context' => 'https://schema.org',
		'@type'    => [ 'LocalBusiness', 'SportsActivityLocation', 'Store' ],
		'@id'      => home_url( '/#business' ),
		'name'     => 'Guns 2 Ammo',
		'image'    => g2a_seo_image() ?: home_url( '/wp-content/uploads/g2a-storefront.jpg' ),
		'url'      => home_url( '/' ),
		'telephone' => $phone,
		'priceRange' => '$$',
		'address'  => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => get_theme_mod( 'g2a_addr1', '6030 E Main St, Suite 103' ),
			'addressLocality' => 'Mesa',
			'addressRegion'   => 'AZ',
			'postalCode'      => '85205',
			'addressCountry'  => 'US',
		],
		'geo' => [
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) get_theme_mod( 'g2a_lat',  '33.4152' ),
			'longitude' => (float) get_theme_mod( 'g2a_lng', '-111.7066' ),
		],
		'openingHoursSpecification' => [
			[ '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday' ], 'opens' => '10:00', 'closes' => '18:00' ],
			[ '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Friday',   'opens' => '10:00', 'closes' => '19:00' ],
			[ '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Saturday', 'opens' => '10:00', 'closes' => '19:00' ],
			[ '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => 'Sunday',   'opens' => '12:00', 'closes' => '18:00' ],
		],
		'aggregateRating' => [
			'@type'       => 'AggregateRating',
			'ratingValue' => $rating,
			'reviewCount' => $reviews ?: 449,
			'bestRating'  => '5',
		],
		/* Local SEO  the East Valley communities Guns 2 Ammo serves. */
		'areaServed' => array_map(
			function ( $city ) {
				return [ '@type' => 'City', 'name' => $city ];
			},
			[ 'Mesa', 'Phoenix', 'Gilbert', 'Tempe', 'Chandler', 'Scottsdale', 'Apache Junction', 'Queen Creek', 'Maricopa County' ]
		),
		'hasMap'     => 'https://www.google.com/maps?q=Guns+2+Ammo+6030+E+Main+St+Mesa+AZ+85205',
		'currenciesAccepted' => 'USD',
		'paymentAccepted'    => 'Cash, Credit Card, Debit Card',
		'knowsAbout' => [
			'Indoor shooting range', 'Concealed carry weapons permit training', 'Arizona CCW certification',
			'FFL firearm transfers', 'Firearm sales', 'Buying used firearms', 'NRA firearms training',
			'Machine gun shooting experience', 'Range membership',
		],
		'slogan' => "Mesa's most-trusted indoor range, FFL firearm store, and NRA-certified training facility.",
		/* Membership plans surfaced for AI answer engines and rich results. */
		'hasOfferCatalog' => [
			'@type' => 'OfferCatalog',
			'name'  => 'Guns 2 Ammo Range Memberships',
			'itemListElement' => [
				[ '@type' => 'Offer', 'name' => 'Defender Membership', 'description' => 'Individual range membership for one person.', 'price' => '29.99', 'priceCurrency' => 'USD', 'url' => home_url( '/memberships/' ) ],
				[ '@type' => 'Offer', 'name' => 'Patriot Membership',  'description' => 'Two-person range membership with linked member profiles.', 'price' => '39.99', 'priceCurrency' => 'USD', 'url' => home_url( '/memberships/' ) ],
				[ '@type' => 'Offer', 'name' => 'Guardian Membership', 'description' => 'Four-person range membership for families and groups.', 'price' => '59.99', 'priceCurrency' => 'USD', 'url' => home_url( '/memberships/' ) ],
			],
		],
		'sameAs' => array_values( array_filter( [
			get_theme_mod( 'g2a_social_fb' ),
			get_theme_mod( 'g2a_social_ig' ),
			get_theme_mod( 'g2a_social_x' ),
			get_theme_mod( 'g2a_social_yt' ),
		] ) ),
	];

	g2a_emit_jsonld( $ld );
}, 8 );

/* ---------- Context-aware Schema (breadcrumbs, article, product, FAQ) ---------- */
add_action( 'wp_head', function () {
	if ( ! is_singular() && ! is_archive() ) return;
	$crumbs = g2a_build_breadcrumbs();
	if ( count( $crumbs ) > 1 ) {
		$items = [];
		foreach ( $crumbs as $i => $c ) {
			$items[] = [
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => $c['name'],
				'item'     => $c['url'],
			];
		}
		g2a_emit_jsonld( [
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		] );
	}

	if ( is_singular( 'post' ) ) {
		$p = get_queried_object();
		g2a_emit_jsonld( [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink( $p ) ],
			'headline'      => get_the_title( $p ),
			'description'   => g2a_seo_description(),
			'image'         => has_post_thumbnail( $p ) ? get_the_post_thumbnail_url( $p, 'large' ) : null,
			'datePublished' => get_the_date( DATE_W3C, $p ),
			'dateModified'  => get_the_modified_date( DATE_W3C, $p ),
			'author'        => [ '@type' => 'Person', 'name' => get_the_author_meta( 'display_name', $p->post_author ) ],
			'publisher'     => [
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'logo'  => [ '@type' => 'ImageObject', 'url' => g2a_seo_image() ?: home_url( '/wp-content/uploads/g2a-logo.png' ) ],
			],
		] );
	}
}, 9 );

function g2a_build_breadcrumbs() {
	$home = [ 'name' => 'Home', 'url' => home_url( '/' ) ];
	$out  = [ $home ];

	if ( is_singular( 'post' ) ) {
		$out[] = [ 'name' => 'Blog', 'url' => home_url( '/blog/' ) ];
		$out[] = [ 'name' => get_the_title(), 'url' => get_permalink() ];
	} elseif ( is_singular( 'product' ) ) {
		$out[] = [ 'name' => 'Shop', 'url' => home_url( '/shop/' ) ];
		$out[] = [ 'name' => get_the_title(), 'url' => get_permalink() ];
	} elseif ( is_page() ) {
		$ancestors = array_reverse( get_post_ancestors( get_queried_object_id() ) );
		foreach ( $ancestors as $aid ) {
			$out[] = [ 'name' => get_the_title( $aid ), 'url' => get_permalink( $aid ) ];
		}
		$out[] = [ 'name' => get_the_title(), 'url' => get_permalink() ];
	} elseif ( is_category() || is_tax() ) {
		$out[] = [ 'name' => single_term_title( '', false ), 'url' => g2a_current_url() ];
	} elseif ( is_post_type_archive() ) {
		$out[] = [ 'name' => post_type_archive_title( '', false ), 'url' => g2a_current_url() ];
	}
	return $out;
}

function g2a_emit_jsonld( $data ) {
	echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n";
}

/* ---------- Robots + sitemap nudge ---------- */
add_filter( 'wp_robots', function ( $robots ) {
	if ( is_singular() || is_front_page() || is_home() ) {
		$robots['max-image-preview'] = 'large';
		$robots['max-snippet'] = -1;
	}
	return $robots;
} );

/* ---------- Helper: FAQ schema usable in templates ----------
 * Usage in a page template:
 *   g2a_emit_jsonld( g2a_faq_schema( [
 *       [ 'q' => '...', 'a' => '...' ],
 *   ] ) );
 */
function g2a_faq_schema( array $items ) {
	$out = [];
	foreach ( $items as $it ) {
		$out[] = [
			'@type'           => 'Question',
			'name'            => $it['q'],
			'acceptedAnswer'  => [ '@type' => 'Answer', 'text' => $it['a'] ],
		];
	}
	return [ '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $out ];
}
