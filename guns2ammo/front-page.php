<?php
/**
 * Home Page  Guns 2 Ammo
 *
 * Source: design/homepage.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$g2a_page_id            = get_the_ID();
$g2a_event_shortcode    = get_post_meta( $g2a_page_id, 'event_shortcode', true );
$g2a_mg_callout_text    = get_post_meta( $g2a_page_id, 'mg_callout_text', true );
$g2a_mg4_name           = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_name', true );
$g2a_mg4_caliber        = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_caliber', true );
$g2a_mg4_desc           = get_post_meta( $g2a_page_id, 'mg_fourth_weapon_desc', true );
?>
<style>/* ============ HERO ============ */
  .hero {
    position: relative;
    min-height: 100vh;
    padding: 140px 32px 80px;
    display: flex; align-items: center; justify-content: center;
    overflow: hidden;
    text-align: center;
  }
  .hero::before {
    content: ""; position: absolute; inset: 0;
    background-image:
      linear-gradient(180deg, rgba(26,25,30,0.75) 0%, rgba(26,25,30,0.85) 50%, rgba(26,25,30,0.95) 100%),
      url("https://guns2ammo.com/wp-content/uploads/2025/06/IMG_1055.webp");
    background-size: cover;
    background-position: center;
    filter: saturate(0.95) contrast(1.05);
  }
  .hero::after {
    content: ""; position: absolute; inset: 0;
    background: radial-gradient(ellipse at center, transparent 30%, rgba(26,25,30,0.5) 80%);
    pointer-events: none;
  }
  .hero .inner { position: relative; z-index: 2; max-width: 1100px; width: 100%; }
  .hero .eb-pill { animation: fadeUp 700ms var(--ease-out) both; }
  .hero h1 { margin-top: 28px; animation: fadeUp 800ms var(--ease-out) both; animation-delay: 100ms; }
  .hero .lead { color: var(--color-fog); font-size: 17px; line-height: 1.7; max-width: 56ch; margin: 32px auto 36px; animation: fadeUp 800ms var(--ease-out) both; animation-delay: 200ms; }
  .hero .ctas { display: flex; gap: 14px; justify-content: center; flex-wrap: wrap; animation: fadeUp 800ms var(--ease-out) both; animation-delay: 300ms; }

  .hero-trust { margin-top: 80px; animation: fadeUp 800ms var(--ease-out) both; animation-delay: 500ms; }
  .hero-scroll-wrap { padding: 32px 0 16px; display: flex; justify-content: center; }
  .hero-scroll { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.4em; color: var(--color-silver); text-transform: uppercase; display: flex; flex-direction: column; align-items: center; gap: 8px; }
  .hero-scroll::after { content:""; width: 1px; height: 32px; background: linear-gradient(180deg, var(--color-silver), transparent); animation: scroll-bob 2s ease-in-out infinite; }
  @keyframes scroll-bob { 0%,100% { opacity: 0.4; transform: translateY(0); } 50% { opacity: 1; transform: translateY(4px); } }

  /* ============ VALUE PROPS BAND ============ */
  .props { padding: 96px 32px; background: linear-gradient(180deg, var(--color-void) 0%, var(--color-surface-1) 100%); }
  .props .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 0; border: 1px solid var(--color-hairline); }
  @media (max-width: 980px) { .props .wrap { grid-template-columns: 1fr 1fr; } }
  @media (max-width: 540px) { .props .wrap { grid-template-columns: 1fr; } }
  .prop { padding: 32px 28px; border-right: 1px solid var(--color-hairline); position: relative; transition: background 250ms var(--ease-std); }
  .prop:hover { background: rgba(232,128,47,0.03); }
  .prop:nth-child(4n) { border-right: none; }
  @media (max-width: 980px) { .prop:nth-child(2n) { border-right: none; } .prop:nth-child(4n) { border-right: 1px solid var(--color-hairline); } .prop:nth-child(-n+2) { border-bottom: 1px solid var(--color-hairline); } }
  .prop .ic { width: 36px; height: 36px; border: 1px solid var(--color-brass-dim); display: grid; place-items: center; color: var(--color-brass-bright); margin-bottom: 18px; }
  .prop h3 { font-family: var(--font-condensed); font-weight: 600; font-size: 18px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .prop p { color: var(--color-fog); font-size: 14px; line-height: 1.6; margin: 6px 0 0; }

  /* ============ FOR CALIFORNIA RESIDENTS BAND ============ */
  .ca-band { padding: 80px 32px; background: linear-gradient(180deg, var(--color-surface-1) 0%, var(--color-surface-2) 100%); border-top: 1px solid var(--color-hairline); border-bottom: 1px solid var(--color-hairline); position: relative; overflow: hidden; }
  .ca-band::before {
    content: ""; position: absolute; right: -10%; top: -20%; width: 60%; height: 140%;
    background-image:
      linear-gradient(135deg, rgba(26,25,30,0.8) 0%, rgba(26,25,30,0.4) 100%),
      url("https://guns2ammo.com/wp-content/uploads/2026/01/ChatGPT-Image-Jan-30-2026-10_10_12-PM.webp");
    background-size: cover; background-position: center;
    opacity: 0.5;
    -webkit-mask-image: linear-gradient(90deg, transparent, black 40%);
            mask-image: linear-gradient(90deg, transparent, black 40%);
  }
  .ca-band .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1.4fr 1fr; gap: 64px; align-items: center; position: relative; }
  @media (max-width: 900px) { .ca-band .wrap { grid-template-columns: 1fr; } }
  .ca-band .eb { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.24em; color: var(--color-ember); text-transform: uppercase; margin-bottom: 18px; }
  .ca-band h2 { font-family: var(--font-display); font-size: clamp(40px, 5.5vw, 64px); line-height: 0.95; letter-spacing: 0.02em; color: var(--color-white); }
  .ca-band h2 .a { color: var(--color-ember); }
  .ca-band p { color: var(--color-fog); margin: 22px 0 0; max-width: 50ch; font-size: 15px; line-height: 1.7; }
  .ca-band .actions { display: flex; gap: 12px; flex-wrap: wrap; }
  .ca-band .actions .btn { padding: 16px 24px; font-size: 13px; letter-spacing: 0.14em; }

  /* ============ MACHINE GUN BAND (MOST IMPORTANT  MATCH REF) ============ */
  .mg { padding: 120px 32px; position: relative; overflow: hidden; background: linear-gradient(180deg, var(--color-surface-2) 0%, var(--color-surface-1) 100%); }
  .mg::before {
    content:""; position: absolute; inset: 0;
    background-image:
      radial-gradient(ellipse at 20% 50%, rgba(232,128,47,0.05), transparent 60%),
      repeating-linear-gradient(0deg, transparent 0 60px, rgba(255,255,255,0.02) 60px 61px),
      repeating-linear-gradient(90deg, transparent 0 60px, rgba(255,255,255,0.02) 60px 61px);
    pointer-events: none;
  }
  .mg .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; position: relative; }
  @media (max-width: 980px) { .mg .wrap { grid-template-columns: 1fr; } }
  .mg .left { display: flex; flex-direction: column; gap: 24px; }
  .mg .left .eb-pill { align-self: flex-start; }
  .mg .left h2 { font-family: var(--font-display); font-size: clamp(56px, 8vw, 104px); line-height: 0.92; letter-spacing: 0.005em; color: var(--color-white); }
  .mg .left h2 .a { color: var(--color-ember); }
  .mg .left .lead { color: var(--color-fog); font-size: 16px; line-height: 1.7; max-width: 50ch; margin: 0; }

  .mg-callout {
    padding: 16px 22px; border: 1px solid rgba(74,222,128,0.3); background: rgba(74,222,128,0.04);
    display: flex; align-items: center; gap: 12px; max-width: 480px;
  }
  .mg-callout .dot { width: 8px; height: 8px; border-radius: 50%; background: #4ADE80; box-shadow: 0 0 8px #4ADE80; flex: 0 0 auto; }
  .mg-callout .t { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; color: #86EFAC; text-transform: uppercase; }
  .mg-callout .t strong { color: #4ADE80; font-weight: 700; }

  .mg-bullets { display: grid; grid-template-columns: 1fr 1fr; gap: 18px 32px; margin-top: 8px; }
  @media (max-width: 600px) { .mg-bullets { grid-template-columns: 1fr; } }
  .mg-bullets li { color: var(--color-fog); font-size: 14px; line-height: 1.5; padding-left: 22px; position: relative; }
  .mg-bullets li::before { content: ""; position: absolute; left: 0; top: 0.45em; width: 8px; height: 8px; background: var(--color-ember); border-radius: 50%; box-shadow: 0 0 0 4px rgba(232,128,47,0.15); }

  .mg .actions { display: flex; gap: 14px; flex-wrap: wrap; margin-top: 8px; }
  .mg .right { display: flex; flex-direction: column; gap: 24px; }
  .mg .photo-card { border: 1px solid var(--color-brass-dim); padding: 12px; background: var(--color-gunmetal); position: relative; }
  .mg .photo-card .ph {
    aspect-ratio: 16/10;
    background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.4)), url("https://guns2ammo.com/wp-content/uploads/2025/03/IMG_20250317_085324.webp");
    background-size: cover; background-position: center;
    filter: saturate(0.95);
  }
  .mg .photo-card .cap { position: absolute; left: 50%; bottom: -10px; transform: translateX(-50%); padding: 6px 18px; background: var(--color-gunmetal); border: 1px solid var(--color-brass-dim); font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.32em; color: var(--color-brass-bright); text-transform: uppercase; white-space: nowrap; }

  .arsenal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 18px; }
  .arsenal-item { padding: 18px 20px; background: var(--color-void); border: 1px solid var(--color-hairline); transition: border-color 250ms var(--ease-std); }
  .arsenal-item:hover { border-color: var(--color-brass-dim); }
  .arsenal-item .nm { font-family: var(--font-display); font-size: 22px; color: var(--color-white); letter-spacing: 0.04em; line-height: 1; }
  .arsenal-item .cal { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.18em; color: var(--color-ember); text-transform: uppercase; margin-top: 4px; }
  .arsenal-item .desc { font-size: 12px; color: var(--color-silver); margin-top: 8px; line-height: 1.5; }

  .mg-reviews {
    display: flex; align-items: center; gap: 14px; padding: 18px 0 0; border-top: 1px solid var(--color-hairline); margin-top: 8px;
  }
  .mg-reviews .star { color: var(--color-brass-bright); font-family: var(--font-mono); font-weight: 700; font-size: 24px; }
  .mg-reviews .num { font-family: var(--font-condensed); font-weight: 700; font-size: 16px; color: var(--color-white); letter-spacing: 0.04em; }
  .mg-reviews .meta { font-family: var(--font-mono); font-size: 11px; letter-spacing: 0.16em; color: var(--color-silver); text-transform: uppercase; line-height: 1.4; }
  .mg-reviews .meta small { display: block; opacity: 0.7; }

  /* ============ COLLECTIONS GRID ============ */
  .cols { padding: 120px 32px; background: linear-gradient(180deg, var(--color-surface-1) 0%, var(--color-void) 100%); }
  .cols .wrap { max-width: 1280px; margin: 0 auto; }
  .cols .head { display: flex; align-items: end; justify-content: space-between; gap: 32px; margin-bottom: 48px; flex-wrap: wrap; }
  .cols .head .l h2 { font-family: var(--font-display); font-size: clamp(48px, 6vw, 80px); line-height: 0.95; letter-spacing: 0.01em; color: var(--color-white); }
  .cols .head .l h2 .a { color: var(--color-ember); }
  .cols .head .l p { color: var(--color-fog); margin: 16px 0 0; max-width: 56ch; }

  .col-grid { display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 16px; }
  @media (max-width: 980px) { .col-grid { grid-template-columns: 1fr 1fr; } .col-grid .feat { grid-column: 1 / -1; } }
  @media (max-width: 540px) { .col-grid { grid-template-columns: 1fr; } }

  .col-tile {
    position: relative;
    aspect-ratio: 1;
    overflow: hidden;
    border: 1px solid var(--color-hairline);
    text-decoration: none;
    transition: all 280ms var(--ease-std);
    display: block;
  }
  .col-tile.feat { aspect-ratio: 16/9; grid-column: 1 / span 1; grid-row: span 2; }
  @media (max-width: 980px) { .col-tile.feat { aspect-ratio: 16/9; grid-row: auto; } }
  .col-tile:hover { transform: translateY(-4px); border-color: var(--color-brass); }
  .col-tile .bg { position: absolute; inset: 0; background-size: cover; background-position: center; filter: saturate(0.9); transition: transform 700ms var(--ease-out); }
  .col-tile:hover .bg { transform: scale(1.05); }
  .col-tile .scrim { position: absolute; inset: 0; background: linear-gradient(180deg, rgba(26,25,30,0.2) 0%, rgba(26,25,30,0.85) 80%); }
  .col-tile .body { position: absolute; left: 0; right: 0; bottom: 0; padding: 24px; }
  .col-tile .cat { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.28em; color: var(--color-brass-bright); text-transform: uppercase; }
  .col-tile h3 { font-family: var(--font-display); font-size: clamp(28px, 3.5vw, 52px); line-height: 1; color: var(--color-white); letter-spacing: 0.02em; margin: 8px 0 0; }
  .col-tile .more { display: inline-flex; align-items: center; gap: 8px; margin-top: 12px; font-family: var(--font-condensed); font-weight: 600; font-size: 12px; letter-spacing: 0.16em; color: var(--color-ember); text-transform: uppercase; opacity: 0; transform: translateY(6px); transition: all 280ms var(--ease-out); }
  .col-tile:hover .more { opacity: 1; transform: translateY(0); }

  /* ============ HOURS / VISIT BAND ============ */
  .visit { padding: 100px 32px; background: linear-gradient(180deg, var(--color-void) 0%, var(--color-surface-1) 100%); }
  .visit .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1.2fr; gap: 56px; align-items: stretch; }
  @media (max-width: 900px) { .visit .wrap { grid-template-columns: 1fr; } }
  .visit-info { padding: 36px; background: var(--color-gunmetal); border: 1px solid var(--color-hairline); }
  .visit-info h2 { font-family: var(--font-display); font-size: clamp(36px, 4.5vw, 56px); line-height: 1; color: var(--color-white); margin: 0 0 8px; letter-spacing: 0.02em; }
  .visit-info .addr { font-family: var(--font-condensed); font-weight: 500; font-size: 17px; color: var(--color-fog); line-height: 1.5; margin: 0 0 24px; }
  .hours { display: grid; gap: 0; padding: 0; border-top: 1px solid var(--color-hairline); }
  .hours .row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--color-hairline); font-family: var(--font-mono); font-size: 12px; letter-spacing: 0.18em; text-transform: uppercase; }
  .hours .row .d { color: var(--color-silver); }
  .hours .row .t { color: var(--color-white); }
  .hours .row.closed .t { color: var(--color-silver); }
  .hours .row.now { background: rgba(232,128,47,0.04); padding: 12px 16px; margin: 0 -16px; border-left: 2px solid var(--color-ember); }
  .hours .row.now .d { color: var(--color-ember); }
  .visit-info .actions { display: flex; gap: 10px; margin-top: 28px; flex-wrap: wrap; }
  .visit-photo { aspect-ratio: 16/12; background-image: linear-gradient(180deg, rgba(0,0,0,0.1), rgba(0,0,0,0.5)), url("https://guns2ammo.com/wp-content/uploads/2024/08/woman-with-handgun-at-indoor-shooting-range.webp"); background-size: cover; background-position: center; border: 1px solid var(--color-hairline); filter: saturate(0.9); position: relative; }
  .visit-photo .badge { position: absolute; top: 18px; left: 18px; padding: 8px 14px; background: rgba(26,25,30,0.85); backdrop-filter: blur(8px); border: 1px solid var(--color-brass-dim); font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.28em; color: var(--color-brass-bright); text-transform: uppercase; }

  /* ============ STORY / ABOUT BAND ============ */
  .story { padding: 120px 32px; background: linear-gradient(180deg, var(--color-surface-1) 0%, var(--color-surface-2) 100%); position: relative; overflow: hidden; }
  .story::before {
    content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 40%;
    background-image: linear-gradient(90deg, rgba(26,25,30,0.95), rgba(26,25,30,0.4)), url("https://guns2ammo.com/wp-content/uploads/2024/06/IMG_8947-1-scaled.webp");
    background-size: cover; background-position: center;
    -webkit-mask-image: linear-gradient(90deg, black, transparent);
            mask-image: linear-gradient(90deg, black, transparent);
    opacity: 0.5;
  }
  .story .wrap { max-width: 1280px; margin: 0 auto; display: grid; grid-template-columns: 1fr 1fr; gap: 64px; align-items: center; position: relative; }
  @media (max-width: 900px) { .story .wrap { grid-template-columns: 1fr; } }
  .story .stats { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
  .story .stat { padding: 22px; border-top: 1px solid var(--color-hairline); border-right: 1px solid var(--color-hairline); }
  .story .stat:nth-child(2n) { border-right: none; }
  .story .stat:nth-child(-n+2) { border-top: none; }
  .story .stat .v { font-family: var(--font-display); font-size: 56px; line-height: 0.9; color: var(--color-ember); letter-spacing: 0.02em; }
  .story .stat .l { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.24em; color: var(--color-silver); text-transform: uppercase; margin-top: 6px; }
  .story h2 { font-family: var(--font-display); font-size: clamp(48px, 6vw, 80px); line-height: 0.95; letter-spacing: 0.01em; color: var(--color-white); }
  .story h2 .a { color: var(--color-ember); }
  .story p { color: var(--color-fog); font-size: 16px; line-height: 1.75; margin: 24px 0 32px; max-width: 50ch; }

  /* ============ REVIEWS ============ */
  .reviews { padding: 120px 32px; }
  .reviews .wrap { max-width: 1280px; margin: 0 auto; }
  .reviews .head { display: flex; align-items: end; justify-content: space-between; gap: 32px; margin-bottom: 48px; flex-wrap: wrap; }
  .reviews .head h2 { font-family: var(--font-display); font-size: clamp(48px, 6vw, 80px); line-height: 0.95; color: var(--color-white); letter-spacing: 0.01em; }
  .reviews .head h2 .a { color: var(--color-ember); }
  .reviews .stars-meta { font-family: var(--font-mono); font-size: 13px; color: var(--color-fog); letter-spacing: 0.06em; }
  .reviews .stars-meta .num { font-family: var(--font-display); font-size: 56px; color: var(--color-brass-bright); line-height: 1; display: block; letter-spacing: 0.02em; }
  .reviews .stars-meta .stars { color: var(--color-brass-bright); font-weight: 700; }
  .rv-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
  @media (max-width: 900px) { .rv-grid { grid-template-columns: 1fr; } }
  .rv { padding: 28px; background: var(--color-gunmetal); border: 1px solid var(--color-hairline); position: relative; transition: all 280ms var(--ease-std); }
  .rv:hover { border-color: var(--color-brass-dim); transform: translateY(-4px); }
  .rv .quote { font-family: var(--font-display); font-size: 96px; color: var(--color-brass); position: absolute; top: 8px; right: 22px; line-height: 1; opacity: 0.18; }
  .rv .stars { color: var(--color-brass-bright); font-family: var(--font-mono); letter-spacing: 0.1em; margin-bottom: 14px; }
  .rv p { color: var(--color-fog); font-size: 15px; line-height: 1.7; margin: 0 0 22px; }
  .rv .who { display: flex; gap: 12px; align-items: center; padding-top: 18px; border-top: 1px solid var(--color-hairline); }
  .rv .av { width: 36px; height: 36px; border-radius: 50%; background: var(--color-brass); color: #111; display: grid; place-items: center; font-family: var(--font-display); font-size: 14px; }
  .rv .nm { font-family: var(--font-condensed); font-weight: 600; font-size: 13px; color: var(--color-white); text-transform: uppercase; letter-spacing: 0.04em; }
  .rv .src { font-family: var(--font-mono); font-size: 10px; letter-spacing: 0.2em; color: var(--color-silver); text-transform: uppercase; }

  /* ============ FINAL CTA ============ */
  .final {
    padding: 140px 32px; text-align: center; position: relative; overflow: hidden;
  }
  .final::before {
    content: ""; position: absolute; inset: 0;
    background-image:
      linear-gradient(180deg, rgba(26,25,30,0.85), rgba(26,25,30,0.95)),
      url("https://guns2ammo.com/wp-content/uploads/2025/06/IMG_1055.webp");
    background-size: cover; background-position: center;
    filter: saturate(0.85);
  }
  .final::after {
    content:""; position: absolute; inset: 0;
    background: radial-gradient(circle at center, rgba(232,128,47,0.06), transparent 60%);
    pointer-events: none;
  }
  .final .wrap { position: relative; max-width: 800px; margin: 0 auto; }
  .final h2 { font-family: var(--font-display); font-size: clamp(56px, 8vw, 112px); line-height: 0.92; letter-spacing: 0.005em; color: var(--color-white); margin: 18px 0; }
  .final h2 .a { color: var(--color-ember); }
  .final p { color: var(--color-fog); font-size: 17px; max-width: 50ch; margin: 0 auto 36px; line-height: 1.6; }
  .final .ctas { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

  /* ==== HERO  bulletproof mobile layout (overrides all hero styling) ==== */
  @media (max-width: 768px) {
    .hero { display: block !important; min-height: 0 !important; height: auto !important;
      padding: 100px 18px 44px !important; text-align: center !important; }
    .hero .inner { position: static !important; max-width: 100% !important; width: 100% !important;
      display: block !important; margin: 0 !important; }
    .hero .inner > * { position: static !important; float: none !important; clear: both !important;
      width: auto !important; max-width: 100% !important; }
    .hero .eb-pill { display: inline-flex !important; max-width: 92% !important;
      white-space: normal !important; line-height: 1.4 !important; border-radius: 14px !important;
      font-size: 9px !important; letter-spacing: 0.1em !important; padding: 7px 13px !important; }
    .hero h1, .hero .hl-display { font-size: clamp(32px, 11vw, 52px) !important;
      line-height: 1.02 !important; margin: 16px 0 0 !important; word-break: normal !important; }
    .hero .lead { font-size: 15px !important; line-height: 1.6 !important;
      margin: 14px auto 20px !important; max-width: 100% !important; }
    .hero .ctas { display: flex !important; flex-direction: column !important; gap: 10px !important;
      align-items: stretch !important; margin: 0 !important; }
    .hero .ctas .btn { width: 100% !important; justify-content: center !important; }
    /* Trust strip stacks vertically  never beside the headline/lead */
    .hero-trust { margin-top: 26px !important; width: 100% !important; }
    .hero-trust, .hero .trust-strip { display: block !important; width: 100% !important;
      padding: 0 !important; }
    .hero .trust-strip .item { display: flex !important; justify-content: center !important;
      align-items: center !important; width: 100% !important; margin: 9px 0 !important;
      font-size: 10px !important; }
    .hero-scroll-wrap { display: none !important; }
  }</style>
<!-- HERO -->
<section class="hero">
  <div class="inner">
    <span class="eb-pill">Arizona's Premier Indoor Range  Mesa, AZ</span>
    <h1 class="hl-display">
      SHOOT SMART.<br>
      CARRY SAFE.<br>
      <span class="a">TRAIN LIKE A PRO.</span>
    </h1>
    <p class="lead">6-lane climate-controlled indoor range, FFL-licensed firearm sales, and NRA-certified training  all under one roof in Mesa. Walk in, book a lane, or shop Mesa's most-trusted arsenal.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">Shop Collections</a>
    </div>
    <div class="hero-trust">
      <div class="trust-strip">
        <span class="item"><span class="stars"> 4.7</span> 449+ Google Reviews</span>
        <span class="item">NRA Certified</span>
        <span class="item">FFL Licensed</span>
        <span class="item">Est. 2015</span>
      </div>
    </div>
  </div>
  <div class="hero-scroll-wrap"><div class="hero-scroll">Scroll</div></div>
</section>

<?php if ( function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
<section class="g2a-plugin-host" style="padding:24px 32px 0;max-width:1280px;margin:0 auto;">
  <?php echo do_shortcode( $g2a_event_shortcode ? $g2a_event_shortcode : '[g2a_event_banner]' ); ?>
</section>
<?php endif; ?>

<!-- VALUE PROPS -->
<section class="props">
  <div class="wrap">
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2" fill="currentColor"/></svg></div>
      <h3>6-Lane Indoor Range</h3>
      <p>Climate-controlled, 25-yard, pistol &amp; rifle approved. Lanes 1, 2, 7 ADA-accessible.</p>
    </div>
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2 L20 5 V12 C20 17 16 21 12 22 C8 21 4 17 4 12 V5 Z"/><path d="M9 12 L11 14 L15 10"/></svg></div>
      <h3>NRA Certified Training</h3>
      <p>State-approved CCW, women's intro, defensive pistol  taught by NRA instructors.</p>
    </div>
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="4" y="6" width="16" height="14"/><path d="M8 6 V4 H16 V6"/><circle cx="12" cy="13" r="2"/></svg></div>
      <h3>FFL-Licensed Sales</h3>
      <p>Handguns, rifles, NFA. Out-of-state transfers $35 flat. Same-day in stock.</p>
    </div>
    <div class="prop">
      <div class="ic"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="12" r="10"/><path d="M8 12 L10.5 14.5 L16 9"/></svg></div>
      <h3>Member Pricing</h3>
      <p>Unlimited range time from $29.99/mo. Cancel anytime. No contracts.</p>
    </div>
  </div>
</section>

<!-- CALIFORNIA CCW BAND -->
<section class="ca-band">
  <div class="wrap">
    <div>
      <div class="eb">For California Residents</div>
      <h2>CALIFORNIA CCW<br>SHOOTING QUALIFICATION<br><span class="a">IN MESA, AZ</span></h2>
      <p>This is the live-fire qualification component only for California CCW applicants. It does not replace California-required classroom education or training modules.</p>
    </div>
    <div class="actions">
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#ca">Reserve Your Time</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>#ca">Course Details</a>
    </div>
  </div>
</section>

<!-- MACHINE GUN BAND  MATCHES REFERENCE -->
<section class="mg">
  <div class="wrap">
    <div class="left">
      <span class="eb-pill">Exclusive  Limited Availability</span>
      <h2>SHOOT A<br>MACHINE GUN<br><span class="a">IN MESA.</span></h2>
      <p class="lead">Live fire. Select fire. No prior experience needed. Walk in as a first-timer, walk out with the story of your life.</p>
      <div class="mg-callout">
        <span class="dot"></span>
        <span class="t"><?php echo esc_html( $g2a_mg_callout_text ? $g2a_mg_callout_text : 'Signature machine gun experience packages available now.' ); ?></span>
      </div>
      <ul class="mg-bullets" style="list-style: none;">
        <li>Full-auto instruction included  one-on-one with a certified pro</li>
        <li>No firearms experience required  we walk you through every step</li>
        <li>Mesa's only indoor full-auto experience  climate-controlled, safe</li>
        <li>Photos &amp; video welcome  capture the moment, share the brag</li>
      </ul>
      <div class="actions">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/machine-gun/" ) ); ?>">Book Your Experience</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/machine-gun/" ) ); ?>#tiers">View Packages</a>
      </div>
      <div class="mg-reviews">
        <span class="num"><span class="star"></span> 4.7</span>
        <div class="meta">449+ Google reviews<small>Mesa's most-trusted indoor range since 2015</small></div>
      </div>
    </div>
    <div class="right">
      <div class="photo-card">
        <div class="ph"></div>
        <span class="cap"> The Arsenal </span>
      </div>
      <div class="arsenal-grid">
        <div class="arsenal-item">
          <div class="nm">M240</div>
          <div class="cal">7.6251 NATO</div>
          <div class="desc">Belt-fed, battlefield-proven</div>
        </div>
        <div class="arsenal-item">
          <div class="nm">M4 AUTO</div>
          <div class="cal">5.5645 NATO</div>
          <div class="desc">Select fire, modern service rifle</div>
        </div>
        <div class="arsenal-item">
          <div class="nm">MP5</div>
          <div class="cal">919 PARABELLUM</div>
          <div class="desc">Iconic German SMG, full auto</div>
        </div>
        <div class="arsenal-item">
          <div class="nm"><?php echo esc_html( $g2a_mg4_name ? $g2a_mg4_name : 'GLOCK 18' ); ?></div>
          <div class="cal"><?php echo esc_html( $g2a_mg4_caliber ? $g2a_mg4_caliber : '9x19 PARABELLUM' ); ?></div>
          <div class="desc"><?php echo esc_html( $g2a_mg4_desc ? $g2a_mg4_desc : 'Select-fire machine pistol platform' ); ?></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- COLLECTIONS -->
<section class="cols">
  <div class="wrap">
    <div class="head">
      <div class="l">
        <span class="eb-pill">Mesa's Most-Trusted Arsenal</span>
        <h2 style="margin-top: 22px;">SHOP THE<br>COLLECTIONS<span class="a">.</span></h2>
        <p>FFL-licensed inventory hand-picked by our instructors. Same-day pickup, no waiting list.</p>
      </div>
      <a class="btn btn-ghost btn-arrow" href="<?php echo esc_url( home_url( "/shop/" ) ); ?>">View All Products</a>
    </div>

    <div class="col-grid">
      <a class="col-tile feat" href="<?php echo esc_url( home_url( "/collections/handguns/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.9)), url('https://guns2ammo.com/wp-content/uploads/2026/04/15.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">Featured Collection  38 SKUs</div>
          <h3>HANDGUNS</h3>
          <span class="more">Shop Handguns </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/collections/rifles/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2025/06/noveske-gen4-556-sbr-bazooka-green-guns2ammo-mesa-az.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">24 SKUs</div>
          <h3>RIFLES</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/collections/ammunition/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2026/04/Gun-Rental-Near-Me-What-to-Expect.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">52 SKUs</div>
          <h3>AMMUNITION</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/collections/magazines/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2026/04/2025-03-23.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">18 SKUs</div>
          <h3>MAGAZINES</h3>
          <span class="more">Shop </span>
        </div>
      </a>
      <a class="col-tile" href="<?php echo esc_url( home_url( "/ffl-services/" ) ); ?>">
        <div class="bg" style="background-image: linear-gradient(180deg, rgba(26,25,30,0.3), rgba(26,25,30,0.85)), url('https://guns2ammo.com/wp-content/uploads/2024/08/one-1-a.webp');"></div>
        <div class="scrim"></div>
        <div class="body">
          <div class="cat">$25 Flat Fee</div>
          <h3>TRANSFERS</h3>
          <span class="more">Start </span>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- STORY / TRAINING ACADEMY -->
<section class="story">
  <div class="wrap">
    <div>
      <span class="eb-pill">Mesa's Training Academy</span>
      <h2 style="margin-top: 22px;">TRAINED HERE.<br>READY <span class="a">ANYWHERE.</span></h2>
      <p>From your first range visit to multi-state CCW certification, our NRA &amp; USCCA instructors take you through it patiently  and properly. Built by operators who've trained law enforcement, military, and church security teams.</p>
      <div style="display: flex; gap: 12px; flex-wrap: wrap;">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/training/" ) ); ?>">Browse Training</a>
        <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/arizona-ccw-certification/" ) ); ?>">CCW Pathway</a>
      </div>
    </div>
    <div class="stats">
      <div class="stat">
        <div class="v">5,000<span style="color:var(--color-brass-bright);">+</span></div>
        <div class="l">Students Trained</div>
      </div>
      <div class="stat">
        <div class="v">37</div>
        <div class="l">States Reciprocity</div>
      </div>
      <div class="stat">
        <div class="v">10</div>
        <div class="l">Years In Mesa</div>
      </div>
      <div class="stat">
        <div class="v">99.6<span style="color:var(--color-brass-bright);">%</span></div>
        <div class="l">CCW Pass Rate</div>
      </div>
    </div>
  </div>
</section>

<!-- COLLECTIONS / VISIT -->
<section class="visit">
  <div class="wrap">
    <div class="visit-info">
      <span class="eb-pill">Visit Us</span>
      <h2 style="margin-top: 20px;">6030 E Main St</h2>
      <p class="addr">Suite 103  Mesa, AZ 85205</p>
      <div class="hours">
        <div class="row now"><span class="d"> Today  Tue</span><span class="t">10am  6pm</span></div>
        <div class="row"><span class="d">Mon, Wed, Thu</span><span class="t">10am  6pm</span></div>
        <div class="row closed"><span class="d">Friday</span><span class="t">Closed</span></div>
        <div class="row"><span class="d">Saturday</span><span class="t">9am  8pm</span></div>
        <div class="row"><span class="d">Sunday</span><span class="t">12pm  6pm</span></div>
      </div>
      <div class="actions">
        <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/contact/" ) ); ?>">Get Directions</a>
        <a class="btn btn-ghost" href="tel:+16027152677">Call (602) 715-2677</a>
      </div>
    </div>
    <div class="visit-photo">
      <span class="badge"> Main Floor</span>
    </div>
  </div>
</section>

<!-- REVIEWS -->
<section class="reviews">
  <div class="wrap">
    <div class="head">
      <h2>449 REVIEWS.<br>4.7 <span class="a">STARS.</span></h2>
      <div class="stars-meta">
        <span class="num">4.7</span>
        <span class="stars"></span>  449+ verified Google reviews
      </div>
    </div>
    <div class="rv-grid">
      <article class="rv">
        <div class="quote">"</div>
        <div class="stars">    </div>
        <p>My wife and I came in nervous and left certified. The instructors took the time to explain everything, never made us feel stupid, and the lanes were spotless. This is how every range should run.</p>
        <div class="who">
          <div class="av">DR</div>
          <div>
            <div class="nm">Daniel R.</div>
            <div class="src">First-Time CCW  APR 2026</div>
          </div>
        </div>
      </article>
      <article class="rv" style="border-color: var(--color-brass-dim);">
        <div class="quote">"</div>
        <div class="stars">    </div>
        <p>As a retired LEO I'm picky about training environments. Guns 2 Ammo runs the floor with the kind of discipline I expect. The RSO program is genuinely best-in-class for the East Valley.</p>
        <div class="who">
          <div class="av">MH</div>
          <div>
            <div class="nm">Maria H.</div>
            <div class="src">Retired Officer  Gilbert</div>
          </div>
        </div>
      </article>
      <article class="rv">
        <div class="quote">"</div>
        <div class="stars">    </div>
        <p>Booked the MP5 package for my son's 21st. The RSO walked us through everything, made it feel safe and serious, and we left with a certificate and a story. Worth every dollar.</p>
        <div class="who">
          <div class="av">AP</div>
          <div>
            <div class="nm">Anthony P.</div>
            <div class="src">Apache Junction  MAR 2026</div>
          </div>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- FINAL CTA -->
<section class="final">
  <div class="wrap">
    <span class="eb-pill" style="margin: 0 auto;">Mesa's Most-Trusted Range</span>
    <h2>READY TO<br><span class="a">SHOOT?</span></h2>
    <p>Book a lane, enroll in a course, or stop by the shop. We're open six days a week  Friday closed  and there's always an RSO on duty.</p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Book A Lane</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( "/memberships/" ) ); ?>">Become a Member</a>
    </div>
  </div>
</section>
<?php get_footer();
