<?php
/**
 * Template Name: Ladies Tuesday
 *
 * Source: design/ladies-tuesday.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$g2a_lt_title       = 'Ladies Night Gun Range in Mesa, AZ | Free Range Time for Women Every Tuesday';
$g2a_lt_description = 'Ladies Tuesday at our Mesa, AZ gun range: women get one free hour of lane time every Tuesday, 10AM-6PM. No membership needed, rentals 25% off, beginners welcome.';
$g2a_lt_url         = home_url( '/ladies-tuesday/' );

/* Keep the page-specific search snippet consistent across WordPress, Rank Math,
 * Yoast, and the theme's own SEO/OG output without changing global SEO rules. */
add_filter( 'pre_get_document_title', function () use ( $g2a_lt_title ) {
	return $g2a_lt_title;
}, 99 );
add_filter( 'rank_math/frontend/title', function () use ( $g2a_lt_title ) {
	return $g2a_lt_title;
}, 99 );
add_filter( 'rank_math/frontend/description', function () use ( $g2a_lt_description ) {
	return $g2a_lt_description;
}, 99 );
add_filter( 'wpseo_title', function () use ( $g2a_lt_title ) {
	return $g2a_lt_title;
}, 99 );
add_filter( 'wpseo_metadesc', function () use ( $g2a_lt_description ) {
	return $g2a_lt_description;
}, 99 );

$g2a_lt_page = get_queried_object();
if ( $g2a_lt_page instanceof WP_Post ) {
	$g2a_lt_page->post_excerpt = $g2a_lt_description;
}

/* Weekly Event JSON-LD — free, recurring every Tuesday. */
add_action( 'wp_head', function () use ( $g2a_lt_url, $g2a_lt_description ) {
	$schema = [
		'@context'            => 'https://schema.org',
		'@type'               => 'Event',
		'@id'                 => $g2a_lt_url . '#event',
		'name'                => 'Ladies Tuesday — Free Range Time for Women',
		'description'         => $g2a_lt_description,
		'url'                 => $g2a_lt_url,
		'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
		'eventStatus'         => 'https://schema.org/EventScheduled',
		'isAccessibleForFree' => true,
		'eventSchedule'       => [
			'@type'            => 'Schedule',
			'byDay'            => 'https://schema.org/Tuesday',
			'startTime'        => '10:00',
			'endTime'          => '18:00',
			'repeatFrequency'  => 'P1W',
			'scheduleTimezone' => 'America/Phoenix',
		],
		'location'            => [
			'@type'   => 'Place',
			'name'    => 'Guns 2 Ammo',
			'address' => [
				'@type'           => 'PostalAddress',
				'streetAddress'   => '6030 E Main St Ste 103',
				'addressLocality' => 'Mesa',
				'addressRegion'   => 'AZ',
				'postalCode'      => '85205',
				'addressCountry'  => 'US',
			],
		],
		'organizer'           => [ '@id' => home_url( '/#business' ) ],
		'offers'              => [
			'@type'         => 'Offer',
			'url'           => $g2a_lt_url,
			'price'         => '0',
			'priceCurrency' => 'USD',
			'availability'  => 'https://schema.org/InStock',
		],
	];
	echo "\n<!-- Ladies Tuesday Weekly Event Schema -->\n";
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 11 );

get_header();

$g2a_page_id                 = get_the_ID();
$g2a_ladies_hero_subtitle    = get_post_meta( $g2a_page_id, 'ladies_hero_subtitle', true );
$g2a_ladies_offer_text       = get_post_meta( $g2a_page_id, 'ladies_offer_text', true );
$g2a_ladies_events_shortcode = get_post_meta( $g2a_page_id, 'ladies_upcoming_events_shortcode', true );
?>
<style>body { background: var(--color-void); }

  /* HERO */
  .lt-hero { padding: 120px 32px 80px; position: relative; overflow: hidden; }
  .lt-hero::before {
    content: ""; position: absolute; inset: 0;
    background-image:
      linear-gradient(180deg, var(--hero-scrim-soft) 0%, var(--hero-scrim-deep) 60%, var(--color-void) 100%),
      url("<?php echo esc_url( g2a_asset( 'img/guns2ammo-happy-customer-target.jpg' ) ); ?>");
    background-size: cover; background-position: center 30%;
    filter: saturate(0.85) contrast(1.05);
  }
  .lt-hero::after {
    content: ""; position: absolute; inset: 0;
    background-image: repeating-linear-gradient(115deg, transparent 0 80px, rgba(201,168,76,0.04) 80px 81px);
    pointer-events: none;
  }
  .lt-hero .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1.3fr 1fr; gap: 64px; align-items: end; position: relative; z-index: 1; }
  @media (max-width: 980px) { .lt-hero .wrap { grid-template-columns: 1fr; gap: 32px; } }

  .lt-hero .badge-row { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 24px; }
  .lt-hero .b-pill {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 12px; border: 1px solid var(--color-brass-dim);
    background: rgba(201,168,76,0.06);
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--brass-on-media); text-transform: uppercase;
  }
  .lt-hero h1 { font-family: var(--font-display); font-size: clamp(64px, 9vw, 124px); line-height: 0.92; letter-spacing: 0.005em; color: var(--ink-on-media); margin: 0 0 22px; }
  .lt-hero h1 em { font-style: normal; color: var(--brass-on-media); }
  .lt-hero .sub { font-size: 18px; color: var(--ink-on-media-dim); max-width: 50ch; line-height: 1.6; margin: 0 0 32px; }
  .lt-hero .actions { display: flex; gap: 12px; flex-wrap: wrap; }

  /* Discount card */
  .lt-disc {
    background: linear-gradient(135deg, rgba(201,168,76,0.14) 0%, rgba(201,168,76,0.02) 100%);
    border: 1px solid var(--color-brass);
    padding: 32px;
    position: relative;
    overflow: hidden;
  }
  .lt-disc::before {
    content: ""; position: absolute; inset: 0;
    background-image: repeating-linear-gradient(45deg, transparent 0 14px, rgba(201,168,76,0.04) 14px 15px);
    pointer-events: none;
  }
  .lt-disc .day { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.3em; color: var(--color-brass-bright); text-transform: uppercase; }
  .lt-disc .pct {
    font-family: var(--font-display);
    font-size: clamp(96px, 14vw, 168px);
    line-height: 0.85;
    color: var(--color-white);
    letter-spacing: 0.005em;
    margin: 8px 0 4px;
  }
  .lt-disc .pct em { font-style: normal; color: var(--color-brass-bright); }
  .lt-disc .lbl { font-family: var(--font-condensed); font-weight: 600; font-size: 18px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; line-height: 1.2; }
  .lt-disc .fine { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; margin-top: 18px; padding-top: 18px; border-top: 1px solid var(--color-hairline); }

  /* COUNTDOWN BAR */
  .lt-count {
    background: var(--color-gunmetal);
    border-top: 1px solid var(--color-hairline);
    border-bottom: 1px solid var(--color-hairline);
    padding: 28px 32px;
  }
  .lt-count .wrap { max-width: 1280px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; gap: 32px; flex-wrap: wrap; }
  .lt-count .left .lbl { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.28em; color: var(--color-brass-bright); text-transform: uppercase; margin-bottom: 4px; }
  .lt-count .left .t { font-family: var(--font-condensed); font-weight: 600; font-size: 22px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }

  /* WHAT'S INCLUDED */
  .lt-inc { padding: 120px 32px; }
  .lt-inc .wrap { max-width: 1280px; margin: 0 auto; }
  .lt-inc .head { display: grid; grid-template-columns: 1fr 1fr; gap: 64px; margin-bottom: 56px; align-items: end; }
  @media (max-width: 900px) { .lt-inc .head { grid-template-columns: 1fr; gap: 24px; } }
  .lt-inc h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; }
  .lt-inc h2 em { font-style: normal; color: var(--color-brass-bright); }
  .lt-inc .lede { color: var(--color-fog); font-size: 16px; line-height: 1.7; max-width: 50ch; }

  .inc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; }
  @media (max-width: 980px) { .inc-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 540px) { .inc-grid { grid-template-columns: 1fr; } }
  .inc-card {
    background: var(--color-gunmetal); border: 1px solid var(--color-hairline);
    padding: 28px 24px;
    transition: transform 250ms var(--ease-std), border-color 250ms var(--ease-std);
  }
  .inc-card:hover { transform: translateY(-4px); border-color: var(--color-brass-dim); }
  .inc-card .ic { width: 42px; height: 42px; border: 1px solid var(--color-brass-dim); display: grid; place-items: center; color: var(--color-brass-bright); margin-bottom: 18px; }
  .inc-card .n { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--color-silver); text-transform: uppercase; }
  .inc-card h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 19px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; margin: 6px 0 10px; }
  .inc-card p { color: var(--color-fog); font-size: 14px; line-height: 1.6; margin: 0; }

  /* SCHEDULE TABLE */
  .lt-sched { padding: 120px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); border-bottom: 1px solid var(--color-hairline); }
  .lt-sched .wrap { max-width: 1080px; margin: 0 auto; }
  .lt-sched h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin-bottom: 12px; }
  .lt-sched .sub { color: var(--color-fog); margin-bottom: 32px; max-width: 60ch; }

  .sched-grid {
    display: grid; grid-template-columns: 100px 1fr 140px 140px;
    border: 1px solid var(--color-hairline-bright);
  }
  @media (max-width: 720px) { .sched-grid { grid-template-columns: 1fr; } }
  .sched-grid > div { padding: 14px 18px; border-bottom: 1px solid var(--color-hairline); }
  @media (max-width: 720px) { .sched-grid > div { border-right: 0; } .sched-grid .h { display: none; } }
  .sched-grid > div:not(:nth-child(4n)) { border-right: 1px solid var(--color-hairline); }
  @media (max-width: 720px) { .sched-grid > div:not(:nth-child(4n)) { border-right: 0; } }
  .sched-grid .h { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-brass-bright); text-transform: uppercase; background: rgba(201,168,76,0.04); }
  .sched-grid .date { font-family: var(--font-display); font-size: 22px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1.1; }
  .sched-grid .date small { display: block; font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.22em; color: var(--color-silver); margin-top: 4px; text-transform: uppercase; }
  .sched-grid .focus { font-family: var(--font-condensed); font-weight: 600; font-size: 15px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .sched-grid .focus small { display: block; font-family: var(--font-body); font-weight: 400; color: var(--color-fog); font-size: 13px; letter-spacing: 0; text-transform: none; margin-top: 2px; }
  .sched-grid .time { font-family: var(--font-mono); font-size: 13px; color: var(--color-fog); letter-spacing: 0.1em; }
  .sched-grid .seats { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; }
  .sched-grid .seats.open { color: #4ADE80; }
  .sched-grid .seats.low { color: var(--color-brass-bright); }
  .sched-grid .seats.full { color: var(--color-silver); }

  /* TESTIMONIALS */
  .lt-vox { padding: 120px 32px; }
  .lt-vox .wrap { max-width: 1280px; margin: 0 auto; }
  .lt-vox h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 64px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin-bottom: 56px; }
  .vox-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
  @media (max-width: 900px) { .vox-grid { grid-template-columns: 1fr; } }
  .vox {
    background: var(--color-gunmetal); border: 1px solid var(--color-hairline);
    padding: 28px;
    position: relative;
  }
  .vox::before {
    content: ""; position: absolute; top: 8px; left: 22px;
    font-family: var(--font-display); font-size: 80px; color: var(--color-brass-bright);
    line-height: 1; opacity: 0.3;
  }
  .vox p { color: var(--color-fog); line-height: 1.65; margin: 24px 0; position: relative; }
  .vox .who { display: flex; gap: 12px; align-items: center; padding-top: 18px; border-top: 1px solid var(--color-hairline); }
  .vox .av { width: 36px; height: 36px; border-radius: 50%; background: var(--color-brass); color: #111; display: grid; place-items: center; font-family: var(--font-display); font-size: 14px; }
  .vox .name { font-family: var(--font-condensed); font-weight: 600; font-size: 13px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .vox .stat { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; }

  /* RULES BAND */
  .lt-rules { padding: 100px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); }
  .lt-rules .wrap { max-width: 1080px; margin: 0 auto; }
  .lt-rules h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 64px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin-bottom: 32px; }
  .lt-rules .row { display: flex; align-items: flex-start; gap: 18px; padding: 18px 0; border-bottom: 1px solid var(--color-hairline); }
  .lt-rules .row:last-child { border-bottom: 0; }
  .lt-rules .row .n { font-family: var(--font-display); font-size: 26px; color: var(--color-brass-bright); width: 36px; flex: 0 0 auto; line-height: 1; }
  .lt-rules .row .body .t { font-family: var(--font-condensed); font-weight: 600; font-size: 17px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 4px; }
  .lt-rules .row .body .d { color: var(--color-fog); font-size: 14px; line-height: 1.55; }

  /* CTA FINAL */
  .lt-final { padding: 140px 32px; background: linear-gradient(135deg, #2A2218, var(--color-void)); border-top: 1px solid var(--color-brass-dim); position: relative; overflow: hidden; }
  .lt-final::before { content: ""; position: absolute; inset: 0; background-image: repeating-linear-gradient(115deg, transparent 0 80px, rgba(201,168,76,0.05) 80px 81px); pointer-events: none; }
  .lt-final .wrap { max-width: 760px; margin: 0 auto; text-align: center; position: relative; }
  .lt-final h2 { font-family: var(--font-display); font-size: clamp(48px, 7vw, 88px); line-height: 0.95; color: var(--color-white); letter-spacing: 0.02em; margin: 16px 0 18px; }
  .lt-final h2 em { font-style: normal; color: var(--color-brass-bright); }
  .lt-final p { color: var(--color-fog); font-size: 17px; max-width: 50ch; margin: 0 auto 32px; line-height: 1.6; }
  .lt-final .row { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
  .lt-final .micro { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; margin-top: 24px; }

  /* FAQ */
  .lt-faq { padding: 100px 32px; }
  .lt-faq .wrap { max-width: 800px; margin: 0 auto; }
  .lt-faq h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 64px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin-bottom: 32px; }</style>
<main>
    <!-- HERO -->
    <section class="lt-hero hero-media">
      <div class="wrap">
        <div>
          <div class="badge-row">
            <span class="b-pill">Every Tuesday</span>
            <span class="b-pill">No Membership Required</span>
            <span class="b-pill">Walk-Ins Welcome</span>
          </div>
          <h1>Ladies <em>Tuesday</em> —<br />Free Lane Time,<br />Every Tuesday.</h1>
          <p class="sub"><?php echo esc_html( $g2a_ladies_hero_subtitle ? $g2a_ladies_hero_subtitle : 'Every Tuesday at our Mesa gun range, women shoot free for one hour. No membership needed, rentals are 25% off, and beginners are always welcome — a range safety officer walks first-timers through safety, grip, and stance at your pace.' ); ?></p>
          <div class="actions">
            <a class="btn btn-ember btn-lg" href="#reserve">Reserve A Tuesday Lane</a>
            <a class="btn btn-brass btn-lg" href="#schedule">View Schedule</a>
          </div>
        </div>
        <aside class="lt-disc">
          <div class="day">Tuesdays · All Day · 10AM&ndash;6PM</div>
          <div class="pct"><em>FREE</em></div>
          <div class="lbl"><?php echo esc_html( $g2a_ladies_offer_text ? $g2a_ladies_offer_text : 'Free 1 Hour Lane Time For Women On Tuesdays' ); ?></div>
          <div class="fine">One free hour of lane time for women every Tuesday during open hours</div>
        </aside>
      </div>
    </section>

    <!-- COUNTDOWN (renders only when the shortcode exists and returns output —
         no empty 00d 00h shell, no leaked shortcode text) -->
    <?php
    $g2a_lt_countdown = '';
    if ( shortcode_exists( 'g2a_event_banner' ) ) {
        $g2a_lt_countdown = trim( do_shortcode( '[g2a_event_banner style="spotlight" title="Next Ladies Tuesday"]' ) );
    }
    if ( '' !== $g2a_lt_countdown ) : ?>
    <section class="lt-count">
      <div class="wrap">
        <?php echo $g2a_lt_countdown; // phpcs:ignore WordPress.Security.EscapeOutput -- shortcode HTML. ?>
      </div>
    </section>
    <?php endif; ?>

    <!-- WHAT'S INCLUDED -->
    <section class="lt-inc">
      <div class="wrap">
        <div class="head">
          <h2>Built for <em>her</em>.<br />Not against him.</h2>
          <p class="lede">Ladies Tuesday isn't a watered-down range day &mdash; it's the full G2A experience with a few practical touches that make new and experienced women shooters feel at home.</p>
        </div>

        <div class="inc-grid">
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg></div>
            <div class="n">01</div>
            <h3>Free 1-Hour Lane Time</h3>
            <p>One full hour of lane time is included for women every Tuesday.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2v4M4.93 4.93l2.83 2.83M2 12h4M4.93 19.07l2.83-2.83M12 22v-4M19.07 19.07l-2.83-2.83M22 12h-4M19.07 4.93l-2.83 2.83"/><circle cx="12" cy="12" r="3"/></svg></div>
            <div class="n">02</div>
            <h3>RSO-Guided First Visits</h3>
            <p>A range safety officer is on the floor every Tuesday and will walk first-timers through safety, grip, and stance &mdash; at your pace, no rush.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="9" r="3"/><circle cx="17" cy="10" r="2.5"/><path d="M3 21c0-4 3-6 6-6s6 2 6 6"/><path d="M14 21c0-3 2-4 3-4s4 1 4 4"/></svg></div>
            <div class="n">03</div>
            <h3>+1 Welcome</h3>
            <p>Bring a friend, sister, or mom &mdash; check in together and they get the same Tuesday pricing, no questions asked.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="5" y="2" width="14" height="20" rx="2"/><path d="M5 8h14M5 16h14"/></svg></div>
            <div class="n">04</div>
            <h3>25% Off Rentals</h3>
            <p>40+ rental firearms on the wall. Try a Glock 19, a Sig P365, or a S&amp;W M&amp;P for 25% off every Tuesday.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M21 11.5c0 4.7-3.5 8-9 8s-9-3.3-9-8 3.5-8 9-8 9 3.3 9 8z"/><path d="M9 11l3 3 5-5"/></svg></div>
            <div class="n">05</div>
            <h3>Calmer Floor</h3>
            <p>Tuesdays are our lowest-volume day. Less noise, more space, no waiting for a lane on most weeks.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M12 7v5l3 2"/></svg></div>
            <div class="n">06</div>
            <h3>Walk-Ins Welcome</h3>
            <p>No appointment needed. If we're full, we'll put you in the queue and text when a lane opens.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2L4 6v6c0 5 3.4 9.5 8 10 4.6-.5 8-5 8-10V6l-8-4z"/></svg></div>
            <div class="n">07</div>
            <h3>Safety First</h3>
            <p>1:1 onboarding for first-timers. We don't rush you to the line &mdash; you'll go when you're ready.</p>
          </div>
          <div class="inc-card">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="9" cy="9" r="3"/><circle cx="17" cy="10" r="2.5"/><circle cx="15" cy="17" r="2"/><path d="M2 22c0-3 2-5 5-5"/></svg></div>
            <div class="n">08</div>
            <h3>Women's Group Day</h3>
            <p>Book the entire range for a private group &mdash; bachelorette parties, birthdays, or a girls' afternoon out.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- FIRST-TIMERS: WHAT TO EXPECT -->
    <section class="lt-rules" id="first-timers" style="border-bottom:1px solid var(--color-hairline);">
      <div class="wrap">
        <h2>First Time?<br />Here's What To Expect</h2>
        <div class="row">
          <div class="n">01</div>
          <div class="body">
            <div class="t">Arrive Any Time, 10AM&ndash;6PM</div>
            <div class="d">No appointment needed &mdash; walk in whenever suits you on a Tuesday. If you'd rather lock in a time, reserve a lane below.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">02</div>
          <div class="body">
            <div class="t">Check In At The Counter</div>
            <div class="d">Bring a government photo ID and sign the quick first-visit waiver on a tablet. Your free hour is applied right there &mdash; nothing to claim or print.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">03</div>
          <div class="body">
            <div class="t">Get Your Eye &amp; Ear Protection</div>
            <div class="d">We set you up with eye and ear protection before you head to the floor. Closed-toe shoes and a high-neck shirt are all you need to bring.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">04</div>
          <div class="body">
            <div class="t">Lane Briefing With An RSO</div>
            <div class="d">A range safety officer walks you through range rules, then safety, grip, and stance at your lane. You fire your first shot when you're ready &mdash; not before.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- SCHEDULE -->
    <section class="lt-sched" id="schedule">
      <div class="wrap">
        <h2>Upcoming Tuesdays</h2>
        <p class="sub">Themed sessions and intro classes are added monthly. Standard Tuesdays are open floor &mdash; show up any time during open hours.</p>

        <div>
          <?php echo g2a_plugin_section( 'g2a_upcoming_events', ' layout="card"' ); ?>
        </div>
      </div>
    </section>

    <!-- TESTIMONIALS -->
    <section class="lt-vox">
      <div class="wrap">
        <h2>Voices From The Floor</h2>
        <div class="vox-grid">
          <article class="vox">
            <p>"I'd never held a firearm in my life. The RSO walked me through every step on my first Tuesday &mdash; never made me feel stupid, never rushed. I'm signing up for the CCW class next month."</p>
            <div class="who">
              <div class="av">KH</div>
              <div>
                <div class="name">Kelly H.</div>
                <div class="stat">First Visit · Mar 2026</div>
              </div>
            </div>
          </article>
          <article class="vox">
            <p>"Brought my mom for her 60th birthday. She had a blast and we both walked out smiling. The free lane hour plus the rental discount meant we tried four different pistols on the same lane for less than $80 total."</p>
            <div class="who">
              <div class="av">JR</div>
              <div>
                <div class="name">Jenna R.</div>
                <div class="stat">Mother &amp; Daughter Day</div>
              </div>
            </div>
          </article>
          <article class="vox">
            <p>"As a competitive shooter I appreciate that Ladies Tuesday isn't dumbed down. The floor is quieter, the ROs are sharp, and I can run drills without the Saturday lane-hog vibe."</p>
            <div class="who">
              <div class="av">MV</div>
              <div>
                <div class="name">Marisol V.</div>
                <div class="stat">USPSA · Member Since '22</div>
              </div>
            </div>
          </article>
        </div>
      </div>
    </section>

    <!-- RULES / HOW IT WORKS -->
    <section class="lt-rules">
      <div class="wrap">
        <h2>How It Works</h2>
        <div class="row">
          <div class="n">01</div>
          <div class="body">
            <div class="t">Walk In Or Reserve</div>
            <div class="d">No code needed. Show up any time between 10am and 6pm on a Tuesday. Online reservations open six weeks out.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">02</div>
          <div class="body">
            <div class="t">Show ID At Check-In</div>
            <div class="d">Standard government photo ID required like any other day. First-time waiver signed on a tablet &mdash; takes 90 seconds.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">03</div>
          <div class="body">
            <div class="t">Free Hour Applied Automatically</div>
            <div class="d">Your free hour of lane time is applied at check-in &mdash; no code, no coupon, nothing to claim.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">04</div>
          <div class="body">
            <div class="t">+1 Welcome At The Same Rate</div>
            <div class="d">Bring a friend (any gender &mdash; yes, husbands too). They get the same Tuesday pricing as long as you check in together.</div>
          </div>
        </div>
        <div class="row">
          <div class="n">05</div>
          <div class="body">
            <div class="t">Your Free Hour &mdash; And Beyond</div>
            <div class="d">The free hour covers a standard one-hour lane window. Want to keep shooting? Add more time at regular posted rates.</div>
          </div>
        </div>
      </div>
    </section>

    <!-- FAQ -->
    <section class="lt-faq">
      <div class="wrap">
        <h2>Common Questions</h2>
        <div class="acc">
          <details open><summary>Do I need to be a member?</summary><div class="answer">No. Ladies Tuesday is open to everyone &mdash; walk-ins and members. Members still get their member rate on rentals (the deeper discount applies).</div></details>
          <details><summary>Can my husband or boyfriend join me?</summary><div class="answer">Yes &mdash; your +1 gets the same Ladies Tuesday rate as long as you check in together. We don't gatekeep the discount.</div></details>
          <details><summary>I've never fired a gun. Is this for me?</summary><div class="answer">Absolutely &mdash; Tuesdays are made for first-timers. A range safety officer will walk you through safety, grip, and stance before you ever load a magazine, and you fire your first shot when you're ready, not before.</div></details>
          <details><summary>Are there themed classes too?</summary><div class="answer">Yes. We add themed sessions and intro classes most months. Check the Upcoming Tuesdays schedule above, or ask at the counter and we'll tell you what's coming up.</div></details>
          <details><summary>Can I book the whole range for a private group?</summary><div class="answer">Yes. Six-lane buyouts run $360 for a 90-minute window with a dedicated RSO. <a href="<?php echo esc_url( home_url( "/contact/" ) ); ?>" style="color: var(--color-brass-bright);">Email us</a> for bachelorette and birthday packages.</div></details>
          <details><summary>What should I wear?</summary><div class="answer">Closed-toe shoes, high-neck shirt (hot brass policy), no loose jewelry. Bring a hat if you have one. We have loaners for everything else.</div></details>
        </div>
      </div>
    </section>

    <?php if ( $g2a_ladies_events_shortcode && function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
      <section class="section g2a-plugin-host">
        <div class="container" style="max-width:1080px;">
          <?php echo do_shortcode( $g2a_ladies_events_shortcode ); ?>
        </div>
      </section>
    <?php endif; ?>

    <!-- RESERVATION FORM -->
    <?php if ( function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
    <section class="section g2a-plugin-host" id="reserve">
      <div class="container" style="max-width:1080px;">
        <span class="eyebrow" style="margin-bottom:14px;">Reservations</span>
        <h2 style="font-family:var(--font-display);font-size:clamp(34px,5vw,56px);color:var(--color-white);letter-spacing:0.02em;margin-bottom:24px;">RESERVE YOUR TUESDAY LANE</h2>
        
		  <?php echo do_shortcode( '[g2a_event_booking event="ladies-tuesday"]' ); ?>
		  
		  <div>
          <?php echo do_shortcode( '[g2a_upcoming_events layout="list"]' ); ?>
        </div>
      </div>
    </section>
    <?php else :
    get_template_part( 'template-parts/reservation-form', null, [
        'subject' => 'Ladies Tuesday Reservation',
        'heading' => 'RESERVE YOUR TUESDAY LANE',
        'intro'   => 'Reserve your free Ladies Tuesday lane — solo, with a friend, or as a group. Send your details and our team will confirm your spot. Walk-ins are always welcome too.',
        'cta'     => 'Request My Tuesday Lane',
    ] );
    endif; ?>

    <!-- FINAL CTA -->
    <section class="lt-final">
      <div class="wrap">
        <div class="eyebrow" style="justify-content: center; margin-bottom: 8px;">See You Tuesday</div>
        <h2>A free hour on us.<br />All of the <em>welcome.</em></h2>
        <p>Every Tuesday is yours. Walk in, reserve ahead, or message us to set up a private group. We'll have your eye and ear set out.</p>
        <div class="row">
          <a class="btn btn-ember btn-lg" href="#reserve">Reserve A Tuesday Lane</a>
          <a class="btn btn-brass btn-lg" href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">Group &amp; Private Bookings</a>
        </div>
        <div class="micro">Walk-Ins Welcome · Mesa, AZ · 6030 E Main St Ste 103</div>
      </div>
    </section>
  </main>
<?php get_footer();
