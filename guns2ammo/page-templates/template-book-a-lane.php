<?php
/**
 * Template Name: Book A Lane
 *
 * Source: design/book-a-lane.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$g2a_page_id                 = get_the_ID();
$g2a_lane_hero_title         = get_post_meta( $g2a_page_id, 'lane_hero_title', true );
$g2a_lane_membership_pitch   = get_post_meta( $g2a_page_id, 'lane_membership_pitch', true );
?>
<style>.bk-hero { padding: 140px 32px 64px; min-height: 50vh; display:flex; align-items:flex-end; position:relative; overflow:hidden; background: linear-gradient(180deg, rgba(26,25,30,0.5), rgba(26,25,30,0.95)), repeating-linear-gradient(180deg, #16161a 0 40px, #101012 40px 80px); }
.bk-hero .container { max-width:1280px; margin:0 auto; width:100%; position:relative; }
.bk-hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 112px); line-height:0.92; }
.bk-hero h1 .a { color: var(--color-brass-bright); }
.bk-trust { display:flex; gap: 24px; margin-top: 28px; flex-wrap:wrap; }
.bk-trust .it { font-family: var(--font-mono); font-size:11px; letter-spacing:0.22em; color: var(--color-fog); text-transform: uppercase; display:flex; align-items:center; gap:8px; }
.bk-trust .it::before { content:""; color: var(--color-brass-bright); }

.bk-layout { padding: 80px 32px; max-width:1280px; margin:0 auto; display:grid; grid-template-columns: 1fr 360px; gap: 48px; }
@media (max-width: 980px){ .bk-layout { grid-template-columns:1fr; } }

.step { padding: 32px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); margin-bottom: 16px; }
.step .hd { display:flex; align-items:center; gap:14px; margin-bottom: 24px; }
.step .num { width:36px; height:36px; border:1.5px solid var(--color-brass); display:grid; place-items:center; font-family: var(--font-display); font-size:18px; color: var(--color-brass-bright); }
.step h3 { font-family: var(--font-display); font-size:32px; color: var(--color-white); line-height:1; letter-spacing:0.02em; }
.step .sub { font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase; margin-top:4px; }

/* Calendar */
.cal { display:grid; grid-template-columns: repeat(7, 1fr); gap:6px; }
.cal .dow { font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase; text-align:center; padding:4px 0; }
.cal .day { aspect-ratio:1; border:1px solid var(--color-hairline-bright); background: var(--color-void); color: var(--color-fog); font-family: var(--font-condensed); font-weight:600; cursor:pointer; transition: all 180ms var(--ease-std); display:grid; place-items:center; position:relative; font-size:14px; }
.cal .day:hover { border-color: var(--color-brass); color: var(--color-brass-bright); }
.cal .day.muted { color: var(--color-silver); opacity:0.4; cursor:default; pointer-events:none; }
.cal .day.selected { background: var(--color-brass); color:#111; border-color: var(--color-brass); }
.cal .day.has-slots::after { content:""; position:absolute; bottom:4px; left:50%; transform:translateX(-50%); width:4px; height:4px; background: var(--color-brass-bright); border-radius:50%; }
.cal-head { display:flex; justify-content:space-between; align-items:center; margin-bottom: 16px; }
.cal-head .month { font-family: var(--font-condensed); font-weight:600; letter-spacing:0.06em; text-transform: uppercase; color: var(--color-white); font-size:18px; }
.cal-head button { width:32px; height:32px; background:transparent; border:1px solid var(--color-hairline-bright); color: var(--color-white); cursor:pointer; }

/* Time slots */
.slots { display:grid; grid-template-columns: repeat(4, 1fr); gap:8px; }
@media (max-width:520px){ .slots { grid-template-columns: repeat(3, 1fr); } }
.slots button { background: var(--color-void); border:1px solid var(--color-hairline-bright); color: var(--color-fog); padding: 12px 6px; font-family: var(--font-mono); font-size:13px; letter-spacing:0.1em; cursor:pointer; transition: all 180ms var(--ease-std); }
.slots button:hover { border-color: var(--color-brass); color: var(--color-brass-bright); }
.slots button.active { background: var(--color-brass); color:#111; border-color: var(--color-brass); }
.slots button.taken { color: var(--color-silver); text-decoration: line-through; pointer-events:none; opacity:0.5; border-color: var(--color-steel); }

/* Lane stepper */
.stepper { display:flex; align-items:center; gap:14px; }
.stepper button { width:40px; height:40px; background: var(--color-void); border:1px solid var(--color-hairline-bright); color: var(--color-white); font-size:20px; cursor:pointer; }
.stepper .v { font-family: var(--font-display); font-size:48px; color: var(--color-brass-bright); min-width:50px; text-align:center; line-height:1; }
.stepper .l { font-family: var(--font-mono); font-size:11px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; margin-left:8px; }

/* Form */
.fields { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
@media (max-width:560px){ .fields { grid-template-columns:1fr; } }
.fields label { font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; text-transform: uppercase; color: var(--color-silver); display:block; margin-bottom:6px; }

/* Sidebar */
.summary { position: sticky; top: 100px; padding: 28px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); height: fit-content; }
.summary h4 { font-family: var(--font-display); font-size:28px; color: var(--color-white); margin: 0 0 20px; letter-spacing:0.02em; }
.summary .row { display:flex; justify-content:space-between; padding: 12px 0; border-bottom:1px solid var(--color-hairline); }
.summary .row:last-of-type { border-bottom:0; }
.summary .row .l { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; }
.summary .row .v { font-family: var(--font-condensed); font-weight:600; letter-spacing:0.02em; color: var(--color-white); font-size:14px; }
.summary .total { display:flex; justify-content:space-between; align-items:baseline; padding: 18px 0; border-top:2px solid var(--color-brass); margin-top:12px; }
.summary .total .l { font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-fog); text-transform: uppercase; }
.summary .total .v { font-family: var(--font-display); font-size:42px; color: var(--color-brass-bright); line-height:1; }
.summary > .btn { width: 100%; white-space: normal; text-align: center; line-height: 1.2; padding: 16px 14px; font-size: clamp(13px, 2.6vw, 16px); letter-spacing: 0.06em; }
@media (max-width: 420px) { .summary > .btn { padding: 14px 10px; letter-spacing: 0.04em; } }

/* Info grid below */
.info-grid { padding: 80px 32px; background: var(--color-void); border-top:1px solid var(--color-hairline); }
.info-grid .ig-head { max-width:1280px; margin:0 auto 28px; }
.info-grid .ig-head h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,60px); color: var(--color-white); letter-spacing:0.02em; line-height:1; }
.info-grid .ig-head p { color: var(--color-fog); font-size:15px; line-height:1.7; max-width:64ch; margin:12px 0 0; }
.info-grid .container { max-width:1280px; margin:0 auto; display:grid; grid-template-columns: repeat(2, 1fr); gap:16px; }
@media (max-width:640px){ .info-grid .container { grid-template-columns: 1fr; } }
.info-card ul { list-style:none; padding:0; margin:12px 0 0; display:grid; gap:7px; }
.info-card ul li { font-size:13px; color:var(--color-fog); padding-left:16px; position:relative; line-height:1.55; }
.info-card ul li::before { content:""; position:absolute; left:0; color:var(--color-brass-bright); font-size:8px; top:4px; }
.info-card .more { display:inline-block; margin-top:14px; font-family:var(--font-condensed); font-weight:600; font-size:11px; letter-spacing:0.12em; text-transform:uppercase; color:var(--color-ember); text-decoration:none; }
.info-card { padding: 24px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.info-card .ic { width:36px; height:36px; border:1.5px solid var(--color-brass); display:grid; place-items:center; color: var(--color-brass-bright); margin-bottom:14px; }
.info-card h4 { font-family: var(--font-display); font-size:24px; color: var(--color-white); margin:0 0 8px; }
.info-card p { color: var(--color-fog); font-size:13px; margin:0; }

.ladies-card { padding: 32px; background: linear-gradient(135deg, rgba(214,170,124,0.10), transparent), var(--color-gunmetal); border:1px solid rgba(214,170,124,0.3); margin-top:24px; }
.ladies-card .tag { display:inline-block; padding:4px 10px; background: rgba(214,170,124,0.15); border:1px solid rgba(214,170,124,0.4); color:#E5BC8A; font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; text-transform: uppercase; }
.ladies-card h4 { font-family: var(--font-display); font-size:32px; color: var(--color-white); margin: 12px 0 8px; }
.ladies-card p { color: var(--color-fog); font-size:14px; margin:0 0 16px; }

/* Non-member membership promo banner */
.bk-promo { max-width:1280px; margin:40px auto -24px; padding:0 32px; }
.bk-promo__in { position:relative; overflow:hidden; display:flex; align-items:center; justify-content:space-between; gap:28px; flex-wrap:wrap;
  padding:28px 32px; border:1px solid var(--color-brass-dim);
  background: linear-gradient(135deg, rgba(232,128,47,0.14), rgba(201,168,76,0.06) 55%, transparent),
              var(--color-gunmetal); }
.bk-promo__in::before { content:""; position:absolute; top:0; left:0; width:4px; height:100%; background: var(--color-ember); }
.bk-promo__tag { display:inline-block; font-family:var(--font-mono); font-size:10px; letter-spacing:0.26em; text-transform:uppercase;
  color:var(--color-brass-bright); background:rgba(201,168,76,0.12); border:1px solid var(--color-brass-dim); padding:5px 12px; margin-bottom:12px; }
.bk-promo__h { font-family:var(--font-display); font-size:clamp(26px,3.4vw,40px); line-height:1.02; color:var(--color-white); letter-spacing:0.02em; }
.bk-promo__h em { font-style:normal; color:var(--color-brass-bright); }
.bk-promo__p { color:var(--color-fog); font-size:14px; line-height:1.6; margin:8px 0 0; max-width:54ch; }
.bk-promo__cta { display:flex; flex-direction:column; align-items:flex-start; gap:8px; }
.bk-promo__price { font-family:var(--font-mono); font-size:11px; letter-spacing:0.18em; text-transform:uppercase; color:var(--color-silver); }
@media (max-width:680px){ .bk-promo { margin:28px auto -8px; padding:0 16px; }
  .bk-promo__in { padding:24px 20px; } .bk-promo__cta { width:100%; } .bk-promo__cta .btn { width:100%; text-align:center; } }</style>
<header class="bk-hero">
  <div class="container">
    <span class="eyebrow"> Reservation  Live Lane Status</span>
    <h1 style="margin-top:18px;"><?php echo esc_html( $g2a_lane_hero_title ? $g2a_lane_hero_title : 'BOOK YOUR LANE.' ); ?></h1>
    <div class="bk-trust">
      <span class="it">Climate Controlled</span>
      <span class="it">6 Lanes</span>
      <span class="it">RSO On Duty</span>
      <span class="it">Smart Ventilation</span>
      <span class="it"><span class="dot-live"></span> 4 of 6 lanes open now</span>
    </div>
  </div>
</header>

<?php
/* Membership promo  shown to non-members (logged-out visitors and
 * any logged-in user without an active Memberistic membership). */
$g2a_is_member = false;
if ( is_user_logged_in() ) {
	if ( function_exists( 'memberistic_user_has_active_membership' ) ) {
		$g2a_is_member = (bool) memberistic_user_has_active_membership( get_current_user_id() );
	} elseif ( function_exists( 'memberistic_is_active_member' ) ) {
		$g2a_is_member = (bool) memberistic_is_active_member( get_current_user_id() );
	}
}
if ( ! $g2a_is_member ) : ?>
<aside class="bk-promo" aria-label="Membership offer">
  <div class="bk-promo__in">
    <div>
      <span class="bk-promo__tag"> Members Hub Exclusive</span>
      <div class="bk-promo__h">YOUR 1-HOUR LANE BOOKING  <em>FULLY FREE.</em></div>
      <p class="bk-promo__p"><?php echo esc_html( $g2a_lane_membership_pitch ? $g2a_lane_membership_pitch : 'Members receive free lane time on every visit. Skip the standard lane fee, access discounted member pricing, and reserve your lane online 24/7 through the Guns 2 Ammo Members Hub.' ); ?></p>
    </div>
    <div class="bk-promo__cta">
      <a class="btn btn-ember" href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">Join The Members Hub </a>
      <span class="bk-promo__price">Plans from $29.99/mo  Cancel anytime  No contracts</span>
    </div>
  </div>
</aside>
<?php endif; ?>

<?php if ( function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
<section class="bk-layout g2a-plugin-host" style="display:block;">
  <?php echo g2a_plugin_section( 'g2a_lane_booking' ); ?>
</section>
<?php else : ?>
<section class="bk-layout">
  <div>
    <div class="step">
      <div class="hd">
        <span class="num">1</span>
        <div><h3>SELECT DATE</h3><div class="sub">Reservations open 30 days out</div></div>
      </div>
      <div class="cal-head">
        <button></button>
        <span class="month">May 2026</span>
        <button></button>
      </div>
      <div class="cal" id="cal"></div>
    </div>

    <div class="step">
      <div class="hd">
        <span class="num">2</span>
        <div><h3>SELECT TIME</h3><div class="sub">Each session is 60 minutes</div></div>
      </div>
      <div class="slots">
        <button class="taken">10:00 AM</button>
        <button>11:00 AM</button>
        <button>12:00 PM</button>
        <button class="taken">1:00 PM</button>
        <button class="active">2:00 PM</button>
        <button>3:00 PM</button>
        <button>4:00 PM</button>
        <button class="taken">5:00 PM</button>
        <button>6:00 PM</button>
        <button>7:00 PM</button>
        <button>8:00 PM</button>
        <button class="taken">9:00 PM</button>
      </div>
    </div>

    <div class="step">
      <div class="hd">
        <span class="num">3</span>
        <div><h3>SHOOTERS &amp; LANES</h3><div class="sub">Up to 2 shooters per lane</div></div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:32px; align-items:center;">
        <div class="stepper"><button></button><span class="v">2</span><button>+</button><span class="l">Shooters</span></div>
        <div class="stepper"><button></button><span class="v">1</span><button>+</button><span class="l">Lanes</span></div>
      </div>
    </div>

    <div class="step">
      <div class="hd">
        <span class="num">4</span>
        <div><h3>SHOOTER DETAILS</h3><div class="sub">Required for waiver &amp; check-in</div></div>
      </div>
      <div class="fields">
        <div><label>Full Name</label><input class="field" placeholder="Joseph Hartwell"></div>
        <div><label>Email Address</label><input class="field" type="email" placeholder="you@example.com"></div>
        <div><label>Phone Number</label><input class="field" placeholder="(480) 555-0199"></div>
        <div><label>Experience Level</label>
          <select class="field" style="background-color: var(--color-void);">
            <option>First Time</option><option>Beginner</option><option>Intermediate</option><option>Advanced</option><option>Law Enforcement / Military</option>
          </select>
        </div>
        <div style="grid-column:1 / -1;"><label>Bringing Your Own Firearm?</label>
          <div style="display:flex; gap:10px;">
            <button class="btn btn-brass btn-sm" style="background:var(--color-brass); color:#111; flex:1;">Yes  Personal Firearm</button>
            <button class="btn btn-ghost btn-sm" style="flex:1;">No  Renting</button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <aside class="summary">
    <h4>RESERVATION</h4>
    <div class="row"><span class="l">Date</span><span class="v">Thu  May 14, 2026</span></div>
    <div class="row"><span class="l">Time</span><span class="v">2:00 PM  60 min</span></div>
    <div class="row"><span class="l">Lanes</span><span class="v">1 Lane</span></div>
    <div class="row"><span class="l">Shooters</span><span class="v">2 People</span></div>
    <div class="row"><span class="l">RSO</span><span class="v">Included</span></div>
    <div class="row"><span class="l">Eye / Ear Protection</span><span class="v">Free</span></div>
    <div class="total"><span class="l">Estimated Total</span><span class="v">$47</span></div>
    <a class="btn btn-ember" style="margin-top:16px;">Confirm Reservation </a>
    <p style="margin-top:14px; font-size:12px; color: var(--color-silver); text-align:center;">No charge until check-in. Free cancellation up to 2 hours before.</p>
  </aside>
</section>
<?php endif; ?>

<section class="info-grid" id="pricing">
  <div class="ig-head">
    <span class="eyebrow" style="margin-bottom:14px;">Before You Book</span>
    <h2>HOURS, PRICING, RULES &amp; WHAT TO BRING</h2>
    <p>Everything you need to plan your visit to our climate-controlled indoor range at 6030 E Main St in Mesa. New to the range? Read it through  then book with confidence.</p>
  </div>
  <div class="container">
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><circle cx="12" cy="12" r="9"/><polyline points="12,7 12,12 15,14"/></svg></div>
      <h4>HOURS</h4>
      <p>The range is open seven days a week with extended weekend hours.</p>
      <ul>
        <li>Monday, Tuesday, Wednesday, Thursday  10:00 AM to 6:00 PM</li>
        <li>Friday  10:00 AM to 7:00 PM</li>
        <li>Saturday  10:00 AM to 7:00 PM</li>
        <li>Sunday  12:00 PM to 6:00 PM</li>
        <li>Last lane booking is taken one hour before close</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Holiday hours &amp; directions </a>
    </div>
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><line x1="12" y1="2" x2="12" y2="22"/><path d="M16 6 H10 a3 3 0 0 0 0 6 h4 a3 3 0 0 1 0 6 H6"/></svg></div>
      <h4>PRICING</h4>
      <p>A standard lane rental is $20 per hour with additional shooters at $15 per lane. Members receive included lane time with qualifying plans plus discounted rentals and guest rates based on membership level.</p>
      <ul>
        <li>Lane rental  $20 per hour</li>
        <li>Additional shooter  $15 per lane</li>
        <li>Handgun rentals start at $15  rifle rentals start at $25</li>
        <li>Eye &amp; ear protection available at check-in</li>
        <li>Paper targets available in-store</li>
        <li>Members receive free lane time and exclusive rental pricing</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">Compare membership plans </a>
    </div>
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M12 2 L20 5 V12 C20 17 16 21 12 22 C8 21 4 17 4 12 V5 Z"/></svg></div>
      <h4>RULES</h4>
      <p>Our range safety officers run a tight, friendly floor. Every shooter follows federal and Arizona law plus a few house rules that keep all six lanes safe.</p>
      <ul>
        <li>Eye and ear protection required at all times on the floor</li>
        <li>No alcohol or impairing substances before or during shooting</li>
        <li>Factory-new ammunition only  no reloads or steel-core</li>
        <li>Shooters 8+ welcome with a parent or guardian present</li>
        <li>An RSO is always on duty  ask anything, anytime</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/range-safety/' ) ); ?>">Full range safety rules </a>
    </div>
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><rect x="3" y="6" width="18" height="14"/><path d="M3 10 h18"/><circle cx="8" cy="16" r="1.5" fill="currentColor"/></svg></div>
      <h4>WHAT TO BRING</h4>
      <p>Pack light  we supply almost everything. First-timers just need an ID and closed-toe shoes; our team handles the rest at check-in.</p>
      <ul>
        <li>Valid government photo ID for every shooter</li>
        <li>Closed-toe shoes and a high-neck shirt (hot-brass policy)</li>
        <li>Your own firearm if you have one  fully optional</li>
        <li>40+ rental firearms available on-site if you don't</li>
        <li>Ammunition can be bought in store  factory-new only</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">Shop firearms &amp; ammo </a>
    </div>
  </div>

  <div class="container" id="ladies" style="margin-top:24px; display:block; max-width:1280px;">
    <div class="ladies-card">
      <span class="tag">Tuesdays  Ladies Day</span>
      <h4>Ladies Tuesday  Free 1-Hour Lane Time</h4>
      <p>Every Tuesday all day, women receive one free hour of lane time. No 1-on-1 range officer package is included with this day.</p>
      <a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( "/ladies-tuesday/" ) ); ?>">Learn More </a>
    </div>
  </div>
</section>

<script>
// Generate calendar (May 2026)
const cal = document.getElementById('cal');
const dows = ['S','M','T','W','T','F','S'];
let html = dows.map(d=>`<div class="dow">${d}</div>`).join('');
const start = new Date(2026, 4, 1).getDay(); // Friday
for (let i=0; i<start; i++) html += '<div class="day muted"></div>';
for (let d=1; d<=31; d++) {
  const has = d % 3 !== 0 ? 'has-slots' : '';
  const sel = d === 14 ? 'selected' : '';
  const muted = d < 9 ? 'muted' : '';
  html += `<div class="day ${has} ${sel} ${muted}">${d}</div>`;
}
cal.innerHTML = html;
cal.addEventListener('click', e => {
  const d = e.target.closest('.day:not(.muted)');
  if (!d) return;
  cal.querySelectorAll('.day').forEach(x => x.classList.remove('selected'));
  d.classList.add('selected');
});
document.querySelectorAll('.slots button:not(.taken)').forEach(b => {
  b.addEventListener('click', () => {
    document.querySelectorAll('.slots button').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
  });
});
</script>
<?php get_footer();
