<?php
/**
 * WooCommerce Single Product Template
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header( 'shop' );
?>
<style>
.g2a-single { padding: 110px 24px 72px; }
.g2a-single .wrap { max-width: 1280px; margin: 0 auto; }
.g2a-single .woocommerce-breadcrumb {
	font-family: var(--font-mono);
	font-size: 11px;
	letter-spacing: 0.22em;
	text-transform: uppercase;
	color: var(--color-silver);
	margin: 0 0 22px;
}
.g2a-single .woocommerce-breadcrumb a { color: var(--color-silver); text-decoration: none; }
.g2a-single .woocommerce-breadcrumb a:hover { color: var(--color-brass-bright); }
.g2a-single .woocommerce-breadcrumb .delimiter { margin: 0 8px; color: var(--color-hairline-bright); }
@media (max-width: 768px) {
	.g2a-single { padding: 96px 16px 56px; }
}
</style>
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
