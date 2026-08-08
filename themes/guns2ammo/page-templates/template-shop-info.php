<?php
/**
 * Template Name: Shop Info Landing
 *
 * One template, four shop-assurance landing pages. Assign to a Page whose
 * slug is one of: ffl-transfers, local-pickup, expert-fitment, federal-compliance
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

// Single source of truth for NAP + hours — see inc/business-info.php.
$g2a_biz             = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
$g2a_si_street       = trim( explode( ',', $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' )[0] );
$g2a_si_city         = $g2a_biz['city'] ?? 'Mesa';
$g2a_si_addr_line    = function_exists( 'g2a_biz_addr_line' ) ? g2a_biz_addr_line() : '6030 E Main St, Suite 103, Mesa, AZ 85205';
$g2a_si_hours_human  = function_exists( 'g2a_biz_hours_human' ) ? g2a_biz_hours_human() : 'Mon–Thu 10am–6pm · Fri 10am–7pm · Sat 10am–7pm · Sun 12pm–6pm';

$slug = '';
$qo   = get_queried_object();
if ( $qo && isset( $qo->post_name ) ) $slug = $qo->post_name;

$pages = [
	'ffl-transfers' => [
		'eyebrow' => 'Shop Services  FFL',
		'h1'      => 'FFL FIREARM',
		'h1a'     => 'TRANSFERS',
		'lead'    => 'Bought a firearm online or from a private party out of state? As a licensed FFL dealer in Mesa, we receive it, run the federal background check, and hand it over  fully compliant, flat-rate, no drama.',
		'cta_t'   => 'Start A Transfer',
		'cta_h'   => home_url( '/transfers/' ),
		'steps_h' => 'HOW A TRANSFER WORKS',
		'steps'   => [
			[ 'n' => '01', 't' => 'Send Us Your FFL', 'd' => 'Have your online seller ship to Guns 2 Ammo. We email our FFL license on request  most retailers already have it on file.' ],
			[ 'n' => '02', 't' => 'We Receive & Log', 'd' => 'Your firearm arrives, we log it into our bound book, and we text you the moment it is ready for pickup.' ],
			[ 'n' => '03', 't' => 'Background Check', 'd' => 'Come in with valid Arizona ID, complete ATF Form 4473, and we run the NICS background check on the spot.' ],
			[ 'n' => '04', 't' => 'Take It Home', 'd' => 'Once approved, your firearm is yours. Most transfers are completed in a single visit.' ],
		],
		'detail_h' => 'TRANSFER PRICING & DETAILS',
		'detail'  => [
			'Flat-rate transfer fee  no per-item surprises, no handling add-ons.',
			'Out-of-state and online purchases both welcome.',
			'Private-party transfers between Arizona residents are handled here too.',
			'We will not accept a firearm that violates federal or Arizona law  we tell you before it ships.',
		],
		'faq'     => [
			[ 'q' => 'How long does an FFL transfer take?', 'a' => 'Once your firearm arrives and the NICS check is approved, most transfers finish in one visit. Background checks are usually instant; occasionally NICS issues a delay.' ],
			[ 'q' => 'What do I need to bring?', 'a' => 'A valid Arizona driver license or state ID with your current address. That is enough to complete ATF Form 4473.' ],
			[ 'q' => 'Can you receive a firearm from a private seller?', 'a' => 'Yes. Interstate private sales must ship to an FFL  that is us. Arizona-to-Arizona private transfers can also be processed here for peace of mind.' ],
		],
	],
	'local-pickup' => [
		'eyebrow' => 'Shop Services  Pickup',
		'h1'      => 'SAME-DAY',
		'h1a'     => 'LOCAL PICKUP',
		'lead'    => 'Skip the shipping fees and the waiting. Reserve online and pick up your firearms, ammo, and gear at our Mesa storefront  often the same day you order.',
		'cta_t'   => 'Browse The Shop',
		'cta_h'   => home_url( '/shop/' ),
		'steps_h' => 'HOW PICKUP WORKS',
		'steps'   => [
			[ 'n' => '01', 't' => 'Order Online', 'd' => 'Add items to your cart and choose local pickup at checkout. No shipping charge.' ],
			[ 'n' => '02', 't' => 'We Pull It', 'd' => 'Our team pulls and stages your order, then texts you when it is ready  usually within hours.' ],
			[ 'n' => '03', 't' => 'Come In With ID', 'd' => 'Bring valid Arizona ID. For firearms, you complete ATF Form 4473 and the NICS check in store.' ],
			[ 'n' => '04', 't' => 'Out The Door', 'd' => 'Non-firearm items are grab-and-go. Firearms release once your background check clears.' ],
		],
		'detail_h' => 'PICKUP LOCATION & HOURS',
		'detail'  => [
			( $g2a_biz['name'] ?? 'Guns 2 Ammo' ) . ' · ' . $g2a_si_addr_line . '.',
			$g2a_si_hours_human . '.',
			'Ammunition and accessories are released immediately at pickup.',
			'Firearms release after ATF Form 4473 and an approved NICS background check.',
		],
		'faq'     => [
			[ 'q' => 'Is local pickup really free?', 'a' => 'Yes  there is no shipping or handling charge for local pickup. You only pay for your items and applicable Arizona sales tax.' ],
			[ 'q' => 'How fast can I pick up?', 'a' => 'In-stock orders are usually ready within a few hours during business hours. We text you the moment your order is staged.' ],
			[ 'q' => 'Can someone else pick up my order?', 'a' => 'Non-firearm items, yes, with your order confirmation. A firearm must be picked up by the buyer, who completes the federal paperwork in person.' ],
		],
	],
	'expert-fitment' => [
		'eyebrow' => 'Shop Services  Fitment',
		'h1'      => 'EXPERT',
		'h1a'     => 'FITMENT',
		'lead'    => 'A firearm that fits your hand, your purpose, and your experience level is a firearm you will actually train with. Sit down with an NRA-certified instructor before you buy  honest advice, zero commission pressure.',
		'cta_t'   => 'Book A Fitment Session',
		'cta_h'   => home_url( '/contact/' ),
		'steps_h' => 'WHAT A FITMENT COVERS',
		'steps'   => [
			[ 'n' => '01', 't' => 'Your Purpose', 'd' => 'Concealed carry, home defense, range sport, or first firearm  we start with how you intend to use it.' ],
			[ 'n' => '02', 't' => 'Hands-On Sizing', 'd' => 'Grip circumference, trigger reach, slide manipulation. We put several candidates in your hand side by side.' ],
			[ 'n' => '03', 't' => 'Shoot Before You Buy', 'd' => 'Rent your top picks on our indoor range and feel the recoil and ergonomics for yourself.' ],
			[ 'n' => '04', 't' => 'Clear Recommendation', 'd' => 'You leave with a short list that fits  and the training path to go with it.' ],
		],
		'detail_h' => 'WHY FITMENT MATTERS',
		'detail'  => [
			'A poorly fitted pistol is the number one reason new shooters stop practicing.',
			'Our instructors are paid to teach  not to push a brand or hit a quota.',
			'Fitment pairs naturally with our Basic Handgun and CCW courses.',
			'Members get priority fitment scheduling and discounted range rentals.',
		],
		'faq'     => [
			[ 'q' => 'Does a fitment session cost anything?', 'a' => 'A counter consultation is free. If you want to rent firearms on the range to test them, standard range and rental rates apply  and members save on both.' ],
			[ 'q' => 'I have never held a gun. Is that okay?', 'a' => 'That is exactly who fitment is for. Our instructors specialize in first-time shooters and will never make you feel rushed or judged.' ],
			[ 'q' => 'Can you fit a firearm for someone with smaller hands?', 'a' => 'Absolutely. Grip reach and frame size vary widely between models  fitment is how we match the firearm to the shooter.' ],
		],
	],
	'federal-compliance' => [
		'eyebrow' => 'Shop Services  Compliance',
		'h1'      => 'ALL FEDERAL',
		'h1a'     => 'LAWS',
		'lead'    => 'Every firearm sale at Guns 2 Ammo follows federal and Arizona law to the letter  background checks, age verification, and proper documentation on each transaction. Compliance protects you as much as it protects us.',
		'cta_t'   => 'Questions? Contact Us',
		'cta_h'   => home_url( '/contact/' ),
		'steps_h' => 'COMPLIANCE ON EVERY SALE',
		'steps'   => [
			[ 'n' => '01', 't' => 'Age Verification', 'd' => 'Long guns require buyers 18+; handguns require 21+. We verify with valid government-issued ID.' ],
			[ 'n' => '02', 't' => 'ATF Form 4473', 'd' => 'Every firearm purchase includes the federal Firearms Transaction Record, completed truthfully in store.' ],
			[ 'n' => '03', 't' => 'NICS Background Check', 'd' => 'We submit the federal background check before any firearm is released. No check, no transfer.' ],
			[ 'n' => '04', 't' => 'Bound-Book Logging', 'd' => 'Each firearm is logged in and out of our ATF bound book  the legal record of custody.' ],
		],
		'detail_h' => 'WHAT THE LAW REQUIRES',
		'detail'  => [
			'Arizona does not require a separate permit to purchase a firearm.',
			'A federal NICS background check is mandatory on every dealer sale.',
			'Falsifying ATF Form 4473 is a federal felony  we will walk you through it honestly.',
			'Prohibited possessors cannot purchase; we decline any sale that fails the check.',
		],
		'faq'     => [
			[ 'q' => 'Do I need a permit to buy a gun in Arizona?', 'a' => 'No state permit is required to purchase. You must pass the federal NICS background check and complete ATF Form 4473 with valid ID.' ],
			[ 'q' => 'What can delay or deny a background check?', 'a' => 'A criminal record, certain protective orders, or matching records that need review can cause a delay or denial. NICS, not the store, makes that determination.' ],
			[ 'q' => 'Can I buy a firearm as a gift?', 'a' => 'You may buy a firearm as a genuine gift, but you cannot buy one on behalf of someone who is a prohibited possessor  that is an illegal straw purchase. Ask us and we will explain the legal way.' ],
		],
	],
];

$d = $pages[ $slug ] ?? $pages['ffl-transfers'];

if ( function_exists( 'g2a_faq_schema' ) && function_exists( 'g2a_emit_jsonld' ) ) {
	g2a_emit_jsonld( g2a_faq_schema( $d['faq'] ) );
}
?>
<style>
.si-hero { padding: 140px 32px 64px; background: linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.si-hero .container { max-width:1100px; margin:0 auto; }
.si-hero .crumbs { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); }
.si-hero .crumbs a { color: var(--color-brass-bright); text-decoration:none; }
.si-hero h1 { font-family: var(--font-display); font-size: clamp(52px,8vw,104px); line-height:0.9; margin-top:16px; }
.si-hero h1 .a { color: var(--color-ember); }
.si-hero .lead { color: var(--color-fog); max-width:62ch; margin:22px 0 0; font-size:16px; line-height:1.75; }
.si-hero .ctas { display:flex; gap:12px; margin-top:30px; flex-wrap:wrap; }
.si-sec { max-width:1100px; margin:0 auto; padding:72px 32px; }
.si-sec h2 { font-family: var(--font-display); font-size: clamp(36px,5vw,64px); color: var(--color-white); letter-spacing:0.01em; }
.si-steps { display:grid; grid-template-columns: repeat(4,1fr); gap:14px; margin-top:32px; }
@media (max-width:880px){ .si-steps { grid-template-columns:1fr 1fr; } }
@media (max-width:480px){ .si-steps { grid-template-columns:1fr; } }
.si-step { padding:24px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.si-step .n { font-family: var(--font-display); font-size:40px; color: var(--color-brass-bright); line-height:1; }
.si-step .t { font-family: var(--font-condensed); font-weight:600; font-size:17px; text-transform:uppercase; letter-spacing:0.03em; color: var(--color-white); margin-top:8px; }
.si-step .d { color: var(--color-fog); font-size:13px; line-height:1.65; margin-top:8px; }
.si-detail { background: var(--color-gunmetal); border-top:1px solid var(--color-hairline); border-bottom:1px solid var(--color-hairline); }
.si-cta { max-width:1100px; margin:0 auto; padding:72px 32px; text-align:center; }
</style>

<header class="si-hero">
  <div class="container">
    <div class="crumbs"><a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>  <a href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">Shop</a>  <?php echo esc_html( $d['h1a'] ); ?></div>
    <h1><?php echo esc_html( $d['h1'] ); ?> <span class="a"><?php echo esc_html( $d['h1a'] ); ?></span></h1>
    <p class="lead"><?php echo esc_html( $d['lead'] ); ?></p>
    <div class="ctas">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( $d['cta_h'] ); ?>"><?php echo esc_html( $d['cta_t'] ); ?></a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/shop/' ) ); ?>">Back To Shop</a>
    </div>
  </div>
</header>

<section class="si-sec">
  <div class="eyebrow" style="margin-bottom:18px;"><?php echo esc_html( $d['eyebrow'] ); ?></div>
  <h2><?php echo esc_html( $d['steps_h'] ); ?></h2>
  <div class="si-steps">
    <?php foreach ( $d['steps'] as $s ) : ?>
      <div class="si-step">
        <div class="n"><?php echo esc_html( $s['n'] ); ?></div>
        <div class="t"><?php echo esc_html( $s['t'] ); ?></div>
        <div class="d"><?php echo esc_html( $s['d'] ); ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="si-detail">
  <div class="si-sec">
    <h2><?php echo esc_html( $d['detail_h'] ); ?></h2>
    <ul class="bullets" style="margin-top:24px;">
      <?php foreach ( $d['detail'] as $line ) : ?>
        <li><?php echo esc_html( $line ); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<section class="si-sec">
  <div class="eyebrow" style="margin-bottom:18px;">Common Questions</div>
  <h2 style="margin-bottom:28px;"><?php echo esc_html( $d['h1a'] ); ?> FAQ</h2>
  <div class="acc">
    <?php foreach ( $d['faq'] as $i => $f ) : ?>
      <details<?php echo 0 === $i ? ' open' : ''; ?>>
        <summary><?php echo esc_html( $f['q'] ); ?></summary>
        <div class="answer"><?php echo esc_html( $f['a'] ); ?></div>
      </details>
    <?php endforeach; ?>
  </div>
</section>

<section class="si-detail">
  <div class="si-cta">
    <span class="eyebrow" style="margin:0 auto 18px;">Ready When You Are</span>
    <h2 style="font-family:var(--font-display); font-size:clamp(36px,5vw,64px); color:var(--color-white);">QUESTIONS? WE'VE GOT ANSWERS.</h2>
    <p style="color:var(--color-fog); max-width:52ch; margin:16px auto 28px;">Our team at <?php echo esc_html( $g2a_si_street ); ?> in <?php echo esc_html( $g2a_si_city ); ?> is happy to walk you through it in person or over the phone.</p>
    <div style="display:flex; gap:12px; justify-content:center; flex-wrap:wrap;">
      <a class="btn btn-ember btn-arrow" href="<?php echo esc_url( $d['cta_h'] ); ?>"><?php echo esc_html( $d['cta_t'] ); ?></a>
      <a class="btn btn-ghost" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>">Contact Guns 2 Ammo</a>
    </div>
  </div>
</section>
<?php get_footer();
