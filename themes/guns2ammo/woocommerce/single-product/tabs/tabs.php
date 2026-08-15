<?php
/**
 * Single-product tabs — vertical rail layout.
 *
 * Standard WooCommerce tab markup is preserved on purpose (`ul.wc-tabs`,
 * `li.{key}_tab`, `#tab-{key}` panels carrying `.wc-tab`), so core's
 * wc-single-product script keeps driving the tab switching and deep links to
 * `#tab-reviews` still work. Only the wrapper and the per-tab icon are new.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$product_tabs = apply_filters( 'woocommerce_product_tabs', array() );

if ( empty( $product_tabs ) ) {
	return;
}
?>
<div class="woocommerce-tabs wc-tabs-wrapper g2a-tabs">
	<ul class="tabs wc-tabs g2a-tabs__nav" role="tablist">
		<?php foreach ( $product_tabs as $key => $product_tab ) : ?>
			<li class="<?php echo esc_attr( $key ); ?>_tab" id="tab-title-<?php echo esc_attr( $key ); ?>" role="tab" aria-controls="tab-<?php echo esc_attr( $key ); ?>">
				<a href="#tab-<?php echo esc_attr( $key ); ?>">
					<?php echo g2a_sp_icon( isset( $product_tab['icon'] ) ? $product_tab['icon'] : 'doc' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
					<span><?php echo wp_kses_post( apply_filters( 'woocommerce_product_' . $key . '_tab_title', $product_tab['title'], $key ) ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>

	<div class="g2a-tabs__panels">
		<?php foreach ( $product_tabs as $key => $product_tab ) : ?>
			<div class="woocommerce-Tabs-panel woocommerce-Tabs-panel--<?php echo esc_attr( $key ); ?> panel entry-content wc-tab" id="tab-<?php echo esc_attr( $key ); ?>" role="tabpanel" aria-labelledby="tab-title-<?php echo esc_attr( $key ); ?>">
				<?php
				if ( isset( $product_tab['callback'] ) ) {
					call_user_func( $product_tab['callback'], $key, $product_tab );
				}
				?>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<?php do_action( 'woocommerce_product_after_tabs' ); ?>
