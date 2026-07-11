<?php
/**
 * Template Name: Legal Document
 *
 * One template for the Privacy Policy, Terms & Conditions, and Refund &
 * Returns Policy. Assign to a Page whose slug is one of:
 * privacy-policy, terms-and-conditions, refund-and-returns-policy
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

// Single source of truth for NAP — see inc/business-info.php.
$g2a_biz          = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
$g2a_legal_email  = $g2a_biz['email'] ?? 'sales@guns2ammo.com';
$g2a_legal_phone  = $g2a_biz['phone'] ?? '(602) 715-2677';
$g2a_legal_addr   = 'Guns 2 Ammo, ' . ( $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' ) . ', ' . ( $g2a_biz['addr2'] ?? 'Mesa, AZ 85205' );
$g2a_legal_addr_no_prefix = ( $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' ) . ', ' . ( $g2a_biz['addr2'] ?? 'Mesa, AZ 85205' );

$slug    = '';
$qo      = get_queried_object();
if ( $qo && isset( $qo->post_name ) ) $slug = $qo->post_name;
$updated = $qo ? get_the_modified_date( 'F j, Y', $qo ) : date( 'F j, Y' );

$docs = [
	'privacy-policy' => [
		'title'   => 'Privacy Policy',
		'intro'   => 'Guns 2 Ammo ("we", "us", "our") respects your privacy. This policy explains what information we collect when you use guns2ammo.com or visit our Mesa, Arizona storefront, how we use it, and the choices you have.',
		'sections' => [
			[ 'h' => 'Information We Collect', 'p' => [ 'We collect information you provide directly  such as your name, email, phone number, and message when you submit a reservation, transfer, sell-your-gun, or support request.', 'When you purchase a firearm, federal and state law require us to collect additional information and verify your identity in person. That process is governed by ATF regulations, not by this website policy.', 'We also collect limited technical data automatically, such as your IP address, browser type, and pages visited, to keep the site secure and improve it.' ] ],
			[ 'h' => 'How We Use Your Information', 'p' => [ 'To respond to your inquiries, process reservations and requests, fulfill orders, and provide customer support.', 'To send you information you have asked for, including range updates and promotions if you opt in. You can unsubscribe at any time.', 'To comply with our legal obligations as a federally licensed firearms dealer and indoor range.' ] ],
			[ 'h' => 'Cookies & Analytics', 'p' => [ 'Our website may use cookies and similar technologies to remember your preferences and understand how the site is used. You can disable cookies in your browser settings; some features may not work as intended if you do.' ] ],
			[ 'h' => 'Sharing Your Information', 'p' => [ 'We do not sell your personal information. We share it only with service providers who help us operate the business (such as payment processors and email tools), and when required by law or to comply with firearms regulations.' ] ],
			[ 'h' => 'Data Security & Retention', 'p' => [ 'We use reasonable administrative and technical safeguards to protect your information, and retain it only as long as needed for the purposes above or as required by law. Firearms transaction records are retained as mandated by federal regulation.' ] ],
			[ 'h' => 'Your Choices', 'p' => [ 'You may request access to, correction of, or deletion of the personal information we hold about you, subject to legal record-keeping requirements. To make a request, contact us using the details below.' ] ],
			[ 'h' => 'Contact Us', 'p' => [ 'Questions about this policy? Email ' . $g2a_legal_email . ', call ' . $g2a_legal_phone . ', or write to ' . $g2a_legal_addr . '.' ] ],
		],
	],
	'terms-and-conditions' => [
		'title'   => 'Terms & Conditions',
		'intro'   => 'These Terms & Conditions govern your use of guns2ammo.com and the products and services offered by Guns 2 Ammo in Mesa, Arizona. By using our website or services, you agree to these terms.',
		'sections' => [
			[ 'h' => 'Eligibility & Age Requirements', 'p' => [ 'You must be of legal age to purchase the products you order  generally 18 or older for long guns and ammunition, and 21 or older for handguns  and not a prohibited person under federal or Arizona law.', 'All firearm purchases require a federal background check and completion of ATF Form 4473 in person before transfer.' ] ],
			[ 'h' => 'Firearms Compliance', 'p' => [ 'All firearm sales and transfers are conducted in accordance with federal law, Arizona law, and ATF regulations. We reserve the right to refuse or cancel any transaction that we believe may be unlawful or that fails a background check.', 'Handguns may only be transferred to residents of Arizona. Long guns may be transferred to residents of other states where the sale is lawful in both states.' ] ],
			[ 'h' => 'Orders, Pricing & Availability', 'p' => [ 'Prices, inventory, and promotions are subject to change without notice. We make every effort to display accurate information, but errors may occur; we reserve the right to correct them and to cancel affected orders.', 'Online orders are reserved for pickup and complete at our storefront, where required verification and paperwork take place.' ] ],
			[ 'h' => 'FFL Transfers', 'p' => [ 'When you have a firearm shipped to us for transfer, you agree to our published transfer fees and to complete the transfer within a reasonable time. Storage fees may apply to firearms left beyond 30 days.' ] ],
			[ 'h' => 'Range Use & Conduct', 'p' => [ 'Use of our indoor range requires following all posted rules and the directions of our Range Safety Officers at all times. We may remove anyone who behaves unsafely, with no refund.', 'Shooting sports carry inherent risks. Every range user signs a liability waiver before using the facility.' ] ],
			[ 'h' => 'Memberships', 'p' => [ 'Memberships are billed on the cycle you select and may be cancelled at any time; access continues through the end of the paid period. Membership benefits are non-transferable except as described in your plan.' ] ],
			[ 'h' => 'Intellectual Property', 'p' => [ 'All content on this website  text, graphics, logos, and images  is the property of Guns 2 Ammo or its licensors and may not be reproduced without permission.' ] ],
			[ 'h' => 'Limitation of Liability', 'p' => [ 'To the fullest extent permitted by law, Guns 2 Ammo is not liable for indirect or consequential damages arising from the use of our website, products, or facilities. Nothing in these terms limits liability that cannot be limited by law.' ] ],
			[ 'h' => 'Governing Law', 'p' => [ 'These terms are governed by the laws of the State of Arizona. Any dispute will be handled in the state or federal courts located in Maricopa County, Arizona.' ] ],
			[ 'h' => 'Contact Us', 'p' => [ 'Questions about these terms? Email ' . $g2a_legal_email . ', call ' . $g2a_legal_phone . ', or write to ' . $g2a_legal_addr . '.' ] ],
		],
	],
	'refund-and-returns-policy' => [
		'title'   => 'Refund & Returns Policy',
		'intro'   => 'We want you to be confident in your purchase from Guns 2 Ammo. Because we sell firearms and ammunition, some items are subject to legal restrictions on returns. This policy explains what can and cannot be returned.',
		'sections' => [
			[ 'h' => 'Firearms', 'p' => [ 'Once a firearm has been transferred to you and left our premises, federal law and safety considerations make it a final sale  it cannot be returned for a refund.', 'If you believe your firearm has a manufacturing defect, contact us right away. We will help you with the manufacturer warranty process, and inspect the firearm before transfer whenever possible.' ] ],
			[ 'h' => 'Ammunition', 'p' => [ 'For safety reasons, ammunition is non-returnable and non-refundable once it has left the store. Please confirm the correct caliber and load before you complete your purchase  our staff is glad to help.' ] ],
			[ 'h' => 'Accessories & Non-Firearm Items', 'p' => [ 'Unused accessories and gear in original, resalable condition with proof of purchase may be returned within 14 days for a refund or exchange. Items that show wear, or are missing packaging, may receive store credit at our discretion.' ] ],
			[ 'h' => 'FFL Transfer Fees', 'p' => [ 'Transfer fees cover work already performed and are non-refundable once a firearm has been received and processed.' ] ],
			[ 'h' => 'Range Fees, Training & Events', 'p' => [ 'Range time and lane fees are non-refundable once used. Training courses may be rescheduled or refunded up to 48 hours before the class start time; inside 48 hours we will reschedule you at no charge.' ] ],
			[ 'h' => 'Memberships', 'p' => [ 'Memberships can be cancelled at any time and remain active through the end of the current billing period. Membership fees already billed are not pro-rated or refunded.' ] ],
			[ 'h' => 'How To Request A Return', 'p' => [ 'To start a return or ask a question, visit our Get Support page, email ' . $g2a_legal_email . ', or call ' . $g2a_legal_phone . '. Bring your item and proof of purchase to ' . $g2a_legal_addr_no_prefix . '.' ] ],
		],
	],
];

$d = $docs[ $slug ] ?? $docs['privacy-policy'];
?>
<style>
.lg-hero { padding:140px 32px 48px; background: linear-gradient(180deg, var(--color-surface-2), var(--color-void)); border-bottom:1px solid var(--color-hairline); }
.lg-hero .c { max-width:880px; margin:0 auto; }
.lg-hero h1 { font-family: var(--font-display); font-size: clamp(44px,7vw,88px); line-height:0.95; color: var(--color-white); margin:14px 0 0; }
.lg-hero .upd { font-family: var(--font-mono); font-size:11px; letter-spacing:0.2em; text-transform:uppercase; color: var(--color-silver); margin-top:14px; }
.lg-wrap { max-width:880px; margin:0 auto; padding:56px 32px 96px; }
.lg-wrap .lede { color: var(--color-fog); font-size:16px; line-height:1.8; }
.lg-toc { margin:28px 0 8px; padding:22px 24px; background: var(--color-gunmetal); border:1px solid var(--color-hairline); }
.lg-toc .t { font-family: var(--font-mono); font-size:11px; letter-spacing:0.22em; color: var(--color-silver); text-transform:uppercase; margin-bottom:12px; }
.lg-toc ol { margin:0; padding-left:20px; columns:2; }
@media (max-width:560px){ .lg-toc ol { columns:1; } }
.lg-toc ol li { margin:0 0 7px; }
.lg-toc a { color: var(--color-brass-bright); text-decoration:none; font-size:14px; }
.lg-sec { margin-top:40px; }
.lg-sec h2 { font-family: var(--font-display); font-size: clamp(26px,3.4vw,38px); color: var(--color-white); letter-spacing:0.02em; }
.lg-sec p { color: var(--color-fog); font-size:15px; line-height:1.85; margin:12px 0 0; }
.lg-note { margin-top:48px; padding:20px 24px; border:1px solid var(--color-brass-dim); background: rgba(201,168,76,0.06); color: var(--color-fog); font-size:13px; line-height:1.7; }
</style>

<header class="lg-hero">
  <div class="c">
    <span class="eyebrow">Guns 2 Ammo  Legal</span>
    <h1><?php echo esc_html( $d['title'] ); ?></h1>
    <div class="upd">Last updated <?php echo esc_html( $updated ); ?></div>
  </div>
</header>

<div class="lg-wrap">
  <p class="lede"><?php echo esc_html( $d['intro'] ); ?></p>

  <nav class="lg-toc" aria-label="Contents">
    <div class="t">On This Page</div>
    <ol>
      <?php foreach ( $d['sections'] as $i => $s ) : ?>
        <li><a href="#sec-<?php echo (int) $i; ?>"><?php echo esc_html( $s['h'] ); ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <?php foreach ( $d['sections'] as $i => $s ) : ?>
    <section class="lg-sec" id="sec-<?php echo (int) $i; ?>">
      <h2><?php echo esc_html( $s['h'] ); ?></h2>
      <?php foreach ( $s['p'] as $para ) : ?>
        <p><?php echo esc_html( $para ); ?></p>
      <?php endforeach; ?>
    </section>
  <?php endforeach; ?>

  <div class="lg-note">
    This page is provided for general information and is not legal advice. Guns 2 Ammo recommends having all published policies reviewed by qualified legal counsel before launch to ensure they reflect current federal, Arizona, and ATF requirements and your exact business practices.
  </div>
</div>
<?php get_footer();
