<?php
/**
 * Template Name: Collections Index
 *
 * Landing hub for /collections/  links to each collection landing page.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$img_base = '/wp-content/uploads/';

$tiles = [
	[ 'slug' => 'handguns',   'name' => 'HANDGUNS',   'href' => home_url( '/collections/handguns/' ),   'img' => $img_base . '2025/06/glock-19-gen5-9mm-guns2ammo-mesa-az.jpg.webp', 'desc' => 'Carry pistols, duty guns, and range favorites fitted to your hand.' ],
	[ 'slug' => 'rifles',     'name' => 'RIFLES',     'href' => home_url( '/collections/rifles/' ),     'img' => $img_base . '2025/06/noveske-gen4-556-sbr-bazooka-green-guns2ammo-mesa-az.webp', 'desc' => 'AR carbines, bolt guns, and SBR builds from trusted makers.' ],
	[ 'slug' => 'ammunition', 'name' => 'AMMUNITION', 'href' => home_url( '/collections/ammunition/' ), 'img' => $img_base . '2026/02/589741689_1348201817319329_6684242787616772114_n.webp', 'desc' => 'Factory-new training rounds and premium defense loads, in bulk.' ],
	[ 'slug' => 'magazines',  'name' => 'MAGAZINES',  'href' => home_url( '/collections/magazines/' ),  'img' => $img_base . '2025/06/Sig-Suer-P320-Custom-works-Guns2ammo-mesa-az.JPG-rotated.webp', 'desc' => 'Factory and proven aftermarket mags for pistols and rifles.' ],
];
?>
<style>
.cx-hero { padding: 140px 32px 64px; position:relative; overflow:hidden; background: linear-gradient(180deg, var(--color-surface-2), var(--color-void)); }
.cx-hero .container { max-width:1280px; margin:0 auto; }
.cx-hero h1 { font-family: var(--font-display); font-size: clamp(56px,9vw,116px); line-height:0.9; margin-top:18px; }
.cx-hero h1 .a { color: var(--color-ember); }
.cx-hero .lead { color: var(--color-fog); max-width:62ch; margin:22px 0 0; font-size:16px; line-height:1.7; }
.cx-grid { max-width:1280px; margin:0 auto; padding:64px 32px; display:grid; grid-template-columns:1fr 1fr; gap:16px; }
@media (max-width:760px){ .cx-grid { grid-template-columns:1fr; } }
.cx-tile { position:relative; min-height:300px; overflow:hidden; border:1px solid var(--color-hairline); text-decoration:none; display:block; transition: border-color 280ms var(--ease-std), transform 280ms var(--ease-std); }
.cx-tile:hover { border-color: var(--color-brass); transform: translateY(-4px); }
.cx-tile .bg { position:absolute; inset:0; background-size:cover; background-position:center; filter:saturate(0.9); transition: transform 700ms var(--ease-out); }
.cx-tile:hover .bg { transform: scale(1.05); }
.cx-tile .scrim { position:absolute; inset:0; background: linear-gradient(180deg, rgba(26,25,30,0.25) 0%, rgba(26,25,30,0.92) 82%); }
.cx-tile .bd { position:absolute; left:0; right:0; bottom:0; padding:28px; }
.cx-tile .ct { font-family: var(--font-mono); font-size:10px; letter-spacing:0.26em; color: var(--color-brass-bright); text-transform:uppercase; }
.cx-tile h2 { font-family: var(--font-display); font-size: clamp(36px,4.5vw,60px); line-height:1; color: var(--color-white); margin:8px 0 6px; letter-spacing:0.02em; }
.cx-tile p { color: var(--color-fog); font-size:14px; margin:0; max-width:42ch; }
.cx-tile .more { display:inline-flex; gap:8px; margin-top:14px; font-family: var(--font-condensed); font-weight:600; font-size:12px; letter-spacing:0.16em; color: var(--color-ember); text-transform:uppercase; }
.cx-why { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); }
.cx-why .wrap { max-width:1280px; margin:0 auto; padding:72px 32px; }
.cx-why h2 { font-family: var(--font-display); font-size: clamp(36px,5vw,60px); color: var(--color-white); }
.cx-why .row { display:grid; grid-template-columns: repeat(4,1fr); gap:12px; margin-top:28px; }
@media (max-width:860px){ .cx-why .row { grid-template-columns:1fr 1fr; } }
@media (max-width:480px){ .cx-why .row { grid-template-columns:1fr; } }
.cx-why .row a { display:block; padding:22px; background: var(--color-void); border:1px solid var(--color-hairline); text-decoration:none; transition: border-color 200ms var(--ease-std); }
.cx-why .row a:hover { border-color: var(--color-brass-dim); }
.cx-why .row a h3 { font-family: var(--font-condensed); font-weight:600; font-size:16px; text-transform:uppercase; letter-spacing:0.04em; color: var(--color-white); }
.cx-why .row a p { color: var(--color-fog); font-size:13px; margin:8px 0 0; line-height:1.6; }
</style>

<header class="cx-hero">
  <div class="container">
    <span class="eyebrow">Shop  Collections</span>
    <h1>BROWSE THE<br><span class="a">COLLECTIONS.</span></h1>
    <p class="lead">FFL-licensed inventory, hand-picked by NRA-certified instructors and organized so you find the right firearm fast. Every collection links straight to live inventory with same-day pickup at our Mesa storefront.</p>
  </div>
</header>

<section class="cx-grid">
  <?php foreach ( $tiles as $t ) : ?>
    <?php
    $term       = get_term_by( 'slug', $t['slug'], 'product_cat' );
    $term_count = $term && ! is_wp_error( $term ) ? (int) $term->count : 0;
    $term_name  = $term && ! is_wp_error( $term ) ? strtoupper( $term->name ) : $t['name'];
    ?>
    <a class="cx-tile" href="<?php echo esc_url( $t['href'] ); ?>">
      <div class="bg" style="background-image:url('<?php echo esc_url( $t['img'] ); ?>');"></div>
      <div class="scrim"></div>
      <div class="bd">
        <div class="ct">Collection <?php echo esc_html( (string) $term_count ); ?> SKUs</div>
        <h2><?php echo esc_html( $term_name ); ?></h2>
        <p><?php echo esc_html( $t['desc'] ); ?></p>
        <span class="more">Explore <?php echo esc_html( ucwords( strtolower( $term_name ) ) ); ?> &rarr;</span>
      </div>
    </a>
  <?php endforeach; ?>
</section>

<section class="cx-why">
  <div class="wrap">
    <span class="eyebrow" style="margin-bottom:18px;">Every Purchase, Covered</span>
    <h2>WHY SHOP GUNS&nbsp;2&nbsp;AMMO</h2>
    <div class="row">
      <a href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>"><h3>FFL Transfers</h3><p>Bought online? We process the federal transfer at a flat rate.</p></a>
      <a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>"><h3>Local Pickup</h3><p>Reserve online, pick up same day at 6030 E Main St, Mesa.</p></a>
      <a href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><h3>Expert Fitment</h3><p>Instructor guidance before you buy  no commission pressure.</p></a>
      <a href="<?php echo esc_url( home_url( '/ffl-services/' ) ); ?>"><h3>All Federal Laws</h3><p>Background checks and full Arizona &amp; federal compliance.</p></a>
    </div>
    <div style="margin-top:32px; display:flex; gap:12px; flex-wrap:wrap;">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">Shop All Products</a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>">Start A Transfer</a>
    </div>
  </div>
</section>
<?php get_footer();
