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
  .map-wrap .pin { position:absolute; top: 50%; left: 50%; transform: translate(-50%, -100%); display: flex; flex-direction: column; align-items: center; gap: 6px; }
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
        <div class="alert success"><span class="ic"></span><div><div class="h">Message Received</div>Thanks  we'll be in touch within one business day. For anything urgent, call (602)&nbsp;715-2677.</div></div>
      <?php else : ?>
      <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
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
          <div><label class="form-label">Phone</label><input class="field" name="g2a_f_phone" placeholder="(602) 715-2677"></div>
        </div>
        <label class="form-label">Email</label>
        <input class="field" type="email" name="g2a_f_email" required placeholder="you@email.com">
        <label class="form-label">Subject</label>
        <input class="field" name="g2a_f_subject_line" placeholder="Brief subject line">
        <label class="form-label">Message</label>
        <textarea class="field" name="g2a_f_message" rows="5" required placeholder="Tell us what you're after  we'll route it to the right person."></textarea>
        <label class="checkbox" style="margin-top: 14px;"><input type="checkbox" name="g2a_f_newsletter" value="Yes"> Subscribe to monthly range update newsletter (no spam  once per month).</label>
        <button class="btn btn-brass btn-lg" type="submit" style="width: 100%; margin-top: 22px;">Send Message </button>
        <div class="form-help" style="text-align: center; margin-top: 12px;"> Or call (602) 715-2677 during business hours</div>
      </form>
      <?php endif; ?>
    </div>

    <div class="info-side">
      <div class="info-card brass">
        <div class="k"> Visit</div>
        <div class="v">6030 E Main St, Suite 103<br>Mesa, Arizona 85205</div>
        <div style="margin-top: 18px;">
          <div class="row"><span>Mon  Thu</span><span class="vh">10am  6pm</span></div>
          <div class="row"><span>Fri</span><span class="vh">Closed</span></div>
          <div class="row"><span>Sat</span><span class="vh">9am  8pm</span></div>
          <div class="row"><span>Sun</span><span class="vh">12pm  6pm</span></div>
        </div>
      </div>

      <div class="map-wrap">
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid slice">
          <rect width="400" height="300" fill="rgba(255,255,255,0.02)"/>
          <!-- Stylized grid -->
          <g stroke="rgba(201,168,76,0.12)" stroke-width="1">
            <line x1="0" y1="60" x2="400" y2="60"/>
            <line x1="0" y1="120" x2="400" y2="120"/>
            <line x1="0" y1="180" x2="400" y2="180"/>
            <line x1="0" y1="240" x2="400" y2="240"/>
            <line x1="60" y1="0" x2="60" y2="300"/>
            <line x1="140" y1="0" x2="140" y2="300"/>
            <line x1="260" y1="0" x2="260" y2="300"/>
            <line x1="340" y1="0" x2="340" y2="300"/>
          </g>
          <!-- Roads -->
          <path d="M0 150 L400 150" stroke="rgba(201,168,76,0.35)" stroke-width="3"/>
          <path d="M200 0 L200 300" stroke="rgba(201,168,76,0.25)" stroke-width="2"/>
          <path d="M0 80 L400 80" stroke="rgba(201,168,76,0.15)" stroke-width="1.5"/>
          <text x="20" y="142" font-family="ui-monospace,monospace" font-size="9" fill="#94908A" letter-spacing="2">E MAIN ST</text>
          <text x="208" y="20" font-family="ui-monospace,monospace" font-size="9" fill="#94908A" letter-spacing="2">CRISMON</text>
          <text x="20" y="290" font-family="ui-monospace,monospace" font-size="8" fill="#5A5651" letter-spacing="2">6030 E MAIN ST  MESA AZ</text>
        </svg>
        <div class="pin"><span class="lbl">G2A  6030 E Main</span><span class="dot"></span></div>
      </div>

      <div class="info-card">
        <div class="k"> Direct</div>
        <div class="row"><span>Phone</span><span class="vh"><a href="tel:+16027152677" style="color: inherit; text-decoration: none;">(602) 715-2677</a></span></div>
        <div class="row"><span>Email</span><span class="vh">sales@guns2ammo.com</span></div>
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
        <div class="t">Membership</div><div class="d">From $29.99/mo</div>
      </a>
    </div>
  </div>
</section>

<section class="faq">
  <div class="c">
    <h2>BEFORE YOU EMAIL</h2>
    <div class="acc" id="faq">
      <div class="acc-item open"><button class="acc-h"><span class="t">Do I need a reservation to shoot?</span><span class="ch">+</span></button><div class="acc-b">Walk-ins welcome any time during open hours, but Saturday 113 and Sunday afternoon are usually full. Online reservations are free for members and $5 for non-members and guarantee your lane within a 30-minute window.</div></div>
      <div class="acc-item"><button class="acc-h"><span class="t">Can I rent a firearm if I don't own one?</span><span class="ch">+</span></button><div class="acc-b">Yes. Our rental wall has 40+ pistols and rifles from $1535/visit. Members get 25% off. We require a second shooter (you can't rent solo) and a valid government ID. Rentals must use range-purchased ammunition.</div></div>
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
