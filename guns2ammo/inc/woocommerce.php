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
 * - skipped when Rank Math / Yoast is active (avoid duplicate Product)
 * - placeholder image fallback when product has no thumbnail
 * ------------------------------------------------------------------ */
add_action( 'wp_head', function () {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	if ( function_exists( 'g2a_seo_plugin_active' ) && g2a_seo_plugin_active() ) {
		return;
	}
	global $product;
	if ( ! $product instanceof WC_Product ) {
		$product = wc_get_product( get_the_ID() );
	}
	if ( ! $product instanceof WC_Product ) {
		return;
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
		'offers'      => [
			'@type'         => 'Offer',
			'priceCurrency' => get_woocommerce_currency(),
			'price'         => $product->get_price(),
			'availability'  => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
			'url'           => get_permalink( $product->get_id() ),
		],
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
		$meta_query[] = $range;
	}
	$query->set( 'meta_query', $meta_query );

	$order = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? '' ) );
	switch ( $order ) {
		case 'date':
			$query->set( 'orderby', 'date' );
			$query->set( 'order', 'DESC' );
			break;
		case 'price':
			$query->set( 'meta_key', '_price' );
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'ASC' );
			break;
		case 'price-desc':
			$query->set( 'meta_key', '_price' );
			$query->set( 'orderby', 'meta_value_num' );
			$query->set( 'order', 'DESC' );
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
