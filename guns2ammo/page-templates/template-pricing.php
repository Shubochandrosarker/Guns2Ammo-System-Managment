<?php
/**
 * Template Name: Pricing
 *
 * Source: design/pricing.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$g2a_page_id                    = get_the_ID();
$g2a_pricing_lane_fee_text      = get_post_meta( $g2a_page_id, 'pricing_walkin_lane_fee', true );
$g2a_pricing_additional_text    = get_post_meta( $g2a_page_id, 'pricing_additional_shooter', true );
$g2a_pricing_unlimited_text     = get_post_meta( $g2a_page_id, 'pricing_unlimited_text', true );
?>
<style>.ph-hero { padding: 140px 32px 60px; position:relative; overflow:hidden; background: var(--color-void); }
  .ph-hero .photo { position:absolute; inset:0; background-image: linear-gradient(180deg, rgba(26,25,30,0.7), rgba(26,25,30,0.95)), url("https://guns2ammo.com/wp-content/uploads/2026/01/ChatGPT-Image-Jan-30-2026-10_10_12-PM.webp"); background-size: cover; background-position: center; }
  .ph-hero .inner { max-width: 1100px; margin:0 auto; position:relative; text-align:center; }
  .ph-hero h1 { font-family: var(--font-display); font-size: clamp(48px, 8vw, 96px); color: var(--color-white); letter-spacing: 0.02em; line-height: 0.92; }
  .ph-hero h1 em { font-style: normal; color: var(--color-brass-bright); }
  .ph-hero .sub { color: var(--color-fog); font-size: 18px; margin: 20px auto 28px; max-width: 60ch; }
  .ph-hero .micro { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; color: var(--color-silver); text-transform:uppercase; }

  .toggle-row { display:flex; justify-content:center; align-items:center; gap:18px; flex-wrap:wrap; padding: 60px 24px 20px; }
  .plans-wrap { max-width: 1280px; margin: 0 auto; padding: 32px; display:grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
  @media (max-width: 980px) { .plans-wrap { grid-template-columns: 1fr; max-width: 480px; } .plan.popular { transform: none; } }
  .trust-row { max-width: 1100px; margin: 32px auto 0; padding: 32px; display:grid; grid-template-columns: repeat(4,1fr); gap: 18px; }
  @media (max-width: 760px) { .trust-row { grid-template-columns: repeat(2,1fr); } }
  .trust-row .ti { display:flex; align-items:center; gap:12px; padding: 16px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
  .trust-row .ti svg { color: var(--color-brass-bright); flex:0 0 24px; }
  .trust-row .ti .t { font-family: var(--font-condensed); font-weight:600; font-size:14px; color: var(--color-white); text-transform:uppercase; letter-spacing:0.04em; line-height: 1.1; }
  .trust-row .ti .s { font-family: var(--font-mono); font-size:10px; letter-spacing:0.18em; color: var(--color-silver); text-transform:uppercase; }

  .ladies-card { max-width: 1100px; margin: 64px auto; padding: 32px; display:grid; grid-template-columns: 1fr 1fr; gap: 0; background: linear-gradient(135deg, rgba(201,168,76,0.08), rgba(201,168,76,0.02)); border-left: 3px solid var(--color-brass); }
  @media (max-width: 760px) { .ladies-card { grid-template-columns: 1fr; padding: 24px; } }
  .ladies-card .photo { background-image: url("https://guns2ammo.com/wp-content/uploads/2024/08/one-1-a.webp"); background-size: cover; background-position: center; min-height: 240px; filter: saturate(0.9); }
  .ladies-card .inner { padding: 32px; }
  .ladies-card h3 { font-family: var(--font-display); font-size: 56px; color: var(--color-white); line-height: 0.95; }
  .ladies-card .pill { display:inline-block; background: rgba(201,168,76,0.18); color: var(--color-brass-bright); padding: 4px 12px; font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; text-transform:uppercase; margin-bottom: 14px; }

  .acc-mini { max-width: 800px; margin: 32px auto 64px; padding: 0 32px; }

  h2.sec { font-family: var(--font-display); font-size: clamp(40px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.02em; line-height: 1; }
  .sec-wrap { max-width: 1280px; margin: 0 auto; padding: 32px; }</style>
<section class="ph-hero">
  <div class="photo"></div>
  <div class="inner">
    <div class="eyebrow fade-up" style="margin-bottom:20px;">Membership  Range Fees</div>
    <h1 class="fade-up d1">RANGE TIME WITHOUT THE <em>HASSLE</em></h1>
    <p class="sub fade-up d2"><?php echo esc_html( $g2a_pricing_unlimited_text ? $g2a_pricing_unlimited_text : 'Unlimited lane access available with select plans. Members-only pricing. No contracts. Train when you want, with the people you trust.' ); ?></p>
    <div class="micro fade-up d3">Cancel Anytime  No Lock-In  Save Up To $69.89 With Annual Billing</div>
  </div>
</section>

<div class="toggle-row fade-up">
  <span style="font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-silver); text-transform: uppercase;">Billing</span>
  <div class="bill-toggle" id="bill-toggle">
    <input type="radio" name="bill" id="bill-m" value="monthly" checked>
    <label for="bill-m">Monthly</label>
    <input type="radio" name="bill" id="bill-a" value="annual">
    <label for="bill-a">Annual</label>
    <div class="pill"></div>
  </div>
  <span class="save-pill" id="annual-save" style="opacity:0;">SAVE UP TO $69.89/YR</span>
</div>

<div class="plans-wrap">
  <article class="plan fade-up d1" data-plan="defender">
    <div class="name">DEFENDER</div>
    <div class="tag">Individual  1 Person</div>
    <div class="price"><span class="num" data-monthly="29.99" data-annual="299.99">$29.99</span><span class="per">/month</span></div>
    <div class="pays">For the solo shooter who wants the best lane price in Mesa  Annual saves $59.89/year</div>
    <ul class="feats">
      <li><strong>1 free hour of lane time</strong> per visit</li>
      <li>Bring friends: <strong>$15 / extra shooter / hour</strong></li>
      <li>Unlimited range time during open hours</li>
      <li>Online lane reservations</li>
      <li>Member profile + digital membership card</li>
      <li>Per-person waiver + check-in tracking</li>
      <li>Walk-in lane fee for non-members: <strong>$20/hr</strong></li>
    </ul>
    <a class="btn btn-brass" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=defender' ) ); ?>">Select Defender</a>
  </article>

  <article class="plan popular fade-up d2" data-plan="patriot">
    <div class="pop-banner"> Most Popular</div>
    <div class="name">PATRIOT</div>
    <div class="tag">Two-Person  2 People</div>
    <div class="price"><span class="num" data-monthly="39.99" data-annual="449.99">$39.99</span><span class="per">/month</span></div>
    <div class="pays">For couples, range buddies, and two-person teams  Annual saves $29.89/year</div>
    <ul class="feats">
      <li>Covers 2 people  primary + 1 linked profile</li>
      <li><strong>1 free hour of lane time</strong> for the primary member</li>
      <li>Bring friends: <strong>$10 / extra shooter / hour</strong></li>
      <li>Unlimited range time during open hours</li>
      <li>Online lane reservations</li>
      <li>Per-person waiver + check-in tracking</li>
      <li>Each member gets their own digital card</li>
    </ul>
    <a class="btn btn-ember" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=patriot' ) ); ?>">Select Patriot</a>
  </article>

  <article class="plan fade-up d3" data-plan="guardian">
    <div class="name">GUARDIAN</div>
    <div class="tag">Family / Group  4 People</div>
    <div class="price"><span class="num" data-monthly="59.99" data-annual="649.99">$59.99</span><span class="per">/month</span></div>
    <div class="pays">For families and groups of up to 4 shooters  Annual saves $69.89/year</div>
    <ul class="feats">
      <li>Covers 4 people  primary + 3 linked profiles</li>
      <li><strong>1 free hour of lane time</strong> for the primary member</li>
      <li>Bring friends: <strong>$10 / extra shooter / hour</strong></li>
      <li>Unlimited range time during open hours</li>
      <li>Online lane reservations</li>
      <li>Per-person waiver + check-in tracking</li>
      <li>Each member gets their own digital card</li>
    </ul>
    <a class="btn btn-brass" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=guardian' ) ); ?>">Select Guardian</a>
  </article>
</div>

<!-- Per-tier guest pricing matrix: makes the per-extra-shooter rule crystal clear -->
<section class="lane-matrix" style="max-width:1100px; margin: 40px auto 0; padding: 0 32px;">
  <div class="eyebrow" style="text-align:center; margin-bottom:14px;">Lane Pricing  Member vs. Guest</div>
  <h2 class="sec" style="text-align:center; margin-bottom: 28px;">How lane pricing works</h2>
  <div style="overflow-x:auto;">
    <table style="width:100%; border-collapse: collapse; font-size: 15px; background:#1A1F26; border:1px solid var(--color-hairline);">
      <thead>
        <tr style="background:#11161D; color: var(--color-white); text-transform: uppercase; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em;">
          <th style="padding:14px 16px; text-align:left; border-bottom:1px solid var(--color-hairline);">Plan</th>
          <th style="padding:14px 16px; text-align:left; border-bottom:1px solid var(--color-hairline);">Primary Member</th>
          <th style="padding:14px 16px; text-align:left; border-bottom:1px solid var(--color-hairline);">Per Extra Shooter</th>
          <th style="padding:14px 16px; text-align:left; border-bottom:1px solid var(--color-hairline);">Example: 3 people, 1 hr</th>
        </tr>
      </thead>
      <tbody style="color: var(--color-fog);">
        <tr>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:var(--color-white);">Walk-in</strong> (no membership)</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$20/hr</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$20/hr each</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$60</td>
        </tr>
        <tr>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:var(--color-white);">Defender</strong></td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline); color:#9DE05B;">1 hr FREE</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$15/hr each</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:#9DE05B;">$30</strong> (you free + 2  $15)</td>
        </tr>
        <tr>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:var(--color-white);">Patriot</strong></td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline); color:#9DE05B;">1 hr FREE</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$10/hr each</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:#9DE05B;">$20</strong> (you free + 2  $10)</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;"><strong style="color:var(--color-white);">Guardian</strong></td>
          <td style="padding:14px 16px; color:#9DE05B;">1 hr FREE</td>
          <td style="padding:14px 16px;">$10/hr each</td>
          <td style="padding:14px 16px;"><strong style="color:#9DE05B;">$20</strong> (you free + 2  $10)</td>
        </tr>
      </tbody>
    </table>
  </div>
  <p style="text-align:center; color: var(--color-silver); font-size: 13px; margin-top: 14px; font-family: var(--font-mono); letter-spacing: 0.04em;">After the first free hour, additional hours bill at standard rates. Linked profiles on Patriot &amp; Guardian count as primary members  not guests.</p>
</section>

<div class="trust-row">
  <div class="ti"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="11" width="16" height="10" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg><div><div class="t">Cancel Anytime</div><div class="s">No commitment</div></div></div>
  <div class="ti"><svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 L14.5 8.5 L21.5 9 L16 13.5 L17.7 20.5 L12 16.7 L6.3 20.5 L8 13.5 L2.5 9 L9.5 8.5 Z"/></svg><div><div class="t">4.7 Stars</div><div class="s">449+ reviews</div></div></div>
  <div class="ti"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M3 12 L9 18 L21 6"/></svg><div><div class="t">No Lock-In</div><div class="s">Stop or pause</div></div></div>
  <div class="ti"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="16" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="8" y1="3" x2="8" y2="7"/><line x1="16" y1="3" x2="16" y2="7"/></svg><div><div class="t">Book Online</div><div class="s">24/7 reservations</div></div></div>
</div>

<section class="section">
  <div class="sec-wrap">
    <div class="eyebrow" style="margin-bottom: 16px;">Walk-In Range Fees</div>
    <h2 class="sec">NON-MEMBER PRICING</h2>
    <p style="color: var(--color-fog); margin: 16px 0 32px; max-width: 60ch;">Members save up to 40% on these rates. Walk-ins always welcome  no appointment required for solo lane rental.</p>
    <table class="dtable">
      <thead><tr><th>Service</th><th>Walk-In</th><th>Member</th><th>Savings</th></tr></thead>
      <tbody>
        <tr><td>Lane Rental  1 hour</td><td><?php echo esc_html( $g2a_pricing_lane_fee_text ? $g2a_pricing_lane_fee_text : '$20.00' ); ?></td><td>Included</td><td>100%</td></tr>
        <tr><td>Lane Rental  Full day</td><td>$60.00</td><td>Included</td><td>100%</td></tr>
        <tr><td>Additional Shooter  per lane</td><td><?php echo esc_html( $g2a_pricing_additional_text ? $g2a_pricing_additional_text : '$15.00' ); ?></td><td>$10.00 / $15.00</td><td></td></tr>
        <tr><td>Handgun Rental  per gun</td><td>$15.00</td><td>$11.25</td><td>25%</td></tr>
        <tr><td>Long Gun Rental  per gun</td><td>$25.00</td><td>$18.75</td><td>25%</td></tr>
        <tr><td>Eye + Ear Protection</td><td>$3.00</td><td>Included</td><td>100%</td></tr>
        <tr><td>Targets  per pack</td><td>$2.00</td><td>$2.00</td><td></td></tr>
        <tr><td>Range Ammunition  9mm box</td><td>$22.00</td><td>$19.80</td><td>10%</td></tr>
      </tbody>
    </table>
    <div class="form-help" style="margin-top: 14px;">All fees subject to 8.05% Mesa sales tax  Range ammunition policy: factory new only  No reloads or steel core</div>
  </div>
</section>

<section class="section" style="background: var(--color-gunmetal);">
  <div class="sec-wrap">
    <div class="ladies-card" style="max-width: none; margin: 0;">
      <div class="photo"></div>
      <div class="inner">
        <span class="pill">Tuesdays  No membership required</span>
        <h3>LADIES TUESDAY</h3>
        <p style="color: var(--color-fog); margin: 14px 0 22px; font-size: 16px;">Every Tuesday, female shooters get one hour of free lane time  solo or with a guest. Walk in. We'll set you up. Eye and ear protection included, plus 25% off any rental.</p>
        <div style="display:flex; gap: 12px; flex-wrap:wrap;">
          <a class="btn btn-brass" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Tuesday </a>
          <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#handgun">New Shooter Class</a>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="sec-wrap" style="max-width: 800px;">
    <div class="eyebrow" style="margin-bottom: 16px;">Common Questions</div>
    <h2 class="sec" style="margin-bottom: 32px;">MEMBERSHIP FAQ</h2>
    <div class="acc">
      <details open><summary>Can I cancel anytime?</summary><div class="answer">Yes. Cancel from your member dashboard or email us  no fees, no questions, no contracts. Your access remains active until the end of your current billing period.</div></details>
      <details><summary>How much does annual billing save?</summary><div class="answer">Annual billing is one upfront charge per year instead of twelve monthly payments. Defender is $299.99/year (saves $59.89), Patriot is $449.99/year (saves $29.89), and Guardian is $649.99/year (saves $69.89) versus paying month to month.</div></details>
      <details><summary>What does "linked profiles" mean for Patriot and Guardian?</summary><div class="answer">Patriot includes a primary member plus one linked profile (2 people total). Guardian includes a primary member plus three linked profiles (4 people total)  built for families or groups with separate people records. Each person gets their own waiver and check-in tracking. Add or swap linked members anytime from your dashboard.</div></details>
      <details><summary>Are there extra fees beyond the membership?</summary><div class="answer">No range fees, no equipment fees, no reservation fees. Members buy ammunition (or bring their own factory-new) and pay for rentals at the discounted rate. Targets are included.</div></details>
      <details><summary>Do you offer law enforcement, military, or veteran discounts?</summary><div class="answer">Yes  15% off any plan with a valid LEO, active military, or veteran ID, on top of any other promotion. Apply during checkout or at the front desk.</div></details>
    </div>
  </div>
</section>


<script>
  // Billing toggle: monthly/annual price flip
  const radios = document.querySelectorAll('#bill-toggle input');
  const annualBadge = document.getElementById('annual-save');
  function updatePrices() {
    const annual = document.getElementById('bill-a').checked;
    annualBadge.style.opacity = annual ? '1' : '0';
    annualBadge.style.transition = 'opacity 240ms';
    document.querySelectorAll('.plan .price').forEach(p => {
      const num = p.querySelector('.num');
      const target = annual ? num.dataset.annual : num.dataset.monthly;
      p.classList.add('flip');
      setTimeout(() => { num.textContent = '$' + target; p.classList.remove('flip'); }, 220);
    });
    document.querySelectorAll('.plan .per').forEach(per => { per.textContent = annual ? '/year' : '/month'; });
  }
  radios.forEach(r => r.addEventListener('change', updatePrices));
</script>
<?php get_footer();
