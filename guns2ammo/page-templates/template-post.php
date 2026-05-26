<?php
/**
 * Template Name: Post
 *
 * Source: design/post.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>/* Progress bar */
  .progress { position: fixed; top: 0; left: 0; height: 2px; background: var(--color-brass); z-index: 200; transition: width 80ms linear; width: 0; }

  /* Breadcrumb */
  .crumb { max-width: 760px; margin: 0 auto; padding: 120px 24px 0; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }
  .crumb a { color: var(--color-fog); text-decoration: none; }
  .crumb a:hover { color: var(--color-brass-bright); }
  .crumb .sep { color: var(--color-steel); margin: 0 8px; }

  /* Header */
  .post-head { max-width: 760px; margin: 0 auto; padding: 24px 24px 32px; }
  .post-head .cat { display: inline-block; background: var(--color-brass); color: #111; padding: 6px 14px; font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; font-weight: 700; text-transform: uppercase; margin-bottom: 24px; }
  .post-head h1 { font-family: var(--font-display); font-size: clamp(48px, 7vw, 80px); line-height: 1.05; letter-spacing: 0.005em; color: var(--color-white); margin: 0 0 20px; }
  .post-head .lede { font-family: var(--font-body); font-style: italic; font-size: 20px; color: var(--color-fog); line-height: 1.5; max-width: 60ch; margin: 0 0 32px; }
  .post-head .by { display: flex; align-items: center; gap: 14px; padding: 18px 0; border-top: 1px solid var(--color-hairline); border-bottom: 1px solid var(--color-hairline); flex-wrap: wrap; }
  .post-head .by .av { width: 44px; height: 44px; border-radius: 50%; background: var(--color-brass); color: #111; display: grid; place-items: center; font-family: var(--font-display); font-size: 18px; flex: 0 0 auto; }
  .post-head .by .who { display: grid; gap: 2px; }
  .post-head .by .who .name { font-family: var(--font-condensed); font-weight: 600; font-size: 14px; color: var(--color-white); letter-spacing: 0.04em; text-transform: uppercase; }
  .post-head .by .who .role { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }
  .post-head .by .meta { margin-left: auto; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; color: var(--color-silver); text-transform: uppercase; display: flex; gap: 14px; align-items: center; }
  .post-head .by .meta span::before { content: ""; margin-right: 14px; color: var(--color-steel); }
  .post-head .by .meta span:first-child::before { display: none; }

  /* Hero image */
  .post-hero { max-width: 1080px; margin: 0 auto 8px; padding: 0 24px; }
  .post-hero .img {
    aspect-ratio: 16/7; position: relative; overflow: hidden;
    background:
      linear-gradient(180deg, rgba(26,25,30,0.2), rgba(26,25,30,0.6)),
      linear-gradient(135deg, #2A1F0E 0%, #14100A 60%, var(--color-void) 100%);
  }
  .post-hero .img::before {
    content: ""; position: absolute; inset: 0;
    background-image: repeating-linear-gradient(45deg, rgba(255,255,255,0.025) 0 1px, transparent 1px 20px);
  }
  .post-hero .img::after {
    content: "ARTICLE COVER  ARIZONA STATE FLAG / CARRY POSE"; position: absolute; bottom: 18px; left: 18px;
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.3em; color: var(--color-brass-bright); text-transform: uppercase;
  }
  .post-hero .cap { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; color: var(--color-silver); text-transform: uppercase; padding: 12px 0 0; max-width: 760px; margin: 0 auto; font-style: italic; }

  /* Article body layout  sticky TOC + body + share rail */
  .post-shell {
    max-width: 1280px; margin: 48px auto 0; padding: 0 24px;
    display: grid; grid-template-columns: 200px minmax(0, 720px) 60px;
    gap: 48px; justify-content: center;
  }
  @media (max-width: 1100px) {
    .post-shell { grid-template-columns: minmax(0, 760px); gap: 24px; padding: 0 16px; }
    .post-toc, .post-share { display: none; }
  }
  .post-toc { position: sticky; top: 100px; height: fit-content; padding-left: 18px; border-left: 2px solid var(--color-brass-dim); background: rgba(28,28,30,0.5); padding: 18px 0 18px 18px; }
  .post-toc h6 { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; color: var(--color-brass-bright); text-transform: uppercase; margin: 0 0 14px; }
  .post-toc ol { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; counter-reset: toc; }
  .post-toc ol li { counter-increment: toc; }
  .post-toc ol a {
    font-family: var(--font-condensed); font-weight: 500; font-size: 13px;
    color: var(--color-fog); text-decoration: none; line-height: 1.4; display: flex; gap: 8px;
    transition: color 180ms var(--ease-std);
  }
  .post-toc ol a::before { content: counter(toc, decimal-leading-zero); font-family: var(--font-mono); font-size: 10px; color: var(--color-silver); }
  .post-toc ol a:hover, .post-toc ol a.active { color: var(--color-brass-bright); }

  .post-share { position: sticky; top: 120px; height: fit-content; display: grid; gap: 8px; justify-items: center; }
  .post-share button { width: 40px; height: 40px; background: var(--color-gunmetal); border: 1px solid var(--color-hairline-bright); color: var(--color-fog); cursor: pointer; display: grid; place-items: center; transition: all 180ms var(--ease-std); }
  .post-share button:hover { color: var(--color-brass-bright); border-color: var(--color-brass-dim); }
  .post-share button.copied { color: #4ADE80; border-color: rgba(74,222,128,0.4); }

  /* Body type */
  .post-body { font-size: 17.5px; line-height: 1.8; color: var(--color-fog); }
  .post-body h2 { font-family: var(--font-condensed); font-weight: 600; font-size: 32px; line-height: 1.15; color: var(--color-brass-bright); margin: 56px 0 18px; text-transform: uppercase; letter-spacing: 0.02em; scroll-margin-top: 100px; }
  .post-body h2 + p { font-size: 18px; }
  .post-body h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 22px; line-height: 1.25; color: var(--color-white); margin: 36px 0 12px; letter-spacing: 0.02em; }
  .post-body p { margin: 0 0 22px; text-wrap: pretty; }
  .post-body strong { color: var(--color-white); font-weight: 600; }
  .post-body a { color: var(--color-brass-bright); text-decoration: none; border-bottom: 1px solid var(--color-brass-dim); }
  .post-body a:hover { border-bottom-color: var(--color-brass); }
  .post-body blockquote { border-left: 3px solid var(--color-brass); padding: 4px 0 4px 24px; margin: 32px 0; font-family: var(--font-body); font-style: italic; font-size: 19px; color: var(--color-white); }
  .post-body blockquote cite { display: block; margin-top: 10px; font-style: normal; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; }
  .post-body ul, .post-body ol { padding-left: 0; list-style: none; margin: 0 0 24px; display: grid; gap: 12px; }
  .post-body ul li { padding-left: 28px; position: relative; }
  .post-body ul li::before { content: ""; position: absolute; left: 4px; top: 0.7em; width: 8px; height: 8px; background: var(--color-brass); transform: rotate(45deg); }
  .post-body ol { counter-reset: ord; }
  .post-body ol li { counter-increment: ord; padding-left: 44px; position: relative; }
  .post-body ol li::before {
    content: counter(ord, decimal-leading-zero);
    position: absolute; left: 0; top: -2px;
    font-family: var(--font-display); font-size: 22px; color: var(--color-brass-bright);
  }

  /* Callouts */
  .callout { margin: 32px 0; padding: 22px 24px 22px 60px; border: 1px solid; position: relative; }
  .callout::before {
    position: absolute; left: 22px; top: 24px; font-size: 22px; line-height: 1;
  }
  .callout .h { font-family: var(--font-condensed); font-weight: 600; font-size: 13px; letter-spacing: 0.18em; text-transform: uppercase; margin-bottom: 6px; }
  .callout p { margin: 0; font-size: 15.5px; }
  .callout.tip { background: rgba(201,168,76,0.06); border-color: var(--color-brass-dim); border-left-width: 3px; }
  .callout.tip .h { color: var(--color-brass-bright); }
  .callout.tip::before { content: ""; color: var(--color-brass-bright); }
  .callout.warn { background: rgba(232,128,47,0.06); border-color: rgba(232,128,47,0.4); border-left-width: 3px; }
  .callout.warn .h { color: var(--color-ember); }
  .callout.warn::before { content: "!"; color: var(--color-ember); font-family: var(--font-display); font-size: 28px; }
  .callout.fact { background: rgba(96,165,250,0.05); border-color: rgba(96,165,250,0.3); border-left-width: 3px; }
  .callout.fact .h { color: #60A5FA; }
  .callout.fact::before { content: ""; color: #60A5FA; font-size: 24px; }

  /* CAN / CANNOT table */
  .can-table { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 28px 0; }
  @media (max-width: 600px) { .can-table { grid-template-columns: 1fr; } }
  .can-col { background: var(--color-gunmetal); border: 1px solid var(--color-hairline-bright); padding: 22px; }
  .can-col .lab { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; text-transform: uppercase; margin-bottom: 14px; padding-bottom: 12px; border-bottom: 1px solid var(--color-hairline); }
  .can-col.do .lab { color: #4ADE80; }
  .can-col.dont .lab { color: var(--color-ember); }
  .can-col ul { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
  .can-col li { font-size: 14.5px; padding-left: 28px; position: relative; line-height: 1.45; }
  .can-col.do li::before { content: ""; position: absolute; left: 0; top: 0; color: #4ADE80; font-weight: 700; font-size: 18px; line-height: 1; }
  .can-col.dont li::before { content: ""; position: absolute; left: 0; top: 0; color: var(--color-ember); font-weight: 700; font-size: 18px; line-height: 1; }

  /* Tags */
  .post-tags { display: flex; flex-wrap: wrap; gap: 8px; margin: 56px 0 0; padding-top: 32px; border-top: 1px solid var(--color-hairline); }
  .post-tags .tag { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; color: var(--color-fog); border: 1px solid var(--color-hairline-bright); padding: 6px 12px; text-transform: uppercase; }
  .post-tags .tag::before { content: "#"; color: var(--color-brass-bright); margin-right: 2px; }

  /* Author bio */
  .author-bio { display: grid; grid-template-columns: 80px 1fr; gap: 18px; align-items: center; background: var(--color-gunmetal); border: 1px solid var(--color-hairline-bright); padding: 24px; margin: 32px 0 0; }
  @media (max-width: 540px) { .author-bio { grid-template-columns: 1fr; text-align: center; } }
  .author-bio .av { width: 80px; height: 80px; border-radius: 50%; background: var(--color-brass); color: #111; display: grid; place-items: center; font-family: var(--font-display); font-size: 32px; }
  @media (max-width: 540px) { .author-bio .av { margin: 0 auto; } }
  .author-bio .lab { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }
  .author-bio .name { font-family: var(--font-display); font-size: 24px; color: var(--color-white); letter-spacing: 0.03em; margin: 4px 0 8px; }
  .author-bio p { font-size: 14px; color: var(--color-fog); margin: 0 0 10px; }

  /* Related */
  .related { max-width: 1280px; margin: 96px auto 0; padding: 0 24px; }
  .related h2 { font-family: var(--font-display); font-size: 48px; line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin: 0 0 32px; }
  .related .grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
  @media (max-width: 900px) { .related .grid { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 600px) { .related .grid { grid-template-columns: 1fr; } }
  .rcard { background: var(--color-gunmetal); border: 1px solid var(--color-hairline); text-decoration: none; transition: all 250ms var(--ease-std); }
  .rcard:hover { transform: translateY(-4px); border-color: var(--color-brass-dim); }
  .rcard .ph { aspect-ratio: 16/9; background: linear-gradient(135deg, #2A1F0E, var(--color-void)); position: relative; }
  .rcard .ph::before { content: ""; position: absolute; inset: 0; background: repeating-linear-gradient(45deg, rgba(255,255,255,0.025) 0 1px, transparent 1px 14px); }
  .rcard .body { padding: 22px; }
  .rcard .cat { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-brass-bright); text-transform: uppercase; }
  .rcard h4 { font-family: var(--font-condensed); font-weight: 600; font-size: 19px; line-height: 1.25; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.02em; margin: 8px 0 0; }

  /* CTA band */
  .post-cta { margin: 96px auto 0; padding: 80px 24px; background: linear-gradient(135deg, #2A2218, var(--color-void)); border-top: 1px solid var(--color-brass-dim); border-bottom: 1px solid var(--color-brass-dim); position: relative; overflow: hidden; }
  .post-cta::before { content: ""; position: absolute; inset: 0; background-image: repeating-linear-gradient(115deg, transparent 0 80px, rgba(201,168,76,0.05) 80px 81px); }
  .post-cta .wrap { max-width: 1080px; margin: 0 auto; text-align: center; position: relative; }
  .post-cta h3 { font-family: var(--font-display); font-size: clamp(40px, 5vw, 64px); line-height: 1; color: var(--color-white); margin: 0 0 24px; }
  .post-cta .row { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; }

  /* Map placeholder */
  .map-states {
    margin: 28px 0; aspect-ratio: 16/9; background: var(--color-gunmetal);
    border: 1px solid var(--color-hairline-bright);
    background-image:
      linear-gradient(135deg, rgba(201,168,76,0.10), transparent 60%),
      repeating-linear-gradient(0deg, transparent 0 40px, rgba(255,255,255,0.025) 40px 41px),
      repeating-linear-gradient(90deg, transparent 0 40px, rgba(255,255,255,0.025) 40px 41px);
    position: relative; display: grid; place-items: center;
  }
  .map-states .pin {
    text-align: center;
  }
  .map-states .pin .num { font-family: var(--font-display); font-size: 88px; color: var(--color-brass-bright); line-height: 1; }
  .map-states .pin .lab { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.3em; color: var(--color-fog); text-transform: uppercase; margin-top: 6px; }
  .map-states .corner { position: absolute; bottom: 14px; left: 14px; font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.3em; color: var(--color-silver); text-transform: uppercase; }

  /* FAQ */
  .post-body details { background: var(--color-gunmetal); border: 1px solid var(--color-hairline); margin-bottom: 8px; }
  .post-body details summary { padding: 18px 22px; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 16px; font-family: var(--font-condensed); font-weight: 600; font-size: 16px; color: var(--color-white); letter-spacing: 0.02em; }
  .post-body details summary::-webkit-details-marker { display: none; }
  .post-body details summary::after { content: "+"; font-family: var(--font-display); font-size: 24px; color: var(--color-brass-bright); flex: 0 0 auto; }
  .post-body details[open] summary::after { content: ""; }
  .post-body details .a { padding: 0 22px 18px; font-size: 15px; }
  .post-body details .a p:last-child { margin-bottom: 0; }</style>
<div class="progress" id="progress"></div>
  <main>
    <nav class="crumb">
      <a href="<?php echo esc_url( home_url( "/" ) ); ?>">Home</a><span class="sep">/</span>
      <a href="<?php echo esc_url( home_url( "/blog/" ) ); ?>">Knowledge Hub</a><span class="sep">/</span>
      <span style="color: var(--color-brass-bright);">CCW &amp; Carry Laws</span>
    </nav>

    <header class="post-head">
      <span class="cat">CCW &amp; Carry Laws</span>
      <h1>Arizona CCW Laws 2025: Complete Carrier's Guide</h1>
      <p class="lede">Everything you need to know about concealed carry in Arizona  from permits to reciprocity to what you can't do  written by NRA-certified instructors at our Mesa range.</p>
      <div class="by">
        <div class="av">G2A</div>
        <div class="who">
          <div class="name">G2A Instructor Team</div>
          <div class="role">NRA-Certified  Updated January 2026</div>
        </div>
        <div class="meta">
          <span>8 min read</span><span>Updated Jan 2026</span>
        </div>
      </div>
    </header>

    <div class="post-hero">
      <div class="img"></div>
      <div class="cap">Mesa, AZ  January 2026. The Arizona state flag at our 6030 E Main St facility.</div>
    </div>

    <div class="post-shell">
      <!-- TOC -->
      <aside class="post-toc" aria-label="In this article">
        <h6>In This Article</h6>
        <ol id="toc-list">
          <li><a href="#what-is" data-toc>What is an AZ CCW?</a></li>
          <li><a href="#qualifies" data-toc>Who Qualifies</a></li>
          <li><a href="#apply" data-toc>How to Apply</a></li>
          <li><a href="#reciprocity" data-toc>37-State Reciprocity</a></li>
          <li><a href="#can-cant" data-toc>Can &amp; Cannot Do</a></li>
          <li><a href="#faq" data-toc>FAQs</a></li>
        </ol>
      </aside>

      <!-- BODY -->
      <article class="post-body" id="article-body">

        <p>Arizona is a <strong>constitutional carry state</strong>  meaning, in most circumstances, an adult who can legally own a handgun can also carry one concealed without a permit. So why bother with a CCW at all? Three big reasons: <strong>reciprocity in 37 other states</strong>, NICS-check exemptions on firearm purchases, and access to a deeper layer of legal protection if you ever need it. Below: what the permit is, who can get one, how to apply, and the surprisingly long list of places it doesn't cover.</p>

        <div class="callout fact">
          <div class="h">Key Fact</div>
          <p>Arizona issues a <strong>shall-issue</strong> permit administered by the Department of Public Safety (DPS). Permit cost: $60 state fee. Course cost (here at G2A): $85. Total time, application to plastic in your wallet: ~6 weeks.</p>
        </div>

        <h2 id="what-is">What is an Arizona CCW Permit?</h2>
        <p>The Arizona Concealed Weapons Permit (CWP, often called CCW) is a state-issued credential that allows the holder to lawfully carry a concealed weapon  handgun, knife, or other deadly weapon  anywhere not specifically restricted by ARS  13-3102. While Arizona has been a constitutional-carry state since 2010, the CCW remains valuable for the practical reasons above.</p>
        <p>The permit is good for <strong>five years</strong> and can be renewed online with no additional course required, provided the holder remains eligible.</p>

        <h2 id="qualifies">Who Qualifies for an Arizona CCW?</h2>
        <p>The eligibility list is short and strict. To apply, you must:</p>
        <ul>
          <li>Be a resident of the United States and at least <strong>21 years of age</strong> (19 if active-duty military or honorably discharged)</li>
          <li>Not be a prohibited possessor under federal or Arizona law</li>
          <li>Not have been convicted of a felony, unless your rights have been formally restored</li>
          <li>Not have been adjudicated mentally incompetent or committed to a mental institution</li>
          <li>Demonstrate competence with a firearm by completing a state-approved CCW course</li>
        </ul>

        <div class="callout warn">
          <div class="h">Watch Out</div>
          <p>Misdemeanor domestic violence convictions are a federal disqualifier  even decades after the fact. If you're unsure, check before you pay the application fee.</p>
        </div>

        <h2 id="apply">How to Apply: Step-by-Step</h2>
        <ol>
          <li><strong>Take a state-approved CCW course.</strong> Ours runs four hours, includes live fire, and meets every DPS requirement. You walk out with a completion certificate the same day.</li>
          <li><strong>Get your fingerprints taken.</strong> We do this on-site for $25. You can also use any AZ DPS-certified vendor.</li>
          <li><strong>Submit your application packet to DPS.</strong> Includes your course certificate, fingerprint card, photo, and the $60 state fee. We'll review everything before you mail it.</li>
          <li><strong>Wait 48 weeks for approval.</strong> DPS will mail your permit directly to you. The credential is a credit-card-sized hard plastic card.</li>
          <li><strong>Carry  or don't.</strong> Your call. Either way, you're now in 37 states' worth of reciprocity.</li>
        </ol>

        <h2 id="reciprocity">The 37 States That Honor Your AZ Permit</h2>
        <p>As of January 2026, 37 states recognize the Arizona CCW. That includes obvious neighbors (NM, UT, NV) plus most of the South and Midwest. Notable holdouts: California, New York, Illinois, Massachusetts, Hawaii, and Oregon do <em>not</em> honor it. New Mexico honors it but does not honor non-resident permits.</p>

        <div class="map-states">
          <div class="pin">
            <div class="num">37</div>
            <div class="lab">States Honoring AZ CCW</div>
          </div>
          <div class="corner">Reciprocity Map  Updated 01.2026</div>
        </div>

        <blockquote>
          Reciprocity changes. We update this guide quarterly  but always verify with the destination state's AG office before you travel armed.
          <cite> G2A Instructor Team</cite>
        </blockquote>

        <h2 id="can-cant">What You CAN and CANNOT Do With a CCW</h2>
        <p>The permit isn't a universal pass. Here's the practical version of ARS  13-3102 broken into a side-by-side.</p>

        <div class="can-table">
          <div class="can-col do">
            <div class="lab">You Can</div>
            <ul>
              <li>Carry concealed in most public places statewide</li>
              <li>Carry into restaurants that serve alcohol (so long as you don't drink)</li>
              <li>Skip the federal NICS check on firearm purchases</li>
              <li>Carry across 37 reciprocity states</li>
              <li>Carry on most public roads &amp; parks</li>
              <li>Renew online every 5 years without a re-course</li>
            </ul>
          </div>
          <div class="can-col dont">
            <div class="lab">You Cannot</div>
            <ul>
              <li>Carry into any K12 school grounds</li>
              <li>Carry into a polling place on election day</li>
              <li>Carry into a "no firearms" posted private business</li>
              <li>Carry into federal buildings (post office, courthouse, etc.)</li>
              <li>Carry while consuming alcohol  period</li>
              <li>Carry into Native American reservations without tribal permission</li>
            </ul>
          </div>
        </div>

        <div class="callout tip">
          <div class="h">Pro Tip</div>
          <p>Print a one-page "no carry" cheat sheet and keep it in your glovebox. Most CCW infractions are honest mistakes  not criminal intent. Knowing where you can't carry is half the job.</p>
        </div>

        <h2 id="faq">Frequently Asked Questions</h2>

        <details open>
          <summary>Do I really need a CCW if Arizona is a constitutional-carry state?</summary>
          <div class="a"><p>Legally, no  for in-state carry. Practically, yes if you ever travel, want to skip NICS checks at the gun counter, or want a stronger legal posture if you ever have to use a firearm in self-defense.</p></div>
        </details>
        <details>
          <summary>How long does the application take from start to finish?</summary>
          <div class="a"><p>About six weeks total. Course (4 hours)  fingerprints (15 min)  mail to DPS  DPS processes in 48 weeks.</p></div>
        </details>
        <details>
          <summary>Can a non-resident get an Arizona CCW?</summary>
          <div class="a"><p>Yes. AZ is one of the most flexible states for non-residents  you don't need to live here. We host out-of-state students every weekend.</p></div>
        </details>
        <details>
          <summary>What if I lose my permit?</summary>
          <div class="a"><p>Request a duplicate from DPS for $10. Until it arrives, default back to constitutional carry within Arizona.</p></div>
        </details>

        <!-- Tags -->
        <div class="post-tags">
          <span class="tag">CCW</span>
          <span class="tag">Arizona</span>
          <span class="tag">CarryLaws</span>
          <span class="tag">Reciprocity</span>
          <span class="tag">Firearms</span>
        </div>

        <!-- Author bio -->
        <div class="author-bio">
          <div class="av">G2A</div>
          <div>
            <div class="lab">About the Author</div>
            <div class="name">G2A Instructor Team</div>
            <p>NRA-certified instructors and AZDPS-approved CCW providers, teaching out of our Mesa range since 2014. Course pass-rate: 99.6%.</p>
            <a href="<?php echo esc_url( home_url( "/blog/" ) ); ?>" class="btn btn-brass btn-sm">View All Articles </a>
          </div>
        </div>
      </article>

      <!-- SHARE -->
      <aside class="post-share" aria-label="Share">
        <button title="Share to X" aria-label="Share to X"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h3l-7.5 8.5L22 22h-6.8l-5.3-7-6.1 7H1l8-9.2L1.6 2h7l4.8 6.4L18 2zm-2.4 18h1.9L7.5 4H5.5l10.1 16z"/></svg></button>
        <button title="Share to Facebook" aria-label="Share to Facebook"><svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12.07C22 6.5 17.52 2 12 2S2 6.5 2 12.07c0 5 3.66 9.13 8.44 9.93v-7H7.9v-2.93h2.54V9.84c0-2.51 1.49-3.9 3.78-3.9 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.88h2.78l-.44 2.93H13.5v7c4.78-.8 8.44-4.93 8.44-9.93z"/></svg></button>
        <button id="copy-link" title="Copy link" aria-label="Copy link"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>
        <button title="Share via email" aria-label="Email"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="14" rx="1"/><path d="m3 7 9 6 9-6"/></svg></button>
      </aside>
    </div>

    <!-- RELATED -->
    <section class="related">
      <h2>Continue Reading</h2>
      <div class="grid">
        <a class="rcard" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
          <div class="ph"></div>
          <div class="body">
            <div class="cat">CCW &amp; Carry</div>
            <h4>Can I Carry in My Car Without a CCW in Arizona?</h4>
          </div>
        </a>
        <a class="rcard" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
          <div class="ph"></div>
          <div class="body">
            <div class="cat">CCW &amp; Carry</div>
            <h4>CCW Reciprocity: 37 States That Honor Your AZ Permit</h4>
          </div>
        </a>
        <a class="rcard" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
          <div class="ph"></div>
          <div class="body">
            <div class="cat">AZ Gun Laws</div>
            <h4>Arizona Open Carry vs. Concealed Carry: Know the Difference</h4>
          </div>
        </a>
      </div>
    </section>

    <!-- CTA BAND -->
    <section class="post-cta">
      <div class="wrap">
        <div class="eyebrow" style="margin-bottom:14px; justify-content:center;">Ready When You Are</div>
        <h3>Put this knowledge into action.</h3>
        <div class="row">
          <a class="btn btn-ember btn-lg" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">Enroll in the AZ CCW Class </a>
          <a class="btn btn-brass btn-lg" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book a Range Session</a>
        </div>
      </div>
    </section>
  </main>

  
  <script>
    // Reading progress
    const bar = document.getElementById('progress');
    const article = document.getElementById('article-body');
    const onScroll = () => {
      const top = article.getBoundingClientRect().top + window.scrollY;
      const h = article.offsetHeight - window.innerHeight;
      const pct = Math.max(0, Math.min(100, ((window.scrollY - top + 200) / h) * 100));
      bar.style.width = pct + '%';
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // TOC active highlighting
    const tocLinks = document.querySelectorAll('[data-toc]');
    const headings = [...tocLinks].map(a => document.getElementById(a.getAttribute('href').slice(1))).filter(Boolean);
    const setActive = () => {
      const y = window.scrollY + 140;
      let active = headings[0];
      for (const h of headings) { if (h.offsetTop <= y) active = h; }
      tocLinks.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + active.id));
    };
    window.addEventListener('scroll', setActive, { passive: true });
    setActive();

    // Smooth scroll
    tocLinks.forEach(a => a.addEventListener('click', e => {
      e.preventDefault();
      const t = document.getElementById(a.getAttribute('href').slice(1));
      if (t) window.scrollTo({ top: t.offsetTop - 100, behavior: 'smooth' });
    }));

    // Copy link
    document.getElementById('copy-link').addEventListener('click', e => {
      const btn = e.currentTarget;
      navigator.clipboard?.writeText(location.href).catch(()=>{});
      btn.classList.add('copied');
      btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12l5 5L20 7"/></svg>';
      setTimeout(() => {
        btn.classList.remove('copied');
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
      }, 1800);
    });
  </script>
<?php get_footer();
