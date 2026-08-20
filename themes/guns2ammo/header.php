<?php
/**
 * Header  Guns 2 Ammo child theme
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!doctype html>
<html <?php language_attributes(); ?> class="g2a-loading">
<head>
	 <script defer src="https://app.chatbotistic.com/install-widget/bundle.js?key=2fa87700-8fd1-453e-a6ed-6154042227a6"></script>
	
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A191E" id="g2a-theme-color">
<script>
/* Theme mode bootstrap — runs before any CSS applies so there is never a
   wrong-color flash. Resolution order:
     1. visitor's saved choice (localStorage g2a-theme: 'light' | 'dark')
     2. OS preference (prefers-color-scheme)
     3. dark (brand default)
   chrome.js owns the toggle UI; this only stamps <html data-theme="…">. */
(function(){
  var d = document.documentElement, t = null;
  try { t = localStorage.getItem('g2a-theme'); } catch(e){}
  if (t !== 'light' && t !== 'dark') {
    t = (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? 'light' : 'dark';
    // System-driven: keep following the OS if the user never chose manually.
    d.setAttribute('data-theme-auto', '1');
  }
  d.setAttribute('data-theme', t);
  var m = document.getElementById('g2a-theme-color');
  if (m) m.setAttribute('content', t === 'light' ? '#F4F2ED' : '#1A191E');
})();
</script>

<?php
/* ============================================================
 * Performance hints — pushed into <head> BEFORE wp_head() so they
 * land as early as possible in the response stream, kicking off
 * font downloads in parallel with HTML parsing. Real-world TTI is
 * dominated by render-blocking resource discovery; preloading the
 * two most-used fonts knocks ~150-300ms off cold loads on slow
 * connections.
 *
 * dns-prefetch + preconnect: api.qrserver.com is only hit on the
 * member dashboard's digital-card view. Hint costs ~0 if the
 * visitor never lands on /account/, saves a full TLS handshake
 * round-trip if they do.
 * ============================================================ */
$g2a_uri = G2A_URI;
?>
<link rel="preconnect" href="https://api.qrserver.com" crossorigin>
<link rel="dns-prefetch" href="//api.qrserver.com">

<?php /* Preload the three most-rendered fonts (body + condensed + the
       Bebas Neue display face). All are referenced in fonts.css via
       @font-face, so the browser would otherwise discover them
       ~80-150ms later. Bebas Neue matters most for CLS: it draws every
       hero headline, and a late swap from the fallback reflows the
       largest text on the page. */ ?>
<link rel="preload" as="font" type="font/woff2"
      href="<?php echo esc_url( $g2a_uri . '/assets/fonts/v16-JTUSjIg69CK48gW7PXoo9Wlhyw.woff2' ); ?>" crossorigin>
<link rel="preload" as="font" type="font/woff2"
      href="<?php echo esc_url( $g2a_uri . '/assets/fonts/v17-rP2Yp2ywxg089UriI5-g4vlH9VoD8Cmcqbu0-K4.woff2' ); ?>" crossorigin>
<link rel="preload" as="font" type="font/woff2"
      href="<?php echo esc_url( $g2a_uri . '/assets/fonts/v13-7cHpv4kjgoGqM7E_DMs5.woff2' ); ?>" crossorigin>

<?php wp_head(); ?>
<style id="g2a-skip-link-css">
/* Hardened a11y skip-link hide — inlined so caching/CDNs can't drop
   it. Uses the WP screen-reader-text recipe (clip + 1×1 box) which
   is more robust than left:-9999px (some translators render that). */
.g2a-skip-link{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important;}
.g2a-skip-link:focus{position:fixed!important;left:12px!important;top:12px!important;width:auto!important;height:auto!important;padding:12px 20px!important;margin:0!important;overflow:visible!important;clip:auto!important;white-space:normal!important;z-index:100000;background:#E8802F;color:#111;font-weight:700;text-decoration:none;outline:2px solid #fff;}
</style>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="g2a-skip-link" href="#g2a-main"><?php esc_html_e( 'Skip to main content', 'guns2ammo' ); ?></a>

<!-- Preloader -->
<div id="g2a-preloader" role="status" aria-label="Loading">
	<div class="pl-stack">
		<div class="pl-logo"><span class="mark"></span>GUNS&nbsp;2&nbsp;AMMO</div>
		<div class="pl-bar"></div>
		<div class="pl-meta">Loading the range...</div>
	</div>
</div>
<script>
/* Inline preloader dismissal — fires as soon as the browser parses
   <body>, BEFORE the footer chrome.js loads. Prevents the preloader
   from sticking on cold-cache (incognito) loads while images, fonts,
   3rd-party widgets, etc. are still streaming in. chrome.js still
   owns the canonical dismiss path; this is a parallel fast lane. */
(function(){
  function done(){
    var pl = document.getElementById('g2a-preloader');
    if(!pl) return;
    document.documentElement.classList.remove('g2a-loading');
    pl.classList.add('done');
    setTimeout(function(){ if(pl.parentNode) pl.parentNode.removeChild(pl); }, 240);
  }
  if (document.readyState !== 'loading') {
    done();
  } else {
    document.addEventListener('DOMContentLoaded', done, { once: true });
  }
  // Hard ceiling: 400ms. Content paints BEHIND the preloader (no
  // visibility:hidden gate) so this is purely a brand flash — never
  // a wait. On fast connections DOMContentLoaded dismisses sooner;
  // on slow ones the page is readable the moment it streams in.
  setTimeout(done, 400);
  // Belt + braces: pagehide/bfcache restores must never resurrect it.
  window.addEventListener('pageshow', done, { once: true });
})();
</script>

<?php get_template_part( 'template-parts/nav' ); ?>
<?php get_template_part( 'template-parts/mobile-drawer' ); ?>

<?php
/**
 * Directly under the site header, above the page content and any
 * WooCommerce breadcrumb. Full-bleed slot for site-wide notices.
 */
do_action( 'g2a_after_header' );
?>

<main id="g2a-main">
