<?php
/**
 * Template Name: Thank You
 *
 * Hosts the Members Hub post-checkout confirmation inside the branded shell.
 * Assign to a Page with slug: thank-you
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<section class="section g2a-plugin-host" style="padding-top:130px;text-align:center;">
  <div class="container" style="max-width:760px;">
    <span class="eyebrow" style="justify-content:center;margin-bottom:14px;">Welcome To Guns 2 Ammo</span>
    <h1 class="hl-display" style="font-size:clamp(44px,6vw,80px);margin-bottom:24px;">YOU'RE <span class="a">IN.</span></h1>
    <?php echo g2a_plugin_section( 'memberistic_thank_you' ); ?>
    <div style="margin-top:28px;display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/account/' ) ); ?>">Go To My Account</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/book-a-lane/' ) ); ?>">Book A Lane</a>
    </div>
  </div>
</section>
<?php get_footer();
