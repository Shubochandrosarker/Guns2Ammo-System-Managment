<?php
/**
 * Template Name: Ffl Services
 *
 * Source: design/ffl-services.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
// Single source of truth for NAP — see inc/business-info.php.
$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
?>
<style>body { background: var(--color-void); }
  .hero { padding: 140px 32px 80px; border-bottom:1px solid var(--color-hairline); }
  .hero .c { max-width: 1280px; margin:0 auto; display:grid; grid-template-columns: 1.2fr 1fr; gap: 56px; align-items: end; }
  @media (max-width: 980px) { .hero .c { grid-template-columns: 1fr; } }
  .hero .eb { display:inline-flex; align-items:center; gap:10px; font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-brass-bright); text-transform: uppercase; margin-bottom: 18px; }
  .hero .eb::before { content:""; width: 28px; height:1px; background: var(--color-brass); }
  .hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 132px); line-height: 0.92; letter-spacing: 0.02em; color: var(--color-white); }
  .hero h1 .a { color: var(--color-brass-bright); }
  .hero p { color: var(--color-fog); max-width: 56ch; margin: 22px 0 0; font-size: 17px; line-height: 1.7; }
  .hero .nums { padding: 28px; background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); display:grid; gap: 18px; }
  .hero .nums .it { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; padding-bottom: 14px; border-bottom: 1px solid var(--color-hairline); }
  .hero .nums .it:last-child { border-bottom: none; padding-bottom: 0; }
  .hero .nums .it strong { display:block; font-family: var(--font-display); font-size: 36px; color: var(--color-brass-bright); letter-spacing: 0.04em; line-height: 1; margin-top: 6px; }

  .sb { padding: 100px 32px; }
  .sb .c { max-width: 1280px; margin:0 auto; }
  .sh { display:flex; align-items: baseline; justify-content: space-between; margin-bottom: 36px; gap: 18px; flex-wrap: wrap; }
  .sh h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .sh .ix { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; color: var(--color-brass-bright); text-transform: uppercase; }

  /* NFA */
  .nfa { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
  .nfa-grid { display:grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-top: 36px; }
  @media (max-width: 900px) { .nfa-grid { grid-template-columns: 1fr; } }
  .nfa-card { padding: 32px; background: var(--color-void); border:1px solid var(--color-hairline); }
  .nfa-card .k { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--color-brass-bright); text-transform: uppercase; margin-bottom: 12px; }
  .nfa-card h3 { font-family: var(--font-display); font-size: 36px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .nfa-card p { color: var(--color-fog); margin: 14px 0; line-height: 1.7; }
  .nfa-card .row { display: flex; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--color-hairline); font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.16em; color: var(--color-silver); text-transform: uppercase; }
  .nfa-card .row .v { color: var(--color-white); }

  .timeline { padding: 100px 32px; }
  .timeline .c { max-width: 1280px; margin:0 auto; }
  .tl { display:grid; grid-template-columns: repeat(5, 1fr); gap: 0; margin-top: 36px; border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
  @media (max-width: 980px) { .tl { grid-template-columns: 1fr; } }
  .tl-step { padding: 28px 24px; border-right: 1px solid var(--color-hairline); position:relative; }
  .tl-step:last-child { border-right: none; }
  .tl-step .num { font-family: var(--font-display); font-size: 56px; color: var(--color-brass-bright); line-height: 1; }
  .tl-step .nm { font-family: var(--font-condensed); font-weight: 600; font-size: 16px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; margin: 14px 0 6px; }
  .tl-step .d { font-size: 13px; color: var(--color-fog); line-height: 1.7; }
  .tl-step .meta { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--color-hairline); }

  /* Shipping */
  .ship { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
  .ship-flex { display:grid; grid-template-columns: 1fr 1fr; gap: 56px; align-items: start; }
  @media (max-width: 900px) { .ship-flex { grid-template-columns: 1fr; } }
  .ship .map { aspect-ratio: 4/3; background: var(--color-void); border: 1px solid var(--color-brass-dim); position: relative; overflow: hidden; }
  .ship .map svg { width: 100%; height: 100%; display: block; }
  .ship .quote { padding: 28px; background: var(--color-void); border:1px solid var(--color-hairline); }
  .ship .quote h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 22px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 16px; }

  /* Consignment */
  .consign { padding: 100px 32px; }
  .consign .c { max-width: 1280px; margin:0 auto; }
  .split { background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); padding: 56px; display: grid; grid-template-columns: 1fr 1fr; gap: 56px; align-items: center; }
  @media (max-width: 900px) { .split { grid-template-columns: 1fr; padding: 36px; } }
  .split h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); color: var(--color-white); letter-spacing: 0.02em; line-height: 0.95; }
  .split h2 .a { color: var(--color-brass-bright); }
  .split p { color: var(--color-fog); margin: 18px 0; line-height: 1.7; }
  .split-rate { padding: 20px; background: var(--color-void); border-left: 3px solid var(--color-brass); }
  .split-rate .row { display: flex; justify-content: space-between; padding: 10px 0; font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.18em; color: var(--color-silver); text-transform: uppercase; border-bottom: 1px solid var(--color-hairline); }
  .split-rate .row:last-child { border-bottom: none; }
  .split-rate .row .v { font-family: var(--font-display); font-size: 22px; color: var(--color-brass-bright); letter-spacing: 0.04em; }

  /* Compliance */
  .comply { padding: 100px 32px; background: var(--color-void); border-top: 1px solid var(--color-hairline); }
  .comply .c { max-width: 980px; margin: 0 auto; text-align: center; }
  .comply h2 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 56px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .comply p { color: var(--color-fog); max-width: 60ch; margin: 18px auto 0; line-height: 1.7; }
  .comply .creds { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; margin-top: 36px; }
  .comply .creds span { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-fog); padding: 14px 22px; border:1px solid var(--color-hairline-bright); text-transform: uppercase; }</style>
<section class="hero">
  <div class="c">
    <div>
      <div class="eb">Section 04  Licensed Services</div>
      <h1>FFL <span class="a">SERVICES</span><br>BEYOND TRANSFER</h1>
      <p>Federally Licensed Type-01 dealer with SOT Class 3 endorsement. NFA processing, interstate shipping, consignment sales  handled correctly the first time.</p>
    </div>
    <div class="nums">
      <div class="it">FFL Number<strong style="font-size: 22px; font-family: var(--font-mono); letter-spacing: 0.1em;">Available In Store</strong></div>
      <div class="it">SOT Class<strong>03</strong></div>
      <div class="it">Active Since<strong>2018</strong></div>
      <div class="it">Transfers Processed<strong>4,200+</strong></div>
    </div>
  </div>
</section>

<section class="nfa" id="nfa">
  <div class="c">
    <div class="sh">
      <div>
        <span class="ix"> Service 02</span>
        <h2 style="margin-top: 8px;">NFA / CLASS III</h2>
      </div>
      <p style="color: var(--color-fog); max-width: 36ch;">SOT-licensed for suppressors, SBRs, SBS, and full-auto. We handle the Form 4, fingerprints, and trust paperwork in-house.</p>
    </div>

    <div class="nfa-grid">
      <div class="nfa-card">
        <div class="k">Item Type 01</div>
        <h3>SUPPRESSOR</h3>
        <p>Pistol, rifle, or rimfire. We stock 30+ from SilencerCo, Dead Air, OSS, and Rugged. Buy and store-on-file at no extra cost during ATF wait.</p>
        <div class="row"><span>Transfer Fee</span><span class="v">$95</span></div>
        <div class="row"><span>ATF Tax Stamp</span><span class="v">$200</span></div>
        <div class="row"><span>Avg Wait Time</span><span class="v">Under 30 Days</span></div>
      </div>
      <div class="nfa-card">
        <div class="k">Item Type 02</div>
        <h3>SHORT BARREL</h3>
        <p>SBRs and SBS. We stamp it, you build it. Storage during waiting period included. Trust setup with our preferred attorney for $150 flat.</p>
        <div class="row"><span>Transfer Fee</span><span class="v">$95</span></div>
        <div class="row"><span>ATF Tax Stamp</span><span class="v">$200</span></div>
        <div class="row"><span>Trust Setup</span><span class="v">$150 Optional</span></div>
      </div>
      <div class="nfa-card">
        <div class="k">Item Type 03</div>
        <h3>FULL AUTO</h3>
        <p>Pre-1986 transferable machine guns. Limited availability  joining our notification list places you in queue when items become available.</p>
        <div class="row"><span>Transfer Fee</span><span class="v">$295</span></div>
        <div class="row"><span>ATF Tax Stamp</span><span class="v">$200</span></div>
        <div class="row"><span>Status</span><span class="v" style="color: var(--color-ember);">By Request</span></div>
      </div>
      <div class="nfa-card">
        <div class="k">Item Type 04</div>
        <h3>AOW</h3>
        <p>Any-Other-Weapon classification. Reduced tax stamp. Common items: Mossberg Shockwave with VFG, pen guns, smooth-bore handguns.</p>
        <div class="row"><span>Transfer Fee</span><span class="v">$75</span></div>
        <div class="row"><span>ATF Tax Stamp</span><span class="v">$5</span></div>
        <div class="row"><span>Avg Wait Time</span><span class="v">Under 30 Days</span></div>
      </div>
    </div>
  </div>
</section>

<section class="timeline" id="how">
  <div class="c">
    <div class="sh">
      <div>
        <span class="ix"> Process</span>
        <h2 style="margin-top: 8px;">HOW IT WORKS</h2>
      </div>
      <p style="color: var(--color-fog); max-width: 36ch;">Five-step process from order to in-hand. Receive item, get notified, complete 4473, walk out with it.</p>
    </div>
    <div class="tl">
      <div class="tl-step"><div class="num">01</div><div class="nm">PURCHASE</div><div class="d">Buy online from any out-of-state dealer or NFA seller. Ship to our FFL.</div><div class="meta">Day 0</div></div>
      <div class="tl-step"><div class="num">02</div><div class="nm">RECEIPT</div><div class="d">We log your item into our bound book within 24 hours of arrival.</div><div class="meta">Day 12</div></div>
      <div class="tl-step"><div class="num">03</div><div class="nm">NOTIFY</div><div class="d">Email and SMS sent. NFA items move to ATF stamp wait at this point.</div><div class="meta">Day 2</div></div>
      <div class="tl-step"><div class="num">04</div><div class="nm">PAPERWORK</div><div class="d">In-store ATF Form 4473, NICS background check, ID verification.</div><div class="meta">Day 2+  15min</div></div>
      <div class="tl-step"><div class="num">05</div><div class="nm">RELEASE</div><div class="d">Standard items: walk out same day. NFA items: after stamp clears.</div><div class="meta">Same Day / 30 Day</div></div>
    </div>
  </div>
</section>

<section class="ship" id="shipping">
  <div class="c">
    <div class="sh">
      <div>
        <span class="ix"> Service 03</span>
        <h2 style="margin-top: 8px;">FIREARM SHIPPING</h2>
      </div>
      <p style="color: var(--color-fog); max-width: 36ch;">Selling to an out-of-state buyer? Drop off your firearm  we pack, label, and ship to their FFL.</p>
    </div>

    <div class="ship-flex">
      <div class="map">
        <svg viewBox="0 0 400 300" preserveAspectRatio="xMidYMid meet">
          <rect width="400" height="300" fill="transparent"/>
          <!-- Stylized US outline -->
          <path d="M30 90 L80 70 L130 65 L180 60 L240 58 L300 65 L350 80 L370 110 L368 160 L350 200 L310 230 L260 245 L210 245 L160 240 L120 235 L80 220 L50 195 L35 160 Z" fill="none" stroke="rgba(201,168,76,0.25)" stroke-width="1"/>
          <!-- Origin pin (Mesa AZ approx) -->
          <circle cx="120" cy="180" r="6" fill="#C9A84C"/>
          <circle cx="120" cy="180" r="14" fill="none" stroke="#C9A84C" stroke-width="1" opacity="0.5"><animate attributeName="r" from="6" to="20" dur="2s" repeatCount="indefinite"/><animate attributeName="opacity" from="0.7" to="0" dur="2s" repeatCount="indefinite"/></circle>
          <!-- Destination pins -->
          <circle cx="280" cy="125" r="3" fill="#E8802F"/>
          <circle cx="320" cy="155" r="3" fill="#E8802F"/>
          <circle cx="240" cy="200" r="3" fill="#E8802F"/>
          <circle cx="200" cy="100" r="3" fill="#E8802F"/>
          <circle cx="60" cy="130" r="3" fill="#E8802F"/>
          <!-- Lines -->
          <path d="M120 180 Q200 100 280 125" fill="none" stroke="rgba(201,168,76,0.5)" stroke-width="1" stroke-dasharray="3 3"/>
          <path d="M120 180 Q220 130 320 155" fill="none" stroke="rgba(201,168,76,0.5)" stroke-width="1" stroke-dasharray="3 3"/>
          <path d="M120 180 Q170 195 240 200" fill="none" stroke="rgba(201,168,76,0.5)" stroke-width="1" stroke-dasharray="3 3"/>
          <text x="124" y="172" font-family="ui-monospace,monospace" font-size="9" fill="#C9A84C" letter-spacing="2">MESA</text>
          <text x="20" y="270" font-family="ui-monospace,monospace" font-size="8" fill="#94908A" letter-spacing="2">SHIPPING NETWORK  50 STATES + DC</text>
        </svg>
      </div>

      <div class="quote" id="request">
        <h3>Quick Shipping Quote</h3>
        <?php if ( isset( $_GET['g2a_sent'] ) ) : ?>
          <div class="alert success" style="margin-top:14px;">
            <span class="ic"></span>
            <div><div class="h">Request Received</div>Thanks - our FFL team will confirm your drop-off window by email or phone shortly. For anything urgent, call <?php echo str_replace( ' ', '&nbsp;', esc_html( trim( $g2a_biz['phone'] ?? '(602) 715-2677' ) ) ); ?>.</div>
          </div>
        <?php else : ?>
        <form method="post" action="<?php echo esc_url( g2a_form_action_url() ); ?>">
          <input type="hidden" name="action" value="g2a_request">
          <input type="hidden" name="g2a_subject" value="Firearm Shipping Quote">
          <?php wp_nonce_field( 'g2a_request', 'g2a_nonce' ); ?>
          <div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true"><label>Leave empty<input type="text" name="g2a_hp" tabindex="-1" autocomplete="off"></label></div>
          <label class="form-label">Item Type</label>
          <select class="field" name="g2a_f_item_type"><option>Handgun</option><option>Long Gun (Rifle/Shotgun)</option><option>Receiver Only</option></select>
          <label class="form-label">Destination State</label>
          <select class="field" name="g2a_f_destination_state"><option>Texas</option><option>California</option><option>Florida</option><option>New York</option><option>Other</option></select>
          <label class="form-label">Service</label>
          <select class="field" id="g2a-ship-service" name="g2a_f_service">
            <option data-price="45">Ground (3-5 days) - $45</option>
            <option data-price="95">Express (Next Day) - $95</option>
          </select>
          <div style="background: var(--color-gunmetal); padding: 14px 18px; margin-top: 14px; display:flex; justify-content: space-between; align-items: center;">
            <span style="font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase;">Estimated Total</span>
            <span style="font-family: var(--font-display); font-size: 28px; color: var(--color-brass-bright);" id="g2a-ship-total">$45.00</span>
          </div>
          <input type="hidden" name="g2a_f_estimated_total" id="g2a-ship-total-input" value="$45.00">
          <button type="submit" class="btn btn-brass" style="width: 100%; margin-top: 16px;">Schedule Drop-Off </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<section class="consign" id="consign">
  <div class="c">
    <div class="split">
      <div>
        <span class="ix" style="font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; color: var(--color-brass-bright); text-transform: uppercase;"> Service 04</span>
        <h2 style="margin-top: 8px;">CONSIGNMENT<br><span class="a">SALES</span></h2>
        <p>Bring in any firearm or accessory in working condition. We display, photograph, list across our network, and handle the sale + transfer. You keep 80% of the listed price.</p>
        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
          <a class="btn btn-brass" href="/contact/?service=consign">Request Appraisal</a>
          <a class="btn btn-ghost" href="/shop/">Browse Consignment Inventory</a>
        </div>
      </div>
      <div class="split-rate">
        <div class="row"><span>You Receive</span><span class="v">80%</span></div>
        <div class="row"><span>Our Commission</span><span class="v">20%</span></div>
        <div class="row"><span>Listing Fee</span><span class="v">$0</span></div>
        <div class="row"><span>Display Period</span><span class="v">90 Days</span></div>
        <div class="row"><span>Avg Sale Time</span><span class="v">21 Days</span></div>
        <div style="font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--color-hairline);"> No fee if item doesn't sell</div>
      </div>
    </div>
  </div>
</section>

<section class="comply">
  <div class="c">
    <h2>FEDERALLY LICENSED. ATF COMPLIANT.</h2>
    <p>Every firearm transaction logged. Every NFA item handled by certified SOT staff. Every shipment carrier-compliant. We've been audited four times since 2018 with zero violations.</p>
    <div class="creds"><span>FFL Type 01</span><span>SOT Class 03</span><span>ATF Compliant</span><span>NRA Business Alliance</span><span>NSSF Member</span></div>
  </div>
</section>
<?php get_footer();
