<?php
/** 404 page  Guns 2 Ammo */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
// Single source of truth for NAP — see inc/business-info.php.
$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
?>
<style>body { background: var(--color-void); overflow-x: hidden; }
  .e-shell { min-height: 100vh; display: grid; place-items: center; padding: 140px 24px 80px; position: relative; overflow: hidden; }
  .e-shell::before {
    content: ""; position: absolute; inset: 0;
    background-image:
      radial-gradient(circle at 30% 30%, rgba(201,168,76,0.06), transparent 50%),
      radial-gradient(circle at 70% 70%, rgba(232,128,47,0.05), transparent 50%),
      repeating-linear-gradient(45deg, transparent 0 1px, rgba(255,255,255,0.015) 1px 24px),
      repeating-linear-gradient(-45deg, transparent 0 1px, rgba(255,255,255,0.015) 1px 24px);
    pointer-events: none;
  }
  .e-inner { max-width: 760px; text-align: center; position: relative; z-index: 1; }
  .stamp { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.4em; color: var(--color-ember); text-transform: uppercase; margin-bottom: 28px; display: inline-flex; align-items: center; gap: 10px; }
  .stamp::before, .stamp::after { content: ""; width: 32px; height: 1px; background: var(--color-ember); }

  /* Massive 404 with bullet hole */
  .num-wrap {
    position: relative;
    font-family: var(--font-display);
    font-size: clamp(180px, 32vw, 380px);
    line-height: 0.85;
    letter-spacing: 0.005em;
    color: var(--color-white);
    margin: 0 0 16px;
    display: inline-block;
  }
  .num-wrap .digit { display: inline-block; position: relative; }
  .num-wrap .digit::after { /* shimmer */
    content: attr(data-d);
    position: absolute; inset: 0;
    background: linear-gradient(115deg, transparent 30%, var(--color-brass-bright) 45%, transparent 60%);
    background-size: 200% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: numshimmer 4s linear infinite;
    pointer-events: none;
  }
  .num-wrap .zero { position: relative; }
  .num-wrap .zero .hole {
    position: absolute; top: 50%; left: 50%;
    width: clamp(70px, 13vw, 150px); height: clamp(70px, 13vw, 150px);
    transform: translate(-50%, -50%);
    border-radius: 50%;
    background: radial-gradient(circle, var(--color-void) 35%, #1c1612 60%, transparent 75%);
    box-shadow:
      inset 0 0 30px rgba(0,0,0,0.9),
      inset 0 0 8px rgba(232,128,47,0.4),
      0 0 40px rgba(232,128,47,0.18);
    z-index: 2;
  }
  .num-wrap .zero .hole::before {
    content: ""; position: absolute; inset: 12%;
    border: 2px solid rgba(232,128,47,0.35);
    border-radius: 50%;
    animation: ripple 2.5s ease-out infinite;
  }
  .num-wrap .zero .hole::after {
    content: ""; position: absolute; inset: 22%;
    border: 1px solid rgba(201,168,76,0.4);
    border-radius: 50%;
    animation: ripple 2.5s ease-out infinite 0.5s;
  }
  /* Crack lines from the hole */
  .crack { position: absolute; top: 50%; left: 50%; width: 200%; height: 1px; background: linear-gradient(90deg, transparent 30%, rgba(255,255,255,0.12) 50%, transparent 70%); transform-origin: 0 0; z-index: 1; pointer-events: none; }
  .crack.c1 { transform: translate(-50%, -50%) rotate(15deg); }
  .crack.c2 { transform: translate(-50%, -50%) rotate(-32deg); }
  .crack.c3 { transform: translate(-50%, -50%) rotate(67deg); }
  .crack.c4 { transform: translate(-50%, -50%) rotate(-71deg); }

  @keyframes numshimmer { 0%,100% { background-position: 200% 0; } 50% { background-position: -100% 0; } }
  @keyframes ripple {
    0% { opacity: 0.8; transform: scale(1); }
    100% { opacity: 0; transform: scale(2.2); }
  }

  /* Subtitle */
  .e-inner h1 { font-family: var(--font-display); font-size: clamp(36px, 5vw, 56px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin: 0 0 16px; }
  .e-inner h1 em { font-style: normal; color: var(--color-brass-bright); }
  .e-inner p.lead { color: var(--color-fog); font-size: 17px; max-width: 50ch; margin: 0 auto 36px; line-height: 1.65; }
  .err-code { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.32em; color: var(--color-silver); text-transform: uppercase; display: inline-block; padding: 6px 14px; border: 1px solid var(--color-hairline-bright); margin-bottom: 32px; }

  /* CTA row */
  .e-cta { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-bottom: 56px; }

  /* Search */
  .e-search { max-width: 480px; margin: 0 auto 64px; position: relative; }
  .e-search input {
    width: 100%; background: var(--color-gunmetal); border: 1px solid var(--color-hairline-bright);
    color: var(--color-white); font-family: var(--font-body); font-size: 15px;
    padding: 16px 16px 16px 48px; outline: none;
    transition: border-color 200ms var(--ease-std), box-shadow 200ms var(--ease-std);
  }
  .e-search input:focus { border-color: var(--color-brass); box-shadow: 0 0 0 1px var(--color-brass); }
  .e-search svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--color-silver); }

  /* Suggested links */
  .e-grid {
    max-width: 920px; margin: 0 auto;
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;
    padding-top: 36px; border-top: 1px solid var(--color-hairline);
  }
  @media (max-width: 760px) { .e-grid { grid-template-columns: repeat(2, 1fr); } }
  .e-grid .hd-row { grid-column: 1/-1; display: flex; justify-content: space-between; align-items: baseline; margin-bottom: -4px; }
  .e-grid .hd-row .h { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.28em; color: var(--color-brass-bright); text-transform: uppercase; }
  .e-grid .hd-row .s { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }
  .e-grid a {
    background: var(--color-gunmetal); border: 1px solid var(--color-hairline);
    padding: 18px 16px; text-decoration: none;
    transition: all 220ms var(--ease-std);
    display: block; text-align: left;
  }
  .e-grid a:hover { transform: translateY(-3px); border-color: var(--color-brass-dim); }
  .e-grid a .ic { width: 26px; height: 26px; border: 1px solid var(--color-brass-dim); color: var(--color-brass-bright); display: grid; place-items: center; margin-bottom: 10px; }
  .e-grid a .t { font-family: var(--font-condensed); font-weight: 600; font-size: 13px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .e-grid a .d { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.16em; color: var(--color-silver); text-transform: uppercase; margin-top: 4px; }

  .e-foot { margin-top: 56px; font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; }
  .e-foot a { color: var(--color-brass-bright); text-decoration: none; }</style>
<main class="e-shell">
    <div class="e-inner">
      <div class="stamp">Off Target</div>

      <div class="err-code">ERR  HTTP 404  MISSING</div>

      <div class="num-wrap" aria-label="Error 404">
        <span class="digit" data-d="4">4</span><span class="digit zero" data-d="0">0<span class="hole"></span></span><span class="digit" data-d="4">4</span>
        <span class="crack c1"></span>
        <span class="crack c2"></span>
        <span class="crack c3"></span>
        <span class="crack c4"></span>
      </div>

      <h1>You <em>missed</em> the target.</h1>
      <p class="lead">That page got away. It may have been moved, retired, or never existed. No casualties  let's get you somewhere useful.</p>

      <div class="e-cta">
        <a href="<?php echo esc_url( home_url( "/" ) ); ?>" class="btn btn-ember btn-lg"> Return Home</a>
        <button class="btn btn-brass btn-lg" onclick="history.back()">Go Back One Step</button>
      </div>

      <div class="e-search">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <input type="text" placeholder="Search the site or knowledge hub" />
      </div>

      <div class="e-grid">
        <div class="hd-row">
          <span class="h">Try One Of These</span>
          <span class="s">Popular destinations</span>
        </div>
        <a href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="5" width="18" height="16" rx="1"/><line x1="3" y1="9" x2="21" y2="9"/></svg></div>
          <div class="t">Book A Lane</div>
          <div class="d">Reserve  Mesa AZ</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg></div>
          <div class="t">CCW Class</div>
          <div class="d">37-state reciprocity</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 7h14l-1.5 11h-11z"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/></svg></div>
          <div class="t">Pro Shop</div>
          <div class="d">Handguns  Ammo</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/memberships/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="6" width="18" height="12" rx="1"/><line x1="3" y1="11" x2="21" y2="11"/></svg></div>
          <div class="t">Membership</div>
          <div class="d">From <?php echo esc_html( function_exists( 'g2a_plan_price_from_fmt' ) ? g2a_plan_price_from_fmt() : '$29.99' ); ?>/mo</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/blog/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M4 4h12a4 4 0 0 1 4 4v12H8a4 4 0 0 1-4-4z"/><line x1="8" y1="9" x2="16" y2="9"/><line x1="8" y1="13" x2="16" y2="13"/></svg></div>
          <div class="t">Knowledge Hub</div>
          <div class="d">12 Articles  Guides</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/machine-gun/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="10" width="14" height="4"/><line x1="18" y1="12" x2="22" y2="12"/></svg></div>
          <div class="t">Machine Gun</div>
          <div class="d">MP5  M16  AK-47</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/transfers/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="6" width="16" height="14"/><path d="M8 6V4h8v2"/></svg></div>
          <div class="t">FFL Transfer</div>
          <div class="d">$35 flat  Same day</div>
        </a>
        <a href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">
          <div class="ic"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 16.92V20a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.86 19.86 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
          <div class="t">Contact</div>
          <div class="d"><?php echo esc_html( $g2a_biz['phone'] ?? '(602) 715-2677' ); ?></div>
        </a>
      </div>

      <div class="e-foot">
        Still lost? <a href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">Tell us what you were looking for </a>
      </div>
    </div>
  </main>
<?php get_footer();
