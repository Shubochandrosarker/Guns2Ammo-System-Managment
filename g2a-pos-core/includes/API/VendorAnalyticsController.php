<?php

namespace G2A\POS\API;

use G2A\POS\Database\VendorPriceHistoryRepository;
use WP_REST_Request;

final class VendorAnalyticsController {

	public static function capture( WP_REST_Request $request ) {
		$wholesaler_id = (int) $request['id'];
		$count         = ( new VendorPriceHistoryRepository() )->capture( $wholesaler_id );
		return array( 'captured' => $count );
	}

	public static function drift( WP_REST_Request $request ) {
		$wholesaler_id = (int) $request['id'];
		$days          = (int) ( $request->get_param( 'days' ) ?: 90 );
		return array(
			'days' => $days,
			'rows' => ( new VendorPriceHistoryRepository() )->drift_report( $wholesaler_id, $days ),
		);
	}
}
