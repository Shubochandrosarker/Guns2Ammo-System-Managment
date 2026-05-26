<?php
/**
 * Template Name: Staff
 *
 * Hosts the Memberistic staff dashboard inside the branded theme shell.
 * Assign to a Page with slug: staff
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<section class="section g2a-plugin-host" style="padding-top:120px;">
  <div class="container" style="max-width:1400px;">
    <span class="eyebrow" style="margin-bottom:14px;">Operations</span>
    <h1 class="hl-display" style="font-size:clamp(36px,5vw,60px);margin-bottom:24px;">STAFF DASHBOARD</h1>
    <?php echo g2a_plugin_section( 'memberistic_staff_dashboard' ); ?>
  </div>
</section>
<?php get_footer();
