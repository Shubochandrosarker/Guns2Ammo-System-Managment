<?php
/**
 * Template Name: Memberships
 *
 * Source: design/memberships.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>.gw-hero { padding: 140px 32px 40px; text-align:center; position:relative; overflow:hidden; }
  .gw-hero::before { content:""; position:absolute; inset:0; pointer-events:none; background-image: linear-gradient(180deg, var(--hero-scrim), var(--color-void)), url("<?php echo esc_url( g2a_asset( 'img/guns2ammo-store-overview.jpg' ) ); ?>"); background-size:cover; background-position:center; }
  .gw-hero > * { position:relative; }
  .gw-hero h1 { font-family: var(--font-display); font-size: clamp(48px, 7vw, 80px); color: var(--ink-on-media); letter-spacing: 0.02em; line-height: 0.95; max-width: 14ch; margin: 0 auto; }
  .gw-hero .sub { color: var(--ink-on-media-dim); margin: 18px auto; max-width: 50ch; }
  .signin-row { display:flex; justify-content: flex-end; align-items: center; gap:14px; max-width: 1280px; margin: 0 auto; padding: 24px 32px 0; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; }
  .signin-row a { color: var(--color-brass-bright); text-decoration: none; }
  .signin-row a:hover { color: var(--color-white); }
  .toggle-row { display:flex; justify-content:center; align-items:center; gap:18px; flex-wrap:wrap; padding: 32px 24px 20px; }
  .plans-wrap { max-width: 1280px; margin: 0 auto; padding: 32px; display:grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
  @media (max-width: 980px) { .plans-wrap { grid-template-columns: 1fr; max-width: 480px; } .plan.popular { transform: none; } }
  .trust-strip { display:flex; justify-content:center; gap: 32px; flex-wrap: wrap; padding: 32px; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; border-top: 1px solid var(--color-hairline); margin-top: 48px; }
  .trust-strip span { display:inline-flex; align-items:center; gap: 8px; }
  .trust-strip svg { color: var(--color-brass-bright); }

  /* Logged-in state */
  .lin { max-width: 1100px; margin: 24px auto 0; padding: 0 32px; display:none; }
  .lin.show { display:block; }
  .lin-card { background: linear-gradient(135deg, rgba(201,168,76,0.12), rgba(201,168,76,0.02)); border:1px solid var(--color-brass-dim); padding: 24px 28px; display:grid; grid-template-columns: 1fr auto; gap: 24px; align-items:center; }
  @media (max-width: 760px) { .lin-card { grid-template-columns: 1fr; } }
  .lin-card .who { font-family: var(--font-condensed); font-weight: 600; font-size: 22px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .lin-card .meta { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; margin-top: 4px; }
  .lin-card .actions { display:flex; gap: 10px; flex-wrap:wrap; }

  .toggle-state { padding: 0 32px; max-width: 1100px; margin: 24px auto 0; }
  .toggle-state .pill-set { display:inline-flex; gap: 0; border:1px solid var(--color-hairline-bright); padding: 4px; }
  .toggle-state .pill-set button { background: transparent; border: none; color: var(--color-fog); font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; padding: 8px 14px; cursor: pointer; text-transform: uppercase; }
  .toggle-state .pill-set button.on { background: var(--color-brass); color: #111; }

  .acc-mini { max-width: 800px; margin: 64px auto; padding: 0 32px; }
  h2.sec { font-family: var(--font-display); font-size: clamp(40px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.02em; line-height: 1; }</style>
<div class="signin-row">
  <span>Already a member?</span>
  <a href="<?php echo esc_url( home_url( "/login/" ) ); ?>">Sign In </a>
</div>

<section class="gw-hero hero-media">
  <div class="eyebrow fade-up" style="margin-bottom: 20px;">Step 01  Choose Your Plan</div>
  <h1 class="fade-up d1">JOIN THE RANGE</h1>
  <p class="sub fade-up d2">Pick a plan, create your account, and shoot today. No contracts. No drama.</p>
</section>

<?php if ( current_user_can( 'manage_options' ) ) : // Design-preview widget — admins only. Was public and exposed a fake "Visa 4242" member card to every visitor (E-E-A-T/trust bug). ?>
<div class="toggle-state">
  <div class="pill-set">
    <button class="on" onclick="toggleState(false)">Visitor View</button>
    <button onclick="toggleState(true)">Logged-In Preview</button>
  </div>
</div>

<div class="lin" id="lin-block">
  <div class="lin-card">
    <div>
      <div class="badge active no-dot" style="margin-bottom: 10px;"><span class="dot-live"></span> &nbsp;Active Member</div>
      <div class="who">You're on the PATRIOT plan</div>
      <div class="meta">Renews May 15, 2026  Visa  4242  2 people</div>
    </div>
    <div class="actions">
      <a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( "/account/" ) ); ?>">Manage Plan</a>
      <a class="btn btn-ember btn-sm" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=guardian&upgrade=1' ) ); ?>">Upgrade To Guardian</a>
    </div>
  </div>
</div>
<?php endif; ?>

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

<?php if ( function_exists( 'g2a_has_memberistic' ) && g2a_has_memberistic() ) : ?>
<div class="plans-wrap" style="display:block;max-width:1280px;"><?php echo g2a_plugin_section( 'memberistic_plans' ); ?></div>
<?php else : ?>
<div class="plans-wrap">
  <article class="plan fade-up d1">
    <div class="name">DEFENDER</div>
    <div class="tag">Individual  1 Person</div>
    <div class="price"><span class="num" data-monthly="29.99" data-annual="299.99">$29.99</span><span class="per">/month</span></div>
    <div class="pays">For the solo shooter who wants the best lane price in Mesa</div>
    <ul class="feats">
      <li><strong>1 free hour of lane time</strong> per visit</li>
      <li>Bring friends: <strong>$15 / extra shooter / hour</strong></li>
      <li>Unlimited range time during open hours</li>
      <li>Online lane reservations</li>
      <li>Member profile + digital membership card</li>
      <li>Per-person waiver + check-in tracking</li>
      <li>Walk-in lane fee for non-members: <strong>$20/hr</strong></li>
    </ul>
    <a class="btn btn-brass" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=defender' ) ); ?>">Select Monthly Plan</a>
  </article>

  <article class="plan popular fade-up d2">
    <div class="pop-banner"> Most Popular</div>
    <div class="name">PATRIOT</div>
    <div class="tag">Two-Person  2 People</div>
    <div class="price"><span class="num" data-monthly="39.99" data-annual="449.99">$39.99</span><span class="per">/month</span></div>
    <div class="pays">For couples, range buddies, and two-person teams</div>
    <ul class="feats">
      <li>Covers 2 people  primary + 1 linked profile</li>
      <li><strong>1 free hour of lane time</strong> for the primary member</li>
      <li>Bring friends: <strong>$10 / extra shooter / hour</strong></li>
      <li>Unlimited range time during open hours</li>
      <li>Online lane reservations</li>
      <li>Per-person waiver + check-in tracking</li>
      <li>Each member gets their own digital card</li>
    </ul>
    <a class="btn btn-ember" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=patriot' ) ); ?>">Select Monthly Plan</a>
  </article>

  <article class="plan fade-up d3">
    <div class="name">GUARDIAN</div>
    <div class="tag">Family / Group  4 People</div>
    <div class="price"><span class="num" data-monthly="59.99" data-annual="649.99">$59.99</span><span class="per">/month</span></div>
    <div class="pays">For families and groups of up to 4 shooters</div>
    <ul class="feats">
      <li>Covers 4 people  primary + 3 linked profiles</li>
      <li><strong>1 free hour of lane time</strong> for the primary member</li>
      <li>Bring friends: <strong>$10 / extra shooter / hour</strong></li>
      <li>Unlimited range time during open hours</li>
      <li>Online lane reservations</li>
      <li>Per-person waiver + check-in tracking</li>
      <li>Each member gets their own digital card</li>
    </ul>
    <a class="btn btn-brass" href="<?php echo esc_url( home_url( '/checkout/?memberistic_plan=guardian' ) ); ?>">Select Monthly Plan</a>
  </article>
</div>

<!-- Per-tier guest pricing matrix — so customers see exactly what they pay for guests at each tier. -->
<section class="lane-matrix" style="max-width:1100px; margin: 56px auto 0; padding: 0 32px;">
  <div class="eyebrow" style="text-align:center; margin-bottom:14px;">Lane Pricing  Member vs. Guest</div>
  <h2 class="sec" style="text-align:center; margin-bottom: 28px;">How lane pricing works</h2>
  <div style="overflow-x:auto;">
    <table style="width:100%; border-collapse: collapse; font-size: 15px; background:var(--color-gunmetal); border:1px solid var(--color-hairline);">
      <thead>
        <tr style="background:var(--color-surface-2); color: var(--color-white); text-transform: uppercase; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em;">
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
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline); color:var(--color-active);">1 hr FREE</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$15/hr each</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:var(--color-active);">$30</strong> (you free + 2  $15)</td>
        </tr>
        <tr>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:var(--color-white);">Patriot</strong></td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline); color:var(--color-active);">1 hr FREE</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);">$10/hr each</td>
          <td style="padding:14px 16px; border-bottom:1px solid var(--color-hairline);"><strong style="color:var(--color-active);">$20</strong> (you free + 2  $10)</td>
        </tr>
        <tr>
          <td style="padding:14px 16px;"><strong style="color:var(--color-white);">Guardian</strong></td>
          <td style="padding:14px 16px; color:var(--color-active);">1 hr FREE</td>
          <td style="padding:14px 16px;">$10/hr each</td>
          <td style="padding:14px 16px;"><strong style="color:var(--color-active);">$20</strong> (you free + 2  $10)</td>
        </tr>
      </tbody>
    </table>
  </div>
  <p style="text-align:center; color: var(--color-silver); font-size: 13px; margin-top: 14px; font-family: var(--font-mono); letter-spacing: 0.04em;">After the first free hour, additional hours bill at standard rates. Linked profiles on Patriot &amp; Guardian count as primary members  not guests.</p>
</section>
<?php endif; ?>

<div class="trust-strip">
  <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="11" width="16" height="10" rx="1"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg> Cancel Anytime</span>
  <span><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2 L14.5 8.5 L21.5 9 L16 13.5 L17.7 20.5 L12 16.7 L6.3 20.5 L8 13.5 L2.5 9 L9.5 8.5 Z"/></svg> 4.7 Stars  449+ Reviews</span>
  <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 12 L9 18 L21 6"/></svg> No Lock-In</span>
  <span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/></svg> Book Online</span>
</div>

<section class="section" style="padding-top: 32px;">
  <div style="max-width: 800px; margin: 0 auto; padding: 0 32px;">
    <div class="eyebrow" style="margin-bottom: 16px;">Common Questions</div>
    <h2 class="sec" style="margin-bottom: 28px;">FAQ</h2>
    <div class="acc">
      <details open><summary>Can I cancel anytime?</summary><div class="answer">Yes. Cancel from your dashboard or email us  no fees, no questions. Access remains active until the end of your billing period.</div></details>
      <details><summary>Annual vs Monthly  which is right?</summary><div class="answer">Monthly is best if you're trying us out. Annual is one upfront charge  Defender $299.99/yr, Patriot $449.99/yr, Guardian $649.99/yr  saving up to $69.89 versus paying month to month.</div></details>
      <details><summary>Can I share my membership with family?</summary><div class="answer">Patriot covers two people  a primary member plus one linked profile. Guardian covers four  a primary member plus three linked profiles. Each person gets their own member profile, waiver, and check-in tracking.</div></details>
      <details><summary>What's included with each plan?</summary><div class="answer">Every plan includes unlimited range time during open hours, online lane reservations, and member profile + waiver tracking. Defender is for one person, Patriot for two, and Guardian for four.</div></details>
      <details><summary>Do you offer veteran or LEO discounts?</summary><div class="answer">Yes  15% off any plan with a valid LEO, military, or veteran ID, stackable with annual savings.</div></details>
    </div>
  </div>
</section>


<script>
  const radios = document.querySelectorAll('#bill-toggle input');
  const annualBadge = document.getElementById('annual-save');
  annualBadge.style.transition = 'opacity 240ms';
  function updatePrices() {
    const annual = document.getElementById('bill-a').checked;
    annualBadge.style.opacity = annual ? '1' : '0';
    document.querySelectorAll('.plan .price').forEach(p => {
      const num = p.querySelector('.num');
      const target = annual ? num.dataset.annual : num.dataset.monthly;
      p.classList.add('flip');
      setTimeout(() => { num.textContent = '$' + target; p.classList.remove('flip'); }, 220);
    });
    document.querySelectorAll('.plan .per').forEach(per => { per.textContent = annual ? '/year' : '/month'; });
    document.querySelectorAll('.plan .btn').forEach(b => { b.textContent = annual ? 'Select Annual Plan' : 'Select Monthly Plan'; });
  }
  radios.forEach(r => r.addEventListener('change', updatePrices));

  function toggleState(loggedIn) {
    document.getElementById('lin-block').classList.toggle('show', loggedIn);
    document.querySelectorAll('.toggle-state .pill-set button').forEach((b,i) => b.classList.toggle('on', i === (loggedIn ? 1 : 0)));
  }
</script>
<?php get_footer();
