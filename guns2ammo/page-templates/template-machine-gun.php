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
<header class="mg-hero hero-media">
  <div class="c">
    <span class="eyebrow" style="color: var(--color-ember);">Signature Experience  Booked 4–6 weeks out</span>
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

<?php
/* Machine Gun Inventory — data-driven from the G2A Theme Control
 * repeater field "Machine Gun Inventory". Edit at:
 *   WP Admin → Pages → (this page) → G2A Theme Control → Machine Gun Inventory
 * Each row supports: name, image, caliber, RPM, magazine, category,
 * price, description, "is_featured" flag (1 = show in hero row),
 * detail-page URL. Falls back to a clear admin-only empty state when
 * no items have been added yet, so the page never reads as broken.
 */
$g2a_mg_items   = (array) get_post_meta( $g2a_page_id, 'mg_inventory_items', true );
$g2a_mg_items   = array_values( array_filter( $g2a_mg_items, function( $r ) {
	return is_array( $r ) && ! empty( $r['name'] );
} ) );
$g2a_mg_featured = array_values( array_filter( $g2a_mg_items, function( $r ) {
	return ! empty( $r['is_featured'] );
} ) );
$g2a_mg_rest     = array_values( array_filter( $g2a_mg_items, function( $r ) {
	return empty( $r['is_featured'] );
} ) );

/* Helper: render one weapon card from a repeater row. */
function g2a_render_mg_card( $row, $tag_index = 0 ) {
	$name     = isset( $row['name'] )        ? $row['name']        : '';
	$cal      = isset( $row['caliber'] )     ? $row['caliber']     : '';
	$rpm      = isset( $row['rpm'] )         ? $row['rpm']         : '';
	$mag      = isset( $row['magazine'] )    ? $row['magazine']    : '';
	$cat      = isset( $row['category'] )    ? $row['category']    : '';
	$price    = isset( $row['price'] )       ? $row['price']       : '';
	$desc     = isset( $row['description'] ) ? $row['description'] : '';
	$link     = isset( $row['link'] )        ? esc_url( $row['link'] ) : '';
	$img_id   = isset( $row['image'] )       ? (int) $row['image'] : 0;
	$img_html = $img_id ? wp_get_attachment_image( $img_id, 'large', false, array( 'style' => 'width:100%;height:160px;object-fit:cover;margin-bottom:16px;' ) ) : '';
	$tag_num  = str_pad( (string) $tag_index, 2, '0', STR_PAD_LEFT );
	?>
	<article class="weapon">
		<?php echo $img_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_attachment_image is safe. ?>
		<div>
			<span class="tag"><?php echo esc_html( $tag_num ); ?><?php echo $cat ? ' / ' . esc_html( strtoupper( $cat ) ) : ''; ?></span>
			<h3>
				<?php if ( $link ) : ?>
					<a href="<?php echo $link; ?>" style="color:inherit;text-decoration:none;"><?php echo esc_html( $name ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $name ); ?>
				<?php endif; ?>
			</h3>
			<?php if ( $cal ) : ?><div class="cal"><?php echo esc_html( $cal ); ?></div><?php endif; ?>
		</div>
		<?php if ( $rpm || $mag || $cal || $price ) : ?>
		<div class="specs">
			<?php if ( $rpm )   : ?><div><div class="v"><?php echo esc_html( $rpm );   ?></div><div class="l">RPM</div></div><?php endif; ?>
			<?php if ( $mag )   : ?><div><div class="v"><?php echo esc_html( $mag );   ?></div><div class="l">MAGAZINE</div></div><?php endif; ?>
			<?php if ( $cal )   : ?><div><div class="v"><?php echo esc_html( $cal );   ?></div><div class="l">CALIBER</div></div><?php endif; ?>
			<?php if ( $price ) : ?><div><div class="v"><?php echo esc_html( $price ); ?></div><div class="l">STARTING</div></div><?php endif; ?>
		</div>
		<?php endif; ?>
		<?php if ( $desc ) : ?>
			<p style="color:var(--color-fog);font-size:13px;line-height:1.6;margin:0 0 16px;"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php if ( $link ) : ?>
			<a class="add" href="<?php echo $link; ?>">EXPLORE <?php echo esc_html( strtoupper( $name ) ); ?> </a>
		<?php endif; ?>
	</article>
	<?php
}
?>

<section class="weapons">
  <div class="weapons-head">
    <div><span class="eyebrow"> The Arsenal</span><h2 style="margin-top:18px;">THREE PLATFORMS.<br>ONE STANDARD.</h2></div>
    <p style="color: var(--color-fog); max-width: 380px;">Each weapon is maintained to mil-spec by our armorer. Every package includes ammunition, eye/ear, and targets.</p>
  </div>

  <div class="weapons-grid">
    <?php if ( ! empty( $g2a_mg_featured ) ) : ?>
        <?php foreach ( $g2a_mg_featured as $i => $row ) {
            g2a_render_mg_card( $row, $i + 1 );
        } ?>
    <?php elseif ( current_user_can( 'edit_pages' ) ) : ?>
        <div style="grid-column:1 / -1;padding:24px;border:1px dashed #c9a84c;background:rgba(201,168,76,.08);color:#c9a84c;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;">
            <strong>Admin note (only you see this):</strong>
            No featured machine guns yet. Edit this page → "Machine Gun Inventory" panel → add weapons and mark up to 3 with <code>is_featured = 1</code>.
        </div>
    <?php else : ?>
        <p style="color:var(--color-fog);grid-column:1/-1;text-align:center;">Inventory is being updated. Call the range for the current full-auto roster.</p>
    <?php endif; ?>
  </div>
</section>

<?php if ( ! empty( $g2a_mg_rest ) ) : ?>
<section class="weapons" style="border-top:0;padding-top:0;">
  <div class="weapons-head">
    <div><span class="eyebrow"> Complete Inventory</span><h2 style="margin-top:18px;">THE FULL ROSTER.</h2></div>
    <p style="color: var(--color-fog); max-width: 380px;">Our complete machine-gun collection — over twenty platforms across every era and class.</p>
  </div>
  <div class="weapons-grid">
    <?php foreach ( $g2a_mg_rest as $i => $row ) {
        g2a_render_mg_card( $row, count( $g2a_mg_featured ) + $i + 1 );
    } ?>
  </div>
</section>
<?php endif; ?>

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
