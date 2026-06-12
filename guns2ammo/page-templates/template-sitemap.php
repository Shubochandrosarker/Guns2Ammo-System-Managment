<?php
/**
 * Template Name: HTML Sitemap
 *
 * Branded human-readable sitemap. Assign to a Page with slug: sitemap
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$groups = [
	'Range' => [
		[ 'Book A Lane', '/book-a-lane/' ],
		[ 'Range Fees & Pricing', '/pricing/' ],
		[ 'Ladies Tuesday', '/ladies-tuesday/' ],
		[ 'Range Safety Rules', '/range-safety/' ],
	],
	'Training' => [
		[ 'Course Catalog', '/training/' ],
		[ 'Arizona CCW Certification', '/arizona-ccw-certification/' ],
		[ 'Arizona CCW Syllabus', '/arizona-ccw-syllabus/' ],
		[ 'Basic Handgun', '/training/basic-handgun/' ],
		[ 'California CCW', '/training/california-ccw/' ],
		[ 'Church Security', '/training/church-security/' ],
		[ "Women's Intro", '/training/womens-intro/' ],
		[ 'Defensive Pistol', '/training/defensive-pistol/' ],
		[ 'Rifle Fundamentals', '/training/rifle-fundamentals/' ],
		[ 'Refuse To Be A Victim', '/training/refuse-to-be-a-victim/' ],
		[ 'Youth Firearm Safety', '/training/youth-firearm-safety/' ],
		[ 'Private Instruction', '/private-instruction/' ],
	],
	'Machine Gun Experience' => [
		[ 'Machine Gun Packages', '/machine-gun/' ],
		[ 'MP5 Experience', '/machine-gun/mp5/' ],
		[ 'M16 Experience', '/machine-gun/m16/' ],
		[ 'AK-47 Experience', '/machine-gun/ak-47/' ],
		[ 'Free Arizona CCW Class', '/free-ccw-class/' ],
	],
	'Shop' => [
		[ 'All Products', '/shop/' ],
		[ 'All Collections', '/collections/' ],
		[ 'Handguns', '/collections/handguns/' ],
		[ 'Rifles', '/collections/rifles/' ],
		[ 'Ammunition', '/collections/ammunition/' ],
		[ 'Magazines', '/collections/magazines/' ],
		[ 'FFL Transfers Info', '/transfers/' ],
		[ 'Cart', '/cart/' ],
		[ 'My Account', '/account/' ],
	],
	'Transfers & Selling' => [
		[ 'FFL Transfer Services', '/transfers/' ],
		[ 'Start A Transfer Request', '/transfer-request/' ],
		[ 'FFL & NFA Services', '/ffl-services/' ],
		[ 'Sell Your Gun', '/sell-your-gun/' ],
	],
	'Membership' => [
		[ 'Plans & Pricing', '/memberships/' ],
		[ 'Member Login', '/login/' ],
		[ 'My Account', '/account/' ],
	],
	'Learn' => [
		[ 'Knowledge Hub', '/blog/' ],
	],
	'Company' => [
		[ 'About Guns 2 Ammo', '/about/' ],
		[ 'Contact', '/contact/' ],
		[ 'Get Support', '/get-support/' ],
	],
	'Legal' => [
		[ 'Privacy Policy', '/privacy-policy/' ],
		[ 'Terms & Conditions', '/terms-and-conditions/' ],
		[ 'Refund & Returns Policy', '/refund-and-returns-policy/' ],
	],
];
?>
<style>
.sm-hero { padding:140px 32px 56px; background: radial-gradient(ellipse at 30% 40%, rgba(201,168,76,0.1), transparent 60%), linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.sm-hero .c { max-width:1280px; margin:0 auto; }
.sm-hero h1 { font-family: var(--font-display); font-size: clamp(52px,8vw,116px); line-height:0.9; color: var(--color-white); margin:14px 0 0; }
.sm-hero h1 .a { color: var(--color-brass-bright); }
.sm-hero .lead { color: var(--color-fog); max-width:60ch; margin:18px 0 0; font-size:15px; line-height:1.7; }
.sm-wrap { max-width:1280px; margin:0 auto; padding:64px 32px 96px; display:grid; grid-template-columns: repeat(3,1fr); gap:18px; }
@media (max-width:900px){ .sm-wrap { grid-template-columns:1fr 1fr; } }
@media (max-width:560px){ .sm-wrap { grid-template-columns:1fr; } }
.sm-card { background: var(--color-gunmetal); border:1px solid var(--color-hairline); padding:26px; }
.sm-card h2 { font-family: var(--font-display); font-size:26px; color: var(--color-brass-bright); letter-spacing:0.03em; margin:0 0 14px; padding-bottom:12px; border-bottom:1px solid var(--color-hairline); }
.sm-card ul { list-style:none; padding:0; margin:0; display:grid; gap:9px; }
.sm-card ul li a { color: var(--color-fog); text-decoration:none; font-family: var(--font-ui); font-weight:500; font-size:14px; display:inline-flex; gap:8px; }
.sm-card ul li a::before { content:""; color: var(--color-brass-bright); }
.sm-card ul li a:hover { color: var(--color-brass-bright); }
.sm-posts { max-width:1280px; margin:0 auto; padding:0 32px 96px; }
.sm-posts h2 { font-family: var(--font-display); font-size:32px; color: var(--color-white); margin-bottom:16px; }
.sm-posts ul { list-style:none; padding:0; margin:0; columns:2; }
@media (max-width:600px){ .sm-posts ul { columns:1; } }
.sm-posts ul li { margin:0 0 8px; }
.sm-posts ul li a { color: var(--color-fog); text-decoration:none; font-size:14px; }
.sm-posts ul li a:hover { color: var(--color-brass-bright); }
</style>

<header class="sm-hero">
  <div class="c">
    <span class="eyebrow">Guns 2 Ammo  Site Map</span>
    <h1>FIND <span class="a">EVERYTHING.</span></h1>
    <p class="lead">Every page on the Guns 2 Ammo website, organized by area  the range, training, the machine gun experience, the shop, memberships, and more. Looking for the XML sitemap? It's at <a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" style="color:var(--color-brass-bright);">/sitemap.xml</a>.</p>
  </div>
</header>

<div class="sm-wrap">
  <?php foreach ( $groups as $title => $links ) : ?>
    <section class="sm-card">
      <h2><?php echo esc_html( $title ); ?></h2>
      <ul>
        <?php foreach ( $links as $l ) : ?>
          <li><a href="<?php echo esc_url( home_url( $l[1] ) ); ?>"><?php echo esc_html( $l[0] ); ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
</div>

<?php
$sm_posts = get_posts( [ 'numberposts' => 24, 'post_status' => 'publish' ] );
if ( $sm_posts ) : ?>
<section class="sm-posts">
  <h2>From The Knowledge Hub</h2>
  <ul>
    <?php foreach ( $sm_posts as $p ) : ?>
      <li><a href="<?php echo esc_url( get_permalink( $p ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
<?php get_footer();
