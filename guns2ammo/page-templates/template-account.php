<?php
/**
 * Template Name: Account
 *
 * Hosts the Members Hub member dashboard inside the branded theme shell.
 * Assign to a Page with slug: account
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<section class="section g2a-plugin-host" style="padding-top:130px;">
  <div class="container">
    <span class="eyebrow" style="margin-bottom:14px;">Members</span>
    <h1 class="hl-display" style="font-size:clamp(40px,6vw,72px);margin-bottom:28px;">MY ACCOUNT</h1>
    <?php echo g2a_plugin_section( 'memberistic_account' ); ?>
  </div>
</section>
<?php get_footer();
