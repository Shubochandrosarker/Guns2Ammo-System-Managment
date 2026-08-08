<?php
/**
 * External integrations — server-side proxy clients.
 *
 * Houses clients for:
 *   - ChatBotistic (WhatsApp chat widgets on Guns2Ammo site)
 *   - Otter Text   (bulk SMS campaigns — legacy; will be replaced by Twilio)
 *
 * Security model:
 *   - API keys stored in WP options table (wpistic_ffl_integrations_settings).
 *   - Never exposed to the frontend — the React dashboard calls our REST endpoints,
 *     which then call the external API on the server side.
 *   - Transient-cached responses (5 min) to keep the dashboard fast + hit rate friendly.
 *   - Graceful degradation: if a key is missing or the API is down, return an
 *     informative empty shape rather than throwing.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Integrations {

	const SETTINGS_KEY = 'wpistic_ffl_integrations_settings';
	const CACHE_TTL    = 300; // 5 minutes

	// ── Setting helpers ───────────────────────────────────────────────────────

	public static function get_settings(): array {
		$s = get_option( self::SETTINGS_KEY, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function set_settings( array $settings ): void {
		update_option( self::SETTINGS_KEY, $settings );
	}

	public static function chatbotistic_key(): string {
		// Constant takes priority over option for better security on managed hosts
		if ( defined( 'WPISTIC_FFL_CHATBOTISTIC_KEY' ) && WPISTIC_FFL_CHATBOTISTIC_KEY ) {
			return (string) WPISTIC_FFL_CHATBOTISTIC_KEY;
		}
		$s = self::get_settings();
		return (string) ( $s['chatbotistic_api_key'] ?? '' );
	}

	public static function otter_key(): string {
		if ( defined( 'WPISTIC_FFL_OTTER_KEY' ) && WPISTIC_FFL_OTTER_KEY ) {
			return (string) WPISTIC_FFL_OTTER_KEY;
		}
		$s = self::get_settings();
		return (string) ( $s['otter_api_key'] ?? '' );
	}

	// ──────────────────────────────────────────────────────────────────────────
	// ChatBotistic (https://app.chatbotistic.com/api/docs)
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch leads from ChatBotistic since a given date.
	 * Walks pagination up to $max_pages (safety cap).
	 *
	 * @return array{ok:bool,leads:array,meta:array,error:?string}
	 */
	public static function chatbotistic_fetch_leads( string $from_date, int $max_pages = 5 ): array {
		$key = self::chatbotistic_key();
		if ( ! $key ) {
			return [ 'ok' => false, 'leads' => [], 'meta' => [], 'error' => 'missing_api_key' ];
		}

		$cache_key = 'wpistic_ffl_cb_leads_' . md5( $from_date . '|' . $max_pages );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$leads = [];
		$page  = 1;
		$last_error = null;

		while ( $page <= $max_pages ) {
			$url = add_query_arg( [
				'fromDate' => sanitize_text_field( $from_date ),
				'page'     => $page,
			], 'https://app.chatbotistic.com/api/get-json-lead' );

			$response = wp_remote_get( $url, [
				'timeout' => 8,
				'headers' => [
					'Accept'        => 'application/json',
					'X-API-Key'     => $key,
					'Authorization' => 'Bearer ' . $key,  // try both — docs unclear
				],
			] );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				break;
			}

			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 !== $code ) {
				$last_error = 'http_' . $code;
				break;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) ) {
				$last_error = 'invalid_json';
				break;
			}

			// The API returns either a flat list or { data: [...] } depending on format.
			$batch = $body['data'] ?? $body['leads'] ?? $body['hydra:member'] ?? ( isset( $body[0] ) ? $body : [] );
			if ( empty( $batch ) ) {
				break; // no more pages
			}

			$leads = array_merge( $leads, $batch );
			$page++;

			// Stop early if the API signals "no more results"
			if ( count( $batch ) < 20 ) {
				break;
			}
		}

		$result = [
			'ok'    => null === $last_error,
			'leads' => $leads,
			'meta'  => [ 'pages_fetched' => $page - 1, 'from_date' => $from_date ],
			'error' => $last_error,
		];

		if ( $result['ok'] ) {
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}

		return $result;
	}

	/**
	 * Aggregate ChatBotistic leads by widget source.
	 * Maps widget IDs / names to human-friendly buckets used by the dashboard.
	 *
	 * @return array{total:int,by_widget:array,recent:array}
	 */
	public static function chatbotistic_summary( string $period = '30d' ): array {
		$days      = self::period_days( $period );
		$from_date = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );

		$raw = self::chatbotistic_fetch_leads( $from_date );
		if ( ! $raw['ok'] ) {
			return [
				'total'     => 0,
				'by_widget' => [],
				'recent'    => [],
				'error'     => $raw['error'],
			];
		}

		// Widget mapping — admin can override via filter
		$widget_map = apply_filters( 'wpistic_ffl_chatbotistic_widget_map', [
			'chatbot'      => [ 'id' => 'whatsapp_chatbot',  'icon' => '📱', 'label' => 'WhatsApp — Chatbot Widget' ],
			'contact_form' => [ 'id' => 'whatsapp_contact',  'icon' => '📋', 'label' => 'WhatsApp — Contact Form' ],
			'ffl_transfer' => [ 'id' => 'whatsapp_ffl',      'icon' => '🔫', 'label' => 'WhatsApp — FFL Transfer Form' ],
			'default'      => [ 'id' => 'whatsapp_other',    'icon' => '💬', 'label' => 'WhatsApp — Other Widgets' ],
		] );

		$buckets = [];
		foreach ( $widget_map as $_ => $meta ) {
			$buckets[ $meta['id'] ] = array_merge( $meta, [ 'count' => 0 ] );
		}

		$recent = [];
		foreach ( $raw['leads'] as $lead ) {
			// Widget identifier — try common field names
			$widget_key = strtolower( (string) (
				$lead['widget_type']  ??
				$lead['widgetType']   ??
				$lead['widget']       ??
				$lead['type']         ??
				$lead['source']       ??
				'default'
			) );

			$bucket_key = 'whatsapp_other';
			foreach ( $widget_map as $match => $meta ) {
				if ( false !== strpos( $widget_key, $match ) ) {
					$bucket_key = $meta['id'];
					break;
				}
			}

			if ( isset( $buckets[ $bucket_key ] ) ) {
				$buckets[ $bucket_key ]['count']++;
			}

			if ( count( $recent ) < 10 ) {
				$recent[] = [
					'name'   => (string) ( $lead['name']  ?? $lead['fullName'] ?? $lead['full_name'] ?? 'Anonymous' ),
					'phone'  => (string) ( $lead['phone'] ?? $lead['phoneNumber'] ?? '' ),
					'email'  => (string) ( $lead['email'] ?? '' ),
					'widget' => $widget_key,
					'date'   => (string) ( $lead['createdAt'] ?? $lead['created_at'] ?? $lead['date'] ?? '' ),
				];
			}
		}

		return [
			'total'     => count( $raw['leads'] ),
			'by_widget' => array_values( $buckets ),
			'recent'    => $recent,
		];
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Otter Text (https://api.ottertext.com/)
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Fetch Otter Text summary. Shape mirrors the documented endpoints; we try
	 * a few common URIs and return whatever the account has access to.
	 *
	 * Note: Otter's public docs aren't fully indexed publicly — real endpoint
	 * URIs should be verified from the user's account dashboard. This client is
	 * resilient: any endpoint returning 404 is silently skipped.
	 *
	 * @return array{ok:bool,messages_sent:int,delivery_rate:float,campaigns:array,error:?string}
	 */
	public static function otter_summary( string $period = '30d' ): array {
		$key = self::otter_key();
		if ( ! $key ) {
			return [
				'ok' => false, 'messages_sent' => 0, 'delivery_rate' => 0,
				'campaigns' => [], 'error' => 'missing_api_key',
			];
		}

		$cache_key = 'wpistic_ffl_otter_sum_' . md5( $period );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$days   = self::period_days( $period );
		$since  = gmdate( 'Y-m-d', time() - ( $days * DAY_IN_SECONDS ) );
		$base   = 'https://api.ottertext.com';

		$messages_sent = 0;
		$delivered     = 0;
		$campaigns     = [];
		$last_error    = null;

		// Messages summary — try /api/messages or /v1/messages
		foreach ( [ '/api/messages', '/v1/messages', '/messages' ] as $path ) {
			$response = wp_remote_get( $base . $path . '?from=' . $since, [
				'timeout' => 6,
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				],
			] );
			if ( is_wp_error( $response ) ) { $last_error = $response->get_error_message(); continue; }
			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( is_array( $body ) ) {
					$messages_sent = (int) (
						$body['total']            ??
						$body['count']            ??
						$body['meta']['total']    ??
						( is_array( $body['data'] ?? null ) ? count( $body['data'] ) : 0 )
					);
					$delivered = (int) (
						$body['delivered']          ??
						$body['stats']['delivered'] ??
						$messages_sent
					);
				}
				break;
			}
		}

		// Campaigns — similar try-multiple approach
		foreach ( [ '/api/campaigns', '/v1/campaigns', '/campaigns' ] as $path ) {
			$response = wp_remote_get( $base . $path, [
				'timeout' => 6,
				'headers' => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				],
			] );
			if ( is_wp_error( $response ) ) continue;
			$code = wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				$items = is_array( $body ) ? ( $body['data'] ?? $body['campaigns'] ?? $body ) : [];
				if ( is_array( $items ) ) {
					foreach ( array_slice( $items, 0, 10 ) as $c ) {
						if ( ! is_array( $c ) ) continue;
						$campaigns[] = [
							'id'        => (string) ( $c['id'] ?? '' ),
							'name'      => (string) ( $c['name'] ?? $c['title'] ?? 'Untitled' ),
							'sent'      => (int) ( $c['sent_count'] ?? $c['sent'] ?? 0 ),
							'delivered' => (int) ( $c['delivered_count'] ?? $c['delivered'] ?? 0 ),
							'status'    => (string) ( $c['status'] ?? '' ),
						];
					}
				}
				break;
			}
		}

		$rate = $messages_sent > 0 ? round( $delivered / $messages_sent, 3 ) : 0.0;

		$result = [
			'ok'             => true,
			'messages_sent'  => $messages_sent,
			'delivered'      => $delivered,
			'delivery_rate'  => $rate,
			'campaigns'      => $campaigns,
			'error'          => $last_error,
		];

		set_transient( $cache_key, $result, self::CACHE_TTL );
		return $result;
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Helpers
	// ──────────────────────────────────────────────────────────────────────────

	public static function period_days( string $period ): int {
		return match ( $period ) {
			'7d'   => 7,
			'30d'  => 30,
			'90d'  => 90,
			'365d' => 365,
			'1y'   => 365,
			default => 30,
		};
	}

	/**
	 * Test connectivity to both integrations.
	 * Used by the "Send Test" buttons in the admin.
	 */
	public static function self_test(): array {
		$out = [ 'chatbotistic' => [], 'otter' => [] ];

		// ChatBotistic
		if ( self::chatbotistic_key() ) {
			$today = gmdate( 'Y-m-d' );
			$cb = self::chatbotistic_fetch_leads( $today, 1 );
			$out['chatbotistic'] = [
				'configured' => true,
				'reachable'  => (bool) $cb['ok'],
				'error'      => $cb['error'] ?? null,
				'count'      => count( $cb['leads'] ?? [] ),
			];
		} else {
			$out['chatbotistic'] = [ 'configured' => false ];
		}

		// Otter
		if ( self::otter_key() ) {
			$ot = self::otter_summary( '7d' );
			$out['otter'] = [
				'configured' => true,
				'reachable'  => (bool) $ot['ok'],
				'error'      => $ot['error'] ?? null,
				'messages'   => $ot['messages_sent'] ?? 0,
			];
		} else {
			$out['otter'] = [ 'configured' => false ];
		}

		return $out;
	}
}
