<?php
/**
 * Template Name: Shop
 * Dynamic WooCommerce shop template.
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WooCommerce' ) ) {
	get_header();
	echo '<section class="section" style="padding-top:120px;"><div class="container"><p>WooCommerce is required for the shop.</p></div></section>';
	get_footer();
	return;
}

$paged = max( 1, (int) get_query_var( 'paged' ), (int) ( $_GET['paged'] ?? 1 ) );
$search = sanitize_text_field( wp_unslash( $_GET['g2a_q'] ?? ( $_GET['s'] ?? '' ) ) );
$order  = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? 'menu_order' ) );
$cat    = sanitize_title( wp_unslash( $_GET['product_cat'] ?? '' ) );
$stock  = sanitize_text_field( wp_unslash( $_GET['stock'] ?? '' ) );
$min    = isset( $_GET['min_price'] ) ? (float) $_GET['min_price'] : 0;
$max    = isset( $_GET['max_price'] ) ? (float) $_GET['max_price'] : 0;

$allowed_order = [ 'menu_order', 'date', 'price', 'price-desc', 'popularity', 'rating' ];
if ( ! in_array( $order, $allowed_order, true ) ) {
	$order = 'menu_order';
}

$args = [
	'post_type'      => 'product',
	'post_status'    => 'publish',
	'posts_per_page' => (int) apply_filters( 'loop_shop_per_page', 12 ),
	'paged'          => $paged,
	's'              => $search,
	'tax_query'      => [],
	'meta_query'     => ( function_exists( 'WC' ) && WC()->query ) ? WC()->query->get_meta_query() : [],
];

if ( $cat ) {
	$args['tax_query'][] = [
		'taxonomy' => 'product_cat',
		'field'    => 'slug',
		'terms'    => $cat,
	];
}

if ( 'in' === $stock ) {
	$args['meta_query'][] = [ 'key' => '_stock_status', 'value' => 'instock' ];
} elseif ( 'out' === $stock ) {
	$args['meta_query'][] = [ 'key' => '_stock_status', 'value' => 'outofstock' ];
}

if ( $min > 0 || $max > 0 ) {
	$range = [ 'key' => '_price', 'type' => 'DECIMAL(10,2)' ];
	if ( $min > 0 && $max > 0 && $max >= $min ) {
		$range['value'] = [ $min, $max ];
		$range['compare'] = 'BETWEEN';
	} elseif ( $min > 0 ) {
		$range['value'] = $min;
		$range['compare'] = '>=';
	} elseif ( $max > 0 ) {
		$range['value'] = $max;
		$range['compare'] = '<=';
	}
	$args['meta_query'][] = $range;
}

switch ( $order ) {
	case 'date':
		$args['orderby'] = 'date';
		$args['order']   = 'DESC';
		break;
	case 'price':
		$args['meta_key'] = '_price';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'ASC';
		break;
	case 'price-desc':
		$args['meta_key'] = '_price';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
		break;
	case 'popularity':
		$args['meta_key'] = 'total_sales';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
		break;
	case 'rating':
		$args['meta_key'] = '_wc_average_rating';
		$args['orderby']  = 'meta_value_num';
		$args['order']    = 'DESC';
		break;
	default:
		$args['orderby'] = [ 'menu_order' => 'ASC', 'date' => 'DESC' ];
}

$query = new WP_Query( $args );
$cats  = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );

get_header();
?>
<style>
.g2a-shop{padding:120px 24px 72px}.g2a-shop .wrap{max-width:1280px;margin:0 auto}
.g2a-shop .head{display:flex;justify-content:space-between;gap:16px;align-items:flex-end;flex-wrap:wrap;margin-bottom:24px}
.g2a-shop h1{margin:0}.g2a-shop .grid{display:grid;grid-template-columns:260px 1fr;gap:28px}
.g2a-shop .side{border:1px solid var(--color-hairline);padding:18px;background:var(--color-gunmetal)}
.g2a-shop .side h5{margin:0 0 10px}.g2a-shop .cat-list a{display:flex;justify-content:space-between;padding:8px 0;color:var(--color-fog);text-decoration:none}
.g2a-shop .cat-list a.active,.g2a-shop .cat-list a:hover{color:var(--color-brass-bright)}
.g2a-shop .filters{display:grid;gap:14px}.g2a-shop .filters input,.g2a-shop .filters select{width:100%}
.g2a-shop .toolbar{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;margin-bottom:18px}
.g2a-shop .products{margin:0;padding:0;list-style:none}
.g2a-shop .pager{margin-top:24px;display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:980px){.g2a-shop .grid{grid-template-columns:1fr}.g2a-shop .side{order:2}}
</style>
<section class="g2a-shop woocommerce">
	<div class="wrap">
		<div class="head">
			<div><span class="eyebrow">Retail Catalog</span><h1>Shop</h1></div>
			<form method="get" class="toolbar" style="margin:0;">
				<input type="search" name="g2a_q" value="<?php echo esc_attr( $search ); ?>" placeholder="Search products">
				<select name="orderby">
					<option value="menu_order" <?php selected( $order, 'menu_order' ); ?>>Default</option>
					<option value="date" <?php selected( $order, 'date' ); ?>>Newest</option>
					<option value="price" <?php selected( $order, 'price' ); ?>>Price: Low to High</option>
					<option value="price-desc" <?php selected( $order, 'price-desc' ); ?>>Price: High to Low</option>
					<option value="popularity" <?php selected( $order, 'popularity' ); ?>>Popularity</option>
					<option value="rating" <?php selected( $order, 'rating' ); ?>>Top Rated</option>
				</select>
				<?php if ( $cat ) : ?><input type="hidden" name="product_cat" value="<?php echo esc_attr( $cat ); ?>"><?php endif; ?>
				<?php if ( $stock ) : ?><input type="hidden" name="stock" value="<?php echo esc_attr( $stock ); ?>"><?php endif; ?>
				<?php if ( $min > 0 ) : ?><input type="hidden" name="min_price" value="<?php echo esc_attr( $min ); ?>"><?php endif; ?>
				<?php if ( $max > 0 ) : ?><input type="hidden" name="max_price" value="<?php echo esc_attr( $max ); ?>"><?php endif; ?>
				<button class="btn btn-brass btn-sm" type="submit">Apply</button>
			</form>
		</div>
		<div class="grid">
			<aside class="side">
				<h5>Categories</h5>
				<div class="cat-list">
					<?php
					$all_url = remove_query_arg( [ 'product_cat', 'paged' ] );
					echo '<a class="' . ( '' === $cat ? 'active' : '' ) . '" href="' . esc_url( $all_url ) . '"><span>All</span><span>' . (int) wp_count_posts( 'product' )->publish . '</span></a>';
					foreach ( $cats as $term ) {
						$url = add_query_arg( 'product_cat', $term->slug, remove_query_arg( 'paged' ) );
						echo '<a class="' . ( $cat === $term->slug ? 'active' : '' ) . '" href="' . esc_url( $url ) . '"><span>' . esc_html( $term->name ) . '</span><span>' . (int) $term->count . '</span></a>';
					}
					?>
				</div>
				<form method="get" class="filters" style="margin-top:16px;">
					<?php if ( $search ) : ?><input type="hidden" name="g2a_q" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
					<?php if ( $cat ) : ?><input type="hidden" name="product_cat" value="<?php echo esc_attr( $cat ); ?>"><?php endif; ?>
					<?php if ( $order ) : ?><input type="hidden" name="orderby" value="<?php echo esc_attr( $order ); ?>"><?php endif; ?>
					<div><label for="stock">Stock</label><select id="stock" name="stock"><option value="">Any</option><option value="in" <?php selected( $stock, 'in' ); ?>>In stock</option><option value="out" <?php selected( $stock, 'out' ); ?>>Out of stock</option></select></div>
					<div><label for="min_price">Min price</label><input id="min_price" type="number" step="0.01" min="0" name="min_price" value="<?php echo esc_attr( $min > 0 ? $min : '' ); ?>"></div>
					<div><label for="max_price">Max price</label><input id="max_price" type="number" step="0.01" min="0" name="max_price" value="<?php echo esc_attr( $max > 0 ? $max : '' ); ?>"></div>
					<button class="btn btn-brass btn-sm" type="submit">Filter</button>
				</form>
			</aside>
			<div>
				<div class="toolbar"><div class="results"><?php echo esc_html( sprintf( 'Showing %d of %d products', (int) $query->post_count, (int) $query->found_posts ) ); ?></div></div>
				<?php if ( $query->have_posts() ) : ?>
					<ul class="products columns-4">
						<?php while ( $query->have_posts() ) : $query->the_post(); wc_get_template_part( 'content', 'product' ); endwhile; ?>
					</ul>
					<?php
					$pagination = paginate_links([
						'total'   => max( 1, (int) $query->max_num_pages ),
						'current' => $paged,
						'format'  => '?paged=%#%',
						'type'    => 'array',
					]);
					if ( ! empty( $pagination ) ) {
						echo '<nav class="pager">';
						foreach ( $pagination as $link ) {
							echo wp_kses_post( $link );
						}
						echo '</nav>';
					}
					?>
				<?php else : ?>
					<p>No products found for this filter.</p>
				<?php endif; wp_reset_postdata(); ?>
			</div>
		</div>
	</div>
</section>
<?php get_footer();