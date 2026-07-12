<?php
/**
 * FAQs page wiring.
 *
 * Auto-creates a `/faqs/` page on theme activation and assigns the
 * dedicated FAQ template. The template renders a smooth-dropdown
 * Q&A list grouped by topic AND emits FAQPage JSON-LD for rich
 * results / AI answer engines.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'after_switch_theme', 'g2a_faqs_ensure_page' );
function g2a_faqs_ensure_page() {
	if ( get_page_by_path( 'faqs' ) ) {
		return;
	}
	$id = wp_insert_post( array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'FAQs — Guns 2 Ammo',
		'post_name'    => 'faqs',
		'post_content' => '',
	) );
	if ( $id && ! is_wp_error( $id ) ) {
		update_post_meta( $id, '_wp_page_template', 'page-templates/template-faqs.php' );
	}
}

/**
 * The canonical FAQ data. Themed page template + JSON-LD both read
 * from this single source so the rendered HTML and the structured
 * data can never drift apart. Edit here to add / change questions.
 *
 * @return array<int, array{ topic:string, items:array<int, array{q:string,a:string}> }>
 */
function g2a_faqs_data() {
	// Single source of truth for hours + address — see inc/business-info.php.
	$g2a_biz         = function_exists( 'g2a_biz' ) ? g2a_biz() : array();
	$g2a_hours_human = function_exists( 'g2a_biz_hours_human' ) ? str_replace( ' · ', ', ', g2a_biz_hours_human() ) : 'Mon–Thu 10am–6pm, Fri 10am–7pm, Sat 10am–7pm, Sun 12pm–6pm';
	$g2a_addr_line   = function_exists( 'g2a_biz_addr_line' ) ? g2a_biz_addr_line() : '6030 E Main St, Suite 103, Mesa, AZ 85205';

	// Single source of truth for membership pricing — see inc/pricing.php.
	$g2a_has_pricing_helper = function_exists( 'g2a_plan_price' );
	$g2a_defender_m = $g2a_has_pricing_helper ? g2a_plan_price_fmt( 'defender', 'monthly' ) : '$29.99';
	$g2a_patriot_m  = $g2a_has_pricing_helper ? g2a_plan_price_fmt( 'patriot', 'monthly' ) : '$39.99';
	$g2a_guardian_m = $g2a_has_pricing_helper ? g2a_plan_price_fmt( 'guardian', 'monthly' ) : '$59.99';
	$g2a_max_save   = $g2a_has_pricing_helper
		? number_format( max( g2a_plan_annual_savings( 'defender' ), g2a_plan_annual_savings( 'patriot' ), g2a_plan_annual_savings( 'guardian' ) ), 2 )
		: '69.89';

	$faqs = array(
		array(
			'topic' => 'Visiting the Range',
			'items' => array(
				array(
					'q' => 'Do I need an appointment to shoot?',
					'a' => 'No — walk-ins are welcome during open hours. Online lane reservations are available 24/7 for guaranteed availability, especially on weekends.',
				),
				array(
					'q' => 'What are your hours?',
					'a' => $g2a_hours_human . ' (all times Arizona / MST — Arizona does not observe Daylight Saving Time).',
				),
				array(
					'q' => 'How old do you have to be to shoot?',
					'a' => 'You must be 18+ to rent a handgun and 21+ to purchase one. Shooters under 18 may use the range with a parent or legal guardian present.',
				),
				array(
					'q' => 'Do you have parking?',
					'a' => 'Yes — free parking on-site at ' . $g2a_addr_line . '. The lot is large and accessible.',
				),
				array(
					'q' => 'Is the range ADA accessible?',
					'a' => 'Yes. Lanes 1 and 2 are ADA-accessible with wider lane widths and accessible benches. Let staff know in advance if you need additional support.',
				),
			),
		),
		array(
			'topic' => 'Pricing & Membership',
			'items' => array(
				array(
					'q' => 'How much does it cost to shoot?',
					'a' => 'Walk-in lane rental is $20 per shooter per hour. Members get included lane time: Defender (1 person) ' . $g2a_defender_m . '/month, Patriot (2 people) ' . $g2a_patriot_m . '/month, Guardian (4 people) ' . $g2a_guardian_m . '/month. Annual billing saves up to $' . $g2a_max_save . '/year.',
				),
				array(
					'q' => 'Can my members bring guests?',
					'a' => 'Yes. Defender members pay $15 per extra shooter per hour. Patriot and Guardian members pay $10 per extra shooter per hour. Each member household covers the primary + linked profiles included in the plan.',
				),
				array(
					'q' => 'Can I cancel my membership anytime?',
					'a' => 'Yes. Cancel from your account dashboard or email us — no fees, no contracts. Access remains active until the end of your current billing period.',
				),
				array(
					'q' => 'Do you offer military, veteran, or law enforcement discounts?',
					'a' => 'Yes — 15% off any membership plan with valid LEO, active military, or veteran ID, stackable with annual savings.',
				),
				array(
					'q' => 'Do members get range gear discounts?',
					'a' => 'Yes. Members save 25% on rental firearms, get eye + ear protection included, and 10% off range ammunition. See the full member benefits list on the Memberships page.',
				),
			),
		),
		array(
			'topic' => 'Firearms — Buying, Renting, Transfers',
			'items' => array(
				array(
					'q' => 'Do you sell new and used firearms?',
					'a' => 'Yes. We are a federally licensed firearm dealer (FFL) carrying a curated selection of new and pre-owned handguns, rifles, and shotguns. Browse our shop online or visit the storefront.',
				),
				array(
					'q' => 'Can I bring a firearm in for transfer?',
					'a' => 'Yes. We accept private-party transfers and FFL-to-FFL transfers. See the Transfers page for current transfer fees, required documents, and processing time.',
				),
				array(
					'q' => 'Do you buy used firearms?',
					'a' => 'Yes — we buy quality used firearms. Bring yours in for a free evaluation; cash or store-credit offers, no obligation.',
				),
				array(
					'q' => 'Do you rent firearms?',
					'a' => 'Yes. Handgun rentals are $15 ($11.25 for members) and long-gun rentals are $25 ($18.75 for members). Range ammunition is required for rental use (no reloads or steel-core).',
				),
				array(
					'q' => 'What ammunition do you allow on the range?',
					'a' => 'Factory new ammunition only — no reloads, no steel core, no incendiary. We sell on-site if you forget yours.',
				),
			),
		),
		array(
			'topic' => 'Training & CCW',
			'items' => array(
				array(
					'q' => 'Do you offer concealed-carry permit training?',
					'a' => 'Yes. We are an NRA-certified training facility offering Arizona CCW classes and California CCW live-fire qualifications. Class schedules are on the Training page.',
				),
				array(
					'q' => 'I have never shot before — can I still train with you?',
					'a' => 'Absolutely. We run regular New Shooter classes for first-timers. Our instructors will walk you through safety, fundamentals, and your first range session in a no-pressure setting.',
				),
				array(
					'q' => 'Do you have a Ladies-only shooting day?',
					'a' => 'Yes — every Tuesday, female shooters get one hour of free lane time (solo or with a guest), eye and ear protection included, plus 25% off any rental. Walk in or reserve online.',
				),
			),
		),
		array(
			'topic' => 'Safety & Range Rules',
			'items' => array(
				array(
					'q' => 'Is the range safe for first-time shooters?',
					'a' => 'Yes. Every lane has a certified range safety officer on duty during open hours. We also offer free first-time-shooter orientation — just ask at the front desk.',
				),
				array(
					'q' => 'What range rules do I need to know?',
					'a' => 'Treat every firearm as loaded, point in a safe direction, keep your finger off the trigger until ready to shoot, and follow the RSO\'s instructions. Full range rules are posted on the Range Safety page and reviewed at check-in.',
				),
				array(
					'q' => 'Can I bring a guest who isn\'t shooting?',
					'a' => 'Yes. Non-shooting guests are welcome in the observation area at no charge. Eye + ear protection is provided.',
				),
			),
		),
		array(
			'topic' => 'Online Orders & Returns',
			'items' => array(
				array(
					'q' => 'How do online firearm orders work?',
					'a' => 'Order online, then we coordinate transfer with your local FFL dealer (or pick up in store). All federal background-check requirements apply at the receiving FFL.',
				),
				array(
					'q' => 'Do you ship ammunition?',
					'a' => 'Yes — to most US states, subject to state and federal law. Restrictions for certain calibers and quantities may apply.',
				),
				array(
					'q' => 'What is your refund policy?',
					'a' => 'See our Refund and Returns Policy for full details. Most accessories are returnable within 14 days; firearms and ammunition follow stricter federal rules.',
				),
			),
		),
	);

	return apply_filters( 'g2a_faqs_data', $faqs );
}

/**
 * Curated, high-value Q&As for the homepage "Common Questions" section.
 * Kept separate from g2a_faqs_data() so the homepage stays a tight,
 * conversion-focused six — and filterable so plugins/customizations can
 * swap questions without touching the template.
 *
 * @return array<int, array{q:string,a:string}>
 */
function g2a_home_faqs() {
	// Single source of truth for hours — see inc/business-info.php.
	$g2a_hours_human = function_exists( 'g2a_biz_hours_human' ) ? str_replace( ' · ', ', ', g2a_biz_hours_human() ) : 'Mon–Thu 10am–6pm, Fri 10am–7pm, Sat 10am–7pm, Sun 12pm–6pm';
	// Single source of truth for membership pricing — see inc/pricing.php.
	$g2a_from_price = function_exists( 'g2a_plan_price_from_fmt' ) ? g2a_plan_price_from_fmt() : '$29.99';

	$faqs = array(
		array(
			'q' => 'How much does it cost to shoot?',
			'a' => 'Walk-in lane rental is $20 per shooter per hour. Members get included lane time on every visit, with plans starting at ' . $g2a_from_price . '/month — cancel anytime, no contracts.',
		),
		array(
			'q' => 'Do I need my own gun?',
			'a' => 'No. Handgun rentals start at $15 and long-gun rentals at $25 (members save 25% on rentals). Range ammunition is required for rental firearms — factory new only, available on-site.',
		),
		array(
			'q' => 'I have never shot before — am I welcome?',
			'a' => 'Absolutely. A certified range safety officer is on duty whenever the range is hot, and we offer free first-time-shooter orientation plus regular New Shooter classes. Just ask at the front desk.',
		),
		array(
			'q' => 'What are your hours?',
			'a' => $g2a_hours_human . '. All times Arizona / MST — Arizona does not observe Daylight Saving Time.',
		),
		array(
			'q' => 'How much is the CCW class?',
			'a' => 'Our Arizona CCW certification class is $85 and includes classroom instruction plus live-fire qualification with NRA-certified instructors. The Arizona permit is honored in 37 states.',
		),
		array(
			'q' => 'Are walk-ins okay, or do I need a reservation?',
			'a' => 'Walk-ins are welcome any time during open hours. Online lane reservations are available 24/7 if you want a guaranteed lane — recommended on weekends.',
		),
	);

	return apply_filters( 'g2a_home_faqs', $faqs );
}

/**
 * Emit FAQPage JSON-LD only on the dedicated FAQs page so we don't
 * confuse search engines with multiple FAQPage entities on other
 * pages that already carry their own contextual schema.
 */
add_action( 'wp_head', 'g2a_faqs_emit_schema', 12 );
function g2a_faqs_emit_schema() {
	if ( ! is_page_template( 'page-templates/template-faqs.php' ) && ! ( is_page() && 'faqs' === get_post_field( 'post_name', get_queried_object_id() ) ) ) {
		return;
	}
	if ( ! function_exists( 'g2a_faq_schema' ) ) {
		return; // requires inc/seo.php
	}
	$flat = array();
	foreach ( g2a_faqs_data() as $group ) {
		foreach ( $group['items'] as $it ) {
			$flat[] = array( 'q' => $it['q'], 'a' => $it['a'] );
		}
	}
	g2a_emit_jsonld( g2a_faq_schema( $flat ) );
}

/**
 * FAQPage JSON-LD for the homepage "Common Questions" section ONLY.
 * The dedicated /faqs/ page carries its own (full) FAQPage entity via
 * g2a_faqs_emit_schema() above; nothing else on the site emits FAQPage,
 * so the front page stays a single, non-duplicated FAQPage entity.
 */
add_action( 'wp_head', 'g2a_home_faqs_emit_schema', 12 );
function g2a_home_faqs_emit_schema() {
	if ( ! is_front_page() ) {
		return;
	}
	if ( ! function_exists( 'g2a_faq_schema' ) || ! function_exists( 'g2a_emit_jsonld' ) ) {
		return; // requires inc/seo.php
	}
	$faqs = g2a_home_faqs();
	if ( empty( $faqs ) ) {
		return;
	}
	g2a_emit_jsonld( g2a_faq_schema( $faqs ) );
}
