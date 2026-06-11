<?php

namespace G2A\POS\API;

use G2A\POS\Domain\TenderService;
use WP_REST_Request;

final class TenderController {

	public static function balance( WP_REST_Request $request ) {
		$id  = (int) $request['id'];
		$res = TenderService::balance( $id );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( $res['error'], $res['error'], array( 'status' => 404 ) );
		}
		return $res;
	}

	public static function add( WP_REST_Request $request ) {
		$id                  = (int) $request['id'];
		$body                = $request->get_json_params() ?: array();
		$register_session_id = isset( $body['register_session_id'] ) ? (int) $body['register_session_id'] : null;
		$res                 = TenderService::add_tender( $id, $body, $register_session_id );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error(
				$res['error'],
				$res['error'],
				array(
					'status' => 422,
					'detail' => $res,
				)
			);
		}
		return $res;
	}

	public static function void( WP_REST_Request $request ) {
		$tender_id = (int) $request['tender_id'];
		$body      = $request->get_json_params() ?: array();
		$res       = TenderService::void_tender( $tender_id, $body['reason'] ?? null );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error(
				$res['error'] ?? 'void_failed',
				'Void failed',
				array(
					'status' => 422,
					'detail' => $res,
				)
			);
		}
		return $res;
	}

	public static function refund( WP_REST_Request $request ) {
		$tender_id = (int) $request['tender_id'];
		$body      = $request->get_json_params() ?: array();
		$amount    = (float) ( $body['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return new \WP_Error( 'invalid_input', 'amount > 0 required', array( 'status' => 400 ) );
		}
		$res = TenderService::refund_tender( $tender_id, $amount );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error(
				$res['error'] ?? 'refund_failed',
				'Refund failed',
				array(
					'status' => 422,
					'detail' => $res,
				)
			);
		}
		return $res;
	}

	public static function methods( WP_REST_Request $request ) {
		return array( 'methods' => TenderService::METHODS );
	}
}
