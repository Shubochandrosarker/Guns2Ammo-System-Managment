<?php
/**
 * Template Name: Book A Lane
 *
 * Source: design/book-a-lane.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$g2a_page_id                 = get_the_ID();
$g2a_lane_hero_title         = get_post_meta( $g2a_page_id, 'lane_hero_title', true );
$g2a_lane_membership_pitch   = get_post_meta( $g2a_page_id, 'lane_membership_pitch', true );
?>
<header class="bk-hero hero-media">
  <div class="container">
    <span class="eyebrow"> Reservation  Live Lane Status</span>
    <h1 style="margin-top:18px;"><?php echo esc_html( $g2a_lane_hero_title ? $g2a_lane_hero_title : 'BOOK YOUR LANE.' ); ?></h1>
    <div class="bk-trust">
      <span class="it">Climate Controlled</span>
      <span class="it">6 Lanes</span>
      <span class="it">RSO On Duty</span>
      <span class="it">Smart Ventilation</span>
      <span class="it"><span class="dot-live"></span> 4 of 6 lanes open now</span>
    </div>
  </div>
</header>

<?php
/* Membership promo  shown to non-members (logged-out visitors and
 * any logged-in user without an active Members Hub membership). */
$g2a_is_member = false;
if ( is_user_logged_in() ) {
	if ( function_exists( 'memberistic_user_has_active_membership' ) ) {
		$g2a_is_member = (bool) memberistic_user_has_active_membership( get_current_user_id() );
	} elseif ( function_exists( 'memberistic_is_active_member' ) ) {
		$g2a_is_member = (bool) memberistic_is_active_member( get_current_user_id() );
	}
}
if ( ! $g2a_is_member ) : ?>
<aside class="bk-promo" aria-label="Membership offer">
  <div class="bk-promo__in">
    <div>
      <span class="bk-promo__tag"> Members Hub Exclusive</span>
      <div class="bk-promo__h">YOUR 1-HOUR LANE BOOKING  <em>FULLY FREE.</em></div>
      <p class="bk-promo__p"><?php echo esc_html( $g2a_lane_membership_pitch ? $g2a_lane_membership_pitch : 'Members receive free lane time on every visit. Skip the standard lane fee, access discounted member pricing, and reserve your lane online 24/7 through the Guns 2 Ammo Members Hub.' ); ?></p>
    </div>
    <div class="bk-promo__cta">
      <a class="btn btn-ember" href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">Join The Members Hub </a>
      <span class="bk-promo__price">Plans from $29.99/mo  Cancel anytime  No contracts</span>
    </div>
  </div>
</aside>
<?php endif; ?>

<?php if ( function_exists( 'g2a_has_booking' ) && g2a_has_booking() ) : ?>
<section class="bk-layout g2a-plugin-host" style="display:block;">
  <?php echo g2a_plugin_section( 'g2a_lane_booking' ); ?>
</section>
<?php else : ?>
<section class="bk-layout" style="display:block;">
  <div class="bk-promo" style="text-align:center; padding:48px 32px;">
    <span class="bk-promo__tag" style="display:inline-block;">Online Booking</span>
    <h3 class="bk-promo__h" style="margin:16px 0;">RESERVATIONS TEMPORARILY OFFLINE</h3>
    <p class="bk-promo__p" style="max-width:560px; margin:0 auto 24px;">
      The online lane reservation system is loading. Please call the
      range to book a lane and we will get you on the floor.
    </p>
    <a class="btn btn-ember btn-lg" href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', get_theme_mod( 'g2a_phone', '(602) 715-2677' ) ) ); ?>">
      Call <?php echo esc_html( get_theme_mod( 'g2a_phone', '(602) 715-2677' ) ); ?>
    </a>
    <p style="margin-top:14px; font-size:12px; color: var(--color-silver);">
      Walk-ins welcome during open hours.
    </p>
  </div>
</section>
<?php endif; ?>

<section class="info-grid" id="pricing">
  <div class="ig-head">
    <span class="eyebrow" style="margin-bottom:14px;">Before You Book</span>
    <h2>HOURS, PRICING, RULES &amp; WHAT TO BRING</h2>
    <p>Everything you need to plan your visit to our climate-controlled indoor range at 6030 E Main St in Mesa. New to the range? Read it through  then book with confidence.</p>
  </div>
  <div class="container">
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><circle cx="12" cy="12" r="9"/><polyline points="12,7 12,12 15,14"/></svg></div>
      <h4>HOURS</h4>
      <p>The range is open seven days a week with extended weekend hours.</p>
      <ul>
        <li>Monday, Tuesday, Wednesday, Thursday  10:00 AM to 6:00 PM</li>
        <li>Friday  10:00 AM to 7:00 PM</li>
        <li>Saturday  10:00 AM to 7:00 PM</li>
        <li>Sunday  12:00 PM to 6:00 PM</li>
        <li>Last lane booking is taken one hour before close</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Holiday hours &amp; directions </a>
    </div>
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><line x1="12" y1="2" x2="12" y2="22"/><path d="M16 6 H10 a3 3 0 0 0 0 6 h4 a3 3 0 0 1 0 6 H6"/></svg></div>
      <h4>PRICING</h4>
      <p>A standard lane rental is $20 per hour with additional shooters at $15 per lane. Members receive included lane time with qualifying plans plus discounted rentals and guest rates based on membership level.</p>
      <ul>
        <li>Lane rental  $20 per hour</li>
        <li>Additional shooter  $15 per lane</li>
        <li>Handgun rentals start at $15  rifle rentals start at $25</li>
        <li>Eye &amp; ear protection available at check-in</li>
        <li>Paper targets available in-store</li>
        <li>Members receive free lane time and exclusive rental pricing</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">Compare membership plans </a>
    </div>
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><path d="M12 2 L20 5 V12 C20 17 16 21 12 22 C8 21 4 17 4 12 V5 Z"/></svg></div>
      <h4>RULES</h4>
      <p>Our range safety officers run a tight, friendly floor. Every shooter follows federal and Arizona law plus a few house rules that keep all six lanes safe.</p>
      <ul>
        <li>Eye and ear protection required at all times on the floor</li>
        <li>No alcohol or impairing substances before or during shooting</li>
        <li>Factory-new ammunition only  no reloads or steel-core</li>
        <li>Shooters 8+ welcome with a parent or guardian present</li>
        <li>An RSO is always on duty  ask anything, anytime</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/range-safety/' ) ); ?>">Full range safety rules </a>
    </div>
    <div class="info-card">
      <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="18" height="18"><rect x="3" y="6" width="18" height="14"/><path d="M3 10 h18"/><circle cx="8" cy="16" r="1.5" fill="currentColor"/></svg></div>
      <h4>WHAT TO BRING</h4>
      <p>Pack light  we supply almost everything. First-timers just need an ID and closed-toe shoes; our team handles the rest at check-in.</p>
      <ul>
        <li>Valid government photo ID for every shooter</li>
        <li>Closed-toe shoes and a high-neck shirt (hot-brass policy)</li>
        <li>Your own firearm if you have one  fully optional</li>
        <li>40+ rental firearms available on-site if you don't</li>
        <li>Ammunition can be bought in store  factory-new only</li>
      </ul>
      <a class="more" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">Shop firearms &amp; ammo </a>
    </div>
  </div>

  <div class="container" id="ladies" style="margin-top:24px; display:block; max-width:1280px;">
    <div class="ladies-card">
      <span class="tag">Tuesdays  Ladies Day</span>
      <h4>Ladies Tuesday  Free 1-Hour Lane Time</h4>
      <p>Every Tuesday all day, women receive one free hour of lane time. No 1-on-1 range officer package is included with this day.</p>
      <a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( "/ladies-tuesday/" ) ); ?>">Learn More </a>
    </div>
  </div>
</section>

<?php get_footer();
