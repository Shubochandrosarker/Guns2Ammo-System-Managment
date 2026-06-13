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
 * Per-page SEO copy  the single slug → [title, desc] map for
 * every key URL (source: the optimized content plan). These feed
 * the document <title>, the meta description, og:title and
 * og:description, plus Rank Math / Yoast when either is active,
 * so the snippet is identical everywhere.
 *
 * Page templates may still override per page (e.g. template-ccw.php
 * hooks the same filters at priority 99); this map runs at 50 and
 * intentionally carries the same CCW copy so both agree.
 * ============================================================ */
function g2a_seo_page_meta_map() {
	static $map = null;
	if ( null === $map ) {
		$map = [
			'/' => [
				'title' => 'Indoor Shooting Range & Gun Store in Mesa, AZ | Guns 2 Ammo',
				'desc'  => 'Mesa\'s 6-lane climate-controlled indoor shooting range, FFL gun store & NRA-certified training. Lanes from $20/hr, CCW classes & full-auto rentals. Book online.',
			],
			'/book-a-lane/' => [
				'title' => 'Book a Shooting Lane in Mesa — $20/hr, Walk-Ins OK | G2A',
				'desc'  => 'Reserve your lane at Guns 2 Ammo in Mesa: $20/hr, extra shooters $15, gun rentals from $15. Climate-controlled, RSO on duty, open 7 days. Instant confirmation.',
			],
			'/machine-gun/' => [
				'title' => 'Shoot a Machine Gun in Mesa, AZ — Packages From $249 | G2A',
				'desc'  => 'Mesa\'s only indoor full-auto experience. Fire an MP5, M16 or AK-47 with a 1-on-1 RSO — no experience needed. Packages from $249, ammo included. Book today.',
			],
			'/machine-gun/mp5/' => [
				'title' => 'Shoot an MP5 in Mesa, AZ — Full-Auto Experience | G2A',
				'desc'  => 'Fire the legendary HK MP5 on full auto at Guns 2 Ammo in Mesa. 1-on-1 RSO, ammo & targets included, no experience needed. From $249 — book your slot.',
			],
			'/machine-gun/m16/' => [
				'title' => 'Shoot an M16 in Mesa, AZ — Full-Auto Experience | G2A',
				'desc'  => 'Fire the iconic M16 (5.56×45) on full auto at Guns 2 Ammo in Mesa. 1-on-1 RSO, ammo & targets included, no experience needed. From $249 — book your slot.',
			],
			'/machine-gun/ak-47/' => [
				'title' => 'Shoot an AK-47 in Mesa, AZ — Full-Auto Experience | G2A',
				'desc'  => 'Fire the legendary AK-47 (7.62×39) on full auto at Guns 2 Ammo in Mesa. 1-on-1 RSO, ammo & targets included, no experience needed. From $249 — book your slot.',
			],
			'/training/' => [
				'title' => 'Firearms Training in Mesa, AZ — NRA & USCCA Classes | G2A',
				'desc'  => 'NRA & USCCA certified firearms training in Mesa: basic handgun, Arizona CCW ($85), defensive pistol & private instruction. Real instructors, weekly classes.',
			],
			// Kept in sync with template-ccw.php (the page's own override).
			'/arizona-ccw-certification/' => [
				'title' => 'Arizona CCW Permit Class in Mesa, AZ | $85 · 4 Hours',
				'desc'  => 'Arizona CCW permit class in Mesa: 4-hour classroom course covering AZ carry laws, legal use of force and safe handling. $85 · Ages 21+ · DPS help.',
			],
			'/training/basic-handgun/' => [
				'title' => 'Basic Handgun Class in Mesa, AZ — $95, Beginners | G2A',
				'desc'  => '4-hour beginner handgun course in Mesa: safety, grip, stance & 50 rounds of live fire. $95, small classes (1–6), no experience needed. Reserve your seat.',
			],
			'/training/california-ccw/' => [
				'title' => 'California CCW Live-Fire Qualification in Mesa, AZ | G2A',
				'desc'  => 'Preparing for a California CCW? Complete live-fire qualification practice at our Mesa indoor range. 16 hours, $175. Verify county requirements with CA DOJ first.',
			],
			'/training/defensive-pistol/' => [
				'title' => 'Defensive Pistol Course in Mesa, AZ — 10 Hours | G2A',
				'desc'  => 'Advanced defensive pistol training in Mesa: draw from holster, move-and-shoot, force-on-force. 10 hours, $295, CCW prerequisite. Train with NRA instructors.',
			],
			'/private-instruction/' => [
				'title' => 'Private Firearms Instruction in Mesa, AZ — $140/hr | G2A',
				'desc'  => 'One-on-one firearms coaching at Guns 2 Ammo in Mesa. $140/hr for up to 2 shooters — range time, targets & eye/ear included. Book your private session.',
			],
			'/memberships/' => [
				'title' => 'Gun Range Memberships in Mesa From $29.99/mo | G2A',
				'desc'  => 'Unlimited range time at Mesa\'s Guns 2 Ammo from $29.99/mo. Free lane time, discounted rentals & training, guest passes. No contracts — cancel anytime.',
			],
			'/pricing/' => [
				'title' => 'Range Pricing in Mesa, AZ — Lanes From $20/hr | G2A',
				'desc'  => 'Transparent range pricing at Guns 2 Ammo Mesa: lanes $20/hr, rentals from $15, eye & ear $3, 9mm from $22/box. Member discounts up to 25%. See the full table.',
			],
			'/about/' => [
				'title' => 'About Guns 2 Ammo — Mesa\'s Range Since 2014 | G2A',
				'desc'  => 'Veteran- and family-owned. Guns 2 Ammo has run Mesa\'s most-trusted indoor range, gun store & training academy since 2014. Meet the team & tour the facility.',
			],
			'/contact/' => [
				'title' => 'Contact Guns 2 Ammo — Mesa, AZ | (602) 715-2677',
				'desc'  => 'Visit Guns 2 Ammo at 6030 E Main St, Ste 103, Mesa, AZ 85205. Open 7 days. Call (602) 715-2677 or send a message — range, training, transfers & sales.',
			],
			'/range-safety/' => [
				'title' => 'Range Safety Rules — Guns 2 Ammo Indoor Range, Mesa',
				'desc'  => 'The four universal gun safety rules plus G2A house rules: required eye/ear protection, ammo policy, age limits & first-visit checklist for our Mesa range.',
			],
			'/ladies-tuesday/' => [
				'title' => 'Ladies Tuesday — Free 1-Hour Lane for Women | G2A Mesa',
				'desc'  => 'Every Tuesday, women shoot free for one hour at Guns 2 Ammo in Mesa. No membership needed, rentals 25% off, beginners welcome. Book your Tuesday lane.',
			],
			'/transfers/' => [
				'title' => 'FFL Transfers in Mesa, AZ — $35 Flat Fee | Guns 2 Ammo',
				'desc'  => '$35 flat FFL transfers in Mesa with same-day pickup when your firearm arrives. NFA/Class III handled. Ship to our E Main St shop — here\'s exactly how it works.',
			],
			'/ffl-services/' => [
				'title' => 'NFA Transfers, Shipping & Consignment — Mesa FFL | G2A',
				'desc'  => 'Suppressor & SBR transfers ($95), full-auto ($295), firearm shipping and 80/20 consignment sales at Mesa\'s Guns 2 Ammo. Federally licensed, ATF compliant.',
			],
			'/sell-your-gun/' => [
				'title' => 'Sell Your Gun in Mesa, AZ — Fair Cash Offers | G2A',
				'desc'  => 'Sell your firearm to Mesa\'s Guns 2 Ammo: free valuation, fair cash offer, all ATF paperwork handled in-store. Handguns, rifles, NFA & collections welcome.',
			],
			'/blog/' => [
				'title' => 'Firearms Knowledge Hub — CCW, Safety & Gear | Guns 2 Ammo',
				'desc'  => 'Guides from Guns 2 Ammo\'s Mesa instructors: Arizona gun law & CCW, range safety, training tips, calibers and gear buying guides for every skill level.',
			],
			'/faqs/' => [
				'title' => 'FAQs — Guns 2 Ammo Range, Store & Training | Mesa, AZ',
				'desc'  => 'Answers to common questions about Guns 2 Ammo in Mesa: range pricing, rentals, CCW classes, FFL transfers, memberships, age limits & what to bring.',
			],
			'/sitemap/' => [
				'title' => 'Sitemap — Every Page at Guns 2 Ammo | Mesa, AZ',
				'desc'  => 'Browse every page on the Guns 2 Ammo site: lane booking, training & CCW classes, the machine gun experience, shop, FFL transfers, memberships & contact.',
			],
		];
	}
	return $map;
}

/** Normalized request path: lowercase, trailing-slashed, home-subdir stripped. */
function g2a_seo_request_path() {
	$path = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '/';
	$home_path = untrailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );
	if ( $home_path && 0 === strpos( $path, $home_path ) ) {
		$path = substr( $path, strlen( $home_path ) );
	}
	$path = strtolower( $path ?: '/' );
	return '/' === $path ? '/' : '/' . trim( $path, '/' ) . '/';
}

/**
 * Resolve the current request to a map entry. Primary match is the
 * request path; falls back to WP conditionals + the page slug chain
 * (covers ?page_id= URLs and odd permalink setups).
 *
 * @return array{title:string,desc:string}|null
 */
function g2a_seo_page_meta() {
	$map  = g2a_seo_page_meta_map();
	$path = g2a_seo_request_path();
	// Once the main query is resolved, never hand mapped page metadata to
	// search results, 404s, feeds, or paginated archives — a raw-path match
	// would otherwise give `/?s=ammo` the homepage title and `/blog/?paged=2`
	// the first-page blog meta. These contexts get their own (or no) meta.
	if ( did_action( 'wp' ) && ( is_search() || is_404() || is_feed() || is_paged() ) ) {
		return null;
	}
	if ( isset( $map[ $path ] ) ) {
		return $map[ $path ];
	}
	if ( ! did_action( 'wp' ) ) {
		return null; // conditionals not ready yet
	}
	if ( is_front_page() && ! is_paged() ) {
		return $map['/'];
	}
	if ( is_home() && ! is_paged() ) {
		return $map['/blog/'];
	}
	if ( is_page() ) {
		$uri = get_page_uri( get_queried_object_id() );
		if ( $uri ) {
			$key = '/' . trim( strtolower( $uri ), '/' ) . '/';
			if ( isset( $map[ $key ] ) ) {
				return $map[ $key ];
			}
		}
	}
	return null;
}

/* Wire the map into the document title + every active SEO surface. */
function g2a_seo_map_title( $title ) {
	$meta = g2a_seo_page_meta();
	return ( $meta && ! empty( $meta['title'] ) ) ? $meta['title'] : $title;
}
function g2a_seo_map_description( $desc ) {
	$meta = g2a_seo_page_meta();
	return ( $meta && ! empty( $meta['desc'] ) ) ? $meta['desc'] : $desc;
}
add_filter( 'pre_get_document_title', 'g2a_seo_map_title', 50 );
add_action( 'init', function () {
	// Rank Math: keep its (single) title/description tags on our copy.
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		add_filter( 'rank_math/frontend/title',       'g2a_seo_map_title', 50 );
		add_filter( 'rank_math/frontend/description', 'g2a_seo_map_description', 50 );
	}
	// Yoast: same treatment.
	if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Frontend' ) ) {
		add_filter( 'wpseo_title',    'g2a_seo_map_title', 50 );
		add_filter( 'wpseo_metadesc', 'g2a_seo_map_description', 50 );
	}
} );

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
	// Meta description: ours only when no SEO plugin is emitting one
	// (Rank Math / Yoast own that tag when active — our copy reaches it
	// via the rank_math/frontend/description + wpseo_metadesc filters),
	// and never an empty boilerplate tag.
	if ( $desc && ! g2a_seo_plugin_active() ) {
		echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
	}
	// Author is always the brand — never a personal developer/admin name.
	echo '<meta name="author" content="Guns 2 Ammo">' . "\n";
	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">' . "\n";
	echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( $site ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $desc ) echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	if ( $image ) echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $desc ) echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
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
	// 1) Per-page copy from the curated map wins everywhere.
	$page = g2a_seo_page_meta();
	if ( $page && ! empty( $page['desc'] ) ) {
		// Homepage stays Customizer-overridable; the map copy is the default.
		if ( is_front_page() && ! is_paged() ) {
			return get_theme_mod( 'g2a_meta_home', $page['desc'] );
		}
		return $page['desc'];
	}
	// 2) Singular content: hand-written excerpt, else trimmed content.
	if ( is_singular() ) {
		$post = get_queried_object();
		if ( $post && ! empty( $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}
		if ( $post && ! empty( $post->post_content ) ) {
			return wp_trim_words( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 32 );
		}
	}
	if ( is_archive() ) {
		return trim( wp_strip_all_tags( get_the_archive_description() ) );
	}
	// 3) No boilerplate fallback: the old site-tagline catch-all produced
	// the same generic description on every unmapped page, so it was
	// removed. An empty value means no description tag is printed.
	return '';
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
	// Prefer the live review helpers (Google Places-backed when an API
	// key is configured) so the schema always matches the visible count.
	$rating = function_exists( 'g2a_reviews_rating' ) ? (float) g2a_reviews_rating() : (float) ( $biz['review_rating'] ?? 0 );
	$count  = function_exists( 'g2a_reviews_count' )  ? (int) g2a_reviews_count()    : (int)   ( $biz['review_count'] ?? 0 );
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
			// Brand byline — never expose a personal developer/admin login name.
			'author'        => [ '@type' => 'Organization', 'name' => 'Guns 2 Ammo', 'url' => home_url( '/' ) ],
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
