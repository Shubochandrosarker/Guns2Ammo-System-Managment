<?php
/**
 * Header  Guns 2 Ammo child theme
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?><!doctype html>
<html <?php language_attributes(); ?> class="g2a-loading">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1A191E">
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
    // DOM already parsed
    done();
  } else {
    document.addEventListener('DOMContentLoaded', done, { once: true });
  }
  // Hard ceiling: dismiss after 900ms no matter what.
  setTimeout(done, 900);
})();
</script>

<?php get_template_part( 'template-parts/nav' ); ?>
<?php get_template_part( 'template-parts/mobile-drawer' ); ?>

<main id="g2a-main">
