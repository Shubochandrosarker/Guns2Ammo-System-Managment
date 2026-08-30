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

// Pull the canonical business info (NAP, founding year, review
// count + rating, hours). Single source of truth — keeps the
// homepage from drifting against the footer / schema / GBP.
$g2a_biz = g2a_biz();

$g2a_page_id            = get_the_ID();
$g2a_event_shortcode    = get_post_meta( $g2a_page_id, 'event_shortcode', true );
$g2a_mg_callout_text    = get_post_meta( $g2a_page_id, 'mg_callout_text', true );
$g2a_mg4_name           = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_name', true );
$g2a_mg4_caliber        = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_caliber', true );
$g2a_mg4_desc           = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_desc', true );
?>
<!-- HERO -->
<section class="hero hero-media">
  <div class="inner">
    <span class="eb-pill">Arizona's Premier Indoor Range  Mesa, AZ</span>
    <h1 class="hl-display">
      <?php echo g2a_content( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- g2a_content() returns wp_kses_post-escaped HTML.
        'home_hero_headline',
        'SHOOT SMART.<br>CARRY SAFE.<br><span class="a">TRAIN LIKE A PRO.</span>',
        'textarea',
        'Homepage — Hero headline'
      ); ?>
    </h1>
    <p class="lead"><?php echo g2a_content( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html applied inside g2a_content().
      'home_hero_lead',
      "6-lane climate-controlled indoor range, FFL-licensed firearm sales, and NRA-certified training  all under one roof in Mesa. Walk in, book a lane, or shop Mesa's most-trusted arsenal.",
      'text',
      'Homepage — Hero lead paragraph'
    ); ?></p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">Shop Collections</a>
    </div>
    <div class="hero-trust">
      <div class="trust-strip">
        <span class="item"><span class="stars"> <?php echo esc_html( number_format( (float) $g2a_biz['review_rating'], 1 ) ); ?></span> <?php /* translators: %1$d: review count e.g. 449, %2$s: source e.g. Google */ printf( esc_html__( '%1$d %2$s Reviews', 'guns2ammo' ), (int) $g2a_biz['review_count'], esc_html( $g2a_biz['review_source'] ) ); ?></span>
        <span class="item">NRA Certified</span>
        <span class="item">FFL Licensed</span>
        <span class="item"><?php /* translators: %d: founding year */ printf( esc_html__( 'Est. %d', 'guns2ammo' ), (int) $g2a_biz['founded_year'] ); ?></span>
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
  <div class="wrap" data-reveal-group>
    <div class="prop" data-reveal>
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg></div>
      <h3>6-Lane Indoor Range</h3>
      <p>Climate-controlled, 25-yard, pistol &amp; rifle approved. Lanes 1 and 2 ADA-accessible.</p>
    </div>
    <div class="prop" data-reveal>
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2 L20 5 V12 C20 17 16 21 12 22 C8 21 4 17 4 12 V5 Z"/><path d="M9 12 L11 14 L15 10"/></svg></div>
      <h3>NRA Certified Training</h3>
      <p>State-approved CCW, women's intro, defensive pistol  taught by NRA instructors.</p>
    </div>
    <div class="prop" data-reveal>
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="6" width="16" height="14"/><path d="M8 6 V4 H16 V6"/><circle cx="12" cy="13" r="2"/></svg></div>
      <h3>FFL-Licensed Sales</h3>
      <p>Handguns, rifles, NFA. Out-of-state transfers $35 flat. Same-day in stock.</p>
    </div>
    <div class="prop" data-reveal>
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M8 12 L10.5 14.5 L16 9"/></svg></div>
      <h3>Member Pricing</h3>
      <p>Unlimited range time from <?php echo esc_html( function_exists( 'g2a_plan_price_from_fmt' ) ? g2a_plan_price_from_fmt() : '$29.99' ); ?>/mo. Cancel anytime. No contracts.</p>
    </div>
  </div>
</section>

<!-- CALIFORNIA CCW BAND -->
<section class="ca-band">
  <div class="wrap">
    <div data-reveal="left">
      <div class="eb">For California Residents</div>
      <h2>CALIFORNIA CCW<br>SHOOTING QUALIFICATION<br><span class="a">IN MESA, AZ</span></h2>
      <p>This is the live-fire qualification component only for California CCW applicants. It does not replace California-required classroom education or training modules.</p>
    </div>
    <div class="actions" data-reveal="right">
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#ca">Reserve Your Time</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#ca">Course Details</a>
    </div>
  </div>
</section>

<!-- MACHINE GUN BAND  MATCHES REFERENCE -->
<section class="mg">
  <div class="wrap">
    <div class="left" data-reveal="left">
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
        <span class="num"><span class="star"></span> <?php echo esc_html( number_format( (float) $g2a_biz['review_rating'], 1 ) ); ?></span>
        <div class="meta"><?php printf( esc_html__( '%1$d %2$s reviews', 'guns2ammo' ), (int) $g2a_biz['review_count'], esc_html( $g2a_biz['review_source'] ) ); ?><small><?php echo esc_html( $g2a_biz['slogan'] ); ?> <?php printf( esc_html__( 'Since %d.', 'guns2ammo' ), (int) $g2a_biz['founded_year'] ); ?></small></div>
      </div>
    </div>
    <div class="right" data-reveal="right">
      <div class="photo-card">
        <?php
        // Editable photo slot — real arsenal-wall photo by default; if the
        // client clears the URL the original CSS placeholder background in
        // front-page.css still renders, so the card never looks broken.
        $g2a_mg_photo = g2a_content( 'home_mg_photo', g2a_asset( 'img/gallery/guns2ammo-arsenal-wall.jpg' ), 'image', 'Homepage — Machine gun section photo' );
        ?>
        <div class="ph"<?php if ( $g2a_mg_photo ) : ?> style="background-image:linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.4)), url('<?php echo esc_url( $g2a_mg_photo ); ?>');"<?php endif; ?>></div>
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
    <div class="head" data-reveal>
      <div class="l">
        <span class="eb-pill">Mesa's Most-Trusted Arsenal</span>
        <h2 style="margin-top: 22px;">SHOP THE<br>COLLECTIONS<span class="a">.</span></h2>
        <p>FFL-licensed inventory hand-picked by our instructors. Same-day pickup, no waiting list.</p>
      </div>
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">View All Products</a>
    </div>

    <?php
    // Category card "SKU" counts read live from the WooCommerce
    // product_cat term counts (cached via business-info.php).
    // Was previously hard-coded 38/24/52/18 — drifted out of sync
    // with both the actual catalog AND the nav menu. Now there is
    // one source of truth.
    $g2a_cat_counts = g2a_biz_category_counts();
    $g2a_cat_label = function ( $slug, $fallback = '' ) use ( $g2a_cat_counts ) {
        if ( isset( $g2a_cat_counts[ $slug ] ) && (int) $g2a_cat_counts[ $slug ] > 0 ) {
            return sprintf( _n( '%d SKU', '%d SKUs', (int) $g2a_cat_counts[ $slug ], 'guns2ammo' ), (int) $g2a_cat_counts[ $slug ] );
        }
        return $fallback;
    };
    ?>
    <div class="col-grid" data-reveal-group>
      <a class="col-tile feat" data-reveal="scale" href="<?php echo esc_url( home_url( "/collections/handguns/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.9)), url('<?php echo esc_url( g2a_asset( 'img/guns2ammo-handgun-case-display.jpg' ) ); ?>');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat"><?php echo esc_html( trim( __( 'Featured Collection', 'guns2ammo' ) . '  ' . $g2a_cat_label( 'handguns' ) ) ); ?></div>
          <h3>HANDGUNS</h3>
          <span class="more">Shop Handguns </span>
        </div>
      </a>
      <a class="col-tile" data-reveal="scale" href="<?php echo esc_url( home_url( "/collections/rifles/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('<?php echo esc_url( g2a_asset( 'img/guns2ammo-rifles-rack.jpg' ) ); ?>');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat"><?php echo esc_html( $g2a_cat_label( 'rifles', __( 'Shop Rifles', 'guns2ammo' ) ) ); ?></div>
          <h3>RIFLES</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" data-reveal="scale" href="<?php echo esc_url( home_url( "/collections/ammunition/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('<?php echo esc_url( g2a_asset( 'img/guns2ammo-ammo-shelves.jpg' ) ); ?>');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat"><?php echo esc_html( $g2a_cat_label( 'ammunition', __( 'Shop Ammo', 'guns2ammo' ) ) ); ?></div>
          <h3>AMMUNITION</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" data-reveal="scale" href="<?php echo esc_url( home_url( "/collections/magazines/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('<?php echo esc_url( g2a_asset( 'img/guns2ammo-gear-case.jpg' ) ); ?>');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat"><?php echo esc_html( $g2a_cat_label( 'magazines', __( 'Shop Magazines', 'guns2ammo' ) ) ); ?></div>
          <h3>MAGAZINES</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" data-reveal="scale" href="<?php echo esc_url( home_url( "/ffl-services/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('<?php echo esc_url( g2a_asset( 'img/guns2ammo-store-counter-staff.jpg' ) ); ?>');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">$35 Flat Fee</div>
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
    <div data-reveal="left">
      <span class="eb-pill">Mesa's Training Academy</span>
      <h2 style="margin-top: 22px;">TRAINED HERE.<br>READY <span class="a">ANYWHERE.</span></h2>
      <p>From your first range visit to multi-state CCW certification, our NRA &amp; USCCA instructors take you through it patiently  and properly. Built by operators who've trained law enforcement, military, and church security teams.</p>
      <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/training/" ) ); ?>">Browse Training</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">CCW Pathway</a>
      </div>
    </div>
    <div class="stats" data-reveal="right">
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
<?php
// Hours block is now generated from $g2a_biz['hours'] — the same
// table that drives the live-status pill and the LocalBusiness
// JSON-LD — so the homepage can never show a different schedule
// than the rest of the site. Today's row is highlighted based on
// actual America/Phoenix weekday (was previously hard-coded "Tue").
$g2a_day_names = array(
	0 => __( 'Sunday',    'guns2ammo' ),
	1 => __( 'Monday',    'guns2ammo' ),
	2 => __( 'Tuesday',   'guns2ammo' ),
	3 => __( 'Wednesday', 'guns2ammo' ),
	4 => __( 'Thursday',  'guns2ammo' ),
	5 => __( 'Friday',    'guns2ammo' ),
	6 => __( 'Saturday',  'guns2ammo' ),
);
try {
	$g2a_now_mesa = new DateTime( 'now', new DateTimeZone( $g2a_biz['timezone'] ) );
	$g2a_today_dow = (int) $g2a_now_mesa->format( 'w' );
} catch ( \Exception $e ) {
	$g2a_today_dow = (int) gmdate( 'w' );
}
$g2a_fmt_hour = function ( $mins ) {
	$h = intdiv( $mins, 60 ); $am = $h < 12; $h12 = $h % 12 ?: 12;
	return $h12 . ( $am ? 'am' : 'pm' );
};
// Render Mon→Sun order so the week reads naturally.
$g2a_week_order = array( 1, 2, 3, 4, 5, 6, 0 );
?>
<section class="visit">
  <div class="wrap">
    <div class="visit-info" data-reveal="left">
      <span class="eb-pill"><?php esc_html_e( 'Visit Us', 'guns2ammo' ); ?></span>
      <h2 style="margin-top: 20px;"><?php echo esc_html( $g2a_biz['addr1'] ); ?></h2>
      <p class="addr"><?php echo esc_html( $g2a_biz['addr2'] ); ?></p>
      <div class="hours">
        <?php foreach ( $g2a_week_order as $dow ) :
          $hrs       = $g2a_biz['hours'][ $dow ] ?? null;
          $is_today  = ( $dow === $g2a_today_dow );
          $is_closed = ! $hrs;
          $cls       = 'row' . ( $is_today ? ' now' : '' ) . ( $is_closed ? ' closed' : '' );
          $label     = ( $is_today ? __( 'Today ·', 'guns2ammo' ) . ' ' : '' ) . $g2a_day_names[ $dow ];
          $time      = $is_closed ? __( 'Closed', 'guns2ammo' ) : ( $g2a_fmt_hour( $hrs['open'] ) . ' – ' . $g2a_fmt_hour( $hrs['close'] ) );
        ?>
        <div class="<?php echo esc_attr( $cls ); ?>"><span class="d"><?php echo esc_html( $label ); ?></span><span class="t"><?php echo esc_html( $time ); ?></span></div>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( $g2a_biz['directions_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Get Directions', 'guns2ammo' ); ?></a>
        <a class="btn btn-ghost" href="<?php echo esc_url( g2a_biz_tel_href() ); ?>"><?php printf( esc_html__( 'Call %s', 'guns2ammo' ), esc_html( $g2a_biz['phone'] ) ); ?></a>
      </div>
    </div>
    <?php
    // Editable photo slot — real retail-floor photo by default; falls back
    // to the stock background baked into front-page.css when cleared.
    $g2a_visit_photo = function_exists( 'g2a_content' ) ? g2a_content( 'home_visit_photo', g2a_asset( 'img/gallery/guns2ammo-retail-floor.jpg' ), 'image', 'Homepage — Visit section photo' ) : '';
    ?>
    <div class="visit-photo" data-reveal="right"<?php if ( $g2a_visit_photo ) : ?> style="background-image:linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.5)), url('<?php echo esc_url( $g2a_visit_photo ); ?>');"<?php endif; ?>>
      <span class="badge"> Main Floor</span>
    </div>
  </div>
</section>

<!-- REVIEWS -->
<section class="reviews">
  <div class="wrap">
    <div class="head" data-reveal>
      <h2><?php echo (int) $g2a_biz['review_count']; ?> REVIEWS.<br><?php echo esc_html( number_format( (float) $g2a_biz['review_rating'], 1 ) ); ?> <span class="a">STARS.</span></h2>
      <div class="stars-meta">
        <span class="num"><?php echo esc_html( number_format( (float) $g2a_biz['review_rating'], 1 ) ); ?></span>
        <span class="stars"></span>  <?php printf( esc_html__( '%1$d verified %2$s reviews', 'guns2ammo' ), (int) $g2a_biz['review_count'], esc_html( $g2a_biz['review_source'] ) ); ?>
      </div>
    </div>
    <div class="rv-grid" data-reveal-group>
      <article class="rv" data-reveal>
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
      <article class="rv" data-reveal style="border-color: var(--color-brass-dim);">
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
      <article class="rv" data-reveal>
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

<!-- FAQ — answer-engine ready, animated accordion -->
<section class="home-faq" style="padding: 96px 32px; background: var(--color-surface-1);">
  <div style="max-width: 880px; margin: 0 auto;">
    <div style="text-align:center; margin-bottom: 40px;" data-reveal>
      <span class="eyebrow">Before You Visit</span>
      <h2 class="sec" style="font-family: var(--font-display); font-size: clamp(40px, 5vw, 60px); color: var(--color-white); letter-spacing: 0.02em; line-height: 1; margin: 14px 0 0;"><span class="g2a-underline">QUESTIONS, ANSWERED.</span></h2>
    </div>
    <?php
    $g2a_home_faqs = function_exists( 'g2a_home_faqs' ) ? g2a_home_faqs() : array();
    ?>
    <div class="g2a-faq" data-reveal-stagger>
      <?php foreach ( $g2a_home_faqs as $i => $f ) : ?>
        <details<?php echo 0 === $i ? ' open' : ''; ?>>
          <summary><?php echo esc_html( $f['q'] ); ?></summary>
          <div class="faq-a"><?php echo esc_html( $f['a'] ); ?></div>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section class="final hero-media">
  <div class="wrap" data-reveal>
    <span class="eb-pill" style="margin: 0 auto;">Mesa's Most-Trusted Range</span>
    <h2><?php echo g2a_content( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- g2a_content() returns wp_kses_post-escaped HTML.
      'home_final_cta_heading',
      'READY TO<br><span class="a">SHOOT?</span>',
      'textarea',
      'Homepage — Final CTA heading'
    ); ?></h2>
    <p><?php echo g2a_content( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html applied inside g2a_content().
      'home_final_cta_text',
      "Book a lane, enroll in a course, or stop by the shop. We're open seven days a week and there's always an RSO on duty.",
      'text',
      'Homepage — Final CTA paragraph'
    ); ?></p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/memberships/" ) ); ?>">Become a Member</a>
    </div>
  </div>
</section>
<?php get_footer();
