<?php

namespace G2A\POS\API;

use G2A\POS\Database\KpiSnapshotRepository;
use G2A\POS\Reporting\KpiService;
use WP_REST_Request;

final class KpiController {

	public static function snapshot( WP_REST_Request $request ) {
		return ( new KpiService() )->snapshot();
	}

	public static function vendor_scorecard( WP_REST_Request $request ) {
		$days = (int) ( $request->get_param( 'days' ) ?: 90 );
		return array(
			'days'    => $days,
			'vendors' => ( new KpiService() )->vendor_scorecard( $days ),
		);
	}

	public static function trend( WP_REST_Request $request ) {
		$from   = sanitize_text_field( (string) ( $request->get_param( 'from' ) ?: date( 'Y-m-d', strtotime( '-30 days' ) ) ) );
		$to     = sanitize_text_field( (string) ( $request->get_param( 'to' ) ?: date( 'Y-m-d' ) ) );
		$metric = $request->get_param( 'metric' ) ? sanitize_text_field( (string) $request->get_param( 'metric' ) ) : null;
		return array(
			'from' => $from,
			'to'   => $to,
			'rows' => ( new KpiSnapshotRepository() )->range( $from, $to, $metric ),
		);
	}
}
