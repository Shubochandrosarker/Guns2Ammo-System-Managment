<?php
/**
 * Template Name: Contact
 *
 * Source: design/contact.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
// Single source of truth for NAP + hours — see inc/business-info.php.
// Hours table below follows the same dynamic pattern used on the
// homepage (front-page.php) so this page can never show a different
// schedule than the rest of the site.
$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
$g2a_contact_day_labels = array(
	0 => __( 'Sun', 'guns2ammo' ),
	1 => __( 'Mon', 'guns2ammo' ),
	2 => __( 'Tue', 'guns2ammo' ),
	3 => __( 'Wed', 'guns2ammo' ),
	4 => __( 'Thu', 'guns2ammo' ),
	5 => __( 'Fri', 'guns2ammo' ),
	6 => __( 'Sat', 'guns2ammo' ),
);
try {
	$g2a_contact_now  = new DateTime( 'now', new DateTimeZone( $g2a_biz['timezone'] ?? 'America/Phoenix' ) );
	$g2a_today_dow    = (int) $g2a_contact_now->format( 'w' );
} catch ( \Exception $e ) {
	$g2a_today_dow = (int) gmdate( 'w' );
}
$g2a_fmt_hour = function ( $mins ) {
	$h = intdiv( $mins, 60 ); $am = $h < 12; $h12 = $h % 12 ?: 12;
	return $h12 . ( $am ? 'am' : 'pm' );
};
// Collapse consecutive identical day-ranges into runs (Mon–Thu, Fri, Sat, Sun…)
// exactly like the homepage hours block.
$g2a_contact_hour_runs = array();
$g2a_run_days = array(); $g2a_run_key = null;
foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $g2a_dow ) {
	$g2a_hrs = $g2a_biz['hours'][ $g2a_dow ] ?? null;
	$g2a_key = $g2a_hrs ? $g2a_hrs['open'] . '-' . $g2a_hrs['close'] : 'closed';
	if ( $g2a_run_key === $g2a_key ) {
		$g2a_run_days[] = $g2a_dow;
		continue;
	}
	if ( $g2a_run_days ) {
		$g2a_contact_hour_runs[] = array( $g2a_run_days, $g2a_run_key );
	}
	$g2a_run_days = array( $g2a_dow ); $g2a_run_key = $g2a_key;
}
if ( $g2a_run_days ) {
	$g2a_contact_hour_runs[] = array( $g2a_run_days, $g2a_run_key );
}
?>
<style>body { background: var(--color-void); }
  .hero { padding: 140px 32px 80px; border-bottom:1px solid var(--color-hairline); }
  .hero .c { max-width: 1280px; margin:0 auto; }
  .hero .eb { display:inline-flex; align-items:center; gap:10px; font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-brass-bright); text-transform: uppercase; margin-bottom: 18px; }
  .hero .eb::before { content:""; width: 28px; height:1px; background: var(--color-brass); }
  .hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 132px); line-height: 0.92; letter-spacing: 0.02em; color: var(--color-white); }
  .hero h1 .a { color: var(--color-brass-bright); }
  .hero p { color: var(--color-fog); max-width: 56ch; margin: 22px 0 0; font-size: 17px; line-height: 1.7; }

  .grid { padding: 80px 32px 100px; }
  .grid .c { max-width: 1280px; margin: 0 auto; display:grid; grid-template-columns: 1.2fr 1fr; gap: 56px; align-items: start; }
  @media (max-width: 980px) { .grid .c { grid-template-columns: 1fr; } }

  .form-card { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding: 36px; }
  .form-card h2 { font-family: var(--font-display); font-size: 36px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 8px; }
  .form-card .sub { color: var(--color-fog); margin-bottom: 28px; font-size: 14px; }
  .topic-row { display:grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 14px; }
  @media (max-width: 540px) { .topic-row { grid-template-columns: 1fr 1fr; } }
  .topic-row label { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.18em; color: var(--color-fog); text-transform: uppercase; padding: 12px; border:1px solid var(--color-hairline-bright); cursor: pointer; text-align: center; transition: all 200ms var(--ease-std); }
  .topic-row input { display:none; }
  .topic-row input:checked + label { background: var(--color-brass); color: #111; border-color: var(--color-brass); }
  .topic-row label:hover { border-color: var(--color-brass-bright); color: var(--color-white); }

  .info-side { display: flex; flex-direction: column; gap: 14px; }
  .info-card { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding: 24px; }
  .info-card.brass { border-color: var(--color-brass-dim); }
  .info-card .k { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--color-brass-bright); text-transform: uppercase; margin-bottom: 12px; }
  .info-card .v { font-family: var(--font-display); font-size: 24px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1.1; }
  .info-card .row { display:flex; justify-content:space-between; padding: 10px 0; font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.14em; color: var(--color-fog); border-top:1px solid var(--color-hairline); text-transform: uppercase; }
  .info-card .row:first-of-type { border-top: none; }
  .info-card .row .vh { color: var(--color-white); }
  .info-card .row.now .vh { color: var(--color-active); display: flex; align-items: center; gap: 6px; }
  .info-card .row.now .vh::before { content:""; width: 8px; height: 8px; border-radius: 50%; background: var(--color-active); animation: pulse 1.6s var(--ease-std) infinite; }
  @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.4; } }

  .map-wrap { aspect-ratio: 4/3; background: var(--color-void); border: 1px solid var(--color-brass-dim); position: relative; overflow: hidden; }
  .map-wrap svg { width: 100%; height: 100%; display: block; }
  .map-wrap iframe { width: 100%; height: 100%; display: block; border: 0; filter: grayscale(0.25) contrast(1.05); }
  html[data-theme="light"] .map-wrap iframe { filter: none; }
  .map-wrap .pin { position:absolute; top: 50%; left: 50%; transform: translate(-50%, -100%); display: flex; flex-direction: column; align-items: center; gap: 6px; pointer-events: none; }
  .map-wrap .pin .lbl { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-brass-bright); background: var(--color-gunmetal); padding: 4px 10px; border:1px solid var(--color-brass-dim); text-transform: uppercase; }
  .map-wrap .pin .dot { width: 18px; height: 18px; border-radius: 50%; background: var(--color-brass); position: relative; }
  .map-wrap .pin .dot::after { content:""; position: absolute; inset:-8px; border-radius: 50%; border:2px solid var(--color-brass); opacity: 0.5; animation: ring 2s ease-out infinite; }
  @keyframes ring { 0% { transform: scale(0.6); opacity: 0.7; } 100% { transform: scale(2.5); opacity: 0; } }

  .quick { padding: 80px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
  .quick .c { max-width: 1280px; margin: 0 auto; }
  .quick h2 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 56px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 8px; }
  .quick .lead { color: var(--color-fog); max-width: 56ch; margin-bottom: 36px; }
  .qg { display:grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
  @media (max-width: 900px) { .qg { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 540px) { .qg { grid-template-columns: 1fr; } }
  .ql { padding: 22px 20px; background: var(--color-void); border:1px solid var(--color-hairline); text-decoration: none; transition: all 250ms var(--ease-std); display: block; }
  .ql:hover { transform: translateY(-3px); border-color: var(--color-brass); }
  .ql .ic { width: 36px; height: 36px; border:1px solid var(--color-brass-dim); display: grid; place-items: center; color: var(--color-brass-bright); margin-bottom: 14px; }
  .ql .t { font-family: var(--font-condensed); font-weight: 600; font-size: 16px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; }
  .ql .d { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.16em; color: var(--color-silver); text-transform: uppercase; margin-top: 6px; }

  .faq { padding: 100px 32px; }
  .faq .c { max-width: 980px; margin: 0 auto; }
  .faq h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 36px; }
  .acc { border: 1px solid var(--color-hairline); }
  .acc-item { border-bottom: 1px solid var(--color-hairline); }
  .acc-item:last-child { border-bottom: none; }
  .acc-h { width: 100%; background: var(--color-gunmetal); padding: 22px 28px; display: flex; align-items: center; justify-content: space-between; gap: 18px; cursor: pointer; border: none; text-align: left; transition: background 200ms var(--ease-std); }
  .acc-h:hover { background: rgba(201,168,76,0.04); }
  .acc-h .t { font-family: var(--font-condensed); font-weight: 600; font-size: 17px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; }
  .acc-h .ch { font-family: var(--font-mono); font-size: 18px; color: var(--color-brass-bright); transition: transform 250ms var(--ease-std); }
  .acc-item.open .acc-h .ch { transform: rotate(45deg); }
  .acc-b { display: none; padding: 0 28px 28px; background: var(--color-gunmetal); color: var(--color-fog); font-size: 14px; line-height: 1.75; }
  .acc-item.open .acc-b { display: block; animation: fadeUp 280ms var(--ease-out); }</style>
<section class="hero">
  <div class="c">
    <div class="eb">Section 06  Get In Touch</div>
    <h1>QUESTIONS?<br><span class="a">ASK US.</span></h1>
    <p>The fastest path is the front desk during open hours. For everything else  schedule a private session, request an appraisal, or just say hi  drop us a line.</p>
  </div>
</section>

<section class="grid">
  <div class="c">
    <div class="form-card" id="request">
      <h2>SEND A MESSAGE</h2>
      <p class="sub">Replies within 1 business day. For urgent range questions, please call.</p>
      <?php if ( isset( $_GET['g2a_sent'] ) ) : ?>
        <div class="alert success"><span class="ic"></span><div><div class="h">Message Received</div>Thanks  we'll be in touch within one business day. For anything urgent, call <?php echo str_replace( ' ', '&nbsp;', esc_html( trim( $g2a_biz['phone'] ?? '(602) 715-2677' ) ) ); ?>.</div></div>
      <?php else : ?>
      <form method="post" action="<?php echo esc_url( g2a_form_action_url() ); ?>">
        <input type="hidden" name="action" value="g2a_request">
        <input type="hidden" name="g2a_subject" value="Contact Message">
        <?php wp_nonce_field( 'g2a_request', 'g2a_nonce' ); ?>
        <div style="position:absolute;left:-9999px;" aria-hidden="true"><label>Leave empty<input type="text" name="g2a_hp" tabindex="-1" autocomplete="off"></label></div>
        <label class="form-label">Topic</label>
        <div class="topic-row">
          <input type="radio" name="g2a_f_topic" id="t1" value="General" checked><label for="t1">General</label>
          <input type="radio" name="g2a_f_topic" id="t2" value="Training"><label for="t2">Training</label>
          <input type="radio" name="g2a_f_topic" id="t3" value="FFL / NFA"><label for="t3">FFL / NFA</label>
          <input type="radio" name="g2a_f_topic" id="t4" value="Private Event"><label for="t4">Private Event</label>
          <input type="radio" name="g2a_f_topic" id="t5" value="Membership"><label for="t5">Membership</label>
          <input type="radio" name="g2a_f_topic" id="t6" value="Press"><label for="t6">Press</label>
        </div>

        <div class="form-row">
          <div><label class="form-label">Full Name</label><input class="field" name="g2a_f_name" required placeholder="John Garcia"></div>
          <div><label class="form-label">Phone</label><input class="field" name="g2a_f_phone" placeholder="<?php echo esc_attr( $g2a_biz['phone'] ?? '(602) 715-2677' ); ?>"></div>
        </div>
        <label class="form-label">Email</label>
        <input class="field" type="email" name="g2a_f_email" required placeholder="you@email.com">
        <label class="form-label">Subject</label>
        <input class="field" name="g2a_f_subject_line" placeholder="Brief subject line">
        <label class="form-label">Message</label>
        <textarea class="field" name="g2a_f_message" rows="5" required placeholder="Tell us what you're after  we'll route it to the right person."></textarea>
        <label class="checkbox" style="margin-top: 14px;"><input type="checkbox" name="g2a_f_newsletter" value="Yes"> Subscribe to monthly range update newsletter (no spam  once per month).</label>
        <button class="btn btn-brass btn-lg" type="submit" style="width: 100%; margin-top: 22px;">Send Message </button>
        <div class="form-help" style="text-align: center; margin-top: 12px;"> Or call <?php echo esc_html( $g2a_biz['phone'] ?? '(602) 715-2677' ); ?> during business hours</div>
      </form>
      <?php endif; ?>
    </div>

    <div class="info-side">
      <div class="info-card brass">
        <div class="k"> Visit</div>
        <div class="v"><?php echo esc_html( $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' ); ?><br><?php echo esc_html( $g2a_biz['addr2'] ?? 'Mesa, AZ 85205' ); ?></div>
        <div style="margin-top: 18px;">
          <?php foreach ( $g2a_contact_hour_runs as $g2a_run ) :
            list( $g2a_run_dow_list, $g2a_run_key ) = $g2a_run;
            $g2a_run_is_now = in_array( $g2a_today_dow, $g2a_run_dow_list, true );
            $g2a_run_label  = ( count( $g2a_run_dow_list ) > 1 )
              ? $g2a_contact_day_labels[ $g2a_run_dow_list[0] ] . '–' . $g2a_contact_day_labels[ end( $g2a_run_dow_list ) ]
              : $g2a_contact_day_labels[ $g2a_run_dow_list[0] ];
            if ( 'closed' === $g2a_run_key ) {
              $g2a_run_time = __( 'Closed', 'guns2ammo' );
            } else {
              list( $g2a_run_open, $g2a_run_close ) = array_map( 'intval', explode( '-', $g2a_run_key ) );
              $g2a_run_time = $g2a_fmt_hour( $g2a_run_open ) . '  ' . $g2a_fmt_hour( $g2a_run_close );
            }
          ?>
          <div class="row<?php echo $g2a_run_is_now ? ' now' : ''; ?>"><span><?php echo esc_html( $g2a_run_label ); ?></span><span class="vh"><?php echo esc_html( $g2a_run_time ); ?></span></div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="map-wrap">
        <?php /* Live keyless Google Maps embed (same source as the footer
                 map) with the animated brand pin overlaid — replaces the old
                 stylised schematic so the contact page shows the real
                 location and stays interactive. */ ?>
        <?php
        $g2a_contact_addr1  = $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103';
        $g2a_contact_addr2  = $g2a_biz['addr2'] ?? 'Mesa, AZ 85205';
        $g2a_contact_map_q  = rawurlencode( ( $g2a_biz['name'] ?? 'Guns 2 Ammo' ) . ', ' . $g2a_contact_addr1 . ', ' . $g2a_contact_addr2 );
        $g2a_contact_map_src = 'https://www.google.com/maps?q=' . $g2a_contact_map_q . '&output=embed';
        // Short pin label: street only (drop ", Suite ###") to match the original design.
        $g2a_contact_pin_street = trim( explode( ',', $g2a_contact_addr1 )[0] );
        ?>
        <iframe
          src="<?php echo esc_url( $g2a_contact_map_src ); ?>"
          loading="lazy" referrerpolicy="no-referrer-when-downgrade"
          title="<?php echo esc_attr( sprintf( /* translators: 1: street address, 2: city/state/zip */ __( 'Guns 2 Ammo on Google Maps — %1$s, %2$s', 'guns2ammo' ), $g2a_contact_addr1, $g2a_contact_addr2 ) ); ?>"
          allowfullscreen></iframe>
        <div class="pin" aria-hidden="true"><span class="lbl">G2A — <?php echo esc_html( $g2a_contact_pin_street ); ?></span><span class="dot"></span></div>
      </div>

      <div class="info-card">
        <div class="k"> Direct</div>
        <div class="row"><span>Phone</span><span class="vh"><a href="<?php echo esc_url( function_exists( 'g2a_biz_tel_href' ) ? g2a_biz_tel_href() : 'tel:+16027152677' ); ?>" style="color: inherit; text-decoration: none;"><?php echo esc_html( $g2a_biz['phone'] ?? '(602) 715-2677' ); ?></a></span></div>
        <div class="row"><span>Email</span><span class="vh"><?php echo esc_html( $g2a_biz['email'] ?? 'sales@guns2ammo.com' ); ?></span></div>
        <div class="row"><span>FFL #</span><span class="vh">Available In Store</span></div>
      </div>
    </div>
  </div>
</section>

<section class="quick">
  <div class="c">
    <h2>FASTER PATHS</h2>
    <p class="lead">Most questions don't need a form. Here's where to go directly.</p>
    <div class="qg">
      <a class="ql" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">
        <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="16" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg></div>
        <div class="t">Book A Lane</div><div class="d">Reserve  Pay At Range</div>
      </a>
      <a class="ql" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">
        <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M9 12 L11 14 L15 9.5"/></svg></div>
        <div class="t">CCW Course</div><div class="d">Multi-State  Schedule</div>
      </a>
      <a class="ql" href="<?php echo esc_url( home_url( "/ffl-services/" ) ); ?>">
        <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="6" width="16" height="14"/><path d="M8 6 V4 H16 V6"/></svg></div>
        <div class="t">FFL Transfer</div><div class="d">$35  Same Day</div>
      </a>
      <a class="ql" href="<?php echo esc_url( home_url( "/memberships/" ) ); ?>">
        <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="6" width="18" height="12" rx="1"/><line x1="3" y1="11" x2="21" y2="11"/><line x1="7" y1="15" x2="11" y2="15"/></svg></div>
        <div class="t">Membership</div><div class="d">From <?php echo esc_html( function_exists( 'g2a_plan_price_from_fmt' ) ? g2a_plan_price_from_fmt() : '$29.99' ); ?>/mo</div>
      </a>
    </div>
  </div>
</section>

<section class="faq">
  <div class="c">
    <h2>BEFORE YOU EMAIL</h2>
    <div class="acc" id="faq">
      <div class="acc-item open"><button class="acc-h"><span class="t">Do I need a reservation to shoot?</span><span class="ch">+</span></button><div class="acc-b">Walk-ins welcome any time during open hours, but Saturday 1–3 PM and Sunday afternoon are usually full. Online reservations are free for members and $5 for non-members and guarantee your lane within a 30-minute window.</div></div>
      <div class="acc-item"><button class="acc-h"><span class="t">Can I rent a firearm if I don't own one?</span><span class="ch">+</span></button><div class="acc-b">Yes. Our rental wall has 40+ pistols and rifles from $15–35/visit. Members get 25% off. We require a second shooter (you can't rent solo) and a valid government ID. Rentals must use range-purchased ammunition.</div></div>
      <div class="acc-item"><button class="acc-h"><span class="t">What's your minimum age?</span><span class="ch">+</span></button><div class="acc-b">Solo shooters must be 18+. Children 8 and up are welcome with a parent or legal guardian present at all times  they share the lane and the parent supervises every shot. Anyone under 18 cannot rent.</div></div>
      <div class="acc-item"><button class="acc-h"><span class="t">Do you accept transfers from out-of-state purchases?</span><span class="ch">+</span></button><div class="acc-b">Yes  that's our daily bread. $35 flat for standard FFL transfers (handgun, long gun, receivers). $100 for NFA items. Have the seller ship to the FFL number listed on our <a href="<?php echo esc_url( home_url( "/ffl-services/" ) ); ?>" style="color: var(--color-brass-bright);">FFL Services</a> page.</div></div>
      <div class="acc-item"><button class="acc-h"><span class="t">Can I bring my own ammunition?</span><span class="ch">+</span></button><div class="acc-b">Yes  brass-cased FMJ, JHP, and lead round nose are welcome. Steel-core, steel-cased, tracer, incendiary, and reloads without RO inspection are not. See our full <a href="<?php echo esc_url( home_url( "/range-safety/" ) ); ?>" style="color: var(--color-brass-bright);">ammo policy</a>.</div></div>
      <div class="acc-item"><button class="acc-h"><span class="t">Is the range wheelchair accessible?</span><span class="ch">+</span></button><div class="acc-b">Yes. Lanes 1, 2, and 7 have ADA-compliant benches. Restrooms, retail floor, and classroom are fully accessible. Service animals welcome in the retail and classroom areas  not in the range bay.</div></div>
    </div>
  </div>
</section>


<script>
  document.querySelectorAll('#faq .acc-h').forEach(b => b.addEventListener('click', () => {
    b.parentElement.classList.toggle('open');
  }));
</script>
<?php get_footer();
