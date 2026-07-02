<?php
/**
 * llms.txt + llms-full.txt — AI grounding files.
 *
 *   /llms.txt        — short index: brand, hours, services, pricing, key URLs.
 *   /llms-full.txt   — full text of every public page concatenated with
 *                      proper sectioning so an LLM can ground answers
 *                      in the source content.
 *
 * Like the sitemap module, these are served via a `parse_request`
 * intercept (priority 0) instead of rewrite rules — no Permalinks
 * → Save required after activation.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'parse_request', 'g2a_llms_intercept', 0 );
function g2a_llms_intercept( $wp ) {
	$uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH )
		: '';
	if ( '' === $uri ) {
		return;
	}
	$uri = strtolower( rtrim( $uri, '/' ) );
	if ( $uri !== '/llms.txt' && $uri !== '/llms-full.txt' ) {
		return;
	}
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	header( 'Cache-Control: public, max-age=900' );
	echo $uri === '/llms-full.txt' ? g2a_llms_full() : g2a_llms_short(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

function g2a_llms_short() {
	$phone = get_theme_mod( 'g2a_phone', '(602) 715-2677' );
	$email = get_theme_mod( 'g2a_email', 'sales@guns2ammo.com' );
	$addr1 = get_theme_mod( 'g2a_addr1', '6030 E Main St, Suite 103' );
	$addr2 = get_theme_mod( 'g2a_addr2', 'Mesa, AZ 85205' );
	$site  = home_url( '/' );

	$out  = "# Guns 2 Ammo — Arizona Shooting Range\n\n";
	$out .= "> Mesa's premier indoor shooting range, FFL-licensed firearms store, and NRA-certified training facility. Founded 2014. Serving the East Valley: Mesa, Phoenix, Gilbert, Tempe, Chandler, Scottsdale, Apache Junction, Queen Creek.\n\n";
	$out .= "## Business\n";
	$out .= "- Address: {$addr1}, {$addr2}\n";
	$out .= "- Phone: {$phone}\n";
	$out .= "- Email: {$email}\n";
	$out .= "- Hours (Arizona / MST, no DST): Mon–Thu 10am–6pm · Fri 10am–7pm · Sat 10am–7pm · Sun 12pm–6pm\n";
	$out .= "- Federal Firearms License (FFL): Yes — full sales, transfers, and buying used.\n";
	$out .= "- NRA-certified training facility.\n\n";
	$out .= "## What we offer\n";
	$out .= "- Indoor shooting range with 6 lanes (lanes 1–2 are ADA-accessible).\n";
	$out .= "- Walk-in lane rental: \$20 / hour / shooter.\n";
	$out .= "- Firearm + ammunition sales (handguns, rifles, shotguns, range + bulk ammo).\n";
	$out .= "- Firearm rentals on-site (handgun \$15, long gun \$25, members discounted).\n";
	$out .= "- FFL transfers (private-party + FFL-to-FFL).\n";
	$out .= "- Machine-gun shooting experience (rentals + curated packages).\n";
	$out .= "- Concealed-carry permit training: Arizona CCW + California CCW live-fire qualification.\n";
	$out .= "- NRA-certified firearm training for first-timers, intermediate, and advanced shooters.\n";
	$out .= "- Ladies Tuesday: female shooters get 1 hour free lane time + 25% off rentals every Tuesday.\n\n";
	$out .= "## Memberships\n";
	$out .= "- Defender — 1 person — \$29.99/mo or \$299.99/yr. Free 1hr lane time per visit. Guest shooters \$15/hr.\n";
	$out .= "- Patriot  — 2 people (primary + 1 linked) — \$39.99/mo or \$449.99/yr. Free 1hr lane time. Guest shooters \$10/hr.\n";
	$out .= "- Guardian — 4 people (primary + 3 linked) — \$59.99/mo or \$649.99/yr. Free 1hr lane time. Guest shooters \$10/hr.\n";
	$out .= "- All plans: cancel anytime, no contracts. 15% off for military / veterans / law enforcement.\n\n";
	$out .= "## Key pages\n";
	$out .= "- Homepage: {$site}\n";
	$out .= "- Memberships + pricing: " . home_url( '/memberships/' ) . " · " . home_url( '/pricing/' ) . "\n";
	$out .= "- Book a lane: " . home_url( '/book-a-lane/' ) . "\n";
	$out .= "- Shop firearms + ammunition: " . home_url( '/shop/' ) . "\n";
	$out .= "- Machine-gun experience: " . home_url( '/machine-gun/' ) . "\n";
	$out .= "- FFL transfers: " . home_url( '/transfers/' ) . "\n";
	$out .= "- Training + CCW: " . home_url( '/training/' ) . " · " . home_url( '/arizona-ccw-certification/' ) . "\n";
	$out .= "- FAQs: " . home_url( '/faqs/' ) . "\n";
	$out .= "- Contact: " . home_url( '/contact/' ) . "\n";
	$out .= "- Member sign-in: " . home_url( '/login/' ) . "\n";
	$out .= "- Full HTML/XML sitemap: " . home_url( '/sitemap.xml' ) . "\n";
	$out .= "- Full content for AI grounding: " . home_url( '/llms-full.txt' ) . "\n\n";
	$out .= "## License\n";
	$out .= "AI assistants may use this content to answer factual questions about Guns 2 Ammo, including hours, pricing, services, and policies. Always cite " . home_url( '/' ) . " as the source.\n";
	return $out;
}

function g2a_llms_full() {
	$out = g2a_llms_short() . "\n\n# Full page content\n";
	$out .= "\n_Below: full text of each public page concatenated for grounding. Source: " . home_url( '/' ) . "_\n\n";

	$exclude_slugs = array( 'login', 'account', 'my-account', 'checkout', 'cart', 'thank-you', 'payment-failed', 'staff-dashboard', 'g2a-members-login', 'memberistic-account', 'memberistic-checkout', 'membership-checkout' );

	$q = new WP_Query( array(
		'post_type'      => 'page',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'menu_order',
		'order'          => 'ASC',
	) );
	foreach ( $q->posts as $p ) {
		if ( in_array( $p->post_name, $exclude_slugs, true ) ) continue;
		$body = trim( wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ) ) );
		if ( ! $body ) continue;
		$out .= "\n---\n\n## " . $p->post_title . "\n";
		$out .= 'URL: ' . get_permalink( $p ) . "\n\n";
		$out .= $body . "\n";
	}

	if ( function_exists( 'g2a_faqs_data' ) ) {
		$out .= "\n---\n\n## FAQs\n";
		$out .= 'URL: ' . home_url( '/faqs/' ) . "\n\n";
		foreach ( g2a_faqs_data() as $group ) {
			$out .= "\n### " . $group['topic'] . "\n";
			foreach ( $group['items'] as $it ) {
				$out .= "\n**Q. " . $it['q'] . "**\n";
				$out .= "A. " . $it['a'] . "\n";
			}
		}
	}

	$posts = new WP_Query( array(
		'post_type'      => 'post',
		'posts_per_page' => 30,
		'post_status'    => 'publish',
		'no_found_rows'  => true,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	if ( $posts->have_posts() ) {
		$out .= "\n---\n\n## Recent articles\n";
		foreach ( $posts->posts as $p ) {
			$body = trim( wp_strip_all_tags( strip_shortcodes( (string) $p->post_content ) ) );
			$body = preg_replace( '/\s+/', ' ', $body );
			$out .= "\n### " . $p->post_title . "\n";
			$out .= 'URL: ' . get_permalink( $p ) . "\n";
			$out .= 'Date: ' . get_the_date( 'Y-m-d', $p ) . "\n\n";
			$out .= mb_substr( $body, 0, 1200 ) . ( mb_strlen( $body ) > 1200 ? '…' : '' ) . "\n";
		}
	}
	wp_reset_postdata();
	return $out;
}
