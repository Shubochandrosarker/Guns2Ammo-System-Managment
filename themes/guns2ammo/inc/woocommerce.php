<?php
/**
 * WooCommerce integration — dynamic loop markup + theme wrappers.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'loop_shop_columns', function () {
	return 4;
} );

add_filter( 'loop_shop_per_page', function () {
	return 12;
} );

/* The theme's archive template renders its own search + orderby toolbar, so
 * suppress the duplicate WooCommerce default ordering dropdown. The native
 * sidebar callback is also unused (no theme sidebars in this build). */
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

/* ------------------------------------------------------------------
 * Product schema (JSON-LD) on single-product pages.
 * - the theme is the sole schema source (inc/seo.php's
 *   g2a_seo_disable_rankmath_overlap() already silences RankMath's own
 *   `rank_math/json_ld` and `rank_math/schema/output`), so this always
 *   runs — the previous g2a_seo_plugin_active() early-return caused
 *   zero Product schema site-wide whenever Rank Math was active.
 * - placeholder image fallback when product has no thumbnail
 * ------------------------------------------------------------------ */
/**
 * Real hasMerchantReturnPolicy for a product's Offer, sourced from the
 * actual published policy at /refund-and-returns-policy/ (never
 * fabricated): firearms are a final sale once transferred (federal
 * law + safety), everything else gets the real 14-day accessories
 * window stated on that page.
 */
function g2a_seo_product_return_policy( WC_Product $product ) {
	$is_firearm = 'yes' === get_post_meta( $product->get_id(), '_wpistic_ffl_required', true );
	$link       = home_url( '/refund-and-returns-policy/' );

	if ( $is_firearm ) {
		return [
			'@type'                => 'MerchantReturnPolicy',
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnNotPermitted',
			'merchantReturnLink'   => $link,
		];
	}

	return [
		'@type'                => 'MerchantReturnPolicy',
		'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
		'merchantReturnDays'   => 14,
		'returnMethod'         => 'https://schema.org/ReturnInStore',
		'returnFees'           => 'https://schema.org/FreeReturn',
		'merchantReturnLink'   => $link,
	];
}

/**
 * Real shippingDetails from the store's actual configured WooCommerce
 * shipping zones — not a fabricated flat number. Firearms never ship
 * direct-to-consumer (FFL transfer or in-store pickup only, per
 * Terms & Conditions), so they intentionally get no shippingDetails at
 * all rather than a misleading one. Delivery/handling time estimates
 * are omitted because the store has no real transit-time data to
 * report — same "never fabricated" rule this file already applies to
 * aggregateRating below.
 */
function g2a_seo_product_shipping_details() {
	static $details = null;
	if ( null !== $details ) {
		return $details;
	}
	$details = false;

	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return $details;
	}

	$cost = null;
	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		foreach ( (array) ( $zone_data['shipping_methods'] ?? [] ) as $method ) {
			if ( ! $method->is_enabled() ) {
				continue;
			}
			if ( 'free_shipping' === $method->id ) {
				$cost = 0.0;
				break 2;
			}
			if ( 'flat_rate' === $method->id && null === $cost ) {
				$cost = (float) $method->get_option( 'cost' );
			}
		}
	}
	if ( null === $cost ) {
		return $details;
	}

	$countries = function_exists( 'WC' ) && WC()->countries
		? array_keys( (array) WC()->countries->get_allowed_countries() )
		: [ 'US' ];

	$details = [
		'@type'               => 'OfferShippingDetails',
		'shippingRate'        => [
			'@type'    => 'MonetaryAmount',
			'value'    => $cost,
			'currency' => get_woocommerce_currency(),
		],
		'shippingDestination' => array_map( function ( $country ) {
			return [ '@type' => 'DefinedRegion', 'addressCountry' => $country ];
		}, $countries ?: [ 'US' ] ),
	];
	return $details;
}

add_action( 'wp_head', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product ) {
		$product = wc_get_product( get_the_ID() );
	}
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$is_firearm = 'yes' === get_post_meta( $product->get_id(), '_wpistic_ffl_required', true );

	$offer = [
		'@type'                  => 'Offer',
		'priceCurrency'          => get_woocommerce_currency(),
		'price'                  => $product->get_price(),
		'priceValidUntil'        => gmdate( 'Y-m-d', strtotime( '+90 days' ) ),
		'availability'           => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
		'url'                    => get_permalink( $product->get_id() ),
		'hasMerchantReturnPolicy'=> g2a_seo_product_return_policy( $product ),
	];

	if ( ! $is_firearm ) {
		$shipping = g2a_seo_product_shipping_details();
		if ( $shipping ) {
			$offer['shippingDetails'] = $shipping;
		}
	}

	$ld = [
		'@context'    => 'https://schema.org',
		'@type'       => 'Product',
		'name'        => $product->get_name(),
		'sku'         => $product->get_sku(),
		'description' => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
		'image'       => $product->get_image_id()
			? wp_get_attachment_url( $product->get_image_id() )
			: ( function_exists( 'wc_placeholder_img_src' ) ? wc_placeholder_img_src( 'woocommerce_single' ) : '' ),
		'brand'       => [ '@type' => 'Brand', 'name' => get_bloginfo( 'name' ) ],
		'offers'      => $offer,
	];

	if ( $product->get_review_count() > 0 ) {
		$ld['aggregateRating'] = [
			'@type'       => 'AggregateRating',
			'ratingValue' => $product->get_average_rating(),
			'reviewCount' => $product->get_review_count(),
		];
	}

	g2a_emit_jsonld( $ld );
}, 10 );

/* ------------------------------------------------------------------
 * Shop loop card structure.
 * Wraps the title + price in .product-info inside the link wrapper,
 * then renders .product-actions (Add to Cart + Quick View) outside.
 * Closing </div> is at priority 4 so it runs BEFORE
 * woocommerce_template_loop_product_link_close (priority 5) and stays
 * inside the <a> wrapper — fixes invalid HTML that was breaking layouts.
 * ------------------------------------------------------------------ */
remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_template_loop_product_thumbnail', 10 );
add_action( 'woocommerce_before_shop_loop_item_title', function () {
	echo '<div class="product-thumb">';
	echo woocommerce_get_product_thumbnail( 'woocommerce_thumbnail' );
	echo '</div>';
}, 10 );

add_action( 'woocommerce_before_shop_loop_item_title', function () {
	echo '<div class="product-info">';
}, 20 );

add_action( 'woocommerce_after_shop_loop_item', function () {
	echo '</div>';
}, 4 );

remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
add_action( 'woocommerce_after_shop_loop_item', function () {
	global $product;
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$qv = wp_json_encode(
		[
			'brand' => get_bloginfo( 'name' ),
			'title' => $product->get_name(),
			'price' => wp_strip_all_tags( wc_price( $product->get_price() ) ),
			'desc'  => wp_strip_all_tags( $product->get_short_description() ?: $product->get_name() ),
			'img'   => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
			'cart_url'   => $product->add_to_cart_url(),
			'detail_url' => $product->get_permalink(),
		]
	);

	echo '<div class="product-actions">';

	if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) {
		echo '<a class="btn btn-brass btn-sm add_to_cart_button ajax_add_to_cart" href="' . esc_url( $product->add_to_cart_url() ) . '" data-quantity="1" data-product_id="' . esc_attr( $product->get_id() ) . '" rel="nofollow">' . esc_html( $product->add_to_cart_text() ) . '</a>';
	} else {
		echo '<a class="btn btn-brass btn-sm" href="' . esc_url( $product->get_permalink() ) . '">' . esc_html__( 'View Product', 'guns2ammo' ) . '</a>';
	}

	echo '<button type="button" class="btn btn-ghost btn-sm quick-view" data-quickview=\'' . esc_attr( $qv ) . '\'>' . esc_html__( 'Quick View', 'guns2ammo' ) . '</button>';
	echo '</div>';
}, 10 );

/* Force product-only archive display to prevent category tiles from occupying
 * first grid slots when Woo settings are set to "both". */
add_filter( 'pre_option_woocommerce_shop_page_display', function () {
	return 'products';
} );

add_filter( 'pre_option_woocommerce_category_archive_display', function () {
	return 'products';
} );

/* ------------------------------------------------------------------
 * Force WooCommerce related/up-sells to render a 4-up grid so they
 * don't render as tall narrow columns inside the single-product layout.
 * (Default is 2 columns / 3 items which break the visual grid.)
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_output_related_products_args', function ( $args ) {
	$args['posts_per_page'] = 4;
	$args['columns']        = 4;
	return $args;
} );
add_filter( 'woocommerce_upsells_total', function () { return 4; } );
add_filter( 'woocommerce_upsell_display_args', function ( $args ) {
	$args['columns']        = 4;
	$args['posts_per_page'] = 4;
	return $args;
} );

/* ------------------------------------------------------------------
 * Cart fragments: refresh the header cart count badge over AJAX.
 * Required so the .cart-chip count updates after "Add to cart" without
 * a full page reload.
 * ------------------------------------------------------------------ */
add_filter( 'woocommerce_add_to_cart_fragments', function ( $fragments ) {
	if ( ! function_exists( 'WC' ) ) {
		return $fragments;
	}
	$count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
	ob_start();
	?>
	<span class="g2a-cart-count cart-chip__count"><?php echo (int) $count; ?></span>
	<?php
	$fragments['span.g2a-cart-count'] = ob_get_clean();
	return $fragments;
} );

/**
 * Apply shop filters on native WooCommerce archives (/shop/, categories, tags).
 */
add_action( 'pre_get_posts', function ( $query ) {
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	// is_product_taxonomy() already covers product_cat and product_tag archives.
	if ( ! ( function_exists( 'is_shop' ) && ( is_shop() || is_product_taxonomy() ) ) ) {
		return;
	}

	$meta_query = $query->get( 'meta_query' );
	if ( ! is_array( $meta_query ) ) {
		$meta_query = [];
	}

	$stock = sanitize_text_field( wp_unslash( $_GET['stock'] ?? '' ) );
	if ( 'in' === $stock ) {
		$meta_query[] = [
			'key'   => '_stock_status',
			'value' => 'instock',
		];
	} elseif ( 'out' === $stock ) {
		$meta_query[] = [
			'key'   => '_stock_status',
			'value' => 'outofstock',
		];
	}

	$min = isset( $_GET['min_price'] ) ? max( 0, (float) $_GET['min_price'] ) : 0;
	$max = isset( $_GET['max_price'] ) ? max( 0, (float) $_GET['max_price'] ) : 0;
	$has_price_filter = false;
	if ( $min > 0 || $max > 0 ) {
		$range = [ 'key' => '_price', 'type' => 'DECIMAL(10,2)' ];
		if ( $min > 0 && $max > 0 && $max >= $min ) {
			$range['value']   = [ $min, $max ];
			$range['compare'] = 'BETWEEN';
		} elseif ( $min > 0 ) {
			$range['value']   = $min;
			$range['compare'] = '>=';
		} elseif ( $max > 0 ) {
			$range['value']   = $max;
			$range['compare'] = '<=';
		}
		// Named clause so price sorting can order by THIS join instead of a
		// second `meta_key=_price` join — combining a price filter with a
		// price sort previously produced an ambiguous query that returned
		// wrong/empty results.
		$meta_query['g2a_price'] = $range;
		$has_price_filter        = true;
	}
	$query->set( 'meta_query', $meta_query );

	$order = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? '' ) );
	switch ( $order ) {
		case 'date':
			$query->set( 'orderby', 'date' );
			$query->set( 'order', 'DESC' );
			break;
		case 'price':
			if ( $has_price_filter ) {
				$query->set( 'orderby', [ 'g2a_price' => 'ASC' ] );
			} else {
				$query->set( 'meta_key', '_price' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'ASC' );
			}
			break;
		case 'price-desc':
			if ( $has_price_filter ) {
				$query->set( 'orderby', [ 'g2a_price' => 'DESC' ] );
			} else {
				$query->set( 'meta_key', '_price' );
				$query->set( 'orderby', 'meta_value_num' );
				$query->set( 'order', 'DESC' );
			}
			break;
		case 'popularity':
			$query->set( 'meta_key', 'total_sales' );
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'DESC' );
			break;
		case 'rating':
			$query->set( 'meta_key', '_wc_average_rating' );
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'DESC' );
			break;
	}
} );

/* ==================================================================
 * SALE / SPECIAL-OFFER COUNTDOWNS
 * A live countdown wherever a product is on sale with a scheduled end
 * date (_sale_price_dates_to): a "Special Offer" banner at the top of
 * the shop, a compact timer on shop cards, and a prominent timer on the
 * single-product page. All driven by one shared CSS + ticker.
 * ================================================================== */

/**
 * Sale-end UNIX timestamp for a product, or 0 when it isn't a time-boxed
 * sale that is still running. Variable products use the soonest child end.
 */
function g2a_sale_ends_ts( $product ) {
	if ( ! $product instanceof WC_Product || ! $product->is_on_sale() ) {
		return 0;
	}
	$dt = $product->get_date_on_sale_to();
	$to = $dt ? (int) $dt->getTimestamp() : 0;
	if ( ! $to && $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $cid ) {
			$child = wc_get_product( $cid );
			$cdt   = $child && $child->is_on_sale() ? $child->get_date_on_sale_to() : null;
			if ( $cdt ) {
				$cto = (int) $cdt->getTimestamp();
				$to  = $to ? min( $to, $cto ) : $cto;
			}
		}
	}
	return ( $to && $to > time() ) ? $to : 0;
}

/**
 * Print the shared countdown CSS + ticker once per page. The ticker
 * re-queries every second, so countdowns rendered after it still update.
 */
function g2a_sale_countdown_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
<style id="g2a-sale-cd-css">
.g2a-cd{--cd-brass:var(--color-brass-bright,#DCB45F);--cd-ember:var(--color-ember,#E8802F);font-family:var(--font-mono,monospace)}
.g2a-cd.is-ended{display:none}
.g2a-cd--card{display:flex;align-items:center;gap:6px;margin:8px 0 2px;font-size:10px;letter-spacing:.1em;text-transform:uppercase;color:var(--color-silver,#8E8D96)}
.g2a-cd--card .g2a-cd__lbl{color:var(--cd-ember);font-weight:700}
.g2a-cd--card .g2a-cd__t b{color:var(--color-white,#F4F4F6);font-weight:700}
.g2a-offer{position:relative;overflow:hidden;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;margin:0 0 22px;padding:18px 22px;border:1px solid var(--color-hairline,rgba(255,255,255,.1));border-radius:12px;background:linear-gradient(120deg,var(--color-gunmetal,#26252C),var(--color-surface-2,#1f1f27))}
.g2a-offer::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,var(--cd-brass),var(--cd-ember))}
.g2a-offer__l{min-width:0}
.g2a-offer__ey{font-family:var(--font-mono,monospace);font-size:10px;letter-spacing:.24em;text-transform:uppercase;color:var(--cd-ember);font-weight:700}
.g2a-offer__t{font-family:var(--font-display,sans-serif);font-size:clamp(20px,3vw,30px);line-height:1.05;margin:4px 0 0;color:var(--color-white,#F4F4F6)}
.g2a-offer__t a{color:inherit;text-decoration:none}
.g2a-offer__t a:hover{color:var(--cd-brass)}
.g2a-offer__sub{font-family:var(--font-ui,sans-serif);font-size:13px;color:var(--color-fog,#CBCAD2);margin-top:2px}
.g2a-cd__units{display:flex;gap:10px}
.g2a-cd__units span{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:54px;padding:10px 8px;background:rgba(0,0,0,.28);border:1px solid rgba(220,180,95,.4);border-radius:10px}
html[data-theme="light"] .g2a-cd__units span{background:rgba(20,19,23,.06)}
.g2a-cd__units b{font-family:var(--font-mono,monospace);font-size:26px;font-weight:700;line-height:1;color:var(--cd-brass);font-variant-numeric:tabular-nums}
.g2a-cd__units i{font-style:normal;font-size:9px;letter-spacing:.16em;text-transform:uppercase;color:var(--color-silver,#8E8D96)}
.g2a-cd--single{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin:14px 0;padding:14px 16px;border:1px solid rgba(220,180,95,.4);border-radius:12px;background:linear-gradient(120deg,rgba(232,128,47,.10),transparent)}
.g2a-cd--single .g2a-cd__flag{font-family:var(--font-mono,monospace);font-size:11px;letter-spacing:.18em;text-transform:uppercase;font-weight:700;color:var(--cd-ember)}
@media(max-width:600px){.g2a-cd__units span{min-width:46px}.g2a-cd__units b{font-size:21px}}
</style>
<script id="g2a-sale-cd-js">
(function(){
	function pad(n){return n<10?'0'+n:''+n;}
	function tick(){
		var now=Date.now();
		var nodes=document.querySelectorAll('.g2a-cd[data-ends]');
		for(var i=0;i<nodes.length;i++){
			var el=nodes[i];
			var t=parseInt(el.getAttribute('data-ends'),10)*1000;
			var diff=Math.max(0,t-now),s=Math.floor(diff/1000);
			var d=Math.floor(s/86400);s%=86400;var h=Math.floor(s/3600);s%=3600;var m=Math.floor(s/60);s%=60;
			var dd=el.querySelector('[data-d]'),hh=el.querySelector('[data-h]'),mm=el.querySelector('[data-m]'),ss=el.querySelector('[data-s]');
			if(dd)dd.textContent=pad(d);if(hh)hh.textContent=pad(h);if(mm)mm.textContent=pad(m);if(ss)ss.textContent=pad(s);
			if(diff<=0)el.classList.add('is-ended');
		}
	}
	tick();setInterval(tick,1000);
})();
</script>
	<?php
}

/** Compact "Sale ends" timer under the price on shop cards. */
function g2a_render_sale_countdown_card() {
	global $product;
	$ends = g2a_sale_ends_ts( $product );
	if ( ! $ends ) {
		return;
	}
	g2a_sale_countdown_assets();
	echo '<div class="g2a-cd g2a-cd--card" data-ends="' . esc_attr( (string) $ends ) . '" role="timer" aria-label="' . esc_attr__( 'Sale ends in', 'guns2ammo' ) . '">'
		. '<span class="g2a-cd__lbl">' . esc_html__( 'Sale ends', 'guns2ammo' ) . '</span>'
		. '<span class="g2a-cd__t"><b data-d>00</b>d <b data-h>00</b>h <b data-m>00</b>m <b data-s>00</b>s</span>'
		. '</div>';
}
add_action( 'woocommerce_after_shop_loop_item_title', 'g2a_render_sale_countdown_card', 11 );

/** Prominent special-offer timer on the single-product summary. */
function g2a_render_sale_countdown_single() {
	global $product;
	$ends = g2a_sale_ends_ts( $product );
	if ( ! $ends ) {
		return;
	}
	g2a_sale_countdown_assets();
	echo '<div class="g2a-cd g2a-cd--single" data-ends="' . esc_attr( (string) $ends ) . '" role="timer">'
		. '<span class="g2a-cd__flag">' . esc_html__( '⚡ Special Offer ends in', 'guns2ammo' ) . '</span>'
		. '<div class="g2a-cd__units">'
		. '<span><b data-d>00</b><i>' . esc_html__( 'Days', 'guns2ammo' ) . '</i></span>'
		. '<span><b data-h>00</b><i>' . esc_html__( 'Hrs', 'guns2ammo' ) . '</i></span>'
		. '<span><b data-m>00</b><i>' . esc_html__( 'Min', 'guns2ammo' ) . '</i></span>'
		. '<span><b data-s>00</b><i>' . esc_html__( 'Sec', 'guns2ammo' ) . '</i></span>'
		. '</div></div>';
}
add_action( 'woocommerce_single_product_summary', 'g2a_render_sale_countdown_single', 25 );

/** "Special Offer" banner with the soonest-ending sale on the main shop. */
function g2a_render_shop_offer_banner() {
	if ( ! ( function_exists( 'is_shop' ) && is_shop() ) || is_paged() ) {
		return;
	}
	$q = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
			'meta_key'       => '_sale_price_dates_to',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => '_sale_price_dates_to',
					'value'   => time(),
					'compare' => '>',
					'type'    => 'NUMERIC',
				),
			),
		)
	);
	if ( empty( $q->posts ) ) {
		return;
	}
	$product = wc_get_product( (int) $q->posts[0] );
	$ends    = g2a_sale_ends_ts( $product );
	if ( ! $ends ) {
		return;
	}
	g2a_sale_countdown_assets();
	?>
	<div class="g2a-offer" role="region" aria-label="<?php esc_attr_e( 'Special offer', 'guns2ammo' ); ?>">
		<div class="g2a-offer__l">
			<div class="g2a-offer__ey"><?php esc_html_e( 'Special Offer', 'guns2ammo' ); ?></div>
			<h3 class="g2a-offer__t"><a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></h3>
			<div class="g2a-offer__sub"><?php echo wp_kses_post( $product->get_price_html() ); ?> &mdash; <?php esc_html_e( 'limited time only', 'guns2ammo' ); ?></div>
		</div>
		<div class="g2a-cd g2a-cd__units" data-ends="<?php echo esc_attr( (string) $ends ); ?>" role="timer">
			<span><b data-d>00</b><i><?php esc_html_e( 'Days', 'guns2ammo' ); ?></i></span>
			<span><b data-h>00</b><i><?php esc_html_e( 'Hrs', 'guns2ammo' ); ?></i></span>
			<span><b data-m>00</b><i><?php esc_html_e( 'Min', 'guns2ammo' ); ?></i></span>
			<span><b data-s>00</b><i><?php esc_html_e( 'Sec', 'guns2ammo' ); ?></i></span>
		</div>
	</div>
	<?php
	wp_reset_postdata();
}
add_action( 'woocommerce_before_shop_loop', 'g2a_render_shop_offer_banner', 5 );
