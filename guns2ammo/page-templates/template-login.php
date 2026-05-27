<?php
/**
 * Template Name: Login
 *
 * Hosts the Members Hub member login inside the branded theme shell.
 * Assign to a Page with slug: login
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<section class="section g2a-plugin-host" style="padding-top:130px;">
  <div class="container" style="max-width:520px;">
    <span class="eyebrow" style="margin-bottom:14px;">Members</span>
    <h1 class="hl-display" style="font-size:clamp(36px,5vw,56px);margin-bottom:24px;">MEMBER LOGIN</h1>
    <?php echo g2a_plugin_section( 'memberistic_login' ); ?>
    <p style="margin-top:20px;color:var(--color-fog);font-size:14px;">Not a member yet? <a href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>" style="color:var(--color-brass-bright);">Join the range &rarr;</a></p>
  </div>
</section>
<?php get_footer();
