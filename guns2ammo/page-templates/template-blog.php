<?php
/**
 * Template Name: Blog
 *
 * Source: design/blog.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<style>/* ============ HERO ============ */
  .kh-hero { padding: 140px 32px 64px; position: relative; overflow: hidden; }
  .kh-hero::before {
    content: ""; position: absolute; inset: 0;
    background-image:
      repeating-linear-gradient(45deg, rgba(255,255,255,0.025) 0 1px, transparent 1px 18px),
      repeating-linear-gradient(-45deg, rgba(255,255,255,0.025) 0 1px, transparent 1px 18px);
    pointer-events: none;
  }
  .kh-hero::after {
    content: ""; position: absolute; right: -120px; top: 80px;
    width: 480px; height: 480px;
    background: radial-gradient(circle, rgba(201,168,76,0.08), transparent 60%);
    pointer-events: none;
  }
  .kh-hero .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1.3fr 1fr; gap: 64px; align-items: center; position: relative; z-index: 1; }
  @media (max-width: 980px) { .kh-hero .wrap { grid-template-columns: 1fr; gap: 48px; } }
  .kh-hero h1 { font-family: var(--font-display); font-size: clamp(64px, 8vw, 104px); line-height: 0.92; letter-spacing: 0.005em; color: var(--color-white); margin: 18px 0 22px; }
  .kh-hero h1 em { font-style: normal; color: var(--color-brass-bright); }
  .kh-hero .sub { font-size: 17px; color: var(--color-fog); max-width: 560px; line-height: 1.7; }
  .kh-search { margin-top: 32px; max-width: 520px; position: relative; }
  .kh-search input {
    width: 100%; background: var(--color-gunmetal); border: 1px solid var(--color-hairline-bright);
    color: var(--color-white); font-family: var(--font-body); font-size: 15px;
    padding: 16px 16px 16px 48px; outline: none;
    transition: border-color 200ms var(--ease-std), box-shadow 200ms var(--ease-std);
  }
  .kh-search input:focus { border-color: var(--color-brass); box-shadow: 0 0 0 1px var(--color-brass); }
  .kh-search svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--color-silver); }
  .kh-search input::placeholder { color: var(--color-silver); }

  /* Featured card */
  .kh-feat {
    background: var(--color-gunmetal); border: 1px solid var(--color-hairline-bright);
    padding: 36px; position: relative; overflow: hidden;
    box-shadow: 0 24px 64px rgba(0,0,0,0.45);
  }
  .kh-feat::before {
    content: ""; position: absolute; inset: 0;
    background-image:
      linear-gradient(135deg, rgba(201,168,76,0.10), transparent 50%),
      repeating-linear-gradient(135deg, transparent 0 22px, rgba(201,168,76,0.02) 22px 23px);
    pointer-events: none;
  }
  .kh-feat .ph {
    aspect-ratio: 16/9; margin: -36px -36px 24px;
    background:
      linear-gradient(180deg, rgba(26,25,30,0.2), rgba(26,25,30,0.7)),
      linear-gradient(135deg, #2A1F0E 0%, #14100A 60%, var(--color-void) 100%);
    position: relative;
  }
  .kh-feat .ph::after {
    content: "FEATURED  CCW 2025"; position: absolute; bottom: 16px; left: 16px;
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.3em;
    color: var(--color-brass-bright); text-transform: uppercase;
  }
  .kh-feat .lab { position: absolute; top: 20px; left: 20px; z-index: 2;
    background: var(--color-brass); color: #111; padding: 6px 14px;
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em;
    font-weight: 700; text-transform: uppercase;
  }
  .kh-feat h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 26px; line-height: 1.15; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.02em; margin: 0 0 16px; position: relative; }
  .kh-feat .meta { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; margin-bottom: 22px; position: relative; }
  .kh-feat .more { position: relative; }

  /* ============ CATEGORY BAR ============ */
  .kh-cats { position: sticky; top: 80px; z-index: 50;
    background: rgba(26,25,30,0.92); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
    border-top: 1px solid var(--color-hairline);
    border-bottom: 1px solid var(--color-hairline);
    padding: 18px 32px;
  }
  .kh-cats .scroll { max-width: 1280px; margin: 0 auto; display: flex; gap: 10px; overflow-x: auto; scrollbar-width: none; }
  .kh-cats .scroll::-webkit-scrollbar { display: none; }
  .kh-pill {
    background: transparent; border: 1px solid var(--color-hairline-bright);
    color: var(--color-fog);
    font-family: var(--font-condensed); font-weight: 600; font-size: 12px;
    letter-spacing: 0.16em; text-transform: uppercase;
    padding: 10px 18px; border-radius: 999px;
    cursor: pointer; white-space: nowrap;
    transition: all 200ms var(--ease-std);
  }
  .kh-pill:hover { color: var(--color-white); border-color: var(--color-brass-dim); }
  .kh-pill.active { background: var(--color-brass); color: #111; border-color: var(--color-brass); }

  /* ============ ARTICLE GRID ============ */
  .kh-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; max-width: 1280px; margin: 0 auto; padding: var(--space-3xl) 32px; }
  @media (max-width: 980px) { .kh-grid { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 640px) { .kh-grid { grid-template-columns: 1fr; padding: var(--space-2xl) 16px; } }

  .article-card {
    background: var(--color-gunmetal); border: 1px solid var(--color-hairline);
    display: flex; flex-direction: column; text-decoration: none;
    transition: transform 280ms var(--ease-std), border-color 280ms var(--ease-std), box-shadow 280ms var(--ease-std);
    position: relative; overflow: hidden;
  }
  .article-card:hover {
    transform: translateY(-6px);
    border-color: var(--color-brass-dim);
    box-shadow: 0 20px 48px rgba(0,0,0,0.5);
  }
  .article-card .accent { height: 3px; background: var(--color-brass); }
  .article-card[data-category="ccw"]       .accent { background: #C9A84C; }
  .article-card[data-category="safety"]    .accent { background: #E8802F; }
  .article-card[data-category="training"]  .accent { background: #4ADE80; }
  .article-card[data-category="gear"]      .accent { background: #B86F3D; }
  .article-card[data-category="laws"]      .accent { background: #60A5FA; }
  .article-card[data-category="beginners"] .accent { background: #E8E8EA; }
  .article-card[data-category="news"]      .accent { background: #FF9F0A; }

  .article-card .thumb {
    aspect-ratio: 16/9; position: relative; overflow: hidden;
    background:
      linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)),
      repeating-linear-gradient(135deg, #1f1f23 0 14px, #18181b 14px 28px);
  }
  .article-card[data-category="ccw"] .thumb       { background: linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)), linear-gradient(135deg, #2A1F0E 0%, #14100A 70%); }
  .article-card[data-category="safety"] .thumb    { background: linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)), linear-gradient(135deg, #2C1508 0%, #14100A 70%); }
  .article-card[data-category="training"] .thumb  { background: linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)), linear-gradient(135deg, #0F2618 0%, #0A1410 70%); }
  .article-card[data-category="gear"] .thumb      { background: linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)), linear-gradient(135deg, #2A1A0E 0%, #14100A 70%); }
  .article-card[data-category="laws"] .thumb      { background: linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)), linear-gradient(135deg, #0E1A2A 0%, #0A100F 70%); }
  .article-card[data-category="beginners"] .thumb { background: linear-gradient(180deg, rgba(26,25,30,0.25), rgba(26,25,30,0.7)), linear-gradient(135deg, #1A1A1F 0%, #0A0A0F 70%); }

  .article-card .thumb::before {
    content: ""; position: absolute; inset: 0;
    background-image: repeating-linear-gradient(45deg, rgba(255,255,255,0.025) 0 1px, transparent 1px 12px);
  }
  .article-card .thumb::after {
    content: attr(data-thumb); position: absolute; bottom: 14px; left: 14px;
    font-family: var(--font-mono); font-size: 9px; letter-spacing: 0.3em; color: var(--color-silver); text-transform: uppercase;
  }
  .article-card .badge-cat {
    position: absolute; top: 14px; left: 14px;
    background: rgba(26,25,30,0.85); backdrop-filter: blur(8px);
    border: 1px solid var(--color-hairline-bright);
    color: var(--color-brass-bright);
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; text-transform: uppercase;
    padding: 5px 10px;
  }
  .article-card .body { padding: 22px 22px 24px; flex: 1; display: flex; flex-direction: column; }
  .article-card h4 {
    font-family: var(--font-condensed); font-weight: 600; font-size: 21px; line-height: 1.25;
    color: var(--color-white); text-transform: uppercase; letter-spacing: 0.02em;
    margin: 0 0 10px;
    transition: color 200ms var(--ease-std);
  }
  .article-card:hover h4 { color: var(--color-brass-bright); }
  .article-card p {
    font-size: 14px; color: var(--color-fog); line-height: 1.55; margin: 0 0 18px;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
  }
  .article-card .meta {
    margin-top: auto; padding-top: 14px; border-top: 1px solid var(--color-hairline);
    display: flex; justify-content: space-between; gap: 12px;
    font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase;
  }
  .article-card .meta .author { color: var(--color-fog); }
  .article-card .read-more {
    position: absolute; bottom: 22px; right: 22px;
    font-family: var(--font-condensed); font-weight: 600; font-size: 12px; letter-spacing: 0.18em;
    color: var(--color-brass-bright); text-transform: uppercase;
    opacity: 0; transform: translateX(-6px); transition: all 220ms var(--ease-std);
  }
  .article-card:hover .read-more { opacity: 1; transform: translateX(0); }

  /* Filter transitions */
  .article-card.filtered-out { opacity: 0; transform: translateY(12px) scale(0.97); pointer-events: none; }
  .article-card { transition: opacity 280ms var(--ease-std), transform 280ms var(--ease-std), border-color 280ms var(--ease-std), box-shadow 280ms var(--ease-std); }

  /* Featured row */
  .kh-featrow { max-width: 1280px; margin: 0 auto; padding: 64px 32px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
  @media (max-width: 900px) { .kh-featrow { grid-template-columns: 1fr; padding: 48px 16px 0; } }
  .kh-featrow .article-card .thumb { aspect-ratio: 16/8; }
  .kh-featrow .article-card h4 { font-size: 26px; }

  /* Empty state */
  .kh-empty {
    grid-column: 1 / -1; text-align: center; padding: 80px 24px;
    color: var(--color-silver); font-family: var(--font-mono); letter-spacing: 0.2em;
    text-transform: uppercase; font-size: 12px;
    display: none;
  }
  .kh-empty.show { display: block; }

  /* Newsletter band */
  .kh-news {
    margin-top: 0;
    background: var(--color-gunmetal);
    border-top: 1px solid var(--color-hairline-bright);
    border-bottom: 1px solid var(--color-hairline-bright);
    padding: 72px 32px;
    position: relative; overflow: hidden;
  }
  .kh-news::before {
    content: ""; position: absolute; inset: 0;
    background-image: repeating-linear-gradient(115deg, transparent 0 60px, rgba(201,168,76,0.04) 60px 61px);
    pointer-events: none;
  }
  .kh-news .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: center; position: relative; }
  @media (max-width: 880px) { .kh-news .wrap { grid-template-columns: 1fr; } }
  .kh-news h3 { font-family: var(--font-display); font-size: clamp(40px, 5vw, 56px); line-height: 0.95; letter-spacing: 0.01em; color: var(--color-white); margin: 0 0 14px; }
  .kh-news p { color: var(--color-fog); margin: 0; font-size: 16px; max-width: 48ch; }
  .kh-news form { display: flex; gap: 8px; }
  .kh-news input { flex: 1; background: var(--color-void); border: 1px solid var(--color-hairline-bright); color: var(--color-white); font-family: var(--font-body); font-size: 15px; padding: 16px; outline: none; }
  .kh-news input:focus { border-color: var(--color-brass); }
  .kh-news .fine { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.22em; color: var(--color-silver); text-transform: uppercase; margin-top: 14px; }</style>
<main>
    <!-- HERO -->
    <section class="kh-hero">
      <div class="wrap">
        <div>
          <div class="eyebrow">Firearms Knowledge Hub</div>
          <h1>Know more.<br /><em>Shoot smarter.</em></h1>
          <p class="sub">Guides, tips, and straight talk about firearms, carry laws, range safety, and everything in between  written by our NRA-certified instructors right here in Mesa, Arizona.</p>
          <div class="kh-search">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <input id="kh-search-input" type="text" placeholder="Search guides &amp; articles" />
          </div>
        </div>
        <a href="<?php echo esc_url( home_url( "/post/" ) ); ?>" class="kh-feat" style="text-decoration:none; display:block;">
          <div class="ph"></div>
          <div class="meta">CCW &amp; Carry Laws  8 min read  Updated Jan 2026</div>
          <h3>Arizona CCW Laws 2025: What Every Carrier Needs to Know</h3>
          <span class="more btn btn-brass btn-sm">Read Guide </span>
        </a>
      </div>
    </section>

    <!-- CATEGORY FILTER -->
    <div class="kh-cats">
      <div class="scroll">
        <button class="kh-pill active" data-cat="all">All Topics <span style="opacity:0.5; margin-left:6px;">12</span></button>
        <button class="kh-pill" data-cat="ccw">CCW &amp; Carry Laws</button>
        <button class="kh-pill" data-cat="safety">Range Safety</button>
        <button class="kh-pill" data-cat="training">Firearms Training</button>
        <button class="kh-pill" data-cat="gear">Gear &amp; Maintenance</button>
        <button class="kh-pill" data-cat="laws">Arizona Gun Laws</button>
        <button class="kh-pill" data-cat="beginners">Beginner's Guide</button>
        <button class="kh-pill" data-cat="news">Industry News</button>
      </div>
    </div>

    <!-- ARTICLE GRID -->
    <div class="kh-grid" id="kh-grid">

      <a class="article-card" data-category="ccw" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 01"><span class="badge-cat">CCW &amp; Carry</span></div>
        <div class="body">
          <h4>Arizona CCW Laws 2025: Complete Carrier's Guide</h4>
          <p>Everything you need to know about concealed carry in Arizona  permits, reciprocity, restrictions.</p>
          <div class="meta"><span class="author">G2A Instructor Team</span><span>8 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="beginners" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 02"><span class="badge-cat">Beginner's Guide</span></div>
        <div class="body">
          <h4>First Gun Purchase Checklist: 12 Things New Owners Must Know</h4>
          <p>Before you walk into the shop  what to ask, what to test, and what to leave on the shelf.</p>
          <div class="meta"><span class="author">Range Safety Team</span><span>6 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="ccw" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 03"><span class="badge-cat">CCW &amp; Carry</span></div>
        <div class="body">
          <h4>Can I Carry in My Car Without a CCW in Arizona?</h4>
          <p>The short answer is yes  here's the long answer with every nuance you should be aware of.</p>
          <div class="meta"><span class="author">M. Vasquez</span><span>4 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="ccw" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 04"><span class="badge-cat">CCW &amp; Carry</span></div>
        <div class="body">
          <h4>CCW Reciprocity: 37 States That Honor Your AZ Permit</h4>
          <p>An interactive guide to which states recognize your Arizona permit, and what to watch when you cross a line.</p>
          <div class="meta"><span class="author">G2A Instructor Team</span><span>5 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="safety" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 05"><span class="badge-cat">Range Safety</span></div>
        <div class="body">
          <h4>10 Range Safety Rules Every Shooter Must Know</h4>
          <p>The non-negotiables  from muzzle awareness to cease-fire protocol  before you ever load a magazine.</p>
          <div class="meta"><span class="author">RSO Mike Calder</span><span>3 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="safety" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 06"><span class="badge-cat">Range Safety</span></div>
        <div class="body">
          <h4>Safe Storage: How to Store Firearms at Home</h4>
          <p>Quick-access safes, biometric locks, and AZ-specific storage law  what works and what to skip.</p>
          <div class="meta"><span class="author">Range Safety Team</span><span>6 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="training" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 07"><span class="badge-cat">Training</span></div>
        <div class="body">
          <h4>Dry Fire Practice: How to Improve Your Accuracy at Home</h4>
          <p>Twenty minutes a day, three times a week  the protocol our instructors use with new students.</p>
          <div class="meta"><span class="author">D. Reyes, NRA</span><span>7 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="training" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 08"><span class="badge-cat">Training</span></div>
        <div class="body">
          <h4>Strong Hand vs. Weak Hand Drills for Beginners</h4>
          <p>Why your support hand is half the equation  and the four-drill progression for fixing it fast.</p>
          <div class="meta"><span class="author">G2A Instructor Team</span><span>5 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="gear" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 09"><span class="badge-cat">Gear</span></div>
        <div class="body">
          <h4>Best 9mm Ammo for Self-Defense in 2025</h4>
          <p>We tested twelve loads through gel and ballistic media. Here are the four we actually carry.</p>
          <div class="meta"><span class="author">Pro Shop Staff</span><span>9 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="gear" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 10"><span class="badge-cat">Gear</span></div>
        <div class="body">
          <h4>How to Clean a Glock: Step-by-Step Guide</h4>
          <p>Field-strip, scrub, oil, reassemble. Twelve minutes, no thread-locker required.</p>
          <div class="meta"><span class="author">Armorer J. Park</span><span>6 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="laws" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 11"><span class="badge-cat">AZ Gun Laws</span></div>
        <div class="body">
          <h4>Arizona Open Carry vs. Concealed Carry: Know the Difference</h4>
          <p>Constitutional carry, the CCW upgrade, and where each one quietly stops applying.</p>
          <div class="meta"><span class="author">G2A Instructor Team</span><span>4 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <a class="article-card" data-category="beginners" href="<?php echo esc_url( home_url( "/post/" ) ); ?>">
        <div class="accent"></div>
        <div class="thumb" data-thumb="ARTICLE 12"><span class="badge-cat">Beginner's Guide</span></div>
        <div class="body">
          <h4>Choosing Your First Handgun: Glock vs SIG vs Smith &amp; Wesson</h4>
          <p>An honest, brand-agnostic walk through what each does well  and which one fits you.</p>
          <div class="meta"><span class="author">Pro Shop Staff</span><span>8 min read</span></div>
        </div>
        <span class="read-more">Read more </span>
      </a>

      <div class="kh-empty" id="kh-empty">No articles match that filter or search yet  try a different topic.</div>
    </div>

    <!-- NEWSLETTER -->
    <section class="kh-news">
      <div class="wrap">
        <div>
          <div class="eyebrow" style="margin-bottom:10px;">The Range Brief</div>
          <h3>Join 2,000+ Mesa shooters.</h3>
          <p>Weekly guides, range updates, and exclusive member deals  straight from our instructors. No fluff.</p>
        </div>
        <div>
          <form class="g2a-newsletter-form"
                data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
                data-action="wpcf_newsletter_subscribe"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'wpcf_newsletter' ) ); ?>"
                data-source="blog">
            <input type="email" name="email" placeholder="your@email.com" required />
            <button type="submit" class="btn btn-ember">Subscribe</button>
            <span class="g2a-newsletter-status" role="status" aria-live="polite" style="display:block; margin-top:8px; font-size:13px;"></span>
          </form>
          <script>
          (function(){
            var f = document.currentScript.previousElementSibling;
            if (!f || f.tagName !== 'FORM') return;
            f.addEventListener('submit', function (e) {
              e.preventDefault();
              var btn = f.querySelector('button');
              var status = f.querySelector('.g2a-newsletter-status');
              var input = f.querySelector('input[type=email]');
              var original = btn.innerHTML;
              btn.disabled = true; btn.textContent = '…'; status.textContent = '';
              var fd = new FormData();
              fd.append('action', f.dataset.action);
              fd.append('_wpnonce', f.dataset.nonce);
              fd.append('email', input.value.trim());
              fd.append('source', f.dataset.source || 'blog');
              fetch(f.dataset.endpoint, { method: 'POST', credentials: 'same-origin', body: fd })
                .then(function (r) { return r.json().catch(function(){ return null; }); })
                .then(function (j) {
                  if (j && j.success) {
                    btn.textContent = '✓ Subscribed';
                    btn.classList.remove('btn-ember');
                    status.style.color = '#9DE05B';
                    status.textContent = (j.data && j.data.message) || 'Subscribed.';
                    input.value = '';
                  } else {
                    btn.disabled = false; btn.innerHTML = original;
                    status.style.color = '#E8802F';
                    status.textContent = (j && j.data && j.data.message) || 'Something went wrong.';
                  }
                })
                .catch(function () {
                  btn.disabled = false; btn.innerHTML = original;
                  status.style.color = '#E8802F';
                  status.textContent = 'Network error. Please try again.';
                });
            });
          })();
          </script>
          <div class="fine">No spam. Unsubscribe anytime.</div>
        </div>
      </div>
    </section>
  </main>

  
  <script>
    // Filter pills
    const pills = document.querySelectorAll('.kh-pill');
    const cards = document.querySelectorAll('.article-card');
    const empty = document.getElementById('kh-empty');
    const search = document.getElementById('kh-search-input');
    let currentCat = 'all';
    let currentQuery = '';

    function applyFilter() {
      let visible = 0;
      cards.forEach((card, i) => {
        const matchCat = currentCat === 'all' || card.dataset.category === currentCat;
        const text = (card.textContent || '').toLowerCase();
        const matchQ = !currentQuery || text.includes(currentQuery);
        const ok = matchCat && matchQ;
        if (ok) {
          card.style.display = '';
          requestAnimationFrame(() => {
            setTimeout(() => card.classList.remove('filtered-out'), i * 30);
          });
          visible++;
        } else {
          card.classList.add('filtered-out');
          setTimeout(() => { if (card.classList.contains('filtered-out')) card.style.display = 'none'; }, 320);
        }
      });
      empty.classList.toggle('show', visible === 0);
    }

    pills.forEach(p => p.addEventListener('click', () => {
      pills.forEach(x => x.classList.remove('active'));
      p.classList.add('active');
      currentCat = p.dataset.cat;
      applyFilter();
    }));
    search.addEventListener('input', e => {
      currentQuery = e.target.value.trim().toLowerCase();
      applyFilter();
    });
  </script>
<?php get_footer();
