<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\FflTransfer;
use G2A\POS\Database\FflPartnerRepository;
use G2A\POS\Database\FflTransferRepository;
use WP_REST_Request;

final class FflTransferController {

	public static function inbound( WP_REST_Request $req ) {
		$r = FflTransfer::inbound_consignment( $req->get_json_params() ?: array() );
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'ffl_inbound', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}
	public static function outbound( WP_REST_Request $req ) {
		$r = FflTransfer::outbound_transfer( $req->get_json_params() ?: array() );
		if ( isset( $r['error'] ) ) {
			return new \WP_Error( 'ffl_outbound', $r['error'], array( 'status' => 422 ) );
		}
		return $r;
	}
	public static function index( WP_REST_Request $req ) {
		$repo = new FflTransferRepository();
		return array(
			'transfers' => $repo->paginate(
				array(
					'direction' => sanitize_key( (string) ( $req->get_param( 'direction' ) ?? '' ) ),
					'status'    => sanitize_key( (string) ( $req->get_param( 'status' ) ?? '' ) ),
					'serial'    => sanitize_text_field( (string) ( $req->get_param( 'serial' ) ?? '' ) ),
					'per_page'  => (int) ( $req->get_param( 'per_page' ) ?: 50 ),
					'page'      => (int) ( $req->get_param( 'page' ) ?: 1 ),
				)
			),
		);
	}
	public static function partners( WP_REST_Request $req ) {
		$repo = new FflPartnerRepository();
		$q    = sanitize_text_field( (string) ( $req->get_param( 'q' ) ?? '' ) );
		return array( 'partners' => $q !== '' ? $repo->search( $q ) : array() );
	}
	public static function upsert_partner( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		if ( empty( $payload['ffl_number'] ) || empty( $payload['business_name'] ) ) {
			return new \WP_Error( 'missing', 'ffl_number and business_name required.', array( 'status' => 400 ) );
		}
		$repo = new FflPartnerRepository();
		$id   = $repo->upsert( $payload );
		return array(
			'id'      => $id,
			'partner' => $repo->find_by_ffl( (string) $payload['ffl_number'] ),
		);
	}
}
