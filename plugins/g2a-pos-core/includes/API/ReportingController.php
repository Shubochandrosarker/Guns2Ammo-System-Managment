<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\BoundBookExporter;
use G2A\POS\Reporting\ReportingService;
use WP_REST_Request;
use WP_REST_Response;

final class ReportingController {

	public static function register_report( WP_REST_Request $req ) {
		$svc = new ReportingService();
		$id  = (int) $req->get_param( 'id' );
		$r   = $svc->register_x_report( $id );
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'report', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}

	public static function sales_by_employee( WP_REST_Request $req ) {
		$svc = new ReportingService();
		return array(
			'rows' => $svc->sales_by_employee(
				sanitize_text_field( (string) ( $req->get_param( 'from' ) ?? date( 'Y-m-d 00:00:00' ) ) ),
				sanitize_text_field( (string) ( $req->get_param( 'to' ) ?? date( 'Y-m-d 23:59:59' ) ) )
			),
		);
	}

	public static function attach_rate( WP_REST_Request $req ) {
		$svc = new ReportingService();
		return $svc->attach_rate(
			sanitize_text_field( (string) ( $req->get_param( 'from' ) ?? date( 'Y-m-01 00:00:00' ) ) ),
			sanitize_text_field( (string) ( $req->get_param( 'to' ) ?? date( 'Y-m-d 23:59:59' ) ) )
		);
	}

	public static function tax_summary( WP_REST_Request $req ) {
		$svc = new ReportingService();
		return $svc->tax_summary(
			sanitize_text_field( (string) ( $req->get_param( 'from' ) ?? date( 'Y-m-01 00:00:00' ) ) ),
			sanitize_text_field( (string) ( $req->get_param( 'to' ) ?? date( 'Y-m-d 23:59:59' ) ) )
		);
	}

	public static function bound_book_csv( WP_REST_Request $req ) {
		$csv  = BoundBookExporter::to_csv(
			array(
				'serial'     => sanitize_text_field( (string) ( $req->get_param( 'serial' ) ?? '' ) ),
				'entry_type' => sanitize_key( (string) ( $req->get_param( 'entry_type' ) ?? '' ) ),
				'date_from'  => sanitize_text_field( (string) ( $req->get_param( 'date_from' ) ?? '' ) ),
				'date_to'    => sanitize_text_field( (string) ( $req->get_param( 'date_to' ) ?? '' ) ),
			)
		);
		$resp = new WP_REST_Response( $csv );
		$resp->set_headers(
			array(
				'Content-Type'        => 'text/csv; charset=utf-8',
				'Content-Disposition' => 'attachment; filename="g2a-bound-book-' . date( 'Y-m-d' ) . '.csv"',
			)
		);
		return $resp;
	}
}
