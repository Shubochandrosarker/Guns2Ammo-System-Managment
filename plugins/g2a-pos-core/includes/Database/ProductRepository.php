<?php

namespace G2A\POS\Database;

use WP_Query;

final class ProductRepository {

	public function search( string $term, int $limit = 20 ): array {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'posts_per_page' => max( 1, min( 50, $limit ) ),
				'post_status'    => 'publish',
				's'              => $term,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$items[] = array(
				'id'             => (int) $product->get_id(),
				'name'           => $product->get_name(),
				'sku'            => (string) $product->get_sku(),
				'price'          => (float) $product->get_price(),
				'stock_quantity' => (float) ( $product->get_stock_quantity() ?? 0 ),
				'is_firearm'     => has_term( 'firearm', 'product_cat', $product->get_id() ),
			);
		}

		return $items;
	}
}
