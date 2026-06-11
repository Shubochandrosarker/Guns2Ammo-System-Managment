<?php

namespace G2A\POS\API;

use G2A\POS\Compliance\ATF\Form4473Pdf;
use WP_REST_Request;
use WP_REST_Response;

final class Form4473PdfController {

	public static function render( WP_REST_Request $req ) {
		$id = (int) $req->get_param( 'id' );
		try {
			$pdf = Form4473Pdf::render( $id );
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'pdf', $e->getMessage(), array( 'status' => 422 ) );
		}
		$resp = new WP_REST_Response( $pdf );
		$resp->set_headers(
			array(
				'Content-Type'        => 'application/pdf',
				'Content-Disposition' => 'inline; filename="4473-' . $id . '.pdf"',
			)
		);
		return $resp;
	}
}
