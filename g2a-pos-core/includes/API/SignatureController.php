<?php

namespace G2A\POS\API;

use G2A\POS\Database\SignatureRepository;
use WP_REST_Request;

final class SignatureController {

	public static function capture( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$img  = (string) ( $body['image_data'] ?? '' );
		if ( ! $img || strlen( $img ) < 64 ) {
			return new \WP_Error( 'invalid_input', 'image_data (base64 PNG) required', array( 'status' => 400 ) );
		}
		$id = ( new SignatureRepository() )->record(
			array(
				'subject_type' => $body['subject_type'] ?? '',
				'subject_id'   => (int) ( $body['subject_id'] ?? 0 ),
				'role'         => $body['role'] ?? 'signer',
				'signer_name'  => $body['signer_name'] ?? '',
				'image_data'   => $img,
			)
		);
		return array( 'id' => $id );
	}

	public static function for_subject( WP_REST_Request $request ) {
		$subject    = sanitize_key( (string) $request->get_param( 'subject_type' ) );
		$subject_id = (int) $request->get_param( 'subject_id' );
		return array( 'items' => ( new SignatureRepository() )->for_subject( $subject, $subject_id ) );
	}
}
