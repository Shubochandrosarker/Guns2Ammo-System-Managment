<?php

namespace G2A\POS\API;

use G2A\POS\Support\SafeHttp;
use G2A\POS\Support\SecretStore;
use G2A\POS\Webhooks\WebhookBus;
use WP_REST_Request;

final class WebhookController {

	public static function index( WP_REST_Request $req ) {
		$subscribers = array_map(
			static function ( array $subscriber ): array {
				$subscriber['secret']            = '';
				$subscriber['secret_configured'] = ! empty( $subscriber['secret_ciphertext'] ?? $subscriber['secret'] ?? '' );
				unset( $subscriber['secret_ciphertext'] );
				return $subscriber;
			},
			(array) get_option( 'g2a_pos_webhooks', array() )
		);
		return array(
			'events'      => WebhookBus::EVENTS,
			'subscribers' => $subscribers,
		);
	}

	public static function save( WP_REST_Request $req ) {
		$payload  = $req->get_json_params() ?: array();
		$subs     = (array) ( $payload['subscribers'] ?? array() );
		$clean    = array();
		$existing = array();
		foreach ( (array) get_option( 'g2a_pos_webhooks', array() ) as $saved ) {
			if ( ! empty( $saved['url'] ) ) {
				$existing[ (string) $saved['url'] ] = $saved;
			}
		}
		foreach ( $subs as $sub ) {
			if ( empty( $sub['url'] ) ) {
				continue;
			}
			$url = esc_url_raw( (string) $sub['url'] );
			if ( ! SafeHttp::validateUrl( $url ) ) {
				return new \WP_Error( 'unsafe_url', 'Webhook URLs must use HTTPS and resolve to public addresses.', array( 'status' => 400 ) );
			}
			$secret  = sanitize_text_field( (string) ( $sub['secret'] ?? '' ) );
			$clean[] = array(
				'url'               => $url,
				'secret_ciphertext' => $secret !== ''
					? SecretStore::seal( $secret )
					: (string) ( $existing[ $url ]['secret_ciphertext'] ?? $existing[ $url ]['secret'] ?? '' ),
				'events'            => array_values( array_intersect( (array) ( $sub['events'] ?? array() ), WebhookBus::EVENTS ) ),
				'enabled'           => ! empty( $sub['enabled'] ),
			);
		}
		update_option( 'g2a_pos_webhooks', $clean );
		return self::index( $req );
	}
}
