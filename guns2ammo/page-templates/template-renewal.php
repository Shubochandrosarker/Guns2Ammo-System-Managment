<?php
/**
 * Template Name: Renewal
 *
 * Hosts the Memberistic renewal flow inside the branded theme shell.
 * Assign to a Page with slug: renewal
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<section class="section g2a-plugin-host" style="padding-top:130px;">
  <div class="container" style="max-width:1080px;">
    <span class="eyebrow" style="margin-bottom:14px;">Membership</span>
    <h1 class="hl-display" style="font-size:clamp(40px,6vw,72px);margin-bottom:28px;">RENEW MEMBERSHIP</h1>
    <?php echo g2a_plugin_section( 'memberistic_renewal' ); ?>
  </div>
</section>
<?php get_footer();
