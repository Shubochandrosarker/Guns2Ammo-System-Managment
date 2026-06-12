<?php
/**
 * Template Name: Transfers
 *
 * Source: design/transfers.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>.hero { padding: 140px 32px 64px; min-height:60vh; display:flex; align-items:center; }
.hero .c { max-width:1280px; margin:0 auto; display:grid; grid-template-columns: 1.1fr 1fr; gap: 56px; align-items:center; }
@media(max-width:980px){ .hero .c { grid-template-columns:1fr; } }
.hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 128px); line-height: 0.92; }
.hero h1 .a { color: var(--color-brass-bright); }
.hero p { color: var(--color-fog); max-width: 56ch; margin: 22px 0 28px; font-size:17px; }
.fee-card { background: var(--color-gunmetal); border:1px solid var(--color-brass-dim); padding: 36px; }
.fee-card .price { font-family: var(--font-display); font-size: 96px; color: var(--color-brass-bright); line-height: 0.9; }
.fee-card .price small { font-family: var(--font-mono); font-size:14px; letter-spacing:0.2em; color: var(--color-silver); display:block; margin-top:8px; }
.fee-card .feat { padding: 8px 0; border-bottom:1px solid var(--color-hairline); display:flex; gap:14px; font-family: var(--font-condensed); font-weight:600; letter-spacing:0.04em; text-transform: uppercase; color: var(--color-fog); font-size:14px; }
.fee-card .feat::before { content:""; color: var(--color-brass-bright); }
.fee-card .feat:last-child { border:0; }
.fee-card .small { font-family: var(--font-mono); font-size:11px; letter-spacing:0.18em; color: var(--color-silver); text-transform: uppercase; margin-top:18px; }

.steps { padding: 100px 32px; background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.steps .c { max-width:1280px; margin:0 auto; }
.steps h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 80px); line-height:0.95; margin: 18px 0 56px; }
.step-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap: 16px; }
@media(max-width:980px){ .step-grid { grid-template-columns: 1fr 1fr; } }
@media(max-width:540px){ .step-grid { grid-template-columns: 1fr; } }
.step { padding: 28px; background: var(--color-void); border:1px solid var(--color-hairline); position:relative; }
.step .num { font-family: var(--font-display); font-size: 56px; color: var(--color-brass-bright); line-height:1; }
.step h4 { font-family: var(--font-condensed); font-weight:600; font-size:18px; letter-spacing:0.06em; text-transform: uppercase; color: var(--color-white); margin: 14px 0 8px; }
.step p { font-size:14px; color: var(--color-fog); margin:0; line-height:1.65; }
.step .meta { font-family: var(--font-mono); font-size:10px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; margin-top: 14px; padding-top: 14px; border-top:1px solid var(--color-hairline); }

.fees { padding: 100px 32px; }
.fees .c { max-width: 1080px; margin: 0 auto; }
.fee-table { border:1px solid var(--color-hairline); }
.fee-table .row { display:grid; grid-template-columns: 1.4fr 1fr 1fr; padding: 18px 24px; border-bottom:1px solid var(--color-hairline); font-family: var(--font-condensed); letter-spacing:0.04em; font-size:15px; }
.fee-table .row:last-child { border:0; }
.fee-table .row.head { background: var(--color-gunmetal); font-family: var(--font-mono); font-size:11px; letter-spacing:0.22em; color: var(--color-silver); text-transform: uppercase; }
.fee-table .row .v { font-family: var(--font-display); font-size: 24px; color: var(--color-brass-bright); }
.fee-table .row .nm { color: var(--color-white); font-weight:600; text-transform: uppercase; }
@media(max-width:600px){ .fee-table .row { grid-template-columns: 1fr 1fr; font-size:14px; } .fee-table .row .desc { display:none; } }

.dealer { padding: 100px 32px; background: var(--color-void); border-top:1px solid var(--color-hairline); }
.dealer .c { max-width: 1080px; margin: 0 auto; display:grid; grid-template-columns: 1fr 1fr; gap: 48px; }
@media(max-width:780px){ .dealer .c { grid-template-columns: 1fr; } }
.dealer .info-card { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding: 32px; }
.dealer .info-card .label { font-family: var(--font-mono); font-size:10px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase; margin-bottom:8px; }
.dealer .info-card .val { font-family: var(--font-display); font-size: 32px; color: var(--color-white); margin-bottom: 16px; line-height:1; }
.dealer .info-card .val.brass { color: var(--color-brass-bright); }

.faq { padding: 100px 32px; }
.faq .c { max-width: 880px; margin:0 auto; }
.faq h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); margin-bottom: 32px; }
.q { border-bottom:1px solid var(--color-hairline); padding: 18px 0; }
.q summary { list-style: none; cursor:pointer; display:flex; justify-content:space-between; gap:24px; align-items:center; font-family: var(--font-condensed); font-weight:600; font-size:18px; letter-spacing:0.04em; color: var(--color-white); text-transform: uppercase; }
.q summary::-webkit-details-marker { display:none; }
.q summary .ex { width:24px; height:24px; border:1px solid var(--color-brass); color: var(--color-brass-bright); display:grid; place-items:center; transition: transform 240ms var(--ease-out); flex-shrink:0; }
.q[open] summary .ex { transform: rotate(45deg); }
.q .a { padding: 14px 0 4px; color: var(--color-fog); font-size:15px; line-height:1.65; }</style>
<header class="hero">
  <div class="c">
    <div>
      <span class="eyebrow"> FFL Transfer Services</span>
      <h1 style="margin-top:18px;">SHIP IT.<br>WE'LL <span class="a">HANDLE</span><br>THE REST.</h1>
      <p>Found a deal online? Inheriting a firearm from family? Buying private-party? We're a fully licensed FFL in Mesa. Have it shipped here, complete the background check on-site, walk out with your firearm  typically same day.</p>
      <div style="display:flex; gap:14px; flex-wrap:wrap;">
        <a class="btn btn-ember btn-lg" href="<?php echo esc_url( home_url( '/transfer-request/' ) ); ?>">Start A Transfer </a>
        <a class="btn btn-brass btn-lg" href="#how">See How It Works</a>
      </div>
    </div>
    <div class="fee-card">
      <div class="label" style="font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase;">Standard Transfer Fee</div>
      <div class="price">$35<small>FLAT  ALL FIREARMS</small></div>
      <div style="margin-top:24px;">
        <div class="feat">Background check on-site</div>
        <div class="feat">No appointment required</div>
        <div class="feat">FedEx, UPS, USPS accepted</div>
        <div class="feat">Pickup typically same day</div>
        <div class="feat">No outbound transfer fee for purchases here</div>
      </div>
      <?php $g2a_ffl = get_theme_mod( 'g2a_ffl_license', '' ); if ( $g2a_ffl ) : ?>
      <div class="small">FFL #: <?php echo esc_html( $g2a_ffl ); ?></div>
      <?php endif; ?>
    </div>
  </div>
</header>

<section class="steps" id="how">
  <div class="c">
    <span class="eyebrow"> The Process</span>
    <h2>FOUR STEPS,<br>UNDER A WEEK.</h2>
    <div class="step-grid">
      <div class="step">
        <div class="num">01</div>
        <h4>Send Us Your Info</h4>
        <p>Submit our online form or stop in. We'll send the seller our FFL license and shipping address.</p>
        <div class="meta">~ 2 minutes</div>
      </div>
      <div class="step">
        <div class="num">02</div>
        <h4>Seller Ships To Us</h4>
        <p>The seller ships your firearm directly to our Mesa location. We'll text you the moment it arrives.</p>
        <div class="meta">~ 3-5 business days</div>
      </div>
      <div class="step">
        <div class="num">03</div>
        <h4>Background Check</h4>
        <p>Come in at your convenience. Bring valid AZ ID. We run NICS on-site  typically clears in minutes.</p>
        <div class="meta">~ 15 minutes on-site</div>
      </div>
      <div class="step">
        <div class="num">04</div>
        <h4>Walk Out</h4>
        <p>Pay your $35 fee, sign your 4473, and the firearm is yours. Optional: try it at our range.</p>
        <div class="meta">Same visit</div>
      </div>
    </div>
  </div>
</section>

<section class="fees" id="fees">
  <div class="c">
    <span class="eyebrow"> Transparent Pricing</span>
    <h2 style="font-family: var(--font-display); font-size: clamp(40px, 6vw, 72px); margin: 18px 0 32px; line-height:0.95;">FEES.</h2>
    <div class="fee-table">
      <div class="row head">
        <span>Service</span>
        <span class="desc">Notes</span>
        <span style="text-align:right;">Fee</span>
      </div>
      <div class="row">
        <span class="nm">Standard FFL Transfer</span>
        <span class="desc" style="color: var(--color-silver);">Single firearm, in-state buyer</span>
        <span class="v" style="text-align:right;">$35</span>
      </div>
      <div class="row">
        <span class="nm">Additional Firearm</span>
        <span class="desc" style="color: var(--color-silver);">Same shipment, same buyer</span>
        <span class="v" style="text-align:right;">$15</span>
      </div>
      <div class="row">
        <span class="nm">NFA / Class III Transfer</span>
        <span class="desc" style="color: var(--color-silver);">SBR, suppressor, full-auto</span>
        <span class="v" style="text-align:right;">$100</span>
      </div>
      <div class="row">
        <span class="nm">Private-Party Transfer</span>
        <span class="desc" style="color: var(--color-silver);">Both parties present, AZ residents</span>
        <span class="v" style="text-align:right;">$25</span>
      </div>
      <div class="row">
        <span class="nm">Storage After 30 Days</span>
        <span class="desc" style="color: var(--color-silver);">Per firearm, per month</span>
        <span class="v" style="text-align:right;">$10</span>
      </div>
      <div class="row">
        <span class="nm">Background Check Only</span>
        <span class="desc" style="color: var(--color-silver);">Required regardless of transfer type</span>
        <span class="v" style="text-align:right;">Included</span>
      </div>
    </div>
    <p style="margin-top:24px; font-family: var(--font-mono); font-size:11px; letter-spacing:0.18em; color: var(--color-silver); text-transform: uppercase;"> All fees subject to AZ state and federal regulation. We reserve the right to refuse any transfer at our discretion.</p>
  </div>
</section>

<section class="fees" style="padding-top:0;">
  <div class="c">
    <span class="eyebrow"> Good To Know</span>
    <h2 style="font-family: var(--font-display); font-size: clamp(34px,5vw,56px); margin: 14px 0 20px; line-height:1;">WHAT YOU CAN TRANSFER</h2>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.8; max-width:68ch;">As a fully licensed Arizona FFL, Guns 2 Ammo can receive nearly any lawful firearm purchase on your behalf  handguns, rifles, shotguns, and NFA items such as suppressors and short-barreled rifles. A few federal rules shape every transfer:</p>
    <ul class="bullets" style="margin-top:18px; max-width:68ch;">
      <li><strong style="color:var(--color-white);">Handguns</strong> can only be transferred to residents of the state where the receiving FFL is located  so handgun transfers here are for Arizona residents.</li>
      <li><strong style="color:var(--color-white);">Long guns</strong> (rifles and shotguns) may be transferred to most out-of-state residents, provided the sale is legal in both states.</li>
      <li><strong style="color:var(--color-white);">NFA items</strong> require an ATF tax stamp and additional paperwork  see our <a href="<?php echo esc_url( home_url( '/ffl-services/' ) ); ?>" style="color:var(--color-brass-bright);">FFL &amp; NFA services</a> page for the full process.</li>
      <li>Every transfer includes a federal NICS <a href="<?php echo esc_url( home_url( '/ffl-services/' ) ); ?>" style="color:var(--color-brass-bright);">background check</a> and a completed ATF Form 4473.</li>
    </ul>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.8; max-width:68ch; margin-top:18px;">Prefer to buy local and skip shipping altogether? Browse our <a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>" style="color:var(--color-brass-bright);">in-store inventory</a> and <a href="<?php echo esc_url( home_url( '/collections/' ) ); ?>" style="color:var(--color-brass-bright);">firearm collections</a>  same-day pickup, no transfer fee on purchases made here.</p>
  </div>
</section>

<section class="dealer">
  <div class="c">
    <div>
      <span class="eyebrow"> For Online Sellers</span>
      <h2 style="font-family: var(--font-display); font-size: clamp(36px, 5vw, 56px); line-height:0.95; margin: 14px 0 14px;">SHIP TO US.</h2>
      <p style="color: var(--color-fog); max-width: 48ch;">Whether you're sending from GunBroker, Palmetto State Armory, Bud's, Sportsman's, or a private seller  here's everything they need.</p>
    </div>
    <div class="info-card">
      <div class="label">SHIP FIREARMS TO</div>
      <div class="val">Guns 2 Ammo</div>
      <div class="label">ATTENTION</div>
      <div class="val">FFL Receiving</div>
      <div class="label">ADDRESS</div>
      <div class="val brass" style="font-size:22px;">6030 E Main St<br>Mesa, AZ 85205</div>
      <?php $g2a_ffl = get_theme_mod( 'g2a_ffl_license', '' ); if ( $g2a_ffl ) : ?>
      <div class="label">FFL LICENSE NUMBER</div>
      <div class="val brass" style="font-family: var(--font-mono); font-size:18px; letter-spacing:0.1em;"><?php echo esc_html( $g2a_ffl ); ?></div>
      <?php endif; ?>
      <a class="btn btn-brass" style="margin-top:10px; width:100%;" href="<?php echo esc_url( home_url( '/transfer-request/' ) ); ?>">Start Your Transfer Request</a>
    </div>
  </div>
</section>

<section class="faq">
  <div class="c">
    <span class="eyebrow"> FAQ</span>
    <h2 style="margin-top:18px;">QUESTIONS.</h2>

    <details class="q"><summary>How long does a transfer take?<span class="ex">+</span></summary><div class="a">From when the seller ships, typically 3–5 business days for the firearm to arrive. Once it's here, the on-site portion takes about 15 minutes  most customers walk out the same visit.</div></details>
    <details class="q"><summary>What if my background check is delayed?<span class="ex">+</span></summary><div class="a">By federal law, we can transfer the firearm after 3 business days even without a NICS response  though we recommend waiting for a clear "proceed" before taking possession.</div></details>
    <details class="q"><summary>Do I need an appointment?<span class="ex">+</span></summary><div class="a">No. Walk in any time during business hours. We'll text you when your firearm arrives so you can plan.</div></details>
    <details class="q"><summary>Can I ship from any seller?<span class="ex">+</span></summary><div class="a">Almost always  we accept from major retailers, GunBroker, and private sellers nationwide. We do reserve the right to refuse a transfer at our discretion (e.g., suspect chain of custody).</div></details>
    <details class="q"><summary>Do you do NFA transfers?<span class="ex">+</span></summary><div class="a">Yes  suppressors, SBRs, full-auto. NFA transfers run $95 for suppressors/SBRs and $295 for full-auto and require additional ATF paperwork. Plan on 6–9 months for the ATF to approve your tax stamp.</div></details>
    <details class="q"><summary>What if I'm not from Arizona?<span class="ex">+</span></summary><div class="a">By federal law, handguns can only be transferred to residents of the state where the FFL is located. Long guns can transfer to most out-of-state residents  call us to confirm based on your home state.</div></details>
  </div>
</section>

<section class="section" style="padding: 100px 32px; background: var(--color-gunmetal); text-align:center;">
  <span class="eyebrow" style="justify-content:center;"> Ready</span>
  <h2 style="font-family: var(--font-display); font-size:clamp(48px,7vw,96px); margin: 18px 0;">START A TRANSFER.</h2>
  <p style="color: var(--color-fog); max-width: 500px; margin: 0 auto 32px;">Submit our quick form and we'll email the seller everything they need.</p>
  <div style="display:inline-flex; gap:14px; flex-wrap:wrap;">
    <a class="btn btn-ember btn-lg">Start A Transfer </a>
    <a class="btn btn-brass btn-lg" href="tel:+16027152677">Call (602) 715-2677</a>
  </div>
</section>
<?php get_footer();
