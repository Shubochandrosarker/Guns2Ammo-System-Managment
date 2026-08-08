<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\BoundBook;
use G2A\POS\Compliance\ATF\BoundBookExporter;
use G2A\POS\Database\BoundBookRepository;
use WP_REST_Request;

final class BoundBookController {

	public static function acquire( WP_REST_Request $request ) {
		$payload = $request->get_json_params() ?: array();
		$result  = BoundBook::record_acquisition( $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'bound_book_acquire', $result['error'], array( 'status' => 422 ) );
		}
		return $result;
	}

	public static function dispose( WP_REST_Request $request ) {
		$payload = $request->get_json_params() ?: array();
		$result  = BoundBook::record_disposition( $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'bound_book_dispose', $result['error'], array( 'status' => 422 ) );
		}
		return $result;
	}

	public static function index( WP_REST_Request $request ) {
		$repo = new BoundBookRepository();
		$rows = $repo->paginate(
			array(
				'serial'       => sanitize_text_field( (string) ( $request->get_param( 'serial' ) ?? '' ) ),
				'entry_type'   => sanitize_key( (string) ( $request->get_param( 'entry_type' ) ?? '' ) ),
				'firearm_type' => sanitize_key( (string) ( $request->get_param( 'firearm_type' ) ?? '' ) ),
				'date_from'    => sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?? '' ) ),
				'date_to'      => sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?? '' ) ),
				'per_page'     => (int) ( $request->get_param( 'per_page' ) ?: 50 ),
				'page'         => (int) ( $request->get_param( 'page' ) ?: 1 ),
			)
		);
		return array( 'entries' => $rows );
	}

	public static function verify( WP_REST_Request $request ) {
		$repo  = new BoundBookRepository();
		$limit = max( 1, min( 50000, (int) ( $request->get_param( 'limit' ) ?: 5000 ) ) );
		return $repo->verify_chain( $limit );
	}

	public static function export_eforms( WP_REST_Request $request ) {
		$format = sanitize_key( (string) ( $request->get_param( 'format' ) ?: 'csv' ) );
		$args   = array(
			'date_from' => sanitize_text_field( (string) ( $request->get_param( 'date_from' ) ?? '' ) ),
			'date_to'   => sanitize_text_field( (string) ( $request->get_param( 'date_to' ) ?? '' ) ),
		);
		if ( $format === 'xml' ) {
			$xml      = BoundBookExporter::to_eforms_xml( $args );
			$response = new \WP_REST_Response( $xml );
			$response->header( 'Content-Type', 'application/xml; charset=UTF-8' );
			$response->header( 'Content-Disposition', 'attachment; filename="eforms-bound-book-' . date( 'Ymd' ) . '.xml"' );
			return $response;
		}
		$csv      = BoundBookExporter::to_eforms_csv( $args );
		$response = new \WP_REST_Response( $csv );
		$response->header( 'Content-Type', 'text/csv; charset=UTF-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="eforms-bound-book-' . date( 'Ymd' ) . '.csv"' );
		return $response;
	}
}
