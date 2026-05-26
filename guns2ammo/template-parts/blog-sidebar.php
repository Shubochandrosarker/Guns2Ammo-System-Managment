<?php
/**
 * Knowledge Hub sidebar  search, categories, recent posts, and promotions.
 * Shared by index.php (blog / category archives) and single.php.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$recent = get_posts( [ 'numberposts' => 5, 'post_status' => 'publish' ] );
?>
<aside class="kh-side">
  <style>
  .kh-side { display:flex; flex-direction:column; gap:20px; }
  .kh-side .sw { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding:24px; }
  .kh-side .sw h4 { font-family: var(--font-mono); font-size:11px; letter-spacing:0.24em; color: var(--color-silver); text-transform:uppercase; margin:0 0 14px; }
  .kh-side form.search-form { display:flex; gap:8px; }
  .kh-side form.search-form input[type=search] { flex:1; min-width:0; background: var(--color-void); border:1px solid var(--color-hairline-bright); color: var(--color-white); padding:11px 12px; font-family: var(--font-body); font-size:14px; outline:none; }
  .kh-side form.search-form input[type=search]:focus { border-color: var(--color-brass); }
  .kh-side form.search-form button { background: var(--color-brass); color:#111; border:none; padding:0 14px; font-family: var(--font-condensed); font-weight:600; font-size:12px; letter-spacing:0.1em; text-transform:uppercase; cursor:pointer; }
  .kh-side ul.cats { list-style:none; padding:0; margin:0; }
  .kh-side ul.cats li { border-bottom:1px solid var(--color-hairline); }
  .kh-side ul.cats li:last-child { border-bottom:0; }
  .kh-side ul.cats li a { display:flex; justify-content:space-between; gap:10px; padding:10px 0; color: var(--color-fog); text-decoration:none; font-family: var(--font-ui); font-weight:500; font-size:14px; }
  .kh-side ul.cats li a:hover { color: var(--color-brass-bright); }
  .kh-side .rp { display:flex; gap:12px; padding:12px 0; border-bottom:1px solid var(--color-hairline); text-decoration:none; }
  .kh-side .rp:last-child { border-bottom:0; padding-bottom:0; }
  .kh-side .rp .rt { font-family: var(--font-condensed); font-weight:600; font-size:14px; color: var(--color-white); line-height:1.3; }
  .kh-side .rp:hover .rt { color: var(--color-brass-bright); }
  .kh-side .rp .rd { font-family: var(--font-mono); font-size:10px; letter-spacing:0.16em; color: var(--color-silver); text-transform:uppercase; margin-top:4px; }
  .kh-side .promo { background: linear-gradient(135deg, rgba(201,168,76,0.16), rgba(232,128,47,0.06)); border:1px solid var(--color-brass-dim); padding:26px; }
  .kh-side .promo .eyebrow { margin-bottom:12px; }
  .kh-side .promo h4 { font-family: var(--font-display); font-size:30px; color: var(--color-white); letter-spacing:0.02em; line-height:1; margin:0 0 10px; text-transform:none; }
  .kh-side .promo p { color: var(--color-fog); font-size:13px; line-height:1.65; margin:0 0 16px; }
  .kh-side .promo.dark { background: var(--color-gunmetal); border-color: var(--color-hairline-bright); }
  </style>

  <div class="sw">
    <h4>Search The Hub</h4>
    <?php get_search_form(); ?>
  </div>

  <div class="sw">
    <h4>Browse Topics</h4>
    <ul class="cats">
      <?php
      wp_list_categories( [
        'title_li'     => '',
        'show_count'   => true,
        'hide_empty'   => false,
        'walker'       => null,
      ] );
      ?>
    </ul>
  </div>

  <div class="promo">
    <div class="eyebrow">Turn Guns Into Cash</div>
    <h4>Sell Your Used Guns To Guns&nbsp;2&nbsp;Ammo</h4>
    <p>Get a fair cash offer from a licensed Mesa dealer  no listing hassle, no private-sale risk. Most offers come back the same day.</p>
    <a class="btn btn-brass btn-sm" style="width:100%;" href="<?php echo esc_url( home_url( '/sell-your-gun/' ) ); ?>">Get My Cash Offer </a>
  </div>

  <?php if ( $recent ) : ?>
  <div class="sw">
    <h4>Recent Articles</h4>
    <?php foreach ( $recent as $rp ) : ?>
      <a class="rp" href="<?php echo esc_url( get_permalink( $rp ) ); ?>">
        <div>
          <div class="rt"><?php echo esc_html( get_the_title( $rp ) ); ?></div>
          <div class="rd"><?php echo esc_html( get_the_date( 'M j, Y', $rp ) ); ?></div>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="promo dark">
    <div class="eyebrow">Join The Range</div>
    <h4 style="font-size:26px;">Unlimited Range Time From $29.99/mo</h4>
    <p>Defender, Patriot &amp; Guardian memberships  unlimited lanes, member pricing, no contracts.</p>
    <a class="btn btn-ember btn-sm" style="width:100%;" href="<?php echo esc_url( home_url( '/memberships/' ) ); ?>">See Membership Plans </a>
  </div>
</aside>
