<?php

namespace G2A\POS\API;

use G2A\POS\Database\TransfereeRepository;
use G2A\POS\Identity\AamvaParser;
use WP_REST_Request;

final class TransfereeController {

	public static function search( WP_REST_Request $req ) {
		$repo = new TransfereeRepository();
		$q    = sanitize_text_field( (string) ( $req->get_param( 'q' ) ?? '' ) );
		return array( 'results' => $q !== '' ? $repo->search_display( $q ) : array() );
	}

	public static function get( WP_REST_Request $req ) {
		$repo = new TransfereeRepository();
		$r    = $repo->find( (int) $req->get_param( 'id' ) );
		if ( ! $r ) {
			return new \WP_Error( 'not_found', 'Transferee not found.', array( 'status' => 404 ) );
		}
		return array( 'transferee' => $r );
	}

	public static function upsert( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		foreach ( array( 'last', 'first', 'dob' ) as $req_f ) {
			if ( empty( $payload[ $req_f ] ) ) {
				return new \WP_Error( 'missing', "{$req_f} required.", array( 'status' => 400 ) );
			}
		}
		$repo = new TransfereeRepository();
		$id   = $repo->upsert( $payload );
		return array(
			'id'         => $id,
			'transferee' => $repo->find( $id ),
		);
	}

	public static function scan_aamva( WP_REST_Request $req ) {
		$payload = $req->get_json_params() ?: array();
		$raw     = (string) ( $payload['payload'] ?? '' );
		$parsed  = AamvaParser::parse( $raw );
		if ( ! $parsed ) {
			return new \WP_Error( 'parse_failed', 'Not a recognized AAMVA payload.', array( 'status' => 422 ) );
		}
		return array( 'parsed' => $parsed );
	}
}
