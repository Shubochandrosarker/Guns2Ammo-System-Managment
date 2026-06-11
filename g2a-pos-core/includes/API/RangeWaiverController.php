<?php

namespace G2A\POS\API;

use G2A\POS\Database\RangeWaiverRepository;
use G2A\POS\Range\OtterWaiverCsvImporter;
use G2A\POS\Wholesalers\Media\VendorImageMirror;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RangeWaiverController {

	public static function index( WP_REST_Request $req ): WP_REST_Response {
		$repo = new RangeWaiverRepository();
		$rows = array_map(
			array( self::class, 'present' ),
			$repo->search(
				array(
					'q'      => $req->get_param( 'q' ),
					'email'  => $req->get_param( 'email' ),
					'phone'  => $req->get_param( 'phone' ),
					'from'   => $req->get_param( 'from' ),
					'to'     => $req->get_param( 'to' ),
					'limit'  => (int) ( $req->get_param( 'limit' ) ?: 50 ),
					'offset' => (int) ( $req->get_param( 'offset' ) ?: 0 ),
				)
			)
		);
		return new WP_REST_Response(
			array(
				'ok'      => true,
				'waivers' => $rows,
			)
		);
	}

	public static function get( WP_REST_Request $req ) {
		$repo = new RangeWaiverRepository();
		$row  = $repo->find( (int) $req->get_param( 'id' ) );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'Waiver not found', array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'waiver' => self::present( $row ),
			)
		);
	}

	public static function find_for_customer( WP_REST_Request $req ): WP_REST_Response {
		$repo = new RangeWaiverRepository();
		$w    = $repo->findLatestForCustomer(
			$req->get_param( 'email' ) ?: null,
			$req->get_param( 'phone' ) ?: null
		);
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'waiver' => $w ? self::present( $w ) : null,
			)
		);
	}

	public static function import( WP_REST_Request $req ) {
		$files = $req->get_file_params();
		$path  = null;
		if ( ! empty( $files['csv']['tmp_name'] ) && is_uploaded_file( $files['csv']['tmp_name'] ) ) {
			$path = $files['csv']['tmp_name'];
		} else {
			$body = $req->get_json_params() ?: array();
			if ( ! empty( $body['csv_b64'] ) ) {
				$tmp = wp_tempnam( 'g2a_waiver_' );
				if ( $tmp ) {
					file_put_contents( $tmp, base64_decode( (string) $body['csv_b64'], true ) ?: '' );
					$path = $tmp;
				}
			}
		}
		if ( ! $path ) {
			return new WP_Error( 'no_csv', 'Provide a CSV file or csv_b64 body.', array( 'status' => 400 ) );
		}
		$importer = new OtterWaiverCsvImporter();
		$result   = $importer->import( $path );
		return new WP_REST_Response( $result );
	}

	public static function checkin( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$repo = new RangeWaiverRepository();
		if ( ! $repo->find( $id ) ) {
			return new WP_Error( 'not_found', 'Waiver not found', array( 'status' => 404 ) );
		}
		$body      = $req->get_json_params() ?: array();
		$checkinId = $repo->recordCheckin(
			$id,
			isset( $body['register_session_id'] ) ? (int) $body['register_session_id'] : null,
			isset( $body['notes'] ) ? sanitize_text_field( (string) $body['notes'] ) : null
		);
		return new WP_REST_Response(
			array(
				'ok'         => true,
				'checkin_id' => $checkinId,
				'waiver'     => self::present( $repo->find( $id ) ?: array() ),
			)
		);
	}

	public static function mirror_pdf( WP_REST_Request $req ) {
		$id   = (int) $req->get_param( 'id' );
		$repo = new RangeWaiverRepository();
		$w    = $repo->find( $id );
		if ( ! $w ) {
			return new WP_Error( 'not_found', 'Waiver not found', array( 'status' => 404 ) );
		}
		// OtterWaiver populates the PDF link in EITHER Certificate URL OR Document URL.
		$url = (string) ( $w['certificate_url'] ?: ( $w['document_url'] ?? '' ) );
		if ( $url === '' ) {
			return new WP_Error( 'no_pdf', 'This waiver has no certificate or document URL', array( 'status' => 422 ) );
		}
		$result = VendorImageMirror::mirror(
			$url,
			array(
				'vendor_sku'            => 'waiver:' . $id,
				'allowed_mime_prefixes' => array( 'image/', 'application/pdf' ),
			)
		);
		if ( ! empty( $result['ok'] ) && ! empty( $result['attachment_id'] ) ) {
			$repo->attachCertificate( $id, (int) $result['attachment_id'] );
		}
		return new WP_REST_Response( $result, ! empty( $result['ok'] ) ? 200 : 422 );
	}

	public static function present( array $row ): array {
		if ( current_user_can( 'g2a_pos_view_waiver_sensitive_data' ) ) {
			return $row;
		}
		foreach ( array(
			'dob',
			'street',
			'questions',
			'check_ins',
			'document_url',
			'certificate_url',
			'emergency_contact_name',
			'emergency_contact_phone',
			'minor_name',
			'minor_age',
			'second_signer_email',
		) as $field ) {
			unset( $row[ $field ] );
		}
		if ( ! empty( $row['email'] ) ) {
			[$local, $domain] = array_pad( explode( '@', (string) $row['email'], 2 ), 2, '' );
			$row['email']     = $domain === '' ? '' : substr( $local, 0, 1 ) . '***@' . $domain;
		}
		if ( ! empty( $row['phone'] ) ) {
			$digits       = preg_replace( '/\D+/', '', (string) $row['phone'] ) ?: '';
			$row['phone'] = $digits === '' ? '' : '***-***-' . substr( $digits, -4 );
		}
		return $row;
	}
}
