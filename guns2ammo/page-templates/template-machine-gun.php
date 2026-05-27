<?php
/**
 * Template Name: Machine Gun
 *
 * Source: design/machine-gun.html ported 1:1.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$g2a_page_id             = get_the_ID();
$g2a_mg_hero_intro       = get_post_meta( $g2a_page_id, 'mg_hero_intro', true );
$g2a_mg_ccw_replace      = get_post_meta( $g2a_page_id, 'mg_ccw_remove_text', true );
?>
<header class="mg-hero">
  <div class="c">
    <span class="eyebrow" style="color: var(--color-ember);">Signature Experience  Booked 46 weeks out</span>
    <h1 style="margin-top:18px;"><span class="thin">FIRE THE</span>UNTHINKABLE<span class="ember">.</span></h1>
    <p><?php echo esc_html( $g2a_mg_hero_intro ? $g2a_mg_hero_intro : 'One-on-one, fully-automatic instruction. Three legendary platforms. A certified RSO at your side. Built for adrenaline, entertainment, and unforgettable range time.' ); ?></p>
    <div style="display:flex; gap:14px; flex-wrap:wrap;">
      <a class="btn btn-ember btn-lg" href="#tiers">Book Your Experience </a>
      <a class="btn btn-ghost btn-lg" href="<?php echo esc_url( home_url( "/book-a-lane/" ) ); ?>">Or Book A Standard Lane</a>
    </div>
  </div>
  <svg class="crosshair" viewBox="0 0 100 100" fill="none" stroke="#C9A84C" stroke-width="0.5">
    <circle cx="50" cy="50" r="40"/><circle cx="50" cy="50" r="25"/><circle cx="50" cy="50" r="2" fill="#C9A84C"/>
    <line x1="50" y1="0" x2="50" y2="20"/><line x1="50" y1="80" x2="50" y2="100"/>
    <line x1="0" y1="50" x2="20" y2="50"/><line x1="80" y1="50" x2="100" y2="50"/>
  </svg>
</header>

<section class="weapons">
  <div class="weapons-head">
    <div><span class="eyebrow"> The Arsenal</span><h2 style="margin-top:18px;">THREE PLATFORMS.<br>ONE STANDARD.</h2></div>
    <p style="color: var(--color-fog); max-width: 380px;">Each weapon is maintained to mil-spec by our armorer. Every package includes ammunition, eye/ear, and targets.</p>
  </div>

  <div class="weapons-grid">
    <article class="weapon" id="mp5">
      <div>
        <span class="tag">01 / SUBMACHINE GUN</span>
        <h3><a href="<?php echo esc_url( home_url( '/machine-gun/mp5/' ) ); ?>" style="color:inherit;text-decoration:none;">MP5</a></h3>
        <div class="cal">919MM  ROLLER-DELAYED BLOWBACK</div>
      </div>
      <div class="specs">
        <div><div class="v">800</div><div class="l">RPM</div></div>
        <div><div class="v">25 RD</div><div class="l">MAGAZINE</div></div>
        <div><div class="v">9MM</div><div class="l">CALIBER</div></div>
        <div><div class="v">$249</div><div class="l">STARTING</div></div>
      </div>
      <a class="add" href="<?php echo esc_url( home_url( '/machine-gun/mp5/' ) ); ?>">EXPLORE THE MP5 </a>
    </article>

    <article class="weapon" id="m16">
      <div>
        <span class="tag">02 / RIFLE</span>
        <h3><a href="<?php echo esc_url( home_url( '/machine-gun/m16/' ) ); ?>" style="color:inherit;text-decoration:none;">M16</a></h3>
        <div class="cal">5.56 NATO  DIRECT IMPINGEMENT</div>
      </div>
      <div class="specs">
        <div><div class="v">700</div><div class="l">RPM</div></div>
        <div><div class="v">30 RD</div><div class="l">MAGAZINE</div></div>
        <div><div class="v">5.56</div><div class="l">CALIBER</div></div>
        <div><div class="v">$329</div><div class="l">STARTING</div></div>
      </div>
      <a class="add" href="<?php echo esc_url( home_url( '/machine-gun/m16/' ) ); ?>">EXPLORE THE M16 </a>
    </article>

    <article class="weapon" id="ak47">
      <div>
        <span class="tag">03 / RIFLE</span>
        <h3><a href="<?php echo esc_url( home_url( '/machine-gun/ak-47/' ) ); ?>" style="color:inherit;text-decoration:none;">AK-47</a></h3>
        <div class="cal">7.6239  LONG-STROKE PISTON</div>
      </div>
      <div class="specs">
        <div><div class="v">600</div><div class="l">RPM</div></div>
        <div><div class="v">30 RD</div><div class="l">MAGAZINE</div></div>
        <div><div class="v">7.62</div><div class="l">CALIBER</div></div>
        <div><div class="v">$349</div><div class="l">STARTING</div></div>
      </div>
      <a class="add" href="<?php echo esc_url( home_url( '/machine-gun/ak-47/' ) ); ?>">EXPLORE THE AK-47 </a>
    </article>
  </div>
</section>

<section class="tiers" id="tiers">
  <div class="weapons-head" style="max-width:1280px; margin:0 auto 64px;">
    <div><span class="eyebrow"> Three Tiers</span><h2 style="font-family: var(--font-display); font-size:clamp(40px,6vw,80px); margin-top:18px;">PICK YOUR PACKAGE.</h2></div>
    <p style="color: var(--color-fog); max-width:420px;"><?php echo esc_html( $g2a_mg_ccw_replace ? $g2a_mg_ccw_replace : 'All packages include 1-on-1 RSO, ammunition, eye/ear protection, and targets.' ); ?></p>
  </div>
  <div class="tiers-grid">
    <article class="tier">
      <span class="pn">PACKAGE 01</span>
      <h3>BASIC</h3>
      <div class="price">$249</div>
      <ul>
        <li>Choice of 1 weapon platform</li>
        <li>50 rounds of ammunition</li>
        <li>30 minutes on the floor</li>
        <li>Certified RSO 1-on-1</li>
        <li>No class requirement</li>
        <li>Eye, ear &amp; targets included</li>
      </ul>
      <a class="btn btn-brass" style="width:100%;">Book Basic </a>
    </article>

    <article class="tier popular">
      <span class="pop">MOST POPULAR</span>
      <span class="pn">PACKAGE 02</span>
      <h3>PREMIUM</h3>
      <div class="price">$449</div>
      <ul>
        <li>Choice of 2 weapon platforms</li>
        <li>100 rounds total</li>
        <li>60 minutes on the floor</li>
        <li>Certified RSO 1-on-1</li>
        <li>No class requirement</li>
        <li>Photo &amp; video pack</li>
        <li>Logo G2A patch</li>
      </ul>
      <a class="btn btn-ember" style="width:100%;">Book Premium </a>
    </article>

    <article class="tier">
      <span class="pn">PACKAGE 03</span>
      <h3>ELITE</h3>
      <div class="price">$749</div>
      <ul>
        <li>All 3 weapon platforms</li>
        <li>200 rounds total</li>
        <li>90 minutes on the floor</li>
        <li>Certified RSO 1-on-1</li>
        <li>No class requirement</li>
        <li>Pro photo + 4K video reel</li>
        <li>Hat, patch &amp; certificate</li>
        <li>One return-visit lane pass</li>
      </ul>
      <a class="btn btn-brass" style="width:100%;">Book Elite </a>
    </article>
  </div>
</section>

<section class="weapons" style="border-top:0;">
  <div class="c" style="max-width:880px; margin:0 auto;">
    <span class="eyebrow"> What To Expect</span>
    <h2 style="font-family: var(--font-display); font-size:clamp(34px,5vw,56px); line-height:0.98; margin:16px 0 16px;">NO EXPERIENCE NEEDED.<br>JUST SHOW UP.</h2>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.85;">A machine gun experience at Guns 2 Ammo is built for first-timers. You do not need to have fired a gun before  a certified Range Safety Officer is one-on-one with you from the moment you step onto the floor. They set your stance, load every magazine, and coach each burst. Photos and video are welcome, so bring someone to capture it.</p>
    <p style="color: var(--color-fog); font-size:15px; line-height:1.85; margin-top:14px;">Pick a single platform or shoot all three  the <a href="<?php echo esc_url( home_url( '/machine-gun/mp5/' ) ); ?>" style="color:var(--color-brass-bright);">MP5</a> is the smoothest first machine gun, the <a href="<?php echo esc_url( home_url( '/machine-gun/m16/' ) ); ?>" style="color:var(--color-brass-bright);">M16</a> is the modern service rifle, and the <a href="<?php echo esc_url( home_url( '/machine-gun/ak-47/' ) ); ?>" style="color:var(--color-brass-bright);">AK-47</a> delivers the hardest-hitting full-auto punch. Shooters must be 18+ (or accompanied by a parent), sober, and able to follow RSO direction  that is the whole list.</p>
  </div>
</section>

<section class="bonus">
  <div class="c">
    <div>
      <h4>Entertainment Experience Packages</h4>
      <p>Built for safe, supervised full-auto range sessions</p>
    </div>
    <a class="btn btn-ghost" style="border-color:#111; color:#111;" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Ask About Inventory </a>
  </div>
</section>
<?php get_footer();
