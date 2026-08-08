<?php

namespace G2A\POS\Webhooks;

use G2A\POS\Database\QueueRepository;
use G2A\POS\Support\SafeHttp;
use G2A\POS\Support\SecretStore;

/**
 * Dispatches POS domain events to outbound HTTP webhooks.
 * Subscribers are stored in option g2a_pos_webhooks: array of
 * { url, secret, events: [...], enabled: bool }.
 * Outbound delivery is queued so a failed endpoint never blocks the till.
 */
final class WebhookBus {

	public const EVENTS = array(
		'order.created',
		'order.refunded',
		'4473.started',
		'4473.transferred',
		'4473.denied',
		'nics.delayed',
		'nics.default_proceed',
		'bound_book.acquired',
		'bound_book.disposed',
		'bound_book.chain_broken',
		'report.created',
		'report.due',
		'register.opened',
		'register.closed',
		'register.variance',
	);

	public static function emit( string $event, array $payload ): int {
		if ( ! in_array( $event, self::EVENTS, true ) ) {
			return 0;
		}
		$subs = self::subscribers( $event );
		if ( ! $subs ) {
			return 0;
		}
		$queue = new QueueRepository();
		$count = 0;
		$body  = array(
			'event'       => $event,
			'occurred_at' => current_time( 'mysql' ),
			'payload'     => $payload,
		);
		foreach ( $subs as $sub ) {
			$queue->enqueue(
				'webhook_delivery',
				array(
					'url'    => (string) $sub['url'],
					'secret' => SecretStore::open( (string) ( $sub['secret_ciphertext'] ?? $sub['secret'] ?? '' ) ),
					'body'   => $body,
				)
			);
			++$count;
		}
		return $count;
	}

	public static function deliver( array $payload ): bool {
		$url    = (string) ( $payload['url'] ?? '' );
		$secret = (string) ( $payload['secret'] ?? '' );
		$body   = wp_json_encode( $payload['body'] ?? array() );
		if ( $url === '' || $body === false ) {
			return false;
		}
		$sig  = hash_hmac( 'sha256', $body, $secret );
		$resp = SafeHttp::post(
			$url,
			array(
				'timeout' => 8,
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-G2A-Signature' => 'sha256=' . $sig,
					'X-G2A-Event'     => (string) ( $payload['body']['event'] ?? '' ),
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $resp ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		return $code >= 200 && $code < 300;
	}

	public static function sign( string $body, string $secret ): string {
		return 'sha256=' . hash_hmac( 'sha256', $body, $secret );
	}

	public static function verify( string $body, string $secret, string $sig_header ): bool {
		return hash_equals( self::sign( $body, $secret ), $sig_header );
	}

	private static function subscribers( string $event ): array {
		$all = (array) get_option( 'g2a_pos_webhooks', array() );
		return array_values(
			array_filter(
				$all,
				static function ( $s ) use ( $event ) {
					return ! empty( $s['enabled'] ) && in_array( $event, (array) ( $s['events'] ?? array() ), true );
				}
			)
		);
	}
}
