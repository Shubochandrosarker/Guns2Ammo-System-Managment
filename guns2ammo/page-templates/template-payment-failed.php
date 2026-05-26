<?php
/**
 * Template Name: Payment Failed
 *
 * Hosts the Memberistic failed-payment recovery flow inside the branded shell.
 * Assign to a Page with slug: payment-failed
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>
<section class="section g2a-plugin-host" style="padding-top:130px;">
  <div class="container" style="max-width:760px;">
    <span class="eyebrow" style="margin-bottom:14px;">Membership  Payment</span>
    <h1 class="hl-display" style="font-size:clamp(40px,6vw,68px);margin-bottom:24px;">PAYMENT NEEDS ATTENTION</h1>
    <?php echo g2a_plugin_section( 'memberistic_payment_failed' ); ?>
    <p style="margin-top:22px;color:var(--color-fog);font-size:14px;">Need a hand? <a href="<?php echo esc_url( home_url( '/get-support/' ) ); ?>" style="color:var(--color-brass-bright);">Contact support</a> or call (602)&nbsp;715-2677.</p>
  </div>
</section>
<?php get_footer();
