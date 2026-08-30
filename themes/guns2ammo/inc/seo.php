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
		// Single source of truth for NAP — see inc/business-info.php.
		$g2a_biz       = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
		$g2a_seo_phone = $g2a_biz['phone'] ?? '(602) 715-2677';
		$g2a_seo_addr1 = $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103';
		$g2a_seo_addr2 = $g2a_biz['addr2'] ?? 'Mesa, AZ 85205';
		$g2a_seo_founded_year = (int) ( $g2a_biz['founded_year'] ?? 2014 );
		// Single source of truth for membership pricing — see inc/pricing.php.
		$g2a_seo_from_price = function_exists( 'g2a_plan_price_from_fmt' ) ? g2a_plan_price_from_fmt() : '$29.99';
		$map = [
			// Commerce and long-form landing pages that otherwise fall through
			// to generic archive text or an abruptly truncated content excerpt.
			// Avoid inventory counts in evergreen metadata: catalogue totals move.
			'/brands/' => [
				'title' => 'Firearm, Ammunition & Gear Brands | Guns 2 Ammo',
				'desc'  => 'Browse firearms, ammunition, optics and accessories by manufacturer at Guns 2 Ammo. Eligible firearm orders ship to a licensed FFL where legally permitted.',
			],
			'/collections/' => [
				'title' => 'Shop Firearms, Ammo & Accessories by Collection | G2A',
				'desc'  => 'Browse handguns, rifles, ammunition, magazines and accessories by collection. Shop online or arrange lawful pickup at Guns 2 Ammo in Mesa, Arizona.',
			],
			'/collections/handguns/' => [
				'title' => 'Handguns for Sale — Pistols & Revolvers | Guns 2 Ammo',
				'desc'  => 'Browse carry pistols, duty handguns and revolvers. Eligible firearm orders ship to a licensed FFL where legally permitted, or arrange pickup in Mesa, AZ.',
			],
			'/collections/rifles/' => [
				'title' => 'Rifles for Sale — Carbines & Bolt-Action Rifles | G2A',
				'desc'  => 'Browse carbines and bolt-action rifles from trusted manufacturers. Eligible orders ship to a licensed FFL where legally permitted, or arrange Mesa pickup.',
			],
			'/collections/magazines/' => [
				'title' => 'Firearm Magazines — OEM & Aftermarket | Guns 2 Ammo',
				'desc'  => 'Shop OEM and aftermarket firearm magazines for range, carry and duty use. Availability and shipping depend on product details and applicable local law.',
			],
			'/collections/ammunition/' => [
				'title' => 'Ammunition for Sale — Range & Defense Loads | G2A',
				'desc'  => 'Shop factory-new range ammunition, defense loads and bulk cases. In-store availability and shipping are subject to product details and applicable law.',
			],
			'/arizona-ccw-laws-2026/' => [
				'title' => 'Arizona CCW Laws 2026 — Constitutional Carry Explained',
				'desc'  => 'Understand Arizona constitutional carry, permit eligibility, the application process and restricted locations. Verify current requirements with official sources.',
			],
			'/arizona-ccw-syllabus/' => [
				'title' => 'Arizona CCW Class Syllabus — Course Topics | Guns 2 Ammo',
				'desc'  => 'See the Arizona CCW course topics: firearm safety, applicable Arizona law, reciprocity and what certification does and does not authorize.',
			],
			'/training/handgun-course/' => [
				'title' => 'Basic Handgun Course in Mesa, AZ | Guns 2 Ammo',
				'desc'  => 'A patient, small-group course for first-time shooters and anyone rebuilding handgun fundamentals, with hands-on instruction at our Mesa indoor range.',
			],
			'/training/church-security/' => [
				'title' => 'Church Security Training in Mesa, AZ | Guns 2 Ammo',
				'desc'  => 'Training for church safety teams covering coordination, de-escalation, medical readiness and practical ways to protect a congregation responsibly.',
			],
			'/training/refuse-to-be-a-victim/' => [
				'title' => 'Refuse To Be A Victim Seminar — Mesa, AZ | G2A',
				'desc'  => 'A non-firearm personal-safety seminar covering situational awareness, risk reduction and practical safety habits for home, travel and online activity.',
			],
			'/training/rifle-fundamentals/' => [
				'title' => 'Rifle Fundamentals Training in Mesa, AZ | Guns 2 Ammo',
				'desc'  => 'Learn safe rifle handling, zeroing, marksmanship positions and reload fundamentals with guided instruction at our indoor range in Mesa, Arizona.',
			],
			'/training/womens-intro/' => [
				'title' => "Women's Intro to Firearms — Mesa, AZ | Guns 2 Ammo",
				'desc'  => 'A supportive small-group introduction with clear safety fundamentals and hands-on coaching at a comfortable pace in Mesa, Arizona.',
			],
			'/training/youth-firearm-safety/' => [
				'title' => 'Youth Firearm Safety Class — Mesa, AZ | Guns 2 Ammo',
				'desc'  => 'An age-appropriate safety class teaching young people to stop, not touch, leave the area and tell an adult. A parent or guardian attends throughout.',
			],
			'/expert-fitment/' => [
				'title' => 'Expert Handgun Fitment — Try Before You Buy | G2A',
				'desc'  => 'Compare grip fit, trigger reach and slide operation, then test suitable rental options on our indoor range before choosing a handgun. Mesa, Arizona.',
			],
			'/compliance/' => [
				'title' => 'FFL Transfers & Federal Compliance | Guns 2 Ammo Mesa',
				'desc'  => 'Learn how incoming firearm transfers, dealer documentation, Form 4473 and required background checks are handled at Guns 2 Ammo in Mesa, Arizona.',
			],
			'/local-pickup/' => [
				'title' => 'Local Pickup in Mesa, AZ — Order Online | Guns 2 Ammo',
				'desc'  => 'Choose local pickup for eligible online orders. We will notify you when the order is ready and explain the identification or transfer steps that apply.',
			],
			'/transfer-request/' => [
				'title' => 'Start an FFL Transfer Request | Guns 2 Ammo Mesa, AZ',
				'desc'  => 'Provide the details for an incoming firearm so Guns 2 Ammo can coordinate required dealer documentation and the lawful in-store transfer process.',
			],
			'/want-to-ship-your-firearm/' => [
				'title' => 'Ship a Firearm to Our Mesa, AZ FFL | Guns 2 Ammo',
				'desc'  => 'See what your seller needs, where to send an eligible firearm and how to begin the required transfer process with Guns 2 Ammo in Mesa, Arizona.',
			],
			'/shop/' => [
				'title' => 'Firearms, Ammo & Accessories for Sale | Guns 2 Ammo',
				'desc'  => 'Shop firearms, ammunition, optics, magazines and accessories. Eligible firearm orders ship to a licensed FFL where legally permitted, or arrange Mesa pickup.',
			],
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
				'title' => 'Gun Range Memberships in Mesa From ' . $g2a_seo_from_price . '/mo | G2A',
				'desc'  => 'Unlimited range time at Mesa\'s Guns 2 Ammo from ' . $g2a_seo_from_price . '/mo. Free lane time, discounted rentals & training, guest passes. No contracts — cancel anytime.',
			],
			'/pricing/' => [
				'title' => 'Range Pricing in Mesa, AZ — Lanes From $20/hr | G2A',
				'desc'  => 'Transparent range pricing at Guns 2 Ammo Mesa: lanes $20/hr, rentals from $15, eye & ear $3, 9mm from $22/box. Member discounts up to 25%. See the full table.',
			],
			'/about/' => [
				'title' => 'About Guns 2 Ammo — Mesa\'s Range Since ' . $g2a_seo_founded_year . ' | G2A',
				'desc'  => 'Veteran- and family-owned. Guns 2 Ammo has run Mesa\'s most-trusted indoor range, gun store & training academy since ' . $g2a_seo_founded_year . '. Meet the team & tour the facility.',
			],
			'/contact/' => [
				'title' => 'Contact Guns 2 Ammo — Mesa, AZ | ' . $g2a_seo_phone,
				'desc'  => 'Visit Guns 2 Ammo at ' . $g2a_seo_addr1 . ', ' . $g2a_seo_addr2 . '. Open 7 days. Call ' . $g2a_seo_phone . ' or send a message — range, training, transfers & sales.',
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

// Internal optimization scores are editorial tooling, not visitor content.
// Disable the Rank Math frontend badge and its promotional backlink at source.
add_filter( 'rank_math/show_score', '__return_false', PHP_INT_MAX );
add_filter( 'rank_math/frontend/seo_score/html', '__return_empty_string', PHP_INT_MAX );
add_filter( 'rank_math/frontend/seo_score/backlink', '__return_empty_string', PHP_INT_MAX );
add_filter( 'rank_math/frontend/remove_credit_notice', '__return_true', PHP_INT_MAX );

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

/**
 * Visible WordPress byline for an article.
 *
 * Use display_name rather than login/user_nicename so metadata never exposes
 * an account identifier that is not already presented to readers.
 *
 * @param WP_Post|int|null $post Post object or ID.
 * @return string
 */
function g2a_article_author_name( $post = null ) {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post ) {
		return '';
	}
	return trim( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) );
}

/**
 * Schema author that matches the byline rendered by single.php.
 *
 * Brand/team bylines resolve to the canonical business entity. Named people
 * become Person nodes; an author URL is included only when the user explicitly
 * configured a public profile URL.
 *
 * @param WP_Post|int|null $post Post object or ID.
 * @return array<string, string>
 */
function g2a_article_author_schema( $post = null ) {
	$post = get_post( $post );
	if ( ! $post instanceof WP_Post ) {
		return [ '@id' => home_url( '/#organization' ) ];
	}
	$name = g2a_article_author_name( $post );
	if ( '' === $name ) {
		return [ '@id' => home_url( '/#organization' ) ];
	}

	if ( preg_match( '/^(guns\s*2\s*ammo|guns2ammo|g2a|g2a\s+(staff|team)|editorial\s+team)$/i', $name ) ) {
		return [ '@id' => home_url( '/#organization' ) ];
	}

	$author = [
		'@type' => 'Person',
		'@id'   => home_url( '/#author-' . (int) $post->post_author ),
		'name'  => $name,
	];
	$url = esc_url_raw( (string) get_the_author_meta( 'user_url', (int) $post->post_author ) );
	if ( $url ) {
		$author['url'] = $url;
	}
	return $author;
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

	// A post may be reachable through more than one category path. WordPress's
	// permalink is the canonical content URL; the request path is not.
	if ( is_singular() && ! is_front_page() && ! is_paged() ) {
		$g2a_true_url = get_permalink( get_queried_object_id() );
		if ( $g2a_true_url ) {
			$url = $g2a_true_url;
		}
	}
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
	$author_name = is_singular( 'post' ) ? g2a_article_author_name( get_queried_object() ) : '';
	$author_name = $author_name ?: 'Guns 2 Ammo';
	echo '<meta name="author" content="' . esc_attr( $author_name ) . '">' . "\n";
	// Search and 404 responses are noindex and have no meaningful canonical
	// content URL. In particular, /?s=query must never canonicalize to home.
	if ( ! is_search() && ! is_404() ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
	}
	echo '<meta property="og:type" content="' . ( is_singular( 'post' ) ? 'article' : 'website' ) . '">' . "\n";
	echo '<meta property="og:locale" content="' . esc_attr( get_locale() ) . '">' . "\n";
	echo '<meta property="og:site_name" content="' . esc_attr( $site ) . '">' . "\n";
	echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $desc ) echo '<meta property="og:description" content="' . esc_attr( $desc ) . '">' . "\n";
	echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
	if ( $image ) {
		echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
		$dims = g2a_seo_image_dimensions( $image );
		if ( $dims ) {
			echo '<meta property="og:image:width" content="' . esc_attr( $dims[0] ) . '">' . "\n";
			echo '<meta property="og:image:height" content="' . esc_attr( $dims[1] ) . '">' . "\n";
		}
	}
	echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '">' . "\n";
	if ( $desc ) echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">' . "\n";
	if ( $image ) {
		echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n";
		echo '<meta name="twitter:image:alt" content="' . esc_attr( $title ) . '">' . "\n";
	}
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

/**
 * Real pixel width/height for an OG/Twitter image URL, resolved from its
 * actual Media Library attachment — never a fabricated placeholder size.
 * Returns null when the URL isn't a local attachment (e.g. an externally
 * hosted image), so the width/height meta tags are simply omitted rather
 * than emitting a guess.
 *
 * @return array{0:int,1:int}|null
 */
function g2a_seo_image_dimensions( $url ) {
	if ( ! $url ) {
		return null;
	}
	$attachment_id = attachment_url_to_postid( $url );
	if ( ! $attachment_id ) {
		return null;
	}
	$src = wp_get_attachment_image_src( $attachment_id, 'large' );
	if ( ! $src || empty( $src[1] ) || empty( $src[2] ) ) {
		return null;
	}
	return [ (int) $src[1], (int) $src[2] ];
}

/* ---------- JSON-LD: always-on LocalBusiness on every page ---------- */
add_action( 'wp_head', function () {
	// All business facts come from the same module used by visible templates.
	$biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();

	$g2a_logo_id       = (int) get_theme_mod( 'custom_logo' );
	$g2a_business_logo = $g2a_logo_id ? wp_get_attachment_image_url( $g2a_logo_id, 'full' ) : '';
	$g2a_business_logo = $g2a_business_logo ?: ( function_exists( 'g2a_asset' ) ? g2a_asset( 'img/guns2ammo-logo.png' ) : '' );
	$g2a_business_img  = get_theme_mod( 'g2a_og_image', '' );
	$g2a_business_img  = $g2a_business_img ?: ( function_exists( 'g2a_asset' ) ? g2a_asset( 'img/guns2ammo-storefront-sign-mesa.jpg' ) : $g2a_business_logo );
	$g2a_phone         = trim( (string) ( $biz['phone'] ?? '' ) );
	$g2a_same_as       = array_values( array_filter( [
		$biz['social']['fb'] ?? '',
		$biz['social']['ig'] ?? '',
		$biz['social']['x']  ?? '',
		$biz['social']['yt'] ?? '',
	] ) );

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
		// Canonical entity node referenced by WebSite, Article and Event schema.
		'@id'      => home_url( '/#organization' ),
		'name'     => $biz['name'] ?? get_bloginfo( 'name' ),
		'url'      => home_url( '/' ),
		'description' => 'Guns 2 Ammo is a Mesa, Arizona indoor shooting range, FFL-licensed firearm store, and NRA-certified training facility offering firearm sales, CCW training, transfers, and range memberships.',
		'priceRange' => '$$',
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
	];

	if ( $g2a_business_logo ) {
		$ld['logo'] = $g2a_business_logo;
	}
	if ( $g2a_business_img ) {
		$ld['image'] = $g2a_business_img;
	}
	if ( $g2a_phone ) {
		$ld['telephone']  = $g2a_phone;
		$ld['contactPoint'] = [
			'@type'             => 'ContactPoint',
			'telephone'         => $g2a_phone,
			'contactType'       => 'customer service',
			'areaServed'        => 'US',
			'availableLanguage' => 'English',
		];
	}
	if ( ! empty( $biz['founded_year'] ) ) {
		$ld['foundingDate'] = (string) $biz['founded_year'];
	}
	if ( $hours_spec ) {
		$ld['openingHoursSpecification'] = $hours_spec;
	}
	if ( $g2a_same_as ) {
		$ld['sameAs'] = $g2a_same_as;
	}
	if ( ! empty( $biz['slogan'] ) ) {
		$ld['slogan'] = (string) $biz['slogan'];
	}

	// Only publish membership prices when the live pricing source is loaded.
	if ( function_exists( 'g2a_plan_price' ) ) {
		$g2a_defender_price = (float) g2a_plan_price( 'defender', 'monthly' );
		$g2a_patriot_price  = (float) g2a_plan_price( 'patriot', 'monthly' );
		$g2a_guardian_price = (float) g2a_plan_price( 'guardian', 'monthly' );
		if ( $g2a_defender_price > 0 && $g2a_patriot_price > 0 && $g2a_guardian_price > 0 ) {
			$ld['hasOfferCatalog'] = [
				'@type' => 'OfferCatalog',
				'name'  => 'Guns 2 Ammo Range Memberships',
				'itemListElement' => [
					[ '@type' => 'Offer', 'name' => 'Defender Membership', 'description' => 'Individual range membership for one person.', 'price' => number_format( $g2a_defender_price, 2, '.', '' ), 'priceCurrency' => 'USD', 'url' => home_url( '/memberships/' ) ],
					[ '@type' => 'Offer', 'name' => 'Patriot Membership',  'description' => 'Two-person range membership with linked member profiles.', 'price' => number_format( $g2a_patriot_price, 2, '.', '' ), 'priceCurrency' => 'USD', 'url' => home_url( '/memberships/' ) ],
					[ '@type' => 'Offer', 'name' => 'Guardian Membership', 'description' => 'Four-person range membership for families and groups.', 'price' => number_format( $g2a_guardian_price, 2, '.', '' ), 'priceCurrency' => 'USD', 'url' => home_url( '/memberships/' ) ],
				],
			];
		}
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
		$article = [
			'@context'      => 'https://schema.org',
			'@type'         => 'Article',
			'mainEntityOfPage' => [ '@type' => 'WebPage', '@id' => get_permalink( $p ) ],
			'headline'      => get_the_title( $p ),
			'datePublished' => get_the_date( DATE_W3C, $p ),
			'dateModified'  => function_exists( 'g2a_post_lastmod_timestamp' )
				? gmdate( DATE_W3C, g2a_post_lastmod_timestamp( $p ) )
				: get_the_modified_date( DATE_W3C, $p ),
			'author'        => g2a_article_author_schema( $p ),
			'publisher'     => [ '@id' => home_url( '/#organization' ) ],
		];
		$article_desc = g2a_seo_description();
		$article_img  = has_post_thumbnail( $p ) ? get_the_post_thumbnail_url( $p, 'large' ) : '';
		if ( $article_desc ) {
			$article['description'] = $article_desc;
		}
		if ( $article_img ) {
			$article['image'] = [ $article_img ];
		}
		g2a_emit_jsonld( $article );
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
	$is_private          = function_exists( 'g2a_is_private_request' ) && g2a_is_private_request();
	$is_filtered_archive = function_exists( 'g2a_is_filtered_commerce_archive' ) && g2a_is_filtered_commerce_archive();

	if ( $is_private || is_search() || is_404() || $is_filtered_archive ) {
		$robots['noindex']  = true;
		$robots['follow']   = true;
		unset( $robots['index'] );
		if ( $is_private ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		}
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

// Rank Math emits its own robots tag when active. Apply the same shared policy
// there so plugin activation cannot reverse a private/filter noindex decision.
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
	$is_private          = function_exists( 'g2a_is_private_request' ) && g2a_is_private_request();
	$is_filtered_archive = function_exists( 'g2a_is_filtered_commerce_archive' ) && g2a_is_filtered_commerce_archive();
	if ( ! $is_private && ! is_search() && ! is_404() && ! $is_filtered_archive ) {
		return $robots;
	}

	unset( $robots['index'] );
	$robots['noindex'] = 'noindex';
	if ( $is_private ) {
		unset( $robots['follow'] );
		$robots['nofollow'] = 'nofollow';
	} else {
		unset( $robots['nofollow'] );
		$robots['follow'] = 'follow';
	}
	return $robots;
}, 40 );

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
