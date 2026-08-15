<?php
/**
 * WooCommerce Single Product Template
 *
 * Thin wrapper only. The page's structure comes from inc/single-product.php
 * (summary stack + tabs), the gallery and tab template overrides in
 * woocommerce/single-product/, and assets/css/single-product.css — all of
 * which are loaded from functions.php on product requests.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header( 'shop' );
?>
<section class="g2a-single">
	<div class="wrap woocommerce single-product">
		<?php do_action( 'woocommerce_before_main_content' ); ?>
		<?php while ( have_posts() ) : the_post(); ?>
			<?php wc_get_template_part( 'content', 'single-product' ); ?>
		<?php endwhile; ?>
		<?php do_action( 'woocommerce_after_main_content' ); ?>
	</div>
</section>
<?php global $product; if ( $product instanceof WC_Product ) : ?>
	<div class="g2a-sticky-product-bar">
		<div class="info">
			<span class="nm"><?php echo esc_html( $product->get_name() ); ?></span>
			<span class="pr"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
		</div>
		<div class="actions">
			<a class="btn btn-brass btn-sm" href="<?php echo esc_url( home_url( '/book-a-lane/' ) ); ?>"><?php esc_html_e( 'Try At Range', 'guns2ammo' ); ?></a>
			<?php if ( $product->is_type( 'simple' ) && $product->is_purchasable() && $product->is_in_stock() ) : ?>
				<a class="btn btn-ember btn-sm ajax_add_to_cart add_to_cart_button" href="<?php echo esc_url( $product->add_to_cart_url() ); ?>" data-product_id="<?php echo esc_attr( $product->get_id() ); ?>" data-quantity="1" rel="nofollow"><?php echo esc_html( $product->add_to_cart_text() ); ?></a>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>
<?php
get_footer( 'shop' );
