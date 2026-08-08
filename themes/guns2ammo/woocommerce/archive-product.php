<?php
/**
 * WooCommerce Product Archive Template
 *
 * @package guns2ammo
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
$order  = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? 'menu_order' ) );
$stock  = sanitize_text_field( wp_unslash( $_GET['stock'] ?? '' ) );
$min    = isset( $_GET['min_price'] ) ? (float) $_GET['min_price'] : 0;
$max    = isset( $_GET['max_price'] ) ? (float) $_GET['max_price'] : 0;
$cat    = sanitize_title( wp_unslash( $_GET['product_cat'] ?? '' ) );

$cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );

// Single source of truth for NAP — see inc/business-info.php.
$g2a_biz = function_exists( 'g2a_biz' ) ? g2a_biz() : array();

get_header( 'shop' );
?>
<style>
.g2a-archive{padding:120px 24px 72px}.g2a-archive .wrap{max-width:1280px;margin:0 auto}
.g2a-archive .hero{margin-bottom:26px;padding:18px 22px;border:1px solid var(--color-hairline);background:linear-gradient(180deg,var(--color-surface-2),var(--color-surface-1))}
.g2a-archive .hero .ey{font-family:var(--font-mono);font-size:11px;letter-spacing:.24em;text-transform:uppercase;color:var(--color-brass-bright)}
.g2a-archive .hero h1{font-family:var(--font-display);font-size:clamp(40px,6vw,72px);line-height:.95;letter-spacing:.02em;margin:8px 0 0}
.g2a-archive .grid{display:grid;grid-template-columns:260px 1fr;gap:28px}
.g2a-archive .side{border:1px solid var(--color-hairline);padding:18px;background:var(--color-gunmetal)}
.g2a-archive .cat-list a{display:flex;justify-content:space-between;padding:8px 0;color:var(--color-fog);text-decoration:none}
.g2a-archive .cat-list a.active,.g2a-archive .cat-list a:hover{color:var(--color-brass-bright)}
.g2a-archive .filters{display:grid;gap:12px;margin-top:12px}
.g2a-archive .filters input,.g2a-archive .filters select{width:100%}
.g2a-archive .toolbar{display:grid;grid-template-columns:minmax(200px,1fr) minmax(180px,220px) auto;align-items:center;gap:12px;margin-bottom:16px;border:1px solid var(--color-hairline);padding:10px 12px;background:var(--color-gunmetal)}
.g2a-archive .toolbar input,.g2a-archive .toolbar select,.g2a-archive .filters input,.g2a-archive .filters select{background:var(--color-void);border:1px solid var(--color-steel);color:var(--color-white);min-height:38px;padding:8px 10px}
.g2a-archive .toolbar button{justify-self:end}
.g2a-archive .why{margin-top:52px;padding:0 0 8px}
.g2a-archive .why .ey{font-family:var(--font-mono);font-size:10px;letter-spacing:.24em;text-transform:uppercase;color:var(--color-brass-bright);margin-bottom:12px}
.g2a-archive .why .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.g2a-archive .why .card{background:var(--color-gunmetal);border:1px solid var(--color-hairline);padding:16px}
.g2a-archive .why .card h4{margin:0 0 6px;font-family:var(--font-mono);font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:var(--color-brass-bright)}
.g2a-archive .why .card p{margin:0;color:var(--color-fog);font-size:13px;line-height:1.5}
.g2a-archive .why .card a{display:inline-block;margin-top:10px;font-family:var(--font-mono);font-size:10px;letter-spacing:.18em;text-transform:uppercase;color:var(--color-ember);text-decoration:none}
.g2a-archive .why .card a:hover{color:var(--color-brass-bright)}
@media(max-width:980px){.g2a-archive .toolbar{grid-template-columns:1fr}.g2a-archive .toolbar button{justify-self:start}.g2a-archive .why .cards{grid-template-columns:1fr 1fr}}
@media(max-width:580px){.g2a-archive .why .cards{grid-template-columns:1fr}}
@media(max-width:980px){.g2a-archive .grid{grid-template-columns:1fr}.g2a-archive .side{order:2}}
</style>
<section class="g2a-archive woocommerce">
	<div class="wrap">
		<?php do_action( 'woocommerce_before_main_content' ); ?>
		<div class="hero">
			<div class="ey">Retail Inventory</div>
			<?php if ( apply_filters( 'woocommerce_show_page_title', true ) ) : ?>
				<h1 class="woocommerce-products-header__title page-title"><?php woocommerce_page_title(); ?></h1>
			<?php endif; ?>
		</div>
		<div class="grid">
			<aside class="side">
				<h5 style="margin:0 0 10px;">Categories</h5>
				<div class="cat-list">
					<?php
					$current_cat_slug = $cat;
					if ( '' === $current_cat_slug && function_exists( 'is_product_category' ) && is_product_category() ) {
						$qo = get_queried_object();
						if ( $qo && isset( $qo->slug ) ) {
							$current_cat_slug = $qo->slug;
						}
					}
					$shop_url = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0
						? get_permalink( wc_get_page_id( 'shop' ) )
						: home_url( '/shop/' );
					echo '<a class="' . ( '' === $current_cat_slug ? 'active' : '' ) . '" href="' . esc_url( $shop_url ) . '"><span>' . esc_html__( 'All', 'guns2ammo' ) . '</span></a>';
					foreach ( $cats as $term ) {
						$term_link = get_term_link( $term );
						if ( is_wp_error( $term_link ) ) {
							continue;
						}
						echo '<a class="' . ( $current_cat_slug === $term->slug ? 'active' : '' ) . '" href="' . esc_url( $term_link ) . '"><span>' . esc_html( $term->name ) . '</span><span>' . (int) $term->count . '</span></a>';
					}
					?>
				</div>
				<form method="get" class="filters">
					<?php if ( $search ) : ?><input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>"><?php endif; ?>
					<?php if ( $cat ) : ?><input type="hidden" name="product_cat" value="<?php echo esc_attr( $cat ); ?>"><?php endif; ?>
					<div><label for="stock">Stock</label><select id="stock" name="stock"><option value="">Any</option><option value="in" <?php selected( $stock, 'in' ); ?>>In stock</option><option value="out" <?php selected( $stock, 'out' ); ?>>Out of stock</option></select></div>
					<div><label for="min_price">Min price</label><input id="min_price" type="number" step="0.01" min="0" name="min_price" value="<?php echo esc_attr( $min > 0 ? $min : '' ); ?>"></div>
					<div><label for="max_price">Max price</label><input id="max_price" type="number" step="0.01" min="0" name="max_price" value="<?php echo esc_attr( $max > 0 ? $max : '' ); ?>"></div>
					<input type="hidden" name="orderby" value="<?php echo esc_attr( $order ); ?>">
					<button class="btn btn-brass btn-sm" type="submit">Filter</button>
				</form>
			</aside>

			<div>
				<header class="woocommerce-products-header" style="margin-bottom:20px;">
					<?php // The page title now renders once, in the hero block above. ?>
					<?php do_action( 'woocommerce_archive_description' ); ?>
				</header>
				<form method="get" class="toolbar">
					<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search products">
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

				<?php if ( woocommerce_product_loop() ) : ?>
					<?php woocommerce_output_all_notices(); ?>
					<?php do_action( 'woocommerce_before_shop_loop' ); ?>
					<?php woocommerce_product_loop_start(); ?>
					<?php while ( have_posts() ) : the_post(); ?>
						<?php do_action( 'woocommerce_shop_loop' ); ?>
						<?php wc_get_template_part( 'content', 'product' ); ?>
					<?php endwhile; ?>
					<?php woocommerce_product_loop_end(); ?>
					<?php do_action( 'woocommerce_after_shop_loop' ); ?>
				<?php else : ?>
					<?php do_action( 'woocommerce_no_products_found' ); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php do_action( 'woocommerce_after_main_content' ); ?>
		<section class="why">
			<div class="ey">Why Buy From Guns 2 Ammo</div>
			<div class="cards">
				<div class="card">
					<h4>FFL Transfers</h4>
					<p>Bought online? We process federal transfer at a flat rate with compliant paperwork.</p>
					<a href="<?php echo esc_url( home_url( '/transfers/' ) ); ?>">How Transfers Work &rarr;</a>
				</div>
				<div class="card">
					<h4>Local Pickup</h4>
					<p>Skip shipping. Reserve online and pick up at <?php echo esc_html( trim( explode( ',', $g2a_biz['addr1'] ?? '6030 E Main St, Suite 103' )[0] ) . ', ' . ( $g2a_biz['city'] ?? 'Mesa' ) ); ?>.</p>
					<a href="<?php echo esc_url( home_url( '/shop-services/local-pickup/' ) ); ?>">Pickup Details &rarr;</a>
				</div>
				<div class="card">
					<h4>Expert Fitment</h4>
					<p>Instructor guidance before you buy. Real fitment, zero commission pressure.</p>
					<a href="<?php echo esc_url( home_url( '/shop-services/expert-fitment/' ) ); ?>">Book A Fitment &rarr;</a>
				</div>
				<div class="card">
					<h4>All Federal Laws</h4>
					<p>Background checks, age verification, and full Arizona &amp; federal compliance.</p>
					<a href="<?php echo esc_url( home_url( '/shop-services/compliance/' ) ); ?>">Compliance Explained &rarr;</a>
				</div>
			</div>
		</section>
	</div>
</section>
<?php get_footer( 'shop' );
