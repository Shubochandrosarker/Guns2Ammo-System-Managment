<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\AceAuditPack;
use G2A\POS\Compliance\ATF\Form4473Xml;
use WP_REST_Request;

final class AceAuditController {

	public static function pack( WP_REST_Request $request ) {
		$from   = sanitize_text_field( (string) ( $request->get_param( 'from' ) ?? '' ) );
		$to     = sanitize_text_field( (string) ( $request->get_param( 'to' ) ?? '' ) );
		$result = AceAuditPack::build( $from ?: null, $to ?: null );
		if ( empty( $result['ok'] ) ) {
			return new \WP_Error( $result['error'] ?? 'pack_failed', 'Audit pack failed', array( 'status' => 500 ) );
		}
		$bytes = file_get_contents( $result['path'] );
		@unlink( $result['path'] );
		$response = new \WP_REST_Response( $bytes );
		$response->header( 'Content-Type', 'application/zip' );
		$response->header(
			'Content-Disposition',
			'attachment; filename="ace-audit-' . ( $from ?: 'all' ) . '_' . ( $to ?: date( 'Ymd' ) ) . '.zip"'
		);
		return $response;
	}

	public static function form_xml( WP_REST_Request $request ) {
		$form_id = (int) $request['id'];
		$xml     = Form4473Xml::for_form( $form_id );
		if ( $xml === null ) {
			return new \WP_Error( 'not_found', '4473 not found', array( 'status' => 404 ) );
		}
		$response = new \WP_REST_Response( $xml );
		$response->header( 'Content-Type', 'application/xml; charset=UTF-8' );
		$response->header(
			'Content-Disposition',
			'attachment; filename="4473-' . $form_id . '.xml"'
		);
		return $response;
	}
}
