<?php
/**
 * Membership pricing — single source of truth.
 *
 * The Memberistic Membership Solutions plugin (memberistic-membership-solutions)
 * owns the REAL, billable plan prices in its own database table
 * (wp_memberistic_plans, via WordPressistic\Memberistic\Database\Plans_Repository).
 * That table is what actually charges members, so it — not this theme — is
 * the source of truth for pricing whenever the plugin is active.
 *
 * Before this file existed, the Defender/Patriot/Guardian monthly prices
 * ($29.99 / $39.99 / $59.99) were hard-coded independently in ~10 different
 * theme files (404.php, front-page.php, inc/faqs.php, inc/llms.php,
 * inc/seo.php, and several page templates). If a client ever changed a
 * plan's price from the Memberistic admin, every one of those files would
 * silently keep advertising the old number.
 *
 * g2a_membership_plans() / g2a_plan_price() fix that: they read the live
 * Memberistic plan row by slug when the plugin is active (guarded the same
 * way the theme already guards Memberistic/booking-engine integration in
 * inc/plugins.php — shortcode_exists() / defined() / class_exists() checks,
 * never a hard dependency), and fall back to the last-known static prices
 * so nothing breaks if Memberistic is ever deactivated.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The three G2A membership tiers, with pricing sourced from Memberistic's
 * Plans_Repository when available. Cached for an hour (transient) the same
 * way g2a_biz_category_counts() caches WooCommerce category counts, and
 * busted whenever a plan is created/updated in Memberistic.
 *
 * @return array<string, array{name:string, monthly_price:float, annual_price:float, included_people:int}>
 */
function g2a_membership_plans() {
	static $runtime_cache = null;
	if ( null !== $runtime_cache ) {
		return $runtime_cache;
	}

	// Last-known static prices. Used whenever Memberistic is inactive, or a
	// given plan hasn't been created/seeded in its plans table yet — this is
	// the theme's fallback, NOT the live source of truth for billing.
	$defaults = array(
		'defender' => array(
			'name'            => 'Defender',
			'monthly_price'   => 29.99,
			'annual_price'    => 299.99,
			'included_people' => 1,
		),
		'patriot'  => array(
			'name'            => 'Patriot',
			'monthly_price'   => 39.99,
			'annual_price'    => 449.99,
			'included_people' => 2,
		),
		'guardian' => array(
			'name'            => 'Guardian',
			'monthly_price'   => 59.99,
			'annual_price'    => 649.99,
			'included_people' => 4,
		),
	);

	$cached = get_transient( 'g2a_membership_plans' );
	if ( is_array( $cached ) && ! empty( $cached ) ) {
		$runtime_cache = $cached;
		return $runtime_cache;
	}

	$plans = $defaults;

	// Memberistic active? Overlay real DB prices per slug, keeping the
	// static default for any slug the plugin doesn't have (e.g. not yet
	// seeded) so a page never renders a missing/zero price.
	if ( class_exists( '\WordPressistic\Memberistic\Database\Plans_Repository' )
		&& is_callable( array( '\WordPressistic\Memberistic\Database\Plans_Repository', 'get_by_slug' ) )
	) {
		foreach ( array_keys( $defaults ) as $slug ) {
			$row = \WordPressistic\Memberistic\Database\Plans_Repository::get_by_slug( $slug );
			if ( ! $row || 'active' !== ( $row['status'] ?? 'active' ) ) {
				continue;
			}
			$plans[ $slug ] = array(
				'name'            => $row['name'] ?? $defaults[ $slug ]['name'],
				'monthly_price'   => isset( $row['monthly_price'] ) ? (float) $row['monthly_price'] : $defaults[ $slug ]['monthly_price'],
				'annual_price'    => isset( $row['annual_price'] ) ? (float) $row['annual_price'] : $defaults[ $slug ]['annual_price'],
				'included_people' => isset( $row['included_people'] ) ? (int) $row['included_people'] : $defaults[ $slug ]['included_people'],
			);
		}
	}

	set_transient( 'g2a_membership_plans', $plans, HOUR_IN_SECONDS );
	$runtime_cache = $plans;

	return $plans;
}

/**
 * Bust the cached plan prices whenever Memberistic creates/updates a plan,
 * so a Customizer... err, Memberistic admin price change shows up across
 * the site within the request, not up to an hour later.
 */
add_action( 'memberistic_plan_created', function () { delete_transient( 'g2a_membership_plans' ); } );
add_action( 'memberistic_plan_updated', function () { delete_transient( 'g2a_membership_plans' ); } );

/**
 * Raw numeric price for one plan + billing cycle. Returns 0.0 for an
 * unknown slug.
 *
 * @param string $slug  'defender' | 'patriot' | 'guardian'.
 * @param string $cycle 'monthly' (default) or 'annual'.
 */
function g2a_plan_price( $slug, $cycle = 'monthly' ) {
	$plans = g2a_membership_plans();
	$slug  = sanitize_title( (string) $slug );
	if ( ! isset( $plans[ $slug ] ) ) {
		return 0.0;
	}
	$key = ( 'annual' === $cycle || 'yearly' === $cycle ) ? 'annual_price' : 'monthly_price';
	return (float) ( $plans[ $slug ][ $key ] ?? 0 );
}

/**
 * Formatted price string, e.g. "$29.99". Safe to echo directly (numeric
 * formatting only, no user input involved).
 *
 * @param string $slug  'defender' | 'patriot' | 'guardian'.
 * @param string $cycle 'monthly' (default) or 'annual'.
 */
function g2a_plan_price_fmt( $slug, $cycle = 'monthly' ) {
	return '$' . number_format( g2a_plan_price( $slug, $cycle ), 2 );
}

/**
 * Annual savings vs. paying monthly x12, e.g. "$69.89" for Defender.
 * Returns 0.0 if either price is unavailable.
 *
 * @param string $slug 'defender' | 'patriot' | 'guardian'.
 */
function g2a_plan_annual_savings( $slug ) {
	$monthly = g2a_plan_price( $slug, 'monthly' );
	$annual  = g2a_plan_price( $slug, 'annual' );
	if ( $monthly <= 0 || $annual <= 0 ) {
		return 0.0;
	}
	return max( 0.0, round( ( $monthly * 12 ) - $annual, 2 ) );
}

/**
 * The lowest monthly plan price across all tiers, e.g. for "From $29.99/mo"
 * copy. Falls back to the Defender default if no plans are found at all.
 */
function g2a_plan_price_from() {
	$plans = g2a_membership_plans();
	$prices = array_filter( array_map( function ( $p ) { return (float) ( $p['monthly_price'] ?? 0 ); }, $plans ) );
	if ( empty( $prices ) ) {
		return 29.99;
	}
	return min( $prices );
}

/**
 * Formatted "from" price string, e.g. "$29.99".
 */
function g2a_plan_price_from_fmt() {
	return '$' . number_format( g2a_plan_price_from(), 2 );
}
