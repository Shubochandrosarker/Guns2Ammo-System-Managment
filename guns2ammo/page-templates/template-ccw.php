<?php
/**
 * Template Name: Arizona CCW Certification
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$g2a_ccw_title       = 'Arizona CCW Permit Class in Mesa, AZ | $85 · 4 Hours';
$g2a_ccw_description = 'Arizona CCW permit class in Mesa: 4-hour classroom course covering AZ carry laws, legal use of force and safe handling. $85 · Ages 21+ · DPS help.';
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

add_action( 'wp_head', function () use ( $g2a_ccw_url, $g2a_ccw_description ) {
	$course_id = $g2a_ccw_url . '#course';
	$offer_id  = $g2a_ccw_url . '#offer';
	$schema    = [
		'@context' => 'https://schema.org',
		'@graph'   => [
			[
				'@type'                        => 'Course',
				'@id'                          => $course_id,
				'name'                         => 'Arizona CCW Permit Course',
				'alternateName'                => [ 'Arizona CCW', 'CCW Permit Class in Mesa, AZ', 'Arizona Concealed Carry Permit Class' ],
				'description'                  => $g2a_ccw_description,
				'url'                          => $g2a_ccw_url,
				'mainEntityOfPage'             => [ '@type' => 'WebPage', '@id' => $g2a_ccw_url ],
				'provider'                     => [ '@id' => home_url( '/#business' ) ],
				'timeRequired'                 => 'PT4H',
				'educationalCredentialAwarded' => 'Certificate of Completion',
				'courseMode'                   => 'Onsite',
				'inLanguage'                   => 'en-US',
				'audience'                     => [
					'@type'        => 'EducationalAudience',
					'audienceType' => 'Adults age 21 and older',
				],
				'about'                        => [
					'Arizona concealed carry laws',
					'Legal use of force and self-defense',
					'Firearm safety and secure storage',
					'Arizona DPS concealed weapons permit application',
				],
				'areaServed'                   => [
					[ '@type' => 'City', 'name' => 'Mesa' ],
					[ '@type' => 'City', 'name' => 'Gilbert' ],
					[ '@type' => 'City', 'name' => 'Chandler' ],
					[ '@type' => 'AdministrativeArea', 'name' => 'East Valley, Arizona' ],
				],
				'offers'                       => [
					'@type'                   => 'Offer',
					'@id'                     => $offer_id,
					'url'                     => $g2a_ccw_url . '#registration',
					'price'                   => '85.00',
					'priceCurrency'           => 'USD',
					'availability'            => 'https://schema.org/InStock',
					'availableDeliveryMethod' => 'https://schema.org/InStoreOnly',
					'validFrom'               => '2026-01-01',
				],
				'hasCourseInstance'            => [
					'@type'          => 'CourseInstance',
					'name'           => 'In-Person Arizona CCW Permit Class',
					'courseMode'     => 'Onsite',
					'courseWorkload' => 'PT4H',
					'offers'         => [ '@id' => $offer_id ],
					'location'       => [
						'@type'   => 'Place',
						'name'    => 'Guns 2 Ammo',
						'address' => [
							'@type'           => 'PostalAddress',
							'streetAddress'   => '6030 E Main St, Suite 103',
							'addressLocality' => 'Mesa',
							'addressRegion'   => 'AZ',
							'postalCode'      => '85205',
							'addressCountry'  => 'US',
						],
					],
				],
			],
			[
				'@type'       => 'HowTo',
				'@id'         => $g2a_ccw_url . '#how-it-works',
				'name'        => 'How to Complete the Arizona CCW Course at Guns 2 Ammo',
				'description' => 'Register for a class date, complete the four-hour classroom course, and receive your completion certificate with guidance for the Arizona DPS application.',
				'totalTime'   => 'PT4H',
				'estimatedCost' => [ '@type' => 'MonetaryAmount', 'currency' => 'USD', 'value' => '85.00' ],
				'step'        => [
					[ '@type' => 'HowToStep', 'position' => 1, 'name' => 'Register for a class date', 'text' => 'Call or stop in to reserve your spot on an upcoming class date.' ],
					[ '@type' => 'HowToStep', 'position' => 2, 'name' => 'Complete the four-hour course', 'text' => 'Attend the in-person classroom session at Guns 2 Ammo in Mesa covering Arizona law, safe handling, and secure storage.' ],
					[ '@type' => 'HowToStep', 'position' => 3, 'name' => 'Receive your certificate and DPS application help', 'text' => 'Receive your completion certificate and guidance for submitting your Arizona DPS concealed weapons permit application.' ],
				],
			],
		],
	];

	echo "\n<!-- Arizona CCW Course + HowTo Schema -->\n";
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	echo '<style id="g2a-ccw-content-grid">.ccw-course-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ccw-process-grid{grid-template-columns:repeat(3,minmax(0,1fr))}@media(max-width:900px){.ccw-course-grid,.ccw-process-grid{grid-template-columns:1fr}}</style>' . "\n";
}, 11 );

get_header();
?>
<header class="ccw-hero">
  <div class="c">
    <span class="eyebrow">Mesa, AZ · State-Compliant Arizona CCW</span>
    <h1 style="margin-top:18px;">ARIZONA CCW PERMIT<br><span class="a">CERTIFICATION COURSE.</span></h1>
    <p>Arizona's concealed carry laws, explained clearly — so you can carry confidently and legally.</p>
    <div style="display:flex; gap:14px; flex-wrap:wrap;">
      <a class="btn btn-ember btn-lg" href="#registration">Ask About Registration</a>
    </div>
    <div class="ccw-meta">
      <div><div class="v">$85 · 4 Hrs</div><div class="l">Classroom Course</div></div>
      <div><div class="v">$149.99 · 5 Hrs</div><div class="l">+ Live Fire</div></div>
      <div><div class="v">Saturdays / Mon Eve</div><div class="l">Schedule</div></div>
      <div><div class="v">21+ Years</div><div class="l">Eligibility</div></div>
    </div>
  </div>
</header>

<section class="cols" id="course-content">
  <div class="c ccw-course-grid">
    <div class="col">
      <span class="eyebrow">Arizona CCW Laws</span>
      <h2 style="font-family:var(--font-display); font-size:32px; color:var(--color-white); margin:14px 0 18px;">ARIZONA LAWS &amp; REGULATIONS</h2>
      <ul>
        <li><a href="https://www.azleg.gov/ars/13/03112.htm" rel="noopener" style="color:inherit;">Arizona Revised Statutes on concealed carry (A.R.S. § 13-3112)</a></li>
        <li>Legal use of force &amp; self-defense law in Arizona</li>
        <li>Where you can and cannot carry in Arizona</li>
        <li>Duty to inform law enforcement</li>
        <li>Federal vs. state firearm regulations</li>
      </ul>
    </div>
    <div class="col">
      <span class="eyebrow">Responsible Carry</span>
      <h2 style="font-family:var(--font-display); font-size:32px; color:var(--color-white); margin:14px 0 18px;">SAFE HANDLING &amp; STORAGE</h2>
      <ul>
        <li>Fundamental rules of firearm safety</li>
        <li>Safe handling practices for everyday carry</li>
        <li>Proper holster selection &amp; draw technique overview</li>
        <li>Secure home storage to prevent unauthorized access</li>
        <li>Transporting firearms legally in Arizona</li>
      </ul>
    </div>
  </div>
</section>

<section class="cols" id="live-fire">
  <div class="c ccw-course-grid">
    <div class="col">
      <span class="eyebrow">Option 1 · Classroom</span>
      <h2 style="font-family:var(--font-display); font-size:32px; color:var(--color-white); margin:14px 0 18px;">ARIZONA CCW — $85</h2>
      <ul>
        <li>4 hours, no shooting component required</li>
        <li>Arizona carry law, regulations &amp; training concepts (~3.5 hrs)</li>
        <li>Fingerprints &amp; DPS application paperwork (~30 min)</li>
        <li>Saturdays at 11:30 AM</li>
      </ul>
    </div>
    <div class="col">
      <span class="eyebrow">Option 2 · Classroom + Range</span>
      <h2 style="font-family:var(--font-display); font-size:32px; color:var(--color-white); margin:14px 0 18px;">CCW + LIVE FIRE — $149.99</h2>
      <ul>
        <li>5 hours — the full classroom course plus supervised live fire</li>
        <li>Shoot on our climate-controlled indoor lanes with an instructor</li>
        <li>Ideal for first-time carriers who want range confidence</li>
        <li>Monday evenings — quieter range, better coaching time</li>
      </ul>
    </div>
  </div>
</section>

<section class="timeline" id="how-it-works">
  <div class="c">
    <span class="eyebrow">Arizona CCW Class Process</span>
    <h2 style="margin-top:18px;">HOW IT WORKS</h2>
    <div class="tl-grid ccw-process-grid">
      <div class="tl-step">
        <div class="num">01</div>
        <h3 style="font-family:var(--font-condensed); font-weight:600; font-size:16px; letter-spacing:0.06em; text-transform:uppercase; color:var(--color-white); margin:12px 0 6px;">Register for a Class Date</h3>
        <p>We schedule courses based on demand. Call or stop in to reserve your spot on an upcoming date.</p>
      </div>
      <div class="tl-step">
        <div class="num">02</div>
        <h3 style="font-family:var(--font-condensed); font-weight:600; font-size:16px; letter-spacing:0.06em; text-transform:uppercase; color:var(--color-white); margin:12px 0 6px;">Complete the 4-Hour Course</h3>
        <p>Attend our in-person classroom session at Guns 2 Ammo in Mesa, covering Arizona law, safe handling &amp; storage.</p>
      </div>
      <div class="tl-step">
        <div class="num">03</div>
        <h3 style="font-family:var(--font-condensed); font-weight:600; font-size:16px; letter-spacing:0.06em; text-transform:uppercase; color:var(--color-white); margin:12px 0 6px;">Receive Your Certificate &amp; DPS Help</h3>
        <p>Walk out with your completion certificate. We'll help guide you through submitting your Arizona DPS CCW application.</p>
      </div>
    </div>
  </div>
</section>

<section class="section" id="registration" style="padding:100px 32px; background:var(--color-gunmetal);">
  <div style="max-width:1280px; margin:0 auto;">
    <span class="eyebrow">Arizona CCW Registration</span>
    <h2 style="font-family:var(--font-display); font-size:clamp(34px,5vw,56px); margin:18px 0 8px;">READY TO GET YOUR CCW PERMIT?</h2>
    <p style="color:var(--color-fog); margin:0 0 32px;">Guns 2 Ammo · 6030 E Main St #103, Mesa, AZ 85205 · (602) 715-2677</p>
    <a class="btn btn-brass btn-lg" href="tel:+16027152677">Ask About Registration ↗</a>
  </div>
</section>
<?php
get_footer();
