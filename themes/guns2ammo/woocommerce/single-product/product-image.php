<?php
/**
 * Single-product gallery.
 *
 * Replaces the stock FlexSlider gallery with the design's vertical thumbnail
 * rail + main stage. The rail scrolls with its own chevron controls and the
 * stage opens a lightbox on click; both are driven by
 * assets/js/single-product.js, so the page ships no FlexSlider, no zoom
 * plugin and no PhotoSwipe (the matching theme supports are intentionally
 * not registered in functions.php).
 *
 * Without JavaScript the stage still shows the featured image and every
 * thumbnail remains a link to its own full-size file.
 *
 * @package guns2ammo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $product;

if ( ! $product instanceof WC_Product ) {
	return;
}

$g2a_image_id  = $product->get_image_id();
$g2a_image_ids = $product->get_gallery_image_ids();

if ( $g2a_image_id ) {
	array_unshift( $g2a_image_ids, $g2a_image_id );
}
$g2a_image_ids = array_values( array_unique( array_filter( array_map( 'absint', $g2a_image_ids ) ) ) );

$g2a_has_images = ! empty( $g2a_image_ids );
$g2a_main_id    = $g2a_has_images ? $g2a_image_ids[0] : 0;
$g2a_alt        = $g2a_main_id ? trim( (string) get_post_meta( $g2a_main_id, '_wp_attachment_image_alt', true ) ) : '';
$g2a_alt        = '' !== $g2a_alt ? $g2a_alt : $product->get_name();
$g2a_full       = $g2a_main_id ? wp_get_attachment_image_url( $g2a_main_id, 'full' ) : '';
?>
<div class="images g2a-gallery<?php echo count( $g2a_image_ids ) > 1 ? ' has-rail' : ''; ?>" data-g2a-gallery>

	<?php if ( count( $g2a_image_ids ) > 1 ) : ?>
		<div class="g2a-gallery__rail">
			<button type="button" class="g2a-gallery__scroll is-prev" data-gallery-scroll="-1" aria-label="<?php esc_attr_e( 'Scroll thumbnails up', 'guns2ammo' ); ?>">
				<?php echo g2a_sp_icon( 'chevron-up' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
			</button>

			<div class="g2a-gallery__thumbs" data-gallery-thumbs>
				<?php foreach ( $g2a_image_ids as $g2a_index => $g2a_id ) : ?>
					<?php
					$g2a_thumb_alt  = trim( (string) get_post_meta( $g2a_id, '_wp_attachment_image_alt', true ) );
					$g2a_thumb_alt  = '' !== $g2a_thumb_alt ? $g2a_thumb_alt : $product->get_name();
					$g2a_thumb_full = wp_get_attachment_image_url( $g2a_id, 'full' );
					$g2a_large      = wp_get_attachment_image_src( $g2a_id, 'woocommerce_single' );
					?>
					<a
						class="g2a-gallery__thumb<?php echo 0 === $g2a_index ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( (string) $g2a_thumb_full ); ?>"
						data-gallery-index="<?php echo (int) $g2a_index; ?>"
						data-src="<?php echo esc_url( (string) ( $g2a_large ? $g2a_large[0] : $g2a_thumb_full ) ); ?>"
						data-srcset="<?php echo esc_attr( (string) wp_get_attachment_image_srcset( $g2a_id, 'woocommerce_single' ) ); ?>"
						data-full="<?php echo esc_url( (string) $g2a_thumb_full ); ?>"
						data-alt="<?php echo esc_attr( $g2a_thumb_alt ); ?>"
					>
						<?php echo wp_get_attachment_image( $g2a_id, 'woocommerce_gallery_thumbnail', false, array( 'alt' => $g2a_thumb_alt ) ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<button type="button" class="g2a-gallery__scroll is-next" data-gallery-scroll="1" aria-label="<?php esc_attr_e( 'Scroll thumbnails down', 'guns2ammo' ); ?>">
				<?php echo g2a_sp_icon( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
			</button>
		</div>
	<?php endif; ?>

	<figure class="g2a-gallery__stage">
		<div class="g2a-gallery__badges">
			<?php if ( g2a_sp_is_new( $product ) ) : ?>
				<span class="g2a-badge g2a-badge--new"><?php esc_html_e( 'New', 'guns2ammo' ); ?></span>
			<?php endif; ?>
			<?php if ( $product->is_on_sale() ) : ?>
				<span class="g2a-badge g2a-badge--sale"><?php esc_html_e( 'Sale', 'guns2ammo' ); ?></span>
			<?php endif; ?>
			<?php if ( ! $product->is_in_stock() ) : ?>
				<span class="g2a-badge g2a-badge--out"><?php esc_html_e( 'Sold Out', 'guns2ammo' ); ?></span>
			<?php endif; ?>
		</div>

		<div class="g2a-gallery__frame">
			<?php if ( $g2a_has_images ) : ?>
				<?php
				echo wp_get_attachment_image(
					$g2a_main_id,
					'woocommerce_single',
					false,
					array(
						'class'         => 'g2a-gallery__image',
						'alt'           => $g2a_alt,
						'data-full'     => (string) $g2a_full,
						'data-index'    => '0',
						'fetchpriority' => 'high',
					)
				);
				?>
			<?php else : ?>
				<img class="g2a-gallery__image" src="<?php echo esc_url( wc_placeholder_img_src( 'woocommerce_single' ) ); ?>" alt="<?php echo esc_attr( $g2a_alt ); ?>" />
			<?php endif; ?>
		</div>

		<?php if ( $g2a_has_images ) : ?>
			<button type="button" class="g2a-gallery__zoom" data-gallery-zoom>
				<?php echo g2a_sp_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline SVG. ?>
				<span><?php esc_html_e( 'Click to zoom', 'guns2ammo' ); ?></span>
			</button>
		<?php endif; ?>
	</figure>

	<?php
	/* Kept so plugins that append to the gallery still get their hook. The
	 * theme's own thumbnails render in the rail above, and
	 * single-product/product-thumbnails.php is overridden to output nothing. */
	do_action( 'woocommerce_product_thumbnails' );
	?>
</div>
