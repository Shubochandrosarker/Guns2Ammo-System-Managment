<?php
/**
 * Default page template — used when a Page does not have a custom Template assigned.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

$is_wc_page = function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() || ( function_exists( 'is_shop' ) && is_shop() ) );

// WooCommerce pages (cart / checkout / my-account) ship their own hero
// inside the shortcode/template output, so skip the page.php H1 and drop
// the top padding to avoid a duplicate "CART" / "MY ACCOUNT" heading.
$wrapper_pad = $is_wc_page ? '0' : '140px';
?>

<section class="section" style="padding-top: <?php echo esc_attr( $wrapper_pad ); ?>;" data-no-hero>
	<div class="container"<?php if ( $is_wc_page ) : ?> style="max-width:none;padding:0;"<?php endif; ?>>
		<?php while ( have_posts() ) : the_post(); ?>
			<?php if ( ! $is_wc_page ) : ?>
				<header style="margin-bottom: 48px;">
					<span class="eb-pill"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
					<h1 class="hl-display" style="font-size: clamp(48px, 7vw, 96px); margin-top: 20px;">
						<?php the_title(); ?>
					</h1>
				</header>
			<?php endif; ?>
			<div class="<?php echo $is_wc_page ? 'woocommerce g2a-wc-page' : 'g2a-prose'; ?>" style="<?php echo $is_wc_page ? 'color: var(--color-fog);' : 'max-width: 72ch; color: var(--color-fog); font-size: 17px; line-height: 1.8;'; ?>">
				<?php the_content(); ?>
			</div>
		<?php endwhile; ?>
	</div>
</section>

<?php get_footer();
