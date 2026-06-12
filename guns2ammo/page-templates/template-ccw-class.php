<?php
/**
 * Template Name: Free CCW Class (DEPRECATED — do not assign)
 *
 * DEPRECATED 2026-05: per the Guns 2 Ammo content cleanup doc, the
 * "Free Arizona CCW Class with Machine Gun Package" tie-in has been
 * retired. The machine gun experience is now positioned as an
 * entertainment/experience product and no longer bundles a CCW class.
 *
 * This template is left in the theme so any existing WP page still
 * assigned to it does not 404, but no new page should use it. To
 * fully remove: unpublish any page using this template in WP admin,
 * then delete this file in a follow-up release.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$img = '/wp-content/uploads/';

$faq = [
	[ 'q' => 'Is the Arizona CCW class really free with a machine gun package?', 'a' => 'Yes. Every Signature Experience package  Basic, Premium, and Elite  includes the Arizona CCW classroom course at no extra cost — an $85 value.' ],
	[ 'q' => 'Do I get an actual CCW certificate?', 'a' => 'Yes. On completion you receive the Arizona CCW certificate of training, which supports your concealed-weapons permit application.' ],
	[ 'q' => 'How many states does the Arizona permit cover?', 'a' => 'An Arizona concealed-carry permit is honored in 37+ states through reciprocity. Pair it with our California course for even broader coverage.' ],
	[ 'q' => 'Can I take the CCW class on the same day?', 'a' => 'Often yes. Many guests complete the class the same day as their machine gun experience  tell us your plan when you book and we will schedule it together.' ],
	[ 'q' => 'I only want the CCW class  is that possible?', 'a' => 'Absolutely. The standalone Arizona CCW certification course is available any time on our Training calendar. The free class is a bonus for machine gun guests.' ],
];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $faq ) );
}
?>
<style>
.fc-hero { padding:140px 32px 76px; position:relative; overflow:hidden; background: radial-gradient(ellipse at 28% 40%, rgba(201,168,76,0.14), transparent 60%), linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.fc-hero .c { max-width:1080px; margin:0 auto; }
.fc-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.fc-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.fc-hero h1 { font-family: var(--font-display); font-size: clamp(52px,8.5vw,120px); line-height:0.9; margin:14px 0 0; color: var(--color-white); }
.fc-hero h1 .a { color: var(--color-brass-bright); }
.fc-hero .lead { color: var(--color-fog); max-width:58ch; margin:20px 0 0; font-size:16px; line-height:1.75; }
.fc-hero .ctas { display:flex; gap:12px; margin-top:28px; flex-wrap:wrap; }
.fc-value { display:inline-flex; align-items:baseline; gap:10px; margin-top:30px; }
.fc-value .num { font-family: var(--font-display); font-size:64px; color: var(--color-brass-bright); line-height:1; }
.fc-value .txt { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.fc-sec { max-width:1080px; margin:0 auto; padding:72px 32px; }
.fc-sec h2 { font-family: var(--font-display); font-size: clamp(34px,5vw,60px); color: var(--color-white); }
.fc-grid { display:grid; grid-template-columns: repeat(2,1fr); gap:14px; margin-top:28px; }
@media (max-width:680px){ .fc-grid { grid-template-columns:1fr; } }
.fc-card { padding:26px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.fc-card .t { font-family: var(--font-condensed); font-weight:600; font-size:18px; text-transform:uppercase; color: var(--color-white); }
.fc-card .d { color: var(--color-fog); font-size:14px; line-height:1.7; margin-top:8px; }
.fc-mod { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.fc-cta { text-align:center; }
</style>

<header class="fc-hero">
  <div class="c">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/machine-gun/' ) ); ?>">Signature Experience</a>  Free CCW Class</div>
    <span class="eyebrow" style="margin-top:14px;">Bonus  Included With Every Package</span>
    <h1>A FREE ARIZONA<br><span class="a">CCW CLASS.</span></h1>
    <p class="lead">Every machine gun experience at Guns 2 Ammo includes the full Arizona CCW certification class  at no extra cost. Fire legendary full-auto platforms, then walk out certified to carry concealed.</p>
    <div class="fc-value"><span class="num">$75</span><span class="txt">Value  Included Free<br>With Every Machine Gun Package</span></div>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/machine-gun/' ) ); ?>#tiers">See Machine Gun Packages</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>">About Arizona CCW</a>
    </div>
  </div>
</header>

<section class="fc-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Why We Include It</span>
  <h2>SHOOT BIG. CARRY SMART.</h2>
  <p style="color:var(--color-fog); font-size:15px; line-height:1.8; margin-top:16px; max-width:64ch;">A machine gun experience is a once-in-a-lifetime thrill  but we want every guest to leave with something lasting, too. That is why each package bundles the Arizona CCW class free. You get the story of firing an MP5, M16, or AK-47 <em>and</em> a real certification that protects you and your family every day after.</p>
  <div class="fc-grid">
    <div class="fc-card"><div class="t">State-Approved Curriculum</div><div class="d">Arizona statute, use-of-force law, safe handling, and the legal weight of carrying concealed.</div></div>
    <div class="fc-card"><div class="t">Same-Day Certificate</div><div class="d">Complete the class and receive your Arizona CCW certificate of training the same day.</div></div>
    <div class="fc-card"><div class="t">37+ State Reciprocity</div><div class="d">An Arizona permit is honored across 37+ states  one of the most travel-friendly permits in the country.</div></div>
    <div class="fc-card"><div class="t">Taught By Certified Pros</div><div class="d">NRA, USCCA, and former law-enforcement instructors  the same team behind our full CCW program.</div></div>
  </div>
</section>

<section class="fc-mod">
  <div class="fc-sec">
    <span class="eyebrow" style="margin-bottom:14px;">How To Claim It</span>
    <h2>THREE SIMPLE STEPS</h2>
    <div class="fc-grid" style="grid-template-columns:repeat(3,1fr);">
      <div class="fc-card"><div class="t">01  Book A Package</div><div class="d">Choose any Basic, Premium, or Elite machine gun package  the CCW class is already included.</div></div>
      <div class="fc-card"><div class="t">02  Fire The Arsenal</div><div class="d">Enjoy your one-on-one full-auto experience with a certified RSO at your side.</div></div>
      <div class="fc-card"><div class="t">03  Get Certified</div><div class="d">Complete your Arizona CCW class  often the same day  and leave certified.</div></div>
    </div>
    <style>@media (max-width:680px){ .fc-mod .fc-grid { grid-template-columns:1fr !important; } }</style>
  </div>
</section>

<section class="fc-sec">
  <span class="eyebrow" style="margin-bottom:14px;">Common Questions</span>
  <h2 style="margin-bottom:26px;">FREE CCW CLASS FAQ</h2>
  <div class="acc">
    <?php foreach ( $faq as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="fc-mod">
  <div class="fc-sec fc-cta">
    <span class="eyebrow" style="margin:0 auto 16px;">Two Experiences, One Booking</span>
    <h2>READY TO SHOOT  AND CARRY?</h2>
    <p style="color:var(--color-fog); max-width:52ch; margin:14px auto 26px;">Book your machine gun package today and your free Arizona CCW class comes with it.</p>
    <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/machine-gun/' ) ); ?>">Book A Machine Gun Package</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/arizona-ccw-certification/' ) ); ?>">Explore Arizona CCW</a>
    </div>
  </div>
</section>
<?php get_footer();
