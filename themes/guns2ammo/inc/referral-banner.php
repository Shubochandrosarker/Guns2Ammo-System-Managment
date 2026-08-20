<?php
/**
 * Top promotional banner — member vs non-member.
 *
 * AirLift page cache is active on this site. If this banner rendered its
 * member variant into the HTML, the first member to view a product page
 * would publish their own referral code to every anonymous visitor served
 * from that cache entry afterwards. So the markup here is a deliberately
 * EMPTY placeholder with its height reserved, and a single call to
 * /wp-json/g2ar/v1/context fills it in per visitor. One request, no layout
 * shift, correct for everyone.
 *
 * When testing, always use a novel query string — repeating a URL returns
 * cached output and makes a working build look broken.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Should the banner render on this request?
 *
 * @return bool
 */
function g2a_referral_banner_active() {
	if ( is_admin() || is_404() ) {
		return false;
	}

	// The referral plugin owns the copy, the offer and the on/off switch.
	if ( ! function_exists( 'g2ar_setting' ) ) {
		return false;
	}

	if ( 'yes' !== g2ar_setting( 'banner_enabled', 'no' ) ) {
		return false;
	}

	// Shop surfaces only: this banner sells memberships against a product,
	// so it has no business on a blog post or the range's opening hours.
	$on_shop = function_exists( 'is_woocommerce' )
		&& ( is_woocommerce() || is_shop() || is_product() || is_cart() || is_checkout() );

	/**
	 * Filter whether the referral banner renders on this request.
	 *
	 * @param bool $active Whether to render.
	 */
	return (bool) apply_filters( 'g2a_referral_banner_active', $on_shop );
}

/**
 * Print the reserved placeholder directly under the site header.
 *
 * @return void
 */
function g2a_render_referral_banner() {
	if ( ! g2a_referral_banner_active() ) {
		return;
	}

	$dismiss_days = (int) g2ar_setting( 'banner_dismiss_days', 7 );
	?>
	<div class="g2a-refbanner" data-g2a-refbanner
		data-endpoint="<?php echo esc_url( rest_url( 'g2ar/v1/context' ) ); ?>"
		data-dismiss-days="<?php echo esc_attr( (string) max( 1, $dismiss_days ) ); ?>"
		aria-live="polite">
		<?php
		/*
		 * Intentionally empty. The height is reserved in CSS (40px desktop /
		 * 56px mobile) so filling this in never shifts the page. Anything
		 * printed here would be cached and served to the wrong visitor.
		 */
		?>
	</div>
	<?php
}
add_action( 'g2a_after_header', 'g2a_render_referral_banner', 10 );

/**
 * Load the banner's assets only where it renders.
 *
 * @return void
 */
function g2a_referral_banner_assets() {
	if ( ! g2a_referral_banner_active() ) {
		return;
	}

	wp_enqueue_style( 'g2a-referral-banner', G2A_URI . '/assets/css/referral-banner.css', array( 'g2a-app' ), G2A_VERSION );
	wp_enqueue_script( 'g2a-referral-banner', G2A_URI . '/assets/js/referral-banner.js', array(), G2A_VERSION, true );

	wp_localize_script(
		'g2a-referral-banner',
		'g2aRefBanner',
		array(
			'copy'   => __( 'Copy', 'guns2ammo' ),
			'copied' => __( 'Copied', 'guns2ammo' ),
			'close'  => __( 'Dismiss', 'guns2ammo' ),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'g2a_referral_banner_assets', 21 );
