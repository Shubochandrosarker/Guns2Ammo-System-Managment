<?php
/**
 * Template Name: Single Product (Legacy)
 *
 * Dynamic fallback template. Use real WooCommerce product permalinks for the
 * canonical single-product experience.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	get_header();
	echo '<section class="section" style="padding-top:120px;"><div class="container"><p>WooCommerce is required for product pages.</p></div></section>';
	get_footer();
	return;
}

$paged = max( 1, (int) get_query_var( 'paged' ), (int) ( $_GET['paged'] ?? 1 ) );
$q     = new WP_Query([
	'post_type'      => 'product',
	'post_status'    => 'publish',
	'posts_per_page' => 12,
	'paged'          => $paged,
]);

get_header();
?>
<section class="section" style="padding-top:120px;">
	<div class="container woocommerce">
		<div style="margin-bottom:20px;">
			<span class="eyebrow">Legacy Product Template</span>
			<h1 style="margin:8px 0 0;">Browse Live Products</h1>
			<p style="max-width:68ch;">This page is dynamic and pulls real products from WooCommerce. For an individual product page, open the product permalink from the shop catalog.</p>
		</div>
		<?php if ( $q->have_posts() ) : ?>
			<ul class="products columns-4">
				<?php while ( $q->have_posts() ) : $q->the_post(); wc_get_template_part( 'content', 'product' ); endwhile; ?>
			</ul>
			<?php
			$links = paginate_links([
				'total'   => (int) $q->max_num_pages,
				'current' => $paged,
				'format'  => '?paged=%#%',
				'type'    => 'array',
			]);
			if ( $links ) {
				echo '<nav style="margin-top:20px;display:flex;gap:8px;flex-wrap:wrap;">';
				foreach ( $links as $link ) {
					echo wp_kses_post( $link );
				}
				echo '</nav>';
			}
			?>
		<?php else : ?>
			<p>No products found.</p>
		<?php endif; wp_reset_postdata(); ?>
	</div>
</section>
<?php get_footer();