<?php
/**
 * Home Page  Guns 2 Ammo
 *
 * Source: design/homepage.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$g2a_page_id            = get_the_ID();
$g2a_event_shortcode    = get_post_meta( $g2a_page_id, 'event_shortcode', true );
$g2a_mg_callout_text    = get_post_meta( $g2a_page_id, 'mg_callout_text', true );
$g2a_mg4_name           = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_name', true );
$g2a_mg4_caliber        = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_caliber', true );
$g2a_mg4_desc           = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_desc', true );
?>
<!-- HERO -->
<section class="hero">
  <div class="inner">
    <span class="eb-pill">Arizona's Premier Indoor Range  Mesa, AZ</span>
    <h1 class="hl-display">
      SHOOT SMART.<br>
      CARRY SAFE.<br>
      <span class="a">TRAIN LIKE A PRO.</span>
    </h1>
    <p class="lead">6-lane climate-controlled indoor range, FFL-licensed firearm sales, and NRA-certified training  all under one roof in Mesa. Walk in, book a lane, or shop Mesa's most-trusted arsenal.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">Shop Collections</a>
    </div>
    <div class="hero-trust">
      <div class="trust-strip">
        <span class="item"><span class="stars"> 4.7</span> 449+ Google Reviews</span>
        <span class="item">NRA Certified</span>
        <span class="item">FFL Licensed</span>
        <span class="item">Est. 2015</span>
      </div>
    </div>
  </div>
  <div class="hero-scroll-wrap"><div class="hero-scroll">Scroll</div></div>
</section>

<?php if ( function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
<section class="g2a-plugin-host" style="padding:24px 32px 0;max-width:1280px;margin:0 auto;">
  <?php echo do_shortcode( $g2a_event_shortcode ? $g2a_event_shortcode : '[g2a_event_banner]' ); ?>
</section>
<?php endif; ?>

<!-- VALUE PROPS -->
<section class="props">
  <div class="wrap">
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg></div>
      <h3>6-Lane Indoor Range</h3>
      <p>Climate-controlled, 25-yard, pistol &amp; rifle approved. Lanes 1 and 2 ADA-accessible.</p>
    </div>
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2 L20 5 V12 C20 17 16 21 12 22 C8 21 4 17 4 12 V5 Z"/><path d="M9 12 L11 14 L15 10"/></svg></div>
      <h3>NRA Certified Training</h3>
      <p>State-approved CCW, women's intro, defensive pistol  taught by NRA instructors.</p>
    </div>
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="6" width="16" height="14"/><path d="M8 6 V4 H16 V6"/><circle cx="12" cy="13" r="2"/></svg></div>
      <h3>FFL-Licensed Sales</h3>
      <p>Handguns, rifles, NFA. Out-of-state transfers $35 flat. Same-day in stock.</p>
    </div>
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M8 12 L10.5 14.5 L16 9"/></svg></div>
      <h3>Member Pricing</h3>
      <p>Unlimited range time from $29.99/mo. Cancel anytime. No contracts.</p>
    </div>
  </div>
</section>

<!-- CALIFORNIA CCW BAND -->
<section class="ca-band">
  <div class="wrap">
    <div>
      <div class="eb">For California Residents</div>
      <h2>CALIFORNIA CCW<br>SHOOTING QUALIFICATION<br><span class="a">IN MESA, AZ</span></h2>
      <p>This is the live-fire qualification component only for California CCW applicants. It does not replace California-required classroom education or training modules.</p>
    </div>
    <div class="actions">
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#ca">Reserve Your Time</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#ca">Course Details</a>
    </div>
  </div>
</section>

<!-- MACHINE GUN BAND  MATCHES REFERENCE -->
<section class="mg">
  <div class="wrap">
    <div class="left">
      <span class="eb-pill">Exclusive  Limited Availability</span>
      <h2>SHOOT A<br>MACHINE GUN<br><span class="a">IN MESA.</span></h2>
      <p class="lead">Live fire. Select fire. No prior experience needed. Walk in as a first-timer, walk out with the story of your life.</p>
      <div class="mg-callout">
        <span class="dot"></span>
        <span class="t"><?php echo esc_html( $g2a_mg_callout_text ? $g2a_mg_callout_text : 'Signature machine gun experience packages available now.' ); ?></span>
      </div>
      <ul class="mg-bullets" style="list-style: none;">
        <li>Full-auto instruction included  one-on-one with a certified pro</li>
        <li>No firearms experience required  we walk you through every step</li>
        <li>Mesa's only indoor full-auto experience  climate-controlled, safe</li>
        <li>Photos &amp; video welcome  capture the moment, share the brag</li>
      </ul>
      <div class="actions">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/machine-gun/" ) ); ?>">Book Your Experience</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/machine-gun/" ) ); ?>#tiers">View Packages</a>
      </div>
      <div class="mg-reviews">
        <span class="num"><span class="star"></span> 4.7</span>
        <div class="meta">449+ Google reviews<small>Mesa's most-trusted indoor range since 2015</small></div>
      </div>
    </div>
    <div class="right">
      <div class="photo-card">
        <div class="ph"></div>
        <span class="cap"> The Arsenal </span>
      </div>
      <div class="arsenal-grid">
        <div class="arsenal-item">
          <div class="nm">M240</div>
          <div class="cal">7.6251 NATO</div>
          <div class="desc">Belt-fed, battlefield-proven</div>
        </div>
        <div class="arsenal-item">
          <div class="nm">M4 AUTO</div>
          <div class="cal">5.5645 NATO</div>
          <div class="desc">Select fire, modern service rifle</div>
        </div>
        <div class="arsenal-item">
          <div class="nm">MP5</div>
          <div class="cal">919 PARABELLUM</div>
          <div class="desc">Iconic German SMG, full auto</div>
        </div>
        <div class="arsenal-item">
          <div class="nm"><?php echo esc_html( $g2a_mg4_name ? $g2a_mg4_name : 'GLOCK 18' ); ?></div>
          <div class="cal"><?php echo esc_html( $g2a_mg4_caliber ? $g2a_mg4_caliber : '9x19 PARABELLUM' ); ?></div>
          <div class="desc"><?php echo esc_html( $g2a_mg4_desc ? $g2a_mg4_desc : 'Select-fire machine pistol platform' ); ?></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- COLLECTIONS -->
<section class="cols">
  <div class="wrap">
    <div class="head">
      <div class="l">
        <span class="eb-pill">Mesa's Most-Trusted Arsenal</span>
        <h2 style="margin-top: 22px;">SHOP THE<br>COLLECTIONS<span class="a">.</span></h2>
        <p>FFL-licensed inventory hand-picked by our instructors. Same-day pickup, no waiting list.</p>
      </div>
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">View All Products</a>
    </div>

    <div class="col-grid">
      <a class="col-tile feat" href="<?php echo esc_url( home_url( "/collections/handguns/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.9)), url('https://guns2ammo.com/wp-content/uploads/2026/04/15.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">Featured Collection  38 SKUs</div>
          <h3>HANDGUNS</h3>
          <span class="more">Shop Handguns </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/collections/rifles/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2025/06/noveske-gen4-556-sbr-bazooka-green-guns2ammo-mesa-az.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">24 SKUs</div>
          <h3>RIFLES</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/collections/ammunition/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2026/04/Gun-Rental-Near-Me-What-to-Expect.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">52 SKUs</div>
          <h3>AMMUNITION</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/collections/magazines/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2026/04/2025-03-23.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">18 SKUs</div>
          <h3>MAGAZINES</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/ffl-services/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2024/08/one-1-a.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">$25 Flat Fee</div>
          <h3>TRANSFERS</h3>
          <span class="more">Start </span>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- STORY / TRAINING ACADEMY -->
<section class="story">
  <div class="wrap">
    <div>
      <span class="eb-pill">Mesa's Training Academy</span>
      <h2 style="margin-top: 22px;">TRAINED HERE.<br>READY <span class="a">ANYWHERE.</span></h2>
      <p>From your first range visit to multi-state CCW certification, our NRA &amp; USCCA instructors take you through it patiently  and properly. Built by operators who've trained law enforcement, military, and church security teams.</p>
      <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/training/" ) ); ?>">Browse Training</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">CCW Pathway</a>
      </div>
    </div>
    <div class="stats">
      <div class="stat">
        <div class="v">5,000<span style="color:var(--color-brass-bright);">+</span></div>
        <div class="l">Students Trained</div>
      </div>
      <div class="stat">
        <div class="v">37</div>
        <div class="l">States Reciprocity</div>
      </div>
      <div class="stat">
        <div class="v">10</div>
        <div class="l">Years In Mesa</div>
      </div>
      <div class="stat">
        <div class="v">99.6<span style="color:var(--color-brass-bright);">%</span></div>
        <div class="l">CCW Pass Rate</div>
      </div>
    </div>
  </div>
</section>

<!-- COLLECTIONS / VISIT -->
<section class="visit">
  <div class="wrap">
    <div class="visit-info">
      <span class="eb-pill">Visit Us</span>
      <h2 style="margin-top: 20px;">6030 E Main St</h2>
      <p class="addr">Suite 103  Mesa, AZ 85205</p>
      <div class="hours">
        <div class="row now"><span class="d"> Today  Tue</span><span class="t">10am  6pm</span></div>
        <div class="row"><span class="d">Mon, Wed, Thu</span><span class="t">10am  6pm</span></div>
        <div class="row closed"><span class="d">Friday</span><span class="t">Closed</span></div>
        <div class="row"><span class="d">Saturday</span><span class="t">9am  8pm</span></div>
        <div class="row"><span class="d">Sunday</span><span class="t">12pm  6pm</span></div>
      </div>
      <div class="actions">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">Get Directions</a>
        <a class="btn btn-ghost" href="tel:+16027152677">Call (602) 715-2677</a>
      </div>
    </div>
    <div class="visit-photo">
      <span class="badge"> Main Floor</span>
    </div>
  </div>
</section>

<!-- REVIEWS -->
<section class="reviews">
  <div class="wrap">
    <div class="head">
      <h2>449 REVIEWS.<br>4.7 <span class="a">STARS.</span></h2>
      <div class="stars-meta">
        <span class="num">4.7</span>
        <span class="stars"></span>  449+ verified Google reviews
      </div>
    </div>
    <div class="rv-grid">
      <article class="rv">
        <div class="quote">"</div>
        <div class="stars">    </div>
        <p>My wife and I came in nervous and left certified. The instructors took the time to explain everything, never made us feel stupid, and the lanes were spotless. This is how every range should run.</p>
        <div class="who">
          <div class="av">DR</div>
          <div>
            <div class="nm">Daniel R.</div>
            <div class="src">First-Time CCW  APR 2026</div>
          </div>
        </div>
      </article>
      <article class="rv" style="border-color: var(--color-brass-dim);">
        <div class="quote">"</div>
        <div class="stars">    </div>
        <p>As a retired LEO I'm picky about training environments. Guns 2 Ammo runs the floor with the kind of discipline I expect. The RSO program is genuinely best-in-class for the East Valley.</p>
        <div class="who">
          <div class="av">MH</div>
          <div>
            <div class="nm">Maria H.</div>
            <div class="src">Retired Officer  Gilbert</div>
          </div>
        </div>
      </article>
      <article class="rv">
        <div class="quote">"</div>
        <div class="stars">    </div>
        <p>Booked the MP5 package for my son's 21st. The RSO walked us through everything, made it feel safe and serious, and we left with a certificate and a story. Worth every dollar.</p>
        <div class="who">
          <div class="av">AP</div>
          <div>
            <div class="nm">Anthony P.</div>
            <div class="src">Apache Junction  MAR 2026</div>
          </div>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section class="final">
  <div class="wrap">
    <span class="eb-pill" style="margin: 0 auto;">Mesa's Most-Trusted Range</span>
    <h2>READY TO<br><span class="a">SHOOT?</span></h2>
    <p>Book a lane, enroll in a course, or stop by the shop. We're open six days a week  Friday closed  and there's always an RSO on duty.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/memberships/" ) ); ?>">Become a Member</a>
    </div>
  </div>
</section>
<?php get_footer();
