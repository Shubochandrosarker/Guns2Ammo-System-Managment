<?php
/**
 * Template Name: Collection Landing
 *
 * One template, four collection landing pages. Assign to a WordPress Page
 * whose slug is one of: handguns, rifles, ammunition, magazines
 * (recommended as children of /collections/).
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

// Single source of truth for NAP — see inc/business-info.php.
$g2a_biz                = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
$g2a_collection_street  = trim( explode( ',', $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' )[0] );
$g2a_collection_city    = $g2a_biz['city'] ?? 'Mesa';

$slug = '';
$qo   = get_queried_object();
if ( $qo && isset( $qo->post_name ) ) $slug = $qo->post_name;

$img_base = '/wp-content/uploads/';

$collections = [
	'handguns' => [
		'eyebrow'   => 'Collection  Pistols & Revolvers',
		'h1_top'    => 'CARRY-READY',
		'h1_accent' => 'HANDGUNS',
		'cat'       => 'handguns',
		'count'     => '24',
		'image'     => g2a_asset( 'img/guns2ammo-handgun-case-display.jpg' ),
		'intro'     => 'Compact carry pistols, full-size duty guns, and range-day favorites  every handgun on our floor is one our instructors would carry off-duty. Hands-on fitment, transparent pricing, and same-day pickup at our Mesa storefront.',
		'guide_h'   => 'CHOOSING YOUR FIRST HANDGUN',
		'guide'     => [
			[ 't' => 'Fit Before Features', 'd' => 'Grip circumference and trigger reach matter more than brand. Our instructors put three or four candidates in your hand before you ever commit.' ],
			[ 't' => 'Caliber For Carry', 'd' => '9mm remains the sweet spot for most carriers  manageable recoil, capacity, and affordable practice ammo so you actually train.' ],
			[ 't' => 'Optics-Ready Or Not', 'd' => 'A red-dot-cut slide future-proofs your purchase. We will walk you through whether a pistol optic fits how you intend to carry.' ],
		],
		'faq'       => [
			[ 'q' => 'Do I need a permit to buy a handgun in Arizona?', 'a' => 'Arizona does not require a permit to purchase. You must be 21+, pass the federal NICS background check, and complete ATF Form 4473 in store. Bring a valid Arizona ID.' ],
			[ 'q' => 'Can I handle the handgun before buying?', 'a' => 'Yes  and you should. Every handgun in this collection can be handled at the counter, and many can be rented on our indoor range so you can shoot before you decide.' ],
			[ 'q' => 'Do you price-match online retailers?', 'a' => 'We stay competitive on every firearm and you skip the FFL transfer fee and shipping wait by buying local. Ask an instructor for current pricing.' ],
		],
	],
	'rifles' => [
		'eyebrow'   => 'Collection  Rifles & Carbines',
		'h1_top'    => 'PRECISION-BUILT',
		'h1_accent' => 'RIFLES',
		'cat'       => 'rifles',
		'count'     => '12',
		'image'     => g2a_asset( 'img/guns2ammo-rifles-rack.jpg' ),
		'intro'     => 'AR-pattern carbines, precision bolt guns, and home-defense rifles from the brands our instructors trust. Cold-hammer-forged barrels, honest fitment advice, and full Arizona &amp; federal compliance on every build.',
		'guide_h'   => 'BUILDING THE RIGHT RIFLE',
		'guide'     => [
			[ 't' => 'Purpose Drives The Build', 'd' => 'Home defense, range competition, and hunting each ask for different barrel lengths and optics. Tell us the job and we narrow the field fast.' ],
			[ 't' => 'Barrel & Gas System', 'd' => 'A mid-length gas system on a 16-inch barrel is the do-everything standard. We explain the trade-offs before you spend.' ],
			[ 't' => 'SBR & NFA Options', 'd' => 'Short-barreled rifles and suppressor-ready builds are available through our FFL/SOT. We handle the paperwork end to end.' ],
		],
		'faq'       => [
			[ 'q' => 'What is the minimum age to buy a rifle?', 'a' => 'You must be 18+ to purchase a long gun and pass the federal NICS background check with completed ATF Form 4473. Bring valid Arizona ID.' ],
			[ 'q' => 'Can you build a custom AR-15 for me?', 'a' => 'Yes. We assemble to spec  barrel, handguard, trigger, optic  and every build leaves zeroed and function-checked.' ],
			[ 'q' => 'Do you sell short-barreled rifles?', 'a' => 'We do, as a licensed FFL/SOT. SBRs require an ATF tax stamp; our team manages the Form 1 or Form 4 process with you.' ],
		],
	],
	'ammunition' => [
		'eyebrow'   => 'Collection  Ammunition',
		'h1_top'    => 'RANGE & DEFENSE',
		'h1_accent' => 'AMMUNITION',
		'cat'       => 'ammunition',
		'count'     => '38',
		'image'     => g2a_asset( 'img/guns2ammo-ammo-shelves.jpg' ),
		'intro'     => 'Factory-new training ammo and premium personal-defense loads  9mm, .223/5.56, .45 ACP and more. Stocked in bulk for members, priced for the people who actually train.',
		'guide_h'   => 'TRAINING VS. DEFENSE AMMO',
		'guide'     => [
			[ 't' => 'Train With FMJ', 'd' => 'Full metal jacket rounds are affordable and reliable for high-volume range practice  buy by the case and save.' ],
			[ 't' => 'Carry With JHP', 'd' => 'Jacketed hollow points are engineered for controlled expansion. Always confirm your carry load runs reliably in your firearm.' ],
			[ 't' => 'Our Range Ammo Policy', 'd' => 'Factory-new ammunition only on our indoor range  no reloads and no steel-core. It keeps every lane safe.' ],
		],
		'faq'       => [
			[ 'q' => 'Can ammunition be shipped to my home?', 'a' => 'Pick up in store at ' . $g2a_collection_street . ', ' . $g2a_collection_city . '. In-store purchase is fastest and lets our team confirm the right load for your firearm.' ],
			[ 'q' => 'Do you offer bulk or case pricing?', 'a' => 'Yes. Case quantities are discounted, and members get additional savings on range and practice ammunition.' ],
			[ 'q' => 'What ammo can I use on your range?', 'a' => 'Factory-new brass or aluminum-cased ammunition only. No reloads, no steel-core, and no steel-jacketed rounds.' ],
		],
	],
	'magazines' => [
		'eyebrow'   => 'Collection  Magazines & Feeding Devices',
		'h1_top'    => 'RELIABLE',
		'h1_accent' => 'MAGAZINES',
		'cat'       => 'magazines',
		'count'     => '21',
		'image'     => g2a_asset( 'img/guns2ammo-gear-case.jpg' ),
		'intro'     => 'Factory and proven aftermarket magazines for pistols and AR-pattern rifles. Anti-tilt followers, hardened feed lips, and the capacities Arizona shooters actually want.',
		'guide_h'   => 'MAGAZINE BUYING BASICS',
		'guide'     => [
			[ 't' => 'Factory First', 'd' => 'For carry and duty use, factory magazines from your pistol maker are the safest bet. We stock OEM for every handgun we sell.' ],
			[ 't' => 'Rotate & Maintain', 'd' => 'Spring fatigue is a myth for quality mags, but grit is not. We carry the tools and follow-blocks to keep them clean.' ],
			[ 't' => 'Capacity & The Law', 'd' => 'Arizona places no magazine capacity limit. If you travel, ask us which states restrict capacity before you cross a line.' ],
		],
		'faq'       => [
			[ 'q' => 'Are aftermarket magazines reliable?', 'a' => 'The good ones are excellent  Magpul PMAGs for AR-15s are a proven standard. We only stock magazines our instructors run themselves.' ],
			[ 'q' => 'Is there a magazine capacity limit in Arizona?', 'a' => 'No. Arizona does not restrict magazine capacity. Capacity laws vary by state, so confirm before traveling out of state.' ],
			[ 'q' => 'Do you stock magazines for older firearms?', 'a' => 'Often, yes. Bring your firearm or the exact model and we will source the correct magazine even for discontinued platforms.' ],
		],
	],
];

$c = $collections[ $slug ] ?? $collections['handguns'];
$collection_slugs = [ 'handguns', 'rifles', 'ammunition', 'magazines' ];

/* Pull real WooCommerce products for this category, if WooCommerce is active. */
$products = [];
$term_count = 0;
if ( function_exists( 'wc_get_products' ) ) {
	$term = get_term_by( 'slug', $c['cat'], 'product_cat' );
	if ( $term && ! is_wp_error( $term ) ) {
		$term_count = (int) $term->count;
	}
	$products = wc_get_products( [
		'status'   => 'publish',
		'limit'    => 8,
		'category' => [ $c['cat'] ],
		'orderby'  => 'popularity',
	] );
}
$display_count = $term_count > 0 ? $term_count : (int) $c['count'];

/* FAQ schema for SEO / AEO */
if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $c['faq'] ) );
}
?>
<style>
.col-hero { padding: 140px 32px 72px; position:relative; overflow:hidden; }
.col-hero::before { content:""; position:absolute; inset:0; pointer-events:none; background-image: linear-gradient(180deg, var(--hero-scrim), var(--hero-scrim-deep)), url('<?php echo esc_url( $c['image'] ); ?>'); background-size:cover; background-position:center; filter: saturate(0.95); }
.col-hero .container { position:relative; max-width:1280px; margin:0 auto; }
.col-hero h1 { font-family: var(--font-display); font-size: clamp(56px, 9vw, 116px); line-height:0.9; letter-spacing:0.01em; margin-top:18px; color: var(--ink-on-media); }
.col-hero h1 .a { color: var(--ember-on-media); }
.col-hero .lead { color: var(--ink-on-media-dim); max-width:60ch; margin:22px 0 0; font-size:16px; line-height:1.7; }
.col-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--ink-on-media-faint); }
.col-hero .crumbs a { color: var(--brass-on-media); text-decoration:none; }
.col-hero .ctas { display:flex; gap:12px; margin-top:32px; flex-wrap:wrap; }

.col-section { max-width:1280px; margin:0 auto; padding:80px 32px; }
.col-section .sec-h2 { font-family: var(--font-display); font-size: clamp(40px,5.5vw,72px); line-height:0.95; color: var(--color-white); letter-spacing:0.01em; }
.col-products { margin-top:32px; }
.guide-grid { display:grid; grid-template-columns: repeat(3,1fr); gap:16px; margin-top:32px; }
@media (max-width:860px){ .guide-grid { grid-template-columns:1fr; } }
.guide-card { padding:28px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.guide-card h3 { font-family: var(--font-condensed); font-weight:600; font-size:20px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); }
.guide-card p { color: var(--color-fog); font-size:14px; line-height:1.7; margin:10px 0 0; }
.col-cross { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.cross-grid { display:grid; grid-template-columns: repeat(4,1fr); gap:12px; margin-top:28px; }
@media (max-width:860px){ .cross-grid { grid-template-columns:1fr 1fr; } }
@media (max-width:480px){ .cross-grid { grid-template-columns:1fr; } }
.cross-grid a { display:block; padding:20px; background: var(--color-void); border:1px solid var(--color-hairline); text-decoration:none; transition: border-color 200ms var(--ease-std), transform 200ms var(--ease-std); }
.cross-grid a:hover { border-color: var(--color-brass-dim); transform: translateY(-3px); }
.cross-grid a .nm { font-family: var(--font-display); font-size:26px; color: var(--color-white); letter-spacing:0.03em; }
.cross-grid a .mt { font-family: var(--font-mono); font-size:10px; letter-spacing:0.2em; color: var(--color-brass-bright); text-transform:uppercase; }
.col-place { display:grid; grid-template-columns: repeat(4,1fr); gap:16px; }
@media (max-width:1100px){ .col-place { grid-template-columns: repeat(3,1fr); } }
@media (max-width:780px){ .col-place { grid-template-columns: repeat(2,1fr); } }
@media (max-width:440px){ .col-place { grid-template-columns:1fr; } }
.col-place .pl { background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.col-place .pl .ph { aspect-ratio:1; background-image: repeating-linear-gradient(135deg,#2a2a30 0 14px,#232329 14px 28px); }
.col-place .pl .bd { padding:18px; }
.col-place .pl .bd .t { font-family: var(--font-condensed); font-weight:600; font-size:16px; color: var(--color-white); text-transform:uppercase; }
.col-place .pl .bd .p { font-family: var(--font-display); font-size:24px; color: var(--color-brass-bright); margin-top:6px; }
</style>

<header class="col-hero hero-media">
  <div class="container">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/collections/' ) ); ?>">Collections</a>  <?php echo esc_html( $c['h1_accent'] ); ?></div>
    <h1><?php echo esc_html( $c['h1_top'] ); ?><br><span class="a"><?php echo esc_html( $c['h1_accent'] ); ?></span></h1>
    <p class="lead"><?php echo wp_kses_post( $c['intro'] ); ?></p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( home_url( '/product-category/' . $c['cat'] . '/' ) ); ?>">Shop All <?php echo esc_html( $c['h1_accent'] ); ?> <?php echo esc_html( (string) $display_count ); ?></a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Talk To An Instructor</a>
    </div>
  </div>
</header>

<section class="col-section woocommerce">
  <div class="eyebrow" style="margin-bottom:18px;"><?php echo esc_html( $c['eyebrow'] ); ?></div>
  <h2 class="sec-h2">FEATURED IN THIS COLLECTION</h2>
  <div class="col-products">
    <?php if ( ! empty( $products ) ) : ?>
      <ul class="products">
        <?php foreach ( $products as $p ) :
          $pid = $p->get_id(); ?>
          <li class="product type-product status-publish instock">
            <a href="<?php echo esc_url( get_permalink( $pid ) ); ?>" class="woocommerce-LoopProduct-link">
              <div class="product-thumb"><?php echo $p->get_image( 'woocommerce_thumbnail' ); ?></div>
            </a>
            <div class="product-info">
              <h2 class="woocommerce-loop-product__title"><?php echo esc_html( $p->get_name() ); ?></h2>
              <p class="price"><?php echo wp_kses_post( $p->get_price_html() ); ?></p>
              <div>
                <span class="stock <?php echo $p->is_in_stock() ? 'in-stock' : 'out-of-stock'; ?>">
                  <?php echo $p->is_in_stock() ? 'In Stock' : 'Call For Avail.'; ?>
                </span>
              </div>
            </div>
            <div class="product-actions">
              <a class="btn btn-ember btn-sm" href="<?php echo esc_url( $p->add_to_cart_url() ); ?>"><?php echo esc_html( $p->add_to_cart_text() ); ?></a>
              <a class="btn btn-ghost btn-sm" href="<?php echo esc_url( get_permalink( $pid ) ); ?>">Details</a>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else : ?>
      <p style="color:var(--color-silver);font-size:14px;margin:0;">No products are currently assigned to this collection. <a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>" style="color:var(--color-brass-bright);">Browse all products &rarr;</a></p>
    <?php endif; ?>
  </div>
  <div style="margin-top:32px;">
    <a class="btn btn-brass btn-arrow" href="<?php echo esc_url( home_url( '/product-category/' . $c['cat'] . '/' ) ); ?>">View All <?php echo esc_html( (string) $display_count ); ?> <?php echo esc_html( $c['h1_accent'] ); ?></a>
  </div>
</section>

<section class="col-cross">
  <div class="col-section">
    <div class="eyebrow" style="margin-bottom:18px;">Buying Guide</div>
    <h2 class="sec-h2"><?php echo esc_html( $c['guide_h'] ); ?></h2>
    <div class="guide-grid">
      <?php foreach ( $c['guide'] as $g ) : ?>
        <div class="guide-card">
          <h3><?php echo esc_html( $g['t'] ); ?></h3>
          <p><?php echo esc_html( $g['d'] ); ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="col-section">
  <div class="eyebrow" style="margin-bottom:18px;">Common Questions</div>
  <h2 class="sec-h2" style="margin-bottom:28px;"><?php echo esc_html( $c['h1_accent'] ); ?> FAQ</h2>
  <div class="acc">
    <?php foreach ( $c['faq'] as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="col-cross">
  <div class="col-section">
    <div class="eyebrow" style="margin-bottom:18px;">Keep Exploring</div>
    <h2 class="sec-h2">ALL COLLECTIONS</h2>
    <div class="cross-grid">
      <?php foreach ( $collection_slugs as $collection_slug ) :
        $term = get_term_by( 'slug', $collection_slug, 'product_cat' );
        $term_name  = $term && ! is_wp_error( $term ) ? $term->name : ucwords( str_replace( '-', ' ', $collection_slug ) );
        $term_count = $term && ! is_wp_error( $term ) ? (int) $term->count : 0;
      ?>
      <a href="<?php echo esc_url( home_url( '/collections/' . $collection_slug . '/' ) ); ?>"><span class="mt"><?php echo esc_html( (string) $term_count ); ?> SKUs</span><div class="nm"><?php echo esc_html( $term_name ); ?></div></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php get_footer();
