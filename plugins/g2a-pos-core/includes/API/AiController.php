<?php

namespace G2A\POS\API;

use G2A\POS\Ai\AgentService;
use G2A\POS\Ai\BrainService;
use G2A\POS\Ai\BusinessKnowledgeCollector;
use G2A\POS\Ai\ToolRegistry;
use G2A\POS\Database\AiAuditRepository;
use G2A\POS\Database\AiBrainRepository;
use G2A\POS\Database\AiConversationRepository;
use G2A\POS\Database\AiPendingActionRepository;
use G2A\POS\Support\SafeHttp;
use G2A\POS\Support\SecretStore;
use WP_REST_Request;

final class AiController {

	public static function settings( WP_REST_Request $request ) {
		return array(
			'gateway' => self::publicGatewayConfig(),
			'tools'   => ToolRegistry::specs(),
		);
	}

	public static function save_settings( WP_REST_Request $request ) {
		$body    = $request->get_json_params() ?: array();
		$current = (array) get_option( 'g2a_pos_ai_gateway', array() );
		$mode    = sanitize_key( (string) ( $body['mode'] ?? ( $current['mode'] ?? 'stub' ) ) );
		if ( ! in_array( $mode, array( 'stub', 'live' ), true ) ) {
			return new \WP_Error( 'invalid_mode', 'mode must be stub or live', array( 'status' => 400 ) );
		}
		if ( array_key_exists( 'provider', $body ) ) {
			$provider = sanitize_key( (string) $body['provider'] );
			if ( ! in_array( $provider, \G2A\POS\Ai\Gateway::PROVIDERS, true ) ) {
				return new \WP_Error( 'invalid_provider', 'provider must be openrouter, openai_compatible or ollama', array( 'status' => 400 ) );
			}
			$current['provider'] = $provider;
		}
		if ( array_key_exists( 'embed_provider', $body ) ) {
			$embed_provider = sanitize_key( (string) $body['embed_provider'] );
			if ( ! in_array( $embed_provider, \G2A\POS\Ai\Gateway::EMBED_PROVIDERS, true ) ) {
				return new \WP_Error( 'invalid_embed_provider', 'embed_provider must be openai, ollama, cloudflare, none or empty', array( 'status' => 400 ) );
			}
			$current['embed_provider'] = $embed_provider;
		}
		if ( array_key_exists( 'brain_backend', $body ) ) {
			$backend = sanitize_key( (string) $body['brain_backend'] );
			if ( ! in_array( $backend, array( 'local', 'cloudflare' ), true ) ) {
				return new \WP_Error( 'invalid_brain_backend', 'brain_backend must be local or cloudflare', array( 'status' => 400 ) );
			}
			$current['brain_backend'] = $backend;
		}
		foreach ( array( 'chat_endpoint', 'embed_endpoint', 'cloudflare_worker_url' ) as $key ) {
			if ( ! array_key_exists( $key, $body ) ) {
				continue;
			}
			$url = esc_url_raw( trim( (string) $body[ $key ] ) );
			if ( $url !== '' && ! SafeHttp::validateUrl( $url, defined( 'G2A_POS_ALLOW_PRIVATE_ENDPOINTS' ) && G2A_POS_ALLOW_PRIVATE_ENDPOINTS === true ) ) {
				return new \WP_Error( 'unsafe_url', $key . ' must use HTTPS and resolve to an allowed address.', array( 'status' => 400 ) );
			}
			$current[ $key ] = $url;
		}
		$current['mode']            = $mode;
		$current['chat_model']      = sanitize_text_field( (string) ( $body['chat_model'] ?? ( $current['chat_model'] ?? '' ) ) );
		$current['embed_model']     = sanitize_text_field( (string) ( $body['embed_model'] ?? ( $current['embed_model'] ?? '' ) ) );
		$current['temperature']     = max( 0.0, min( 2.0, (float) ( $body['temperature'] ?? ( $current['temperature'] ?? 0.2 ) ) ) );
		$current['max_tokens']      = max( 1, min( 32768, (int) ( $body['max_tokens'] ?? ( $current['max_tokens'] ?? 800 ) ) ) );
		$current['request_timeout'] = max( 5, min( 120, (int) ( $body['request_timeout'] ?? ( $current['request_timeout'] ?? 60 ) ) ) );
		foreach ( array( 'api_key', 'embed_api_key', 'cloudflare_worker_token' ) as $secret ) {
			$value = trim( (string) ( $body[ $secret ] ?? '' ) );
			if ( $value !== '' ) {
				$current[ $secret ] = SecretStore::seal( $value );
			} elseif ( ! empty( $current[ $secret ] ) && ! str_starts_with( (string) $current[ $secret ], 'enc2:' ) ) {
				$current[ $secret ] = SecretStore::seal( SecretStore::open( (string) $current[ $secret ] ) );
			}
		}
		update_option( 'g2a_pos_ai_gateway', $current, false );
		return array( 'gateway' => self::publicGatewayConfig() );
	}

	/** POST /ai/gateway/test — 1-shot chat round-trip against the configured provider. */
	public static function gateway_test( WP_REST_Request $request ) {
		return \G2A\POS\Ai\Gateway::test_connection();
	}

	public static function conversations( WP_REST_Request $request ) {
		return array( 'items' => ( new AiConversationRepository() )->list_for_user( get_current_user_id() ) );
	}

	public static function start_conversation( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		$id   = ( new AiConversationRepository() )->create(
			get_current_user_id(),
			array(
				'channel'    => $body['channel'] ?? 'pos',
				'title'      => $body['title'] ?? '',
				'model_name' => $body['model_name'] ?? '',
			)
		);
		( new AiAuditRepository() )->record( 'conversation_started', array( 'id' => $id ), $id );
		return array( 'conversation_id' => $id );
	}

	public static function get_conversation( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$conv = ( new AiConversationRepository() )->find( $id );
		if ( ! $conv ) {
			return new \WP_Error( 'not_found', 'Conversation not found', array( 'status' => 404 ) );
		}
		if ( ! self::canAccessConversation( $conv ) ) {
			return new \WP_Error( 'forbidden', 'Not your conversation', array( 'status' => 403 ) );
		}
		$conv['messages'] = ( new AiConversationRepository() )->messages( $id );
		return $conv;
	}

	public static function send_message( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$body = $request->get_json_params() ?: array();
		$text = trim( (string) ( $body['message'] ?? '' ) );
		if ( $text === '' ) {
			return new \WP_Error( 'invalid_input', 'message required', array( 'status' => 400 ) );
		}

		$conv = ( new AiConversationRepository() )->find( $id );
		if ( ! $conv ) {
			return new \WP_Error( 'not_found', 'Conversation not found', array( 'status' => 404 ) );
		}
		if ( ! self::canAccessConversation( $conv ) ) {
			return new \WP_Error( 'forbidden', 'Not your conversation', array( 'status' => 403 ) );
		}

		return AgentService::respond( $id, get_current_user_id(), $text );
	}

	public static function decide_action( WP_REST_Request $request ) {
		$id       = (int) $request['id'];
		$body     = $request->get_json_params() ?: array();
		$decision = sanitize_key( (string) ( $body['decision'] ?? '' ) );
		if ( ! in_array( $decision, array( 'approve', 'deny' ), true ) ) {
			return new \WP_Error( 'invalid_input', 'decision must be approve or deny', array( 'status' => 400 ) );
		}
		$res = AgentService::confirm_action( $id, $decision, get_current_user_id() );
		if ( empty( $res['ok'] ) ) {
			return new \WP_Error( $res['error'] ?? 'failed', 'Decision failed', array( 'status' => 422 ) );
		}
		return $res;
	}

	public static function pending_actions( WP_REST_Request $request ) {
		return array( 'items' => ( new AiPendingActionRepository() )->pending_for_user( get_current_user_id() ) );
	}

	public static function record_feedback( WP_REST_Request $request ) {
		$body   = $request->get_json_params() ?: array();
		$msg_id = (int) ( $body['message_id'] ?? 0 );
		$rating = (int) ( $body['rating'] ?? 0 );
		if ( ! $msg_id || ! in_array( $rating, array( -1, 0, 1 ), true ) ) {
			return new \WP_Error( 'invalid_input', 'message_id and rating (-1/0/1) required', array( 'status' => 400 ) );
		}
		( new AiConversationRepository() )->record_feedback( $msg_id, $rating, $body['comment'] ?? null, get_current_user_id() );
		return array( 'ok' => true );
	}

	// ---- Brain endpoints ----
	public static function brain_documents( WP_REST_Request $request ) {
		return array( 'items' => ( new AiBrainRepository() )->list_documents() );
	}

	public static function brain_ingest_text( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['label'] ) || empty( $body['body'] ) ) {
			return new \WP_Error( 'invalid_input', 'label and body required', array( 'status' => 400 ) );
		}
		return BrainService::ingest_text( (string) $body['label'], (string) $body['body'], $body );
	}

	public static function brain_ingest_url( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		if ( empty( $body['url'] ) ) {
			return new \WP_Error( 'invalid_input', 'url required', array( 'status' => 400 ) );
		}
		return BrainService::ingest_url( (string) $body['url'], $body );
	}

	public static function brain_delete( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return BrainService::delete_document( $id );
	}

	/** POST /ai/brain/refresh-site — re-ingest the Guns 2 Ammo website knowledge pack. */
	public static function brain_refresh_site( WP_REST_Request $request ) {
		return \G2A\POS\Ai\WebsiteKnowledgeSeeder::refresh();
	}

	/** POST /ai/brain/seed-defaults — (re)ingest the verified Guns2Ammo default knowledge pack. */
	public static function brain_seed_defaults( WP_REST_Request $request ) {
		return \G2A\POS\Ai\WebsiteKnowledgeSeeder::seed_default_pack();
	}

	/** GET /ai/brain/stats — corpus size, embedded %, per-source breakdown, last refresh. */
	/**
	 * Push the current business state into the brain on demand.
	 *
	 * Runs the same collectors the hourly cron does. Slow by nature — it walks
	 * several aggregates — so it is a POST behind manage_ai rather than
	 * something a page load triggers.
	 */
	public static function brain_refresh_business( WP_REST_Request $request ) {
		$result = BusinessKnowledgeCollector::refresh();

		// Partial success is reported as such rather than as a flat failure: if
		// five collectors landed and one threw, the brain is better off than it
		// was and the caller still needs to see which one broke.
		return array(
			'ok'       => $result['ok'],
			'ingested' => $result['ingested'],
			'skipped'  => $result['skipped'],
			'failed'   => $result['failed'],
			'partial'  => ! $result['ok'] && $result['ingested'] > 0,
		);
	}

	/** Last business refresh, for the dashboard to show staleness. */
	public static function brain_business_status( WP_REST_Request $request ) {
		$last = get_option( 'g2a_pos_brain_business_last_run', array() );
		$next = wp_next_scheduled( BusinessKnowledgeCollector::CRON_HOOK );

		return array(
			'last_run'     => is_array( $last ) ? $last : array(),
			'next_run_at'  => $next ? gmdate( 'c', (int) $next ) : null,
			'cron_healthy' => (bool) $next,
		);
	}

	public static function brain_stats( WP_REST_Request $request ) {
		$stats                    = ( new AiBrainRepository() )->stats();
		$stats['backend']         = BrainService::backend();
		$refreshed                = get_option( 'g2a_pos_brain_site_refreshed_at', array() );
		$stats['last_refresh_at'] = is_array( $refreshed ) ? ( $refreshed['at'] ?? null ) : null;
		if ( 'cloudflare' === $stats['backend'] ) {
			$cf                  = \G2A\POS\Ai\CloudflareBrain::stats();
			$stats['cloudflare'] = $cf;
		}
		return $stats;
	}

	/** POST /ai/brain/cloudflare/test — round-trip the Worker's /stats with the saved token. */
	public static function brain_cloudflare_test( WP_REST_Request $request ) {
		return \G2A\POS\Ai\CloudflareBrain::test_connection();
	}

	/** POST /ai/brain/migrate-cloudflare — push a batch of local chunks to the Worker. */
	public static function brain_migrate_cloudflare( WP_REST_Request $request ) {
		$body = $request->get_json_params() ?: array();
		return BrainService::migrate_to_cloudflare(
			(int) ( $body['offset'] ?? 0 ),
			(int) ( $body['limit'] ?? 20 )
		);
	}

	public static function brain_search( WP_REST_Request $request ) {
		$q = (string) $request->get_param( 'q' );
		if ( $q === '' ) {
			return new \WP_Error( 'invalid_input', 'q required', array( 'status' => 400 ) );
		}
		$res = BrainService::retrieve_with_meta( $q, (int) ( $request->get_param( 'k' ) ?: 5 ) );
		return array(
			'count'   => count( $res['hits'] ),
			'hits'    => $res['hits'],
			'backend' => $res['backend'],
			'notice'  => $res['notice'],
		);
	}

	public static function audit_recent( WP_REST_Request $request ) {
		return array( 'items' => ( new AiAuditRepository() )->recent( (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );
	}

	public static function audit_verify( WP_REST_Request $request ) {
		return ( new AiAuditRepository() )->verify_chain( (int) ( $request->get_param( 'limit' ) ?: 5000 ) );
	}

	private static function canAccessConversation( array $conversation ): bool {
		return (int) ( $conversation['user_id'] ?? 0 ) === get_current_user_id()
			|| current_user_can( 'g2a_pos_manage_ai' );
	}

	private static function publicGatewayConfig(): array {
		$config = \G2A\POS\Ai\Gateway::config();
		// Never echo secrets back to the browser — only whether they're set.
		$config['api_key_configured']                 = (string) ( $config['api_key'] ?? '' ) !== '';
		$config['embed_api_key_configured']           = (string) ( $config['embed_api_key'] ?? '' ) !== '';
		$config['cloudflare_worker_token_configured'] = (string) ( $config['cloudflare_worker_token'] ?? '' ) !== '';
		unset( $config['api_key'] );
		unset( $config['embed_api_key'] );
		unset( $config['cloudflare_worker_token'] );
		$config['api_key']                 = '';
		$config['embed_api_key']           = '';
		$config['cloudflare_worker_token'] = '';
		return $config;
	}
}
