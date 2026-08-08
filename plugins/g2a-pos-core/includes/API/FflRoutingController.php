<?php

namespace G2A\POS\API;

use G2A\POS\Shipping\FflRouter;
use WP_REST_Request;

final class FflRoutingController {

	public static function suggest( WP_REST_Request $request ) {
		$state = (string) ( $request->get_param( 'state' ) ?: '' );
		$zip   = (string) ( $request->get_param( 'zip' ) ?: '' );
		if ( ! $state ) {
			return new \WP_Error( 'invalid_input', 'state required', array( 'status' => 400 ) );
		}
		return array( 'suggestions' => FflRouter::suggest( $state, $zip ) );
	}
}
