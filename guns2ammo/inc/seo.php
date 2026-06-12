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

/* ============================================================
 * RankMath coexistence: this theme is the single source of truth
 * for Schema.org JSON-LD, Open Graph tags, Twitter cards, and the
 * canonical link. RankMath stays responsible ONLY for:
 *   - <title>
 *   - <meta name="description">
 *   - <meta name="keywords">  (if enabled in RM settings)
 *   - <meta name="robots">    (noindex/nofollow per-post toggles)
 *   - Its own backend tools (404 monitor, redirections module, etc.)
 *
 * We turn off every RankMath surface that overlaps with our output
 * so there are no duplicate canonicals, no double OG, no two
 * LocalBusiness JSON-LDs fighting in <head>. If RankMath isn't
 * active these filters/options simply no-op.
 * ============================================================ */
add_action( 'init', 'g2a_seo_disable_rankmath_overlap', 20 );
function g2a_seo_disable_rankmath_overlap() {
	if ( ! defined( 'RANK_MATH_VERSION' ) && ! class_exists( 'RankMath' ) ) {
		return;
	}
	// Schema (RankMath emits its own JSON-LD — silence it).
	add_filter( 'rank_math/json_ld',                  '__return_empty_array', 99 );
	add_filter( 'rank_math/schema/output',            '__return_empty_array', 99 );
	add_filter( 'rank_math/snippet/rich_snippet_default', '__return_false', 99 );
	add_filter( 'rank_math/sitemap/enable',           '__return_false', 99 );

	// Open Graph + Twitter — theme handles these in the basic-meta block below.
	add_filter( 'rank_math/opengraph/facebook/enable_og_tags', '__return_false', 99 );
	add_filter( 'rank_math/opengraph/twitter/enable',          '__return_false', 99 );

	// Canonical (theme emits its own).
	add_filter( 'rank_math/frontend/canonical', '__return_false', 99 );
	remove_filter( 'wp_head', 'rel_canonical' ); // belt-and-suspenders against WP core too

	// Breadcrumb output — theme drives Breadcrumb JSON-LD itself.
	add_filter( 'rank_math/frontend/breadcrumb/enable', '__return_false', 99 );
}

/* ---------- Basic meta + OG + canonical ----------
 * Always run our own basic meta block. Title / description /
 * keywords still come from RankMath via the title-tag + the
 * wp_get_document_title filter, so RankMath's per-post overrides
 * keep working. We just append OG, canonical, and JSON-LD.
 * Was previously gated behind "no SEO plugin active" — flipped so
 * the theme always owns canonical + OG. */
add_action( 'wp_head', function () {

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

/**
 * Curated per-page titles + descriptions for the money pages (2026-06 SEO
 * audit). Used as defaults: a manual excerpt or an SEO-plugin override
 * still wins. Keyed by page slug.
 */
function g2a_seo_meta_map() {
	return apply_filters( 'g2a_seo_meta_map', array(
		'book-a-lane'         => array( 'title' => 'Book a Shooting Lane in Mesa — $20/hr, Walk-Ins OK | G2A', 'desc' => 'Reserve your lane at Guns 2 Ammo in Mesa: $20/hr, extra shooters $15, gun rentals from $15. Climate-controlled, RSO on duty, open 7 days. Instant confirmation.' ),
		'machine-gun'         => array( 'title' => 'Shoot a Machine Gun in Mesa, AZ — Packages From $249 | G2A', 'desc' => 'Mesa\'s only indoor full-auto experience. Fire an MP5, M16 or AK-47 with a 1-on-1 RSO — no experience needed. Packages from $249, ammo included. Book today.' ),
		'training'            => array( 'title' => 'Firearms Training in Mesa, AZ — NRA & USCCA Classes | G2A', 'desc' => 'NRA & USCCA certified firearms training in Mesa: basic handgun, Arizona CCW ($85), defensive pistol & private instruction. Real instructors, weekly classes.' ),
		'memberships'         => array( 'title' => 'Gun Range Memberships in Mesa From $29.99/mo | G2A', 'desc' => 'Unlimited range time at Mesa\'s Guns 2 Ammo from $29.99/mo. Free lane time, discounted rentals & training, guest passes. No contracts — cancel anytime.' ),
		'pricing'             => array( 'title' => 'Range Pricing in Mesa, AZ — Lanes From $20/hr | G2A', 'desc' => 'Transparent range pricing at Guns 2 Ammo Mesa: lanes $20/hr, rentals from $15, eye & ear protection available, 9mm from $22/box. Member discounts up to 25%.' ),
		'about'               => array( 'title' => 'About Guns 2 Ammo — Mesa\'s Range Since 2014 | G2A', 'desc' => 'Veteran- and family-owned. Guns 2 Ammo has run Mesa\'s most-trusted indoor range, gun store & training academy since 2014. Meet the team & tour the facility.' ),
		'contact'             => array( 'title' => 'Contact Guns 2 Ammo — Mesa, AZ | (602) 715-2677', 'desc' => 'Visit Guns 2 Ammo at 6030 E Main St, Ste 103, Mesa, AZ 85205. Open 7 days. Call (602) 715-2677 or send a message — range, training, transfers & sales.' ),
		'sell-your-gun'       => array( 'title' => 'Sell Your Gun in Mesa, AZ — Fair Cash Offers | G2A', 'desc' => 'Sell your firearm to Mesa\'s Guns 2 Ammo: free valuation, fair cash offer, all ATF paperwork handled in-store. Handguns, rifles, NFA & collections welcome.' ),
		'transfers'           => array( 'title' => 'FFL Transfers in Mesa, AZ — $35 Flat Fee | Guns 2 Ammo', 'desc' => '$35 flat FFL transfers in Mesa with same-day pickup when your firearm arrives. NFA/Class III handled. Ship to our E Main St shop — here\'s exactly how it works.' ),
		'ladies-tuesday'      => array( 'title' => 'Ladies Tuesday — Free 1-Hour Lane for Women | G2A Mesa', 'desc' => 'Every Tuesday, women shoot free for one hour at Guns 2 Ammo in Mesa. No membership needed, beginners welcome. Book your Tuesday lane.' ),
		'range-safety'        => array( 'title' => 'Range Safety Rules — Guns 2 Ammo Indoor Range, Mesa', 'desc' => 'The four universal gun safety rules plus G2A house rules: required eye/ear protection, ammo policy, age limits & first-visit checklist for our Mesa range.' ),
		'ffl-services'        => array( 'title' => 'NFA Transfers, Shipping & Consignment — Mesa FFL | G2A', 'desc' => 'Suppressor & SBR transfers ($95), full-auto ($295), firearm shipping and 80/20 consignment sales at Mesa\'s Guns 2 Ammo. Federally licensed, ATF compliant.' ),
		'private-instruction' => array( 'title' => 'Private Firearms Instruction in Mesa, AZ — $140/hr | G2A', 'desc' => 'One-on-one firearms coaching at Guns 2 Ammo in Mesa. $140/hr for up to 2 shooters — range time, targets & eye/ear included. Book your private session.' ),
		'basic-handgun'       => array( 'title' => 'Basic Handgun Class in Mesa, AZ — $95, Beginners | G2A', 'desc' => '4-hour beginner handgun course in Mesa: safety, grip, stance & 50 rounds of live fire. $95, small classes (1–6), no experience needed. Reserve your seat.' ),
		'defensive-pistol'    => array( 'title' => 'Defensive Pistol Course in Mesa, AZ — 10 Hours | G2A', 'desc' => 'Advanced defensive pistol training in Mesa: draw from holster, move-and-shoot, low light. 10 hours, $295, CCW prerequisite. Train with NRA instructors.' ),
		'california-ccw'      => array( 'title' => 'California CCW Live-Fire Qualification in Mesa, AZ | G2A', 'desc' => 'Preparing for a California CCW? Complete live-fire qualification practice at our Mesa indoor range. Verify county requirements with the CA DOJ first.' ),
	) );
}

/** Curated map entry for the current page, or null. */
function g2a_seo_meta_for_page() {
	if ( ! is_page() ) {
		return null;
	}
	$post = get_queried_object();
	if ( ! $post || empty( $post->post_name ) ) {
		return null;
	}
	$map = g2a_seo_meta_map();
	return isset( $map[ $post->post_name ] ) ? $map[ $post->post_name ] : null;
}

// Curated titles as the default when no SEO plugin manages titles.
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( $title || g2a_seo_plugin_active() ) {
		return $title;
	}
	$meta = g2a_seo_meta_for_page();
	return ( $meta && ! empty( $meta['title'] ) ) ? $meta['title'] : $title;
}, 5 );

function g2a_seo_description() {
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		// Curated audit copy beats the generic content trim on money pages.
		$curated = g2a_seo_meta_for_page();
		if ( $curated && ! empty( $curated['desc'] ) ) {
			return $curated['desc'];
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
	// All values pulled from the centralized business-info module
	// so the schema can never disagree with the visible page
	// content (NAP, hours, rating, review count). aggregateRating
	// is emitted ONLY when a real, admin-verified count + rating
	// exist — never fabricated.
	$biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();

	$day_names = [ 0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday' ];
	$hours_spec = [];
	if ( ! empty( $biz['hours'] ) ) {
		foreach ( $biz['hours'] as $dow => $h ) {
			if ( ! $h ) {
				continue; // closed that day — omit from spec
			}
			$hours_spec[] = [
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => $day_names[ $dow ],
				'opens'     => sprintf( '%02d:%02d', intdiv( $h['open'], 60 ), $h['open'] % 60 ),
				'closes'    => sprintf( '%02d:%02d', intdiv( $h['close'], 60 ), $h['close'] % 60 ),
			];
		}
	}

	$ld = [
		'@context' => 'https://schema.org',
		'@type'    => [ 'LocalBusiness', 'SportsActivityLocation', 'Store' ],
		'@id'      => home_url( '/#business' ),
		'name'     => $biz['name'] ?? get_bloginfo( 'name' ),
		'image'    => g2a_seo_image() ?: home_url( '/wp-content/uploads/g2a-storefront.jpg' ),
		'url'      => home_url( '/' ),
		'telephone' => $biz['phone'] ?? '',
		'priceRange' => '$$',
		'foundingDate' => (string) ( $biz['founded_year'] ?? '' ),
		'address'  => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $biz['addr1'] ?? '',
			'addressLocality' => $biz['city'] ?? 'Mesa',
			'addressRegion'   => $biz['region'] ?? 'AZ',
			'postalCode'      => $biz['postal'] ?? '85205',
			'addressCountry'  => $biz['country'] ?? 'US',
		],
		'geo' => [
			'@type'     => 'GeoCoordinates',
			'latitude'  => (float) ( $biz['lat'] ?? 33.4152 ),
			'longitude' => (float) ( $biz['lng'] ?? -111.7066 ),
		],
		'openingHoursSpecification' => $hours_spec,
		/* Local SEO  the East Valley communities Guns 2 Ammo serves. */
		'areaServed' => array_map(
			function ( $city ) {
				return [ '@type' => 'City', 'name' => $city ];
			},
			[ 'Mesa', 'Phoenix', 'Gilbert', 'Tempe', 'Chandler', 'Scottsdale', 'Apache Junction', 'Queen Creek', 'Maricopa County' ]
		),
		'hasMap'     => $biz['maps_url'] ?? 'https://www.google.com/maps?q=Guns+2+Ammo+6030+E+Main+St+Mesa+AZ+85205',
		'currenciesAccepted' => 'USD',
		'paymentAccepted'    => 'Cash, Credit Card, Debit Card',
		'knowsAbout' => [
			'Indoor shooting range', 'Concealed carry weapons permit training', 'Arizona CCW certification',
			'FFL firearm transfers', 'Firearm sales', 'Buying used firearms', 'NRA firearms training',
			'Machine gun shooting experience', 'Range membership',
		],
		'slogan' => $biz['slogan'] ?? "Mesa's most-trusted indoor range, FFL firearm store, and NRA-certified training facility.",
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
			$biz['social']['fb'] ?? '',
			$biz['social']['ig'] ?? '',
			$biz['social']['x']  ?? '',
			$biz['social']['yt'] ?? '',
		] ) ),
	];

	// aggregateRating: emit ONLY when an admin-verified rating +
	// review count are present. Never fabricate. The values are
	// the same ones rendered visibly on the homepage + footer, so
	// they're substantiated by on-page content (Google's policy).
	$rating = (float) ( $biz['review_rating'] ?? 0 );
	$count  = (int)   ( $biz['review_count'] ?? 0 );
	if ( $rating > 0 && $count > 0 ) {
		$ld['aggregateRating'] = [
			'@type'       => 'AggregateRating',
			'ratingValue' => $rating,
			'reviewCount' => $count,
			'bestRating'  => '5',
			'worstRating' => '1',
		];
	}

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
	// Private / transactional pages: noindex,nofollow. These have
	// no search value and may carry session/PII context. We use
	// meta-robots noindex (NOT just robots.txt Disallow) per
	// Google's guidance — robots.txt only blocks crawl, noindex
	// actually keeps them out of the index.
	$private_slugs = array(
		'account', 'my-account', 'cart', 'checkout', 'login',
		'g2a-members-login', 'thank-you', 'payment-failed',
		'staff-dashboard', 'renew-membership', 'membership-checkout-page',
		'memberistic-account', 'memberistic-checkout',
	);
	$is_private = false;
	if ( is_page() ) {
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		if ( in_array( $slug, $private_slugs, true ) ) {
			$is_private = true;
		}
	}
	// WooCommerce cart/checkout/account pages by function (covers
	// installs where the slug differs).
	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		$is_private = true;
	}

	if ( $is_private ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['max-image-preview'], $robots['max-snippet'], $robots['max-video-preview'] );
		return $robots;
	}

	if ( is_singular() || is_front_page() || is_home() || is_archive() ) {
		$robots['max-image-preview'] = 'large';
		$robots['max-snippet']       = -1;
		$robots['max-video-preview'] = -1;
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
