<?php
/**
 * Template Name: Range Safety
 *
 * Source: design/range-safety.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>body { background: var(--color-void); }
  .hero { padding: 140px 32px 80px; border-bottom:1px solid var(--color-hairline); background:
    radial-gradient(ellipse at 80% 30%, rgba(232,128,47,0.06), transparent 60%); }
  .hero .c { max-width: 1280px; margin:0 auto; display:grid; grid-template-columns: 1.4fr 1fr; gap: 56px; align-items: end; }
  @media (max-width: 980px) { .hero .c { grid-template-columns: 1fr; } }
  .hero .eb { display:inline-flex; align-items:center; gap:10px; font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-ember); text-transform: uppercase; margin-bottom: 18px; }
  .hero .eb::before { content:""; width: 28px; height:1px; background: var(--color-ember); }
  .hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 132px); line-height: 0.92; letter-spacing: 0.02em; color: var(--color-white); }
  .hero h1 .a { color: var(--color-ember); }
  .hero p { color: var(--color-fog); max-width: 56ch; margin: 22px 0 0; font-size: 17px; line-height: 1.7; }
  .hero .stamp { padding: 36px; background: var(--color-gunmetal); border:1px solid var(--color-ember); position: relative; }
  .hero .stamp::before { content: ""; position: absolute; top: -1px; left: -1px; right: -1px; height: 4px; background: var(--color-ember); }
  .hero .stamp .k { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--color-ember); text-transform: uppercase; margin-bottom: 12px; }
  .hero .stamp .nm { font-family: var(--font-display); font-size: 32px; color: var(--color-white); letter-spacing: 0.02em; line-height: 1; }
  .hero .stamp .row { display: flex; justify-content: space-between; padding: 12px 0; border-top: 1px solid var(--color-hairline); margin-top: 12px; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; color: var(--color-silver); text-transform: uppercase; }
  .hero .stamp .row .v { color: var(--color-white); }

  .four { padding: 100px 32px; background: var(--color-gunmetal); border-bottom:1px solid var(--color-hairline); }
  .four .c { max-width: 1280px; margin:0 auto; }
  .four h2 { font-family: var(--font-display); font-size: clamp(40px, 6vw, 80px); color: var(--color-white); letter-spacing: 0.04em; line-height: 0.95; margin-bottom: 8px; }
  .four .lead { color: var(--color-fog); max-width: 60ch; font-size: 17px; line-height: 1.7; margin-bottom: 36px; }
  .four-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border: 1px solid var(--color-hairline-bright); }
  @media (max-width: 980px) { .four-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 540px) { .four-grid { grid-template-columns: 1fr; } }
  .rule { padding: 36px 32px; border-right: 1px solid var(--color-hairline-bright); border-bottom: 1px solid var(--color-hairline-bright); position: relative; transition: background 250ms var(--ease-std); }
  .rule:hover { background: rgba(232,128,47,0.03); }
  .rule:nth-child(4n) { border-right: none; }
  @media (max-width: 980px) { .rule:nth-child(2n) { border-right: none; } .rule:nth-child(4n) { border-right: 1px solid var(--color-hairline-bright); } }
  .rule .num { font-family: var(--font-display); font-size: 80px; color: var(--color-ember); line-height: 0.9; letter-spacing: 0.02em; }
  .rule h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 18px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; margin: 18px 0 10px; }
  .rule p { color: var(--color-fog); font-size: 14px; line-height: 1.7; margin: 0; }

  .standard { padding: 100px 32px; }
  .standard .c { max-width: 1280px; margin:0 auto; }
  .standard h2 { font-family: var(--font-display); font-size: clamp(40px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 36px; }
  .acc { border: 1px solid var(--color-hairline); }
  .acc-item { border-bottom: 1px solid var(--color-hairline); }
  .acc-item:last-child { border-bottom: none; }
  .acc-h { width: 100%; background: var(--color-gunmetal); padding: 24px 28px; display: flex; align-items: center; justify-content: space-between; gap: 18px; cursor: pointer; border: none; text-align: left; transition: background 200ms var(--ease-std); }
  .acc-h:hover { background: rgba(201,168,76,0.04); }
  .acc-h .ttl { display:flex; align-items: center; gap: 18px; }
  .acc-h .n { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-brass-bright); }
  .acc-h .t { font-family: var(--font-condensed); font-weight: 600; font-size: 17px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; }
  .acc-h .ch { font-family: var(--font-mono); font-size: 18px; color: var(--color-brass-bright); transition: transform 250ms var(--ease-std); }
  .acc-item.open .acc-h .ch { transform: rotate(45deg); }
  .acc-b { display: none; padding: 24px 28px 28px 80px; background: var(--color-gunmetal); }
  .acc-b ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; color: var(--color-fog); font-size: 14px; line-height: 1.7; }
  .acc-b ul li { padding-left: 24px; position: relative; }
  .acc-b ul li::before { content: ""; background: var(--color-brass-bright); position: absolute; left: 0; top: 0.45em; width: 6px; height: 6px; border-radius: 50%; opacity: 0.7; }
  .acc-item.open .acc-b { display: block; animation: slideDown 280ms var(--ease-out); }
  @keyframes slideDown { from { opacity: 0; max-height: 0; } to { opacity: 1; max-height: 600px; } }

  .prohibit { padding: 100px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); border-bottom: 1px solid var(--color-hairline); }
  .prohibit .c { max-width: 1280px; margin: 0 auto; }
  .prohibit h2 { font-family: var(--font-display); font-size: clamp(40px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 8px; }
  .prohibit .lead { color: var(--color-fog); max-width: 56ch; margin-bottom: 36px; }
  .pr-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
  @media (max-width: 900px) { .pr-grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 540px) { .pr-grid { grid-template-columns: 1fr; } }
  .pr-card { padding: 22px 20px; background: var(--color-void); border:1px solid rgba(232,128,47,0.2); }
  .pr-card .x { font-family: var(--font-display); font-size: 32px; color: var(--color-ember); line-height: 1; }
  .pr-card .t { font-family: var(--font-condensed); font-weight: 600; font-size: 14px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; margin-top: 14px; }
  .pr-card .d { color: var(--color-silver); font-size: 13px; line-height: 1.65; margin-top: 6px; }

  .ammo { padding: 100px 32px; }
  .ammo .c { max-width: 1280px; margin: 0 auto; }
  .ammo h2 { font-family: var(--font-display); font-size: clamp(40px, 5vw, 64px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 36px; }
  .am-table { border:1px solid var(--color-hairline); }
  .am-row { display: grid; grid-template-columns: 1fr 1fr 1fr 100px; padding: 18px 24px; border-bottom: 1px solid var(--color-hairline); align-items: center; }
  .am-row:last-child { border-bottom: none; }
  .am-row.head { background: var(--color-gunmetal); font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }
  .am-row .nm { font-family: var(--font-condensed); font-weight: 600; font-size: 15px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; }
  .am-row .v { font-family: var(--font-mono); font-size: 13px; color: var(--color-fog); }
  .am-row .ok, .am-row .no { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; text-transform: uppercase; }
  .am-row .ok { color: var(--color-active); }
  .am-row .no { color: var(--color-ember); }
  @media (max-width: 800px) { .am-row { grid-template-columns: 1fr 100px; } .am-row .v:first-of-type, .am-row .v:nth-of-type(2) { display:none; } .am-row.head { display: none; } }

  .checklist { padding: 100px 32px; background: var(--color-void); border-top: 1px solid var(--color-hairline); }
  .checklist .c { max-width: 980px; margin: 0 auto; }
  .checklist h2 { font-family: var(--font-display); font-size: clamp(40px, 5vw, 56px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; margin-bottom: 36px; text-align: center; }
  .ck-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  @media (max-width: 700px) { .ck-grid { grid-template-columns: 1fr; } }
  .ck { padding: 22px 24px; background: var(--color-gunmetal); border-left: 3px solid var(--color-brass); display: flex; gap: 16px; align-items: flex-start; }
  .ck .box { width: 24px; height: 24px; border: 2px solid var(--color-brass); flex-shrink: 0; display: grid; place-items: center; color: var(--color-brass-bright); font-family: var(--font-display); font-size: 14px; }
  .ck .nm { font-family: var(--font-condensed); font-weight: 600; font-size: 15px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.06em; }
  .ck .nm small { display: block; font-family: var(--font-mono); font-weight: 400; font-size: 11px; letter-spacing: 0.18em; color: var(--color-silver); margin-top: 4px; }

  .agree { padding: 80px 32px; background: var(--color-gunmetal); border-top: 1px solid var(--color-hairline); }
  .agree .c { max-width: 720px; margin: 0 auto; text-align: center; }
  .agree h2 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 56px); color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .agree p { color: var(--color-fog); margin: 18px 0 28px; line-height: 1.7; }
  .agree .row { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }</style>
<section class="hero">
  <div class="c">
    <div>
      <div class="eb">Read Before First Visit</div>
      <h1>RANGE<br><span class="a">SAFETY</span></h1>
      <p>Every shooter agrees to these rules at check-in. We don't bend them. Following them keeps you, your guests, and 200+ other shooters safe.</p>
    </div>
    <div class="stamp">
      <div class="k"> Status</div>
      <div class="nm">Active Posting</div>
      <div class="row"><span>Last Updated</span><span class="v">May 01, 2026</span></div>
      <div class="row"><span>Range Officers</span><span class="v">3 On Floor</span></div>
      <div class="row"><span>Min Age (Solo)</span><span class="v">18+</span></div>
      <div class="row"><span>Min Age (Supervised)</span><span class="v">8+</span></div>
    </div>
  </div>
</section>

<section class="four">
  <div class="c">
    <h2>THE FOUR RULES</h2>
    <p class="lead">Universal firearm safety. They never change. They never get easier. They are absolute, not relative.</p>
    <div class="four-grid">
      <div class="rule">
        <div class="num">01</div>
        <h3>TREAT EVERY GUN AS LOADED</h3>
        <p>Even after you've checked. Even after the previous person checked. Always. No exceptions for "but I just cleared it."</p>
      </div>
      <div class="rule">
        <div class="num">02</div>
        <h3>MUZZLE SAFE DIRECTION</h3>
        <p>Downrange or up at the ceiling  never sideways, never at the bench, never at another shooter. Your trigger finger doesn't get a vote.</p>
      </div>
      <div class="rule">
        <div class="num">03</div>
        <h3>FINGER OFF TRIGGER</h3>
        <p>Index along the slide or frame until your sights are on target and you've decided to fire. The trigger guard is not a finger rest.</p>
      </div>
      <div class="rule">
        <div class="num">04</div>
        <h3>KNOW YOUR TARGET</h3>
        <p>And what's in front of it, behind it, and beside it. Center mass on the cardboard, not the hanger. Bullets pass through.</p>
      </div>
    </div>
  </div>
</section>

<section class="standard">
  <div class="c">
    <h2>RANGE STANDARDS</h2>
    <div class="acc" id="acc">
      <div class="acc-item open">
        <button class="acc-h"><span class="ttl"><span class="n">A.01</span><span class="t">Eye And Ear Protection</span></span><span class="ch">+</span></button>
        <div class="acc-b">
          <ul>
            <li>ANSI Z87.1-rated eye protection required at all times in the range bay  no exceptions, including spectators.</li>
            <li>NRR 22+ ear protection required. Doubled-up (plugs + muffs) recommended for indoor and pistol-caliber carbines.</li>
            <li>Loaner sets available at front desk for $3 (eye) and $5 (ear). Members loan free.</li>
            <li>Children under 12 require electronic muffs (loaner free with adult).</li>
          </ul>
        </div>
      </div>
      <div class="acc-item">
        <button class="acc-h"><span class="ttl"><span class="n">A.02</span><span class="t">Holstering & Drawing</span></span><span class="ch">+</span></button>
        <div class="acc-b">
          <ul>
            <li>Drawing from concealment or open holster requires a CCW range orientation  schedule with the front desk.</li>
            <li>Cross-draw and shoulder-rig holsters are not permitted on the public range.</li>
            <li>Sweeping the support hand or any body part during a draw stops the relay immediately.</li>
            <li>Members with CCW certification may use private bay reservations for draw practice.</li>
          </ul>
        </div>
      </div>
      <div class="acc-item">
        <button class="acc-h"><span class="ttl"><span class="n">A.03</span><span class="t">Rapid Fire & Drills</span></span><span class="ch">+</span></button>
        <div class="acc-b">
          <ul>
            <li>Maximum 1 round per second on the public range. Faster cadence is for private bays only.</li>
            <li>No more than 2 magazines may be loaded simultaneously per shooter.</li>
            <li>Multi-target transitions allowed within your own lane only  never across lane lines.</li>
            <li>Bill drills, El Presidente, and other competition drills require RO sign-off.</li>
          </ul>
        </div>
      </div>
      <div class="acc-item">
        <button class="acc-h"><span class="ttl"><span class="n">A.04</span><span class="t">Lane Etiquette</span></span><span class="ch">+</span></button>
        <div class="acc-b">
          <ul>
            <li>Stay in your lane. Do not cross the firing line for any reason without an RO call.</li>
            <li>Pick up your brass when leaving  you keep what you brought, range keeps what you didn't.</li>
            <li>One shooter behind the firing line at a time per lane (max 2 with RO permission).</li>
            <li>Cell phones, food, and beverages are not permitted forward of the safety glass.</li>
          </ul>
        </div>
      </div>
      <div class="acc-item">
        <button class="acc-h"><span class="ttl"><span class="n">A.05</span><span class="t">Cease Fire Protocol</span></span><span class="ch">+</span></button>
        <div class="acc-b">
          <ul>
            <li>"CEASE FIRE" called by ANY shooter halts the entire range immediately. Don't be embarrassed to call it.</li>
            <li>On call: stop firing, finger off trigger, magazine out, action open, gun on bench muzzle downrange.</li>
            <li>Step back behind the yellow line and wait for RO clearance.</li>
            <li>Resume only when an RO calls "RANGE IS HOT, COMMENCE FIRING."</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="prohibit">
  <div class="c">
    <h2>PROHIBITED</h2>
    <p class="lead">These will end your range day immediately and may end your range membership permanently. No warnings.</p>
    <div class="pr-grid">
      <div class="pr-card"><div class="x"></div><div class="t">Alcohol Or Drugs</div><div class="d">Including prescription opioids and benzodiazepines. Smell of alcohol on breath = ejection.</div></div>
      <div class="pr-card"><div class="x"></div><div class="t">Steel-Core Ammunition</div><div class="d">Including 5.45x39 surplus, M855 green-tip, M193, and Tula 7.62. Ricochets and damages backstop.</div></div>
      <div class="pr-card"><div class="x"></div><div class="t">Tracers / Incendiary</div><div class="d">All ignition-class ammunition. Includes API, FRAG, and pyrotechnic loads.</div></div>
      <div class="pr-card"><div class="x"></div><div class="t">Fully Automatic Fire</div><div class="d">Public range only. Private bay rentals + RO supervision required for FA, MP5, M16, and AK platforms.</div></div>
      <div class="pr-card"><div class="x"></div><div class="t">.50 BMG / Anti-Materiel</div><div class="d">Caliber capacity ends at .338 Lapua. Larger calibers require pre-arranged private session.</div></div>
      <div class="pr-card"><div class="x"></div><div class="t">Photography Of Patrons</div><div class="d">No filming or photography of other shooters without written consent. Self-only on your phone OK.</div></div>
    </div>
  </div>
</section>

<section class="ammo">
  <div class="c">
    <h2>AMMUNITION POLICY</h2>
    <div class="am-table">
      <div class="am-row head"><div>Round Type</div><div>Caliber Range</div><div>Notes</div><div>Status</div></div>
      <div class="am-row"><div class="nm">FMJ Brass-Cased</div><div class="v">.22  .338 Lapua</div><div class="v">Preferred. Available at counter.</div><div class="ok"> Allowed</div></div>
      <div class="am-row"><div class="nm">JHP / Defense</div><div class="v">All standard pistol</div><div class="v">No problem on public range.</div><div class="ok"> Allowed</div></div>
      <div class="am-row"><div class="nm">Lead Round Nose</div><div class="v">.22, .38, .45 Cowboy</div><div class="v">Limit to pistol calibers.</div><div class="ok"> Allowed</div></div>
      <div class="am-row"><div class="nm">Steel-Cased (Tula/Wolf)</div><div class="v">9mm, 7.62×39</div><div class="v">Magnet test at counter.</div><div class="no"> Prohibited</div></div>
      <div class="am-row"><div class="nm">Steel-Core / M855</div><div class="v">5.56, 7.62×39</div><div class="v">Penetrates backstop.</div><div class="no"> Prohibited</div></div>
      <div class="am-row"><div class="nm">Tracer / API</div><div class="v">Any caliber</div><div class="v">Fire hazard. No exceptions.</div><div class="no"> Prohibited</div></div>
      <div class="am-row"><div class="nm">Reloads (Personal)</div><div class="v">Any caliber</div><div class="v">RO inspection required.</div><div class="ok"> With RO Approval</div></div>
    </div>
    <div class="form-help" style="margin-top: 18px;"> When in doubt, ask a Range Officer. Magnet testing your ammo at the counter is free.</div>
  </div>
</section>

<section class="checklist">
  <div class="c">
    <h2>FIRST-VISIT CHECKLIST</h2>
    <div class="ck-grid">
      <div class="ck"><div class="box"></div><div class="nm">Government Photo ID<small>Driver's license  State ID  Passport</small></div></div>
      <div class="ck"><div class="box"></div><div class="nm">Eye & Ear Protection<small>Or rent at counter  $3 / $5</small></div></div>
      <div class="ck"><div class="box"></div><div class="nm">Closed-Toe Shoes<small>No sandals  No open tops</small></div></div>
      <div class="ck"><div class="box"></div><div class="nm">High-Neck Shirt<small>Hot brass policy</small></div></div>
      <div class="ck"><div class="box"></div><div class="nm">Approved Ammunition<small>FMJ brass-cased preferred</small></div></div>
      <div class="ck"><div class="box"></div><div class="nm">Signed Waiver<small>Once per shooter  Lifetime</small></div></div>
    </div>
  </div>
</section>

<section class="agree">
  <div class="c">
    <h2>READY TO SHOOT?</h2>
    <p>Reserve your lane online or walk in any time during open hours. First-time waiver can be signed digitally before you arrive.</p>
    <div class="row">
      <a class="btn btn-brass btn-lg" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Reserve A Lane </a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">Questions? Contact Us</a>
    </div>
  </div>
</section>


<script>
  document.querySelectorAll('#acc .acc-h').forEach(b => b.addEventListener('click', () => {
    b.parentElement.classList.toggle('open');
  }));
</script>
<?php get_footer();
