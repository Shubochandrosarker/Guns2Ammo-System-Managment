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
	$home  = home_url( '/' );
	$phone = get_theme_mod( 'g2a_phone', '(602) 715-2677' );
	$logo  = g2a_seo_image() ?: home_url( '/wp-content/uploads/g2a-logo.png' );

	g2a_emit_jsonld( [
		'@context' => 'https://schema.org',
		'@type'    => 'Organization',
		'@id'      => $home . '#organization',
		'name'     => 'Guns 2 Ammo',
		'url'      => $home,
		'logo'     => $logo,
		'image'    => $logo,
		'telephone'=> $phone,
		'description' => "Guns 2 Ammo is a Mesa, Arizona indoor shooting range, FFL-licensed firearm store, and NRA-certified training facility. We sell and buy firearms, run CCW certification courses, and offer range memberships.",
		'address'  => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => get_theme_mod( 'g2a_addr1', '6030 E Main St, Suite 103' ),
			'addressLocality' => 'Mesa',
			'addressRegion'   => 'AZ',
			'postalCode'      => '85205',
			'addressCountry'  => 'US',
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

/* ---------- llms.txt content ---------- */
function g2a_llms_txt( $full = false ) {
	$h = untrailingslashit( home_url( '/' ) );

	$out  = "# Guns 2 Ammo\n\n";
	$out .= "> Mesa, Arizona indoor shooting range, FFL-licensed firearm store, and NRA-certified training facility. Book a lane, train for an Arizona CCW permit, shoot a machine gun, buy or sell firearms, and join a range membership.\n\n";
	$out .= "Guns 2 Ammo is a locally owned indoor shooting range and gun store at 6030 E Main St, Suite 103, Mesa, AZ 85205. Phone: (602) 715-2677. Email: sales@guns2ammo.com. ";
	$out .= "Hours: MondayThursday 10am6pm, Friday 10am7pm, Saturday 10am7pm, Sunday 12pm6pm. ";
	$out .= "Rated 4.7 stars across 449+ Google reviews. Serving Mesa, Phoenix, Gilbert, Tempe, Chandler, Scottsdale, Apache Junction, Queen Creek and the greater Phoenix East Valley in Maricopa County, Arizona.\n\n";

	$out .= "## Range & Memberships\n";
	$out .= "- [Book A Lane]({$h}/book-a-lane/): Reserve a lane at the 6-lane climate-controlled indoor range. Hours, pricing, rules and what to bring.\n";
	$out .= "- [Memberships]({$h}/memberships/): Range memberships  Defender \$29.99/mo (1 person), Patriot \$39.99/mo (2 people), Guardian \$59.99/mo (4 people). Unlimited range time, no contracts.\n";
	$out .= "- [Range Fees & Pricing]({$h}/pricing/): Walk-in lane and rental pricing, and member savings.\n";
	$out .= "- [Ladies Tuesday]({$h}/ladies-tuesday/): Every Tuesday, women shoot for a free hour of lane time.\n";
	$out .= "- [Range Safety Rules]({$h}/range-safety/): Range rules, eye/ear protection policy, and ammunition policy.\n\n";

	$out .= "## Training & CCW\n";
	$out .= "- [Training Courses]({$h}/training/): NRA-certified firearms courses for every level.\n";
	$out .= "- [Arizona CCW Certification]({$h}/arizona-ccw-certification/): State-recognized concealed carry course, taught Saturdays, with 37+ state reciprocity.\n";
	$out .= "- [California CCW]({$h}/training/california-ccw/): Live-fire shooting component for the California CCW requirement.\n";
	$out .= "- [Defensive Pistol]({$h}/training/defensive-pistol/): 3-hour defensive handgun course.\n";
	$out .= "- [Private Instruction]({$h}/private-instruction/): One-on-one firearms training.\n\n";

	$out .= "## Shop, Transfers & Selling\n";
	$out .= "- [Shop Firearms]({$h}/shop/): Handguns, rifles, ammunition and magazines with same-day local pickup in Mesa.\n";
	$out .= "- [Collections]({$h}/collections/): Browse handguns, rifles, ammunition and magazines.\n";
	$out .= "- [FFL Transfers]({$h}/transfers/): Federal firearm transfer service from a base \$30 transfer fee.\n";
	$out .= "- [FFL & NFA Services]({$h}/ffl-services/): NFA / Class III items, firearm shipping, consignment and trusts.\n";
	$out .= "- [Sell Your Gun]({$h}/sell-your-gun/): Sell used firearms to Guns 2 Ammo for a fair cash offer from a licensed dealer.\n\n";

	$out .= "## Experience\n";
	$out .= "- [Machine Gun Experience]({$h}/machine-gun/): Shoot full-auto platforms  M16, Glock 18, MP5, AK-47, AK-74  with a certified RSO at your side.\n\n";

	$out .= "## Help\n";
	$out .= "- [Get Support]({$h}/get-support/): Membership, selling, transfer, order and general support requests.\n";
	$out .= "- [Contact]({$h}/contact/): Visit, call, or get directions.\n";
	$out .= "- [Knowledge Hub]({$h}/blog/): Guides on CCW law, range safety, training and gear.\n";

	if ( $full ) {
		$out .= "\n## About Guns 2 Ammo\n\n";
		$out .= "Guns 2 Ammo has served the Phoenix East Valley from Mesa, Arizona since 2014. The facility combines three businesses under one roof: a 6-lane, climate-controlled indoor shooting range; a federally licensed (FFL) firearm retail store; and an NRA- and USCCA-certified training academy. It is a family-owned local business, not a national chain.\n\n";

		$out .= "### Location & contact\n";
		$out .= "Address: 6030 E Main St, Suite 103, Mesa, AZ 85205, United States. Phone: (602) 715-2677. Email: sales@guns2ammo.com. ";
		$out .= "Directions: https://www.google.com/maps/dir/?api=1&destination=Guns+2+Ammo,+6030+E+Main+St+%23103,+Mesa,+AZ+85205. ";
		$out .= "The range sits on East Main Street in east Mesa, a short drive from Apache Junction, Gilbert and the wider East Valley.\n\n";

		$out .= "### Hours\n";
		$out .= "Monday 10am6pm, Tuesday 10am6pm, Wednesday 10am6pm, Thursday 10am6pm, Friday 10am7pm, Saturday 10am7pm, Sunday 12pm6pm. The last lane booking each day is taken one hour before close.\n\n";

		$out .= "### Memberships in detail\n";
		$out .= "- Defender  \$29.99/month or \$299.99/year. Individual membership for 1 person. Includes unlimited range time, online lane reservations, and member profile and waiver tracking.\n";
		$out .= "- Patriot  \$39.99/month or \$449.99/year. Two-person membership: a primary member plus one linked profile, with per-person waiver and check-in tracking.\n";
		$out .= "- Guardian  \$59.99/month or \$649.99/year. Four-person membership for families or groups: a primary member plus three linked profiles.\n";
		$out .= "All memberships include unlimited range time during open hours and can be cancelled at any time with no contract. Members shoot lane time free and receive discounted rentals, ammunition and course tuition. Law enforcement, active military and veterans receive 15% off any plan with valid ID.\n\n";

		$out .= "### Walk-in range pricing\n";
		$out .= "Lane rental is \$20 per hour with each additional shooter \$15 per lane (up to 3 shooters per lane). Handgun rentals start at \$15, rifle rentals start at \$25. Eye and ear protection and paper targets are available at check-in. Members on qualifying plans receive included lane time and discounted guest rates.\n\n";

		$out .= "### Training courses\n";
		$out .= "Basic Handgun, Arizona CCW Certification, California CCW (live-fire component), Church Security, Women's Intro, Defensive Pistol, Rifle Fundamentals, Refuse To Be A Victim, and Youth Firearm Safety. The Arizona CCW course is held on Saturdays and covers laws, regulations and safe handling. Members save on course tuition.\n\n";

		$out .= "### Machine gun experience\n";
		$out .= "One-on-one, fully-automatic instruction with a certified Range Safety Officer. Current full-auto platforms include the M16, Glock 18, MP5, AK-47 and AK-74, with more added as the collection grows. A photo and video pack is available. Machine gun rentals are popular with visitors and out-of-town guests who cannot access full-auto firearms at home.\n\n";

		$out .= "### FFL transfers, NFA & selling\n";
		$out .= "Guns 2 Ammo is a licensed FFL dealer. Standard incoming firearm transfers start at a \$30 base fee plus actual shipping and insurance. NFA / Class III items (suppressors, SBRs, full-auto) are supported, and a notary is available on site to help set up gun trusts. Non-member customers who buy and transfer an NFA item elsewhere pay a \$75 fee; there is no fee when the firearm is purchased from Guns 2 Ammo. The store also buys used firearms from the public  handguns, rifles, shotguns, collectible and estate firearms. No license is required to sell a firearm you lawfully own to a licensed dealer; sellers bring a valid Arizona photo ID. Offers are typically returned the same business day, payable as cash or boosted store credit.\n\n";

		$out .= "### Service area\n";
		$out .= "Mesa, Phoenix, Gilbert, Tempe, Chandler, Scottsdale, Apache Junction, Queen Creek, and all of Maricopa County, Arizona. Firearms can also be shipped nationwide where lawful via FFL transfer.\n\n";

		$out .= "### Common questions\n";
		$out .= "- Do I need a membership to shoot? No  walk-ins are welcome for solo lane rental, no appointment required.\n";
		$out .= "- Can beginners shoot here? Yes  shooters 8+ are welcome with a parent or guardian, and an RSO is always on duty.\n";
		$out .= "- Do you rent firearms? Yes  40+ rental firearms are available on site.\n";
		$out .= "- Can I bring my own gun and ammo? Yes  factory-new ammunition only; no reloads or steel-core.\n";
		$out .= "- How do I get an Arizona CCW permit? Take the Arizona CCW Certification course, held Saturdays at the range.\n";
	}

	$out .= "\n## Canonical\n";
	$out .= "- Website: {$h}/\n";
	$out .= "- Sitemap (HTML): {$h}/sitemap/\n";
	$out .= "- Sitemap (XML): {$h}/sitemap.xml\n";
	$out .= "- Full profile: {$h}/llms-full.txt\n";

	return $out;
}

/* ---------- Serve /llms.txt and /llms-full.txt ---------- */
add_action( 'init', function () {
	$path = trim( (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ), '/' );
	if ( 'llms.txt' !== $path && 'llms-full.txt' !== $path ) return;
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: all' );
	echo g2a_llms_txt( 'llms-full.txt' === $path );
	exit;
} );

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

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<?xml-stylesheet type="text/xsl" href="' . esc_url( home_url( '/wp-sitemap.xsl' ) ) . '"?>' . "\n";
	echo "<!-- Guns 2 Ammo  Mesa, AZ indoor shooting range, gun store & training facility. -->\n";
	echo "<!-- Local business sitemap. NAP: Guns 2 Ammo, 6030 E Main St Suite 103, Mesa, AZ 85205, (602) 715-2677. -->\n";
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

	$lines = [];

	$lines[] = '# ======================================================================';
	$lines[] = '# robots.txt  Guns 2 Ammo';
	$lines[] = '# Mesa, Arizona indoor shooting range, FFL gun store & NRA training facility';
	$lines[] = '# 6030 E Main St, Suite 103, Mesa, AZ 85205  (602) 715-2677';
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
