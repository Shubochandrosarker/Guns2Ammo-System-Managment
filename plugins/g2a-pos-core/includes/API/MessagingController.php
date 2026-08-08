<?php

namespace G2A\POS\API;

use G2A\POS\Database\MessagingRepository;
use G2A\POS\Messaging\MessagingService;
use WP_REST_Request;

final class MessagingController {

	public static function index( WP_REST_Request $request ) {
		return array( 'items' => ( new MessagingRepository() )->recent( (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
	}

	public static function templates( WP_REST_Request $request ) {
		return array( 'items' => ( new MessagingRepository() )->templates() );
	}

	public static function upsert_template( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$id   = ( new MessagingRepository() )->upsert_template( $body );
		return array( 'id' => $id );
	}

	public static function queue( WP_REST_Request $request ) {
		$body    = $request->get_json_params() ?: array();
		$channel = sanitize_key( (string) ( $body['channel'] ?? 'email' ) );
		if ( empty( $body['to_address'] ) || empty( $body['body'] ) ) {
			return new \WP_Error( 'invalid_input', 'to_address and body required', array( 'status' => 400 ) );
		}
		if ( ! empty( $body['template_key'] ) ) {
			$id = MessagingService::queue_from_template(
				$body['template_key'],
				$channel,
				$body['to_address'],
				$body['vars'] ?? array(),
				$body
			);
		} elseif ( $channel === 'sms' ) {
			$id = MessagingService::queue_sms( $body['to_address'], (string) $body['body'], $body );
		} else {
			$id = MessagingService::queue_email( $body['to_address'], (string) ( $body['subject'] ?? '' ), (string) $body['body'], $body );
		}
		return array( 'id' => $id );
	}

	public static function flush( WP_REST_Request $request ) {
		$batch = (int) ( $request->get_param( 'limit' ) ?: 25 );
		return MessagingService::flush( $batch );
	}
}
