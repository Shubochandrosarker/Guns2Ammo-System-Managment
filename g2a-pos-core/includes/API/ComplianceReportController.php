<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\ReportingService;
use G2A\POS\Database\AtfReportRepository;
use G2A\POS\Database\AuditLogRepository;
use WP_REST_Request;

final class ComplianceReportController {

	public static function index( WP_REST_Request $request ) {
		$repo = new AtfReportRepository();
		return array(
			'reports' => $repo->paginate(
				array(
					'status'      => sanitize_key( (string) ( $request->get_param( 'status' ) ?? '' ) ),
					'report_type' => sanitize_text_field( (string) ( $request->get_param( 'report_type' ) ?? '' ) ),
					'per_page'    => (int) ( $request->get_param( 'per_page' ) ?: 50 ),
					'page'        => (int) ( $request->get_param( 'page' ) ?: 1 ),
				)
			),
		);
	}

	public static function get( WP_REST_Request $request ) {
		$repo = new AtfReportRepository();
		$r    = $repo->find( (int) $request->get_param( 'id' ) );
		if ( ! $r ) {
			return new \WP_Error( 'not_found', 'Report not found.', array( 'status' => 404 ) ); }
		return array( 'report' => $r );
	}

	public static function mark_submitted( WP_REST_Request $request ) {
		$repo    = new AtfReportRepository();
		$id      = (int) $request->get_param( 'id' );
		$payload = $request->get_json_params() ?: array();
		$ok      = $repo->update(
			$id,
			array(
				'status'         => 'submitted',
				'submitted_at'   => current_time( 'mysql' ),
				'submission_ref' => sanitize_text_field( (string) ( $payload['submission_ref'] ?? '' ) ),
			)
		);
		if ( ! $ok ) {
			return new \WP_Error( 'not_updated', 'Report not updated.', array( 'status' => 500 ) ); }
		return array( 'report' => $repo->find( $id ) );
	}

	public static function create_theft_loss( WP_REST_Request $request ) {
		$payload = $request->get_json_params() ?: array();
		$result  = ReportingService::create_theft_loss_report( $payload );
		if ( isset( $result['error'] ) ) {
			return new \WP_Error( 'theft_loss', $result['error'], array( 'status' => 422 ) ); }
		return $result;
	}

	public static function audit_verify( WP_REST_Request $request ) {
		$audit = new AuditLogRepository();
		$limit = max( 1, min( 50000, (int) ( $request->get_param( 'limit' ) ?: 5000 ) ) );
		return $audit->verify_chain( $limit );
	}
}
