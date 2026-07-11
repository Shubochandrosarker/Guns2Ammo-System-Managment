<?php
/**
 * Template Name: Arizona CCW Certification
 *
 * Flagship landing page for /arizona-ccw-certification/. Two course formats:
 * the $85 four-hour classroom course (Saturdays 11:30 AM) and the $149.99
 * five-hour live-fire course (Monday evenings).
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Single source of truth for NAP — see inc/business-info.php.
$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();

$g2a_ccw_title       = 'Arizona CCW Class in Mesa, AZ | $85 · Saturdays 11:30 AM';
$g2a_ccw_description = 'Arizona CCW class in Mesa: $85 · 4-hour classroom course, Saturdays 11:30 AM, DPS paperwork done in class. Live-fire option $149.99 · 5 hours. NRA-certified instructors.';
$g2a_ccw_url         = home_url( '/arizona-ccw-certification/' );

/* Keep the page-specific search snippet consistent across WordPress, Rank Math,
 * Yoast, and the theme's own SEO/OG output without changing global SEO rules. */
add_filter( 'pre_get_document_title', function () use ( $g2a_ccw_title ) {
	return $g2a_ccw_title;
}, 99 );
add_filter( 'rank_math/frontend/title', function () use ( $g2a_ccw_title ) {
	return $g2a_ccw_title;
}, 99 );
add_filter( 'rank_math/frontend/description', function () use ( $g2a_ccw_description ) {
	return $g2a_ccw_description;
}, 99 );
add_filter( 'wpseo_title', function () use ( $g2a_ccw_title ) {
	return $g2a_ccw_title;
}, 99 );
add_filter( 'wpseo_metadesc', function () use ( $g2a_ccw_description ) {
	return $g2a_ccw_description;
}, 99 );

/* g2a_seo_description() reads the queried page excerpt. Set the runtime value
 * only, so the theme's meta description and social previews use this copy. */
$g2a_ccw_page = get_queried_object();
if ( $g2a_ccw_page instanceof WP_Post ) {
	$g2a_ccw_page->post_excerpt = $g2a_ccw_description;
}

/* FAQ content — rendered in the .g2a-faq accordion below AND emitted as
 * FAQPage JSON-LD, so markup and schema never drift apart. */
$g2a_ccw_faqs = [
	[
		'q' => 'How much does the Arizona CCW class cost?',
		'a' => 'The classroom course is $85 and runs 4 hours, Saturdays at 11:30 AM. The live-fire version is $149.99, runs 5 hours on Monday evenings, and adds one hour of coached shooting on our indoor range. Both include your DPS paperwork completed in class.',
	],
	[
		'q' => 'Arizona is constitutional carry — do I still need this class?',
		'a' => 'You can legally carry without a permit in Arizona (18+ open carry, 21+ concealed). The permit still matters: it is honored in 37 states, lets you skip the NICS background-check wait when buying from a licensed dealer, restores carry rights inside the federal school-zone buffer, and makes carrying in restaurants that serve alcohol clearly legal.',
	],
	[
		'q' => 'What should I bring to class?',
		'a' => 'A government-issued photo ID and something to take notes with. No firearm is needed for the $85 classroom course. Live-fire students can bring their own handgun or rent one of ours — eye and ear protection are provided either way.',
	],
	[
		'q' => 'How long is the permit valid, and how many states honor it?',
		'a' => 'The Arizona concealed weapons permit is valid for 5 years and is currently honored in 37 states. Renewal is a simpler process than the original application — no class retake is required by the state.',
	],
	[
		'q' => 'Is there a shooting requirement?',
		'a' => 'No. Arizona does not require a live-fire qualification for the permit, so the $85 classroom course involves no shooting at all. If you want trigger time with a coach, choose the $149.99 live-fire option, which adds one hour on our 6-lane indoor range.',
	],
	[
		'q' => 'What is the age requirement?',
		'a' => 'You must be at least 21 to apply for the Arizona permit — or at least 19 if you are active-duty military or a veteran. You will also need to meet the state\'s standard eligibility rules, which we walk through in class.',
	],
];

/* Course JSON-LD for both formats + HowTo for the DPS application. */
add_action( 'wp_head', function () use ( $g2a_ccw_url, $g2a_ccw_description, $g2a_biz ) {
	$place = [
		'@type'   => 'Place',
		'name'    => $g2a_biz['name'] ?? 'Guns 2 Ammo',
		'address' => [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103',
			'addressLocality' => $g2a_biz['city'] ?? 'Mesa',
			'addressRegion'   => $g2a_biz['region'] ?? 'AZ',
			'postalCode'      => $g2a_biz['postal'] ?? '85205',
			'addressCountry'  => $g2a_biz['country'] ?? 'US',
		],
	];
	$area  = [
		[ '@type' => 'City', 'name' => 'Mesa' ],
		[ '@type' => 'City', 'name' => 'Gilbert' ],
		[ '@type' => 'City', 'name' => 'Apache Junction' ],
		[ '@type' => 'AdministrativeArea', 'name' => 'East Valley, Phoenix metro, Arizona' ],
	];
	$schema = [
		'@context' => 'https://schema.org',
		'@graph'   => [
			[
				'@type'                        => 'Course',
				'@id'                          => $g2a_ccw_url . '#course-classroom',
				'name'                         => 'AZ CCW — Classroom',
				'alternateName'                => [ 'Arizona CCW Permit Class', 'Arizona Concealed Carry Permit Class in Mesa, AZ' ],
				'description'                  => '4-hour Arizona CCW classroom course in Mesa, AZ: about 3.5 hours of Arizona law and regulations plus 30 minutes completing your DPS paperwork in class. No live fire, no prerequisite. Saturdays at 11:30 AM. Taught by NRA-certified instructors.',
				'url'                          => $g2a_ccw_url,
				'mainEntityOfPage'             => [ '@type' => 'WebPage', '@id' => $g2a_ccw_url ],
				'provider'                     => [ '@id' => home_url( '/#business' ) ],
				'timeRequired'                 => 'PT4H',
				'educationalCredentialAwarded' => 'Certificate of Completion',
				'courseMode'                   => 'Onsite',
				'inLanguage'                   => 'en-US',
				'audience'                     => [ '@type' => 'EducationalAudience', 'audienceType' => 'Adults age 21 and older (19+ active military or veterans)' ],
				'areaServed'                   => $area,
				'offers'                       => [
					'@type'                   => 'Offer',
					'@id'                     => $g2a_ccw_url . '#offer-classroom',
					'url'                     => $g2a_ccw_url . '#registration',
					'price'                   => '85.00',
					'priceCurrency'           => 'USD',
					'availability'            => 'https://schema.org/InStock',
					'availableDeliveryMethod' => 'https://schema.org/InStoreOnly',
				],
				'hasCourseInstance'            => [
					'@type'          => 'CourseInstance',
					'name'           => 'Arizona CCW Classroom Course — Saturdays 11:30 AM',
					'courseMode'     => 'Onsite',
					'courseWorkload' => 'PT4H',
					'offers'         => [ '@id' => $g2a_ccw_url . '#offer-classroom' ],
					'location'       => $place,
				],
			],
			[
				'@type'                        => 'Course',
				'@id'                          => $g2a_ccw_url . '#course-livefire',
				'name'                         => 'AZ CCW + Live Fire',
				'alternateName'                => [ 'Arizona CCW Class with Live Fire', 'Arizona CCW Live-Fire Course in Mesa, AZ' ],
				'description'                  => '5-hour Arizona CCW course in Mesa, AZ: the full classroom curriculum plus one hour of coached live fire on a 6-lane indoor range. Monday evenings. Taught by NRA-certified instructors.',
				'url'                          => $g2a_ccw_url,
				'mainEntityOfPage'             => [ '@type' => 'WebPage', '@id' => $g2a_ccw_url ],
				'provider'                     => [ '@id' => home_url( '/#business' ) ],
				'timeRequired'                 => 'PT5H',
				'educationalCredentialAwarded' => 'Certificate of Completion',
				'courseMode'                   => 'Onsite',
				'inLanguage'                   => 'en-US',
				'audience'                     => [ '@type' => 'EducationalAudience', 'audienceType' => 'Adults age 21 and older (19+ active military or veterans)' ],
				'areaServed'                   => $area,
				'offers'                       => [
					'@type'                   => 'Offer',
					'@id'                     => $g2a_ccw_url . '#offer-livefire',
					'url'                     => $g2a_ccw_url . '#registration',
					'price'                   => '149.99',
					'priceCurrency'           => 'USD',
					'availability'            => 'https://schema.org/InStock',
					'availableDeliveryMethod' => 'https://schema.org/InStoreOnly',
				],
				'hasCourseInstance'            => [
					'@type'          => 'CourseInstance',
					'name'           => 'Arizona CCW + Live Fire Course — Monday Evenings',
					'courseMode'     => 'Onsite',
					'courseWorkload' => 'PT5H',
					'offers'         => [ '@id' => $g2a_ccw_url . '#offer-livefire' ],
					'location'       => $place,
				],
			],
			[
				'@type'         => 'HowTo',
				'@id'           => $g2a_ccw_url . '#dps-application',
				'name'          => 'How to Apply for the Arizona CCW Permit After Class',
				'description'   => 'Complete the course at Guns 2 Ammo in Mesa, get fingerprinted, and submit your application to the Arizona Department of Public Safety.',
				'estimatedCost' => [ '@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => '60.00' ],
				'step'          => [
					[ '@type' => 'HowToStep', 'position' => 1, 'name' => 'Receive your certificate same day', 'text' => 'Your certificate of completion is issued at the end of class — there is nothing to wait on from us.' ],
					[ '@type' => 'HowToStep', 'position' => 2, 'name' => 'Get fingerprinted', 'text' => 'Fingerprint guidance is covered in class so you know exactly where to go and what to ask for.' ],
					[ '@type' => 'HowToStep', 'position' => 3, 'name' => 'Submit your AZ DPS application', 'text' => 'Mail your application packet to the Arizona Department of Public Safety with the $60 state fee.' ],
					[ '@type' => 'HowToStep', 'position' => 4, 'name' => 'Wait for processing', 'text' => 'Typical processing takes a few weeks. DPS will mail your permit when it is approved.' ],
					[ '@type' => 'HowToStep', 'position' => 5, 'name' => 'Carry with a 5-year permit', 'text' => 'Your Arizona concealed weapons permit is valid for five years and honored in 37 states.' ],
				],
			],
		],
	];

	echo "\n<!-- Arizona CCW Course + HowTo Schema -->\n";
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 11 );

get_header();
?>
<style>
/* Page-scoped layout on top of assets/css/ccw.css — theme tokens only, light/dark aware. */
.ccw-hero .ccw-sub { font-family: var(--font-mono); font-size: 13px; letter-spacing: 0.18em; text-transform: uppercase; color: var(--color-brass-bright); margin: 18px 0 0; }
.ccw-courses { padding: 100px 32px; }
.ccw-courses .wrap { max-width: 1280px; margin: 0 auto; }
.ccw-course-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 24px; margin-top: 36px; }
@media (max-width: 900px) { .ccw-course-grid { grid-template-columns: 1fr; } }
.ccw-card { background: var(--color-gunmetal); border: 1px solid var(--color-hairline); padding: 36px; display: flex; flex-direction: column; }
.ccw-card.is-flagship { border-color: var(--color-brass-dim); }
.ccw-card .tag { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; text-transform: uppercase; color: var(--color-brass-bright); }
.ccw-card h3 { font-family: var(--font-display); font-size: 34px; color: var(--color-white); margin: 10px 0 4px; letter-spacing: 0.02em; }
.ccw-card .price { font-family: var(--font-display); font-size: 52px; color: var(--color-brass-bright); line-height: 1; margin: 10px 0 2px; }
.ccw-card .sched { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.2em; text-transform: uppercase; color: var(--color-silver); margin-bottom: 18px; }
.ccw-card p { color: var(--color-fog); font-size: 14px; line-height: 1.7; margin: 0 0 16px; }
.ccw-card ul { list-style: none; padding: 0; margin: 0 0 22px; }
.ccw-card ul li { padding: 9px 0; font-size: 14px; color: var(--color-fog); border-bottom: 1px solid var(--color-hairline); }
.ccw-card ul li:last-child { border-bottom: 0; }
.ccw-card .cta { margin-top: auto; }
.ccw-law { padding: 100px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); border-bottom: 1px solid var(--color-hairline); }
.ccw-law .wrap { max-width: 1080px; margin: 0 auto; }
.ccw-law h2 { font-family: var(--font-display); font-size: clamp(36px,5vw,64px); color: var(--color-white); line-height: 1; margin: 14px 0 22px; letter-spacing: 0.01em; }
.ccw-law p.body { color: var(--color-fog); font-size: 15px; line-height: 1.8; margin: 0 0 18px; max-width: 72ch; }
.ccw-stat { border-left: 3px solid var(--color-brass); background: var(--color-void); padding: 18px 22px; margin: 0 0 26px; color: var(--color-white); font-size: 16px; line-height: 1.7; max-width: 72ch; }
.ccw-why { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; margin-top: 28px; }
@media (max-width: 760px) { .ccw-why { grid-template-columns: 1fr; } }
.ccw-why .w { background: var(--color-void); border: 1px solid var(--color-hairline); padding: 22px; }
.ccw-why .w .t { font-family: var(--font-condensed); font-weight: 600; font-size: 16px; letter-spacing: 0.05em; text-transform: uppercase; color: var(--color-white); margin-bottom: 6px; }
.ccw-why .w .d { color: var(--color-fog); font-size: 14px; line-height: 1.65; }
.ccw-curr { padding: 100px 32px; }
.ccw-curr .wrap { max-width: 1280px; margin: 0 auto; }
.ccw-curr h2 { font-family: var(--font-display); font-size: clamp(36px,5vw,64px); color: var(--color-white); line-height: 1; margin: 14px 0 12px; letter-spacing: 0.01em; }
.ccw-curr .lede { color: var(--color-fog); font-size: 15px; line-height: 1.75; max-width: 70ch; margin: 0 0 34px; }
.ccw-curr-grid { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 18px; }
@media (max-width: 900px) { .ccw-curr-grid { grid-template-columns: 1fr; } }
.ccw-curr-grid .col p { color: var(--color-fog); font-size: 14px; line-height: 1.7; margin: 0; }
.timeline .tl-intro { color: var(--color-fog); font-size: 15px; line-height: 1.75; max-width: 70ch; margin: -34px 0 40px; }
.instructors { padding: 100px 32px; background: var(--color-void); border-top: 1px solid var(--color-hairline); }
.instructors .c { max-width: 1280px; margin: 0 auto; }
.instructors h2 { font-family: var(--font-display); font-size: clamp(36px,5vw,64px); color: var(--color-white); line-height: 1; margin: 14px 0 12px; letter-spacing: 0.01em; }
.instructors .lede { color: var(--color-fog); font-size: 15px; line-height: 1.75; max-width: 70ch; margin: 0; }
.ig { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 36px; }
@media (max-width: 900px) { .ig { grid-template-columns: 1fr; } }
.inst { padding: 28px; background: var(--color-gunmetal); border: 1px solid var(--color-hairline); }
.inst .av { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, var(--color-brass), var(--color-brass-dim)); display: grid; place-items: center; font-family: var(--font-display); font-size: 26px; color: var(--color-void); margin-bottom: 18px; }
.inst .nm { font-family: var(--font-display); font-size: 26px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
.inst .role { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-brass-bright); text-transform: uppercase; margin-top: 6px; }
.inst p { color: var(--color-fog); font-size: 14px; line-height: 1.7; margin: 14px 0; }
.inst .creds { display: flex; gap: 6px; flex-wrap: wrap; grid-template-columns: none; }
.inst .creds span { font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.2em; padding: 4px 8px; border: 1px solid var(--color-hairline-bright); color: var(--color-silver); text-transform: uppercase; }
.ccw-resv { padding: 100px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); }
.ccw-resv .rf-card { max-width: 880px; margin: 0 auto; background: var(--color-void); border: 1px solid var(--color-brass-dim); padding: 40px; }
@media (max-width: 560px) { .ccw-resv .rf-card { padding: 26px; } }
.ccw-resv h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,56px); color: var(--color-white); letter-spacing: 0.02em; line-height: 1; }
.ccw-resv .rf-intro { color: var(--color-fog); margin: 14px 0 26px; font-size: 15px; line-height: 1.7; max-width: 60ch; }
.ccw-resv .rf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 560px) { .ccw-resv .rf-grid { grid-template-columns: 1fr; } }
.ccw-resv .rf-full { grid-column: 1 / -1; }
.ccw-resv .rf-hp { position: absolute; left: -9999px; width: 1px; height: 1px; overflow: hidden; }
</style>

<header class="ccw-hero">
  <div class="c">
    <span class="eyebrow">Mesa, AZ · State-Compliant Arizona CCW</span>
    <h1 style="margin-top:18px;">ARIZONA CCW PERMIT<br><span class="a">CERTIFICATION COURSE.</span></h1>
    <p class="ccw-sub">$85 · 4 Hours · Saturdays 11:30 AM · Mesa, AZ</p>
    <p>Arizona's concealed carry laws, explained clearly by NRA-certified instructors — so you can carry confidently and legally anywhere in the East Valley and across 37 states.</p>
    <div style="display:flex; gap:14px; flex-wrap:wrap;">
      <a class="btn btn-ember btn-lg" href="#registration">Reserve Your Seat</a>
      <a class="btn btn-brass btn-lg" href="#courses">Compare Both Courses</a>
    </div>
    <div class="ccw-meta">
      <div><div class="v">$85</div><div class="l">Classroom Course</div></div>
      <div><div class="v">4 Hours</div><div class="l">Saturdays 11:30 AM</div></div>
      <div><div class="v">$149.99</div><div class="l">+ Live Fire · 5 Hours</div></div>
      <div><div class="v">21+</div><div class="l">19+ Military / Veteran</div></div>
    </div>
  </div>
</header>

<section class="ccw-courses" id="courses">
  <div class="wrap">
    <span class="eyebrow">Two Ways To Certify</span>
    <h2 style="font-family:var(--font-display); font-size:clamp(36px,5vw,64px); color:var(--color-white); line-height:1; margin:14px 0 12px;">PICK YOUR COURSE</h2>
    <p style="color:var(--color-fog); font-size:15px; line-height:1.75; max-width:70ch; margin:0;">Same Arizona curriculum, two formats. Both are taught by NRA-certified instructors at our Mesa storefront, and both end with your DPS paperwork completed before you walk out the door.</p>
    <div class="ccw-course-grid" data-reveal-stagger>
      <div class="ccw-card is-flagship">
        <span class="tag">Classroom · No Prerequisite</span>
        <h3>AZ CCW — CLASSROOM</h3>
        <div class="price">$85</div>
        <div class="sched">4 Hours · Saturdays 11:30 AM</div>
        <p>The straightforward path to your Arizona concealed weapons permit. Roughly 3.5 hours of Arizona law and regulations, then the final half hour is spent completing your DPS paperwork in class — so the only thing left to do afterward is mail it.</p>
        <ul>
          <li>~3.5 hours of Arizona law &amp; regulations</li>
          <li>0.5 hour of DPS paperwork, completed in class</li>
          <li>No live fire — entirely classroom-based</li>
          <li>No prerequisite, no firearm needed</li>
          <li>Certificate of completion issued same day</li>
        </ul>
        <div class="cta"><a class="btn btn-ember btn-arrow" href="#registration">Reserve — Classroom $85</a></div>
      </div>
      <div class="ccw-card">
        <span class="tag">Classroom + Range · Monday Evenings</span>
        <h3>AZ CCW + LIVE FIRE</h3>
        <div class="price">$149.99</div>
        <div class="sched">5 Hours · Monday Evenings</div>
        <p>The same certification curriculum, plus one full hour of coached live fire on our 6-lane indoor range. Arizona doesn't require a shooting qualification — but if you've never carried, an hour of supervised trigger time with an instructor is the most valuable upgrade we offer.</p>
        <ul>
          <li>Full classroom curriculum + DPS paperwork</li>
          <li>1 hour of coached live fire, 6-lane indoor range</li>
          <li>Bring your own handgun or rent one of ours</li>
          <li>Eye &amp; ear protection provided</li>
          <li>Certificate of completion issued same day</li>
        </ul>
        <div class="cta"><a class="btn btn-brass btn-arrow" href="#registration">Reserve — Live Fire $149.99</a></div>
      </div>
    </div>
  </div>
</section>

<section class="ccw-law" id="arizona-law">
  <div class="wrap" data-reveal>
    <span class="eyebrow">Constitutional Carry &amp; The Permit</span>
    <h2>WHAT ARIZONA LAW ACTUALLY REQUIRES (A.R.S. §13-3112)</h2>
    <p class="ccw-stat">The 4-hour Arizona CCW class at Guns 2 Ammo in Mesa costs $85; the live-fire version is $149.99 and runs 5 hours.</p>
    <p class="body">Arizona is a constitutional carry state. Under <a href="https://www.azleg.gov/ars/13/03112.htm" rel="noopener" style="color:var(--color-brass-bright);">A.R.S. §13-3112</a>, adults 18 and older may open carry, and adults 21 and older may carry concealed without any permit at all. So why do thousands of Arizonans — from Mesa and Gilbert to Apache Junction and across the Phoenix metro — still get the permit every year?</p>
    <p class="body">Because the permit unlocks things constitutional carry can't. It travels with you, it speeds up purchases, and it clears up the gray areas in state and federal law that catch unpermitted carriers off guard. Here's what the Arizona concealed weapons permit actually does for you:</p>
    <div class="ccw-why" data-reveal-stagger>
      <div class="w">
        <div class="t">Reciprocity In 37 States</div>
        <div class="d">Constitutional carry stops at the state line. The Arizona permit is honored in 37 states, so a road trip to see family doesn't turn your legal carry into a felony at the border.</div>
      </div>
      <div class="w">
        <div class="t">NICS-Exempt Purchases</div>
        <div class="d">A current Arizona permit serves as an alternative to the NICS background check at licensed dealers (FFLs). Buying a firearm becomes a faster, paperwork-lighter transaction.</div>
      </div>
      <div class="w">
        <div class="t">School-Zone Exception</div>
        <div class="d">The federal Gun-Free School Zones Act creates a 1,000-foot buffer around schools that unpermitted carriers cross constantly without realizing it. A state-issued permit provides the exception.</div>
      </div>
      <div class="w">
        <div class="t">Restaurant Carry Clarity</div>
        <div class="d">Want to carry into a restaurant that serves alcohol? Arizona law allows it for permit holders (provided you're not drinking and no prohibiting sign is posted). Without the permit, you're leaving it in the car.</div>
      </div>
    </div>
  </div>
</section>

<section class="ccw-curr" id="course-content">
  <div class="wrap" data-reveal>
    <span class="eyebrow">The Curriculum</span>
    <h2>WHAT THE CLASS COVERS</h2>
    <p class="lede">Four hours, no filler. Our instructors teach Arizona law the way it actually applies on the street and in your home — plain language, real scenarios, and time for your questions. Every student leaves with the same six blocks covered:</p>
    <div class="ccw-curr-grid" data-reveal-stagger>
      <div class="col">
        <h3>STATUTES &amp; USE OF FORCE</h3>
        <p>Arizona's carry statutes and the legal framework for self-defense: what justification means, when force is and isn't lawful, and the duties that follow any defensive encounter.</p>
      </div>
      <div class="col">
        <h3>WHERE YOU CAN &amp; CAN'T CARRY</h3>
        <p>Prohibited places under state and federal law — schools, polling places, federal buildings, posted businesses — and how to handle a "no firearms" sign without breaking the law.</p>
      </div>
      <div class="col">
        <h3>LAW ENFORCEMENT CONTACT</h3>
        <p>What to say and do during a traffic stop or police contact while carrying. Arizona's disclosure rules, keeping the encounter calm, and protecting yourself legally.</p>
      </div>
      <div class="col">
        <h3>SAFE STORAGE &amp; LIABILITY</h3>
        <p>Securing firearms at home and in the vehicle, preventing unauthorized access, and understanding the civil and criminal liability that comes with ownership and carry.</p>
      </div>
      <div class="col">
        <h3>PERMIT APPLICATION WALKTHROUGH</h3>
        <p>We don't just hand you a packet — the final half hour of class is spent completing your Arizona DPS application together, line by line, so nothing gets bounced back.</p>
      </div>
      <div class="col">
        <h3>FINGERPRINTING GUIDANCE</h3>
        <p>Exactly where to get fingerprinted, what to ask for, and how the prints attach to your application — covered in class so the step after the certificate is obvious.</p>
      </div>
    </div>
  </div>
</section>

<section class="timeline" id="how-to-apply">
  <div class="c" data-reveal>
    <span class="eyebrow">After Class</span>
    <h2>HOW TO APPLY TO AZ DPS AFTER CLASS</h2>
    <p class="tl-intro">The class satisfies Arizona's training requirement; the permit itself is issued by the Arizona Department of Public Safety. Here's the whole path from your seat in our Mesa classroom to a permit in your wallet:</p>
    <div class="tl-grid" data-reveal-stagger>
      <div class="tl-step">
        <div class="num">01</div>
        <h4>Certificate Issued Same Day</h4>
        <p>You leave class with your certificate of completion in hand — nothing to wait on from us.</p>
      </div>
      <div class="tl-step">
        <div class="num">02</div>
        <h4>Get Fingerprinted</h4>
        <p>Follow the fingerprinting guidance from class — where to go and exactly what to request.</p>
      </div>
      <div class="tl-step">
        <div class="num">03</div>
        <h4>Submit To AZ DPS + $60 Fee</h4>
        <p>Mail your completed application packet to Arizona DPS with the $60 state fee.</p>
      </div>
      <div class="tl-step">
        <div class="num">04</div>
        <h4>Typical Processing: A Few Weeks</h4>
        <p>DPS reviews and processes applications in a few weeks on average, then mails your permit.</p>
      </div>
      <div class="tl-step">
        <div class="num">05</div>
        <h4>Permit Valid 5 Years</h4>
        <p>Your Arizona permit is good for five years and honored in 37 states. Renewal is simpler than the first application.</p>
      </div>
    </div>
  </div>
</section>

<?php if ( function_exists( 'g2a_render_instructors' ) ) : ?>
<section class="instructors" id="instructors">
  <div class="c" data-reveal>
    <span class="eyebrow">Who's Teaching</span>
    <h2>YOUR INSTRUCTORS</h2>
    <p class="lede">Every Arizona CCW course at Guns 2 Ammo is taught by NRA-certified instructors who teach this material weekly — not occasionally. Safety-first, question-friendly, and zero ego. Students drive in from Mesa, Gilbert, Apache Junction, and across the East Valley for a reason.</p>
    <?php g2a_render_instructors(); ?>
	  <?php if ( shortcode_exists( 'g2a_upcoming_events' ) ) : ?>
	  <span style="margin-top: 50px;" class="eyebrow">Upcoming Events</span>
	  <h2>Book Your Seat For Upcoming Events</h2>
	 <p class="sub">Themed sessions and intro classes are added monthly. And Standard Tuesdays are open floor &mdash; show up any time during open hours.</p>
	<?php echo do_shortcode( '[g2a_upcoming_events]' ); ?>
	  <?php endif; ?>

  </div>

</section>
<?php endif; ?>

<section class="faq" id="faq">
  <div class="c" data-reveal>
    <span class="eyebrow">Common Questions</span>
    <h2>ARIZONA CCW FAQ</h2>
    <div class="g2a-faq" data-reveal-stagger>
      <?php foreach ( $g2a_ccw_faqs as $g2a_ccw_i => $g2a_ccw_f ) : ?>
        <details<?php echo 0 === $g2a_ccw_i ? ' open' : ''; ?>>
          <summary><?php echo esc_html( $g2a_ccw_f['q'] ); ?></summary>
          <div class="faq-a"><?php echo esc_html( $g2a_ccw_f['a'] ); ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php
if ( function_exists( 'g2a_emit_jsonld' ) && function_exists( 'g2a_faq_schema' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $g2a_ccw_faqs ) );
} else {
	$g2a_ccw_faq_schema = [
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map( static function ( $f ) {
			return [
				'@type'          => 'Question',
				'name'           => $f['q'],
				'acceptedAnswer' => [ '@type' => 'Answer', 'text' => $f['a'] ],
			];
		}, $g2a_ccw_faqs ),
	];
	echo '<script type="application/ld+json">' . wp_json_encode( $g2a_ccw_faq_schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}
?>

<section class="ccw-resv" id="registration">
  <span id="reserve"></span>
  <div class="rf-card" data-reveal>
    <span class="eyebrow" style="margin-bottom:14px;">Arizona CCW Registration</span>
    <h2>RESERVE YOUR CCW SEAT</h2>
    <?php if ( isset( $_GET['g2a_sent'] ) ) : ?>
      <div class="alert success" style="margin-top:22px;">
        <span class="ic"></span>
        <div><div class="h">Request Received</div>Thanks — your CCW class reservation request is in. Our team will reach out to confirm your date. For anything urgent, call <?php echo str_replace( ' ', '&nbsp;', esc_html( trim( $g2a_biz['phone'] ?? '(602) 715-2677' ) ) ); ?>.</div>
      </div>
    <?php else : ?>
      <p class="rf-intro">Pick your course, tell us when you'd like to attend, and our team will confirm your seat by phone or email — usually within one business day. <?php echo esc_html( ( $g2a_biz['name'] ?? 'Guns 2 Ammo' ) . ' · ' . ( $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' ) . ', ' . ( $g2a_biz['addr2'] ?? 'Mesa, AZ 85205' ) ); ?>.</p>
      <div>
          <?php echo g2a_plugin_section( 'g2a_event_booking' ); ?>
        </div>
	      

    <?php endif; ?>
  </div>
</section>
<?php
get_footer();
