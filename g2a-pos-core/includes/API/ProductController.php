<?php

namespace G2A\POS\API;

use G2A\POS\Database\ProductRepository;
use WP_REST_Request;

final class ProductController {

	public static function search( WP_REST_Request $request ) {
		$term  = sanitize_text_field( (string) $request->get_param( 'q' ) );
		$limit = (int) ( $request->get_param( 'limit' ) ?: 20 );

		return array( 'items' => ( new ProductRepository() )->search( $term, $limit ) );
	}
}
