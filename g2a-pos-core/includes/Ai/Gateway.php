<?php

namespace G2A\POS\Ai;

use G2A\POS\Support\SafeHttp;
use G2A\POS\Support\SecretStore;

/**
 * Thin client over an LLM endpoint. Supports per-provider presets:
 *
 *   - openrouter        — hosted, recommended. Chat via
 *                         https://openrouter.ai/api/v1/chat/completions with
 *                         attribution headers. OpenRouter does not serve an
 *                         /embeddings endpoint, so embeddings come from a
 *                         separate `embed_provider` (openai / ollama /
 *                         cloudflare / none).
 *   - openai_compatible — any OpenAI-compatible /chat/completions (vLLM,
 *                         llama.cpp server, LM Studio, a hosted proxy, …).
 *   - ollama            — local Ollama; keeps the legacy local-tag model
 *                         defaults (qwen2.5:7b-instruct / nomic-embed-text,
 *                         which 404 on hosted endpoints).
 *
 * Endpoint + model are set under the `g2a_pos_ai_gateway` option — see
 * /docker/README.md in the plugin for a one-command local stack.
 *
 * If no endpoint is configured we fall back to `mode: stub` which lets
 * the rest of the agent (tool registry, audit, action queue, RAG
 * retrieval) exercise end-to-end without an LLM running yet.
 */
final class Gateway {

	public const OPENROUTER_CHAT_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
	public const OPENAI_EMBED_ENDPOINT    = 'https://api.openai.com/v1/embeddings';

	public const PROVIDERS       = array( 'openrouter', 'openai_compatible', 'ollama' );
	public const EMBED_PROVIDERS = array( '', 'openai', 'ollama', 'cloudflare', 'none' );

	public static function config(): array {
		$defaults = array(
			'mode'                    => 'stub',
			'provider'                => 'ollama',
			'chat_endpoint'           => '',
			'embed_endpoint'          => '',
			'chat_model'              => '',
			'embed_model'             => '',
			'embed_provider'          => '',
			'api_key'                 => '',
			'embed_api_key'           => '',
			'temperature'             => 0.2,
			'max_tokens'              => 800,
			'request_timeout'         => 60,
			// Brain backend (see BrainService): 'local' keeps embeddings in
			// MySQL; 'cloudflare' pushes chunks to the RAG Worker.
			'brain_backend'           => 'local',
			'cloudflare_worker_url'   => '',
			'cloudflare_worker_token' => '',
		);
		$config   = array_merge( $defaults, (array) get_option( 'g2a_pos_ai_gateway', array() ) );
		$config   = self::apply_provider_presets( $config );

		$config['api_key']                 = SecretStore::open( (string) ( $config['api_key'] ?? '' ) );
		$config['embed_api_key']           = SecretStore::open( (string) ( $config['embed_api_key'] ?? '' ) );
		$config['cloudflare_worker_token'] = SecretStore::open( (string) ( $config['cloudflare_worker_token'] ?? '' ) );
		return $config;
	}

	/**
	 * Fill provider-specific defaults for anything the operator left blank.
	 * Kept pure (no get_option / HTTP) so preset resolution is unit-testable.
	 *
	 * @param array<string,mixed> $config Raw option merged over the defaults.
	 * @return array<string,mixed>
	 */
	public static function apply_provider_presets( array $config ): array {
		$provider = (string) ( $config['provider'] ?? '' );
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			// Legacy rows predate the provider concept and were written for a
			// local Ollama stack — keep their old model defaults intact.
			$provider           = 'ollama';
			$config['provider'] = 'ollama';
		}
		$embed_provider = (string) ( $config['embed_provider'] ?? '' );
		if ( ! in_array( $embed_provider, self::EMBED_PROVIDERS, true ) ) {
			$embed_provider           = '';
			$config['embed_provider'] = '';
		}

		if ( 'openrouter' === $provider ) {
			if ( '' === (string) $config['chat_endpoint'] ) {
				$config['chat_endpoint'] = self::OPENROUTER_CHAT_ENDPOINT;
			}
			if ( '' === (string) $config['chat_model'] ) {
				$config['chat_model'] = 'anthropic/claude-sonnet-4.5';
			}
		} elseif ( 'ollama' === $provider ) {
			// Ollama tags — these 404 on hosted endpoints, so they are only
			// ever defaulted for the local provider.
			if ( '' === (string) $config['chat_model'] ) {
				$config['chat_model'] = 'qwen2.5:7b-instruct';
			}
			if ( '' === (string) $config['embed_model'] && in_array( $embed_provider, array( '', 'ollama' ), true ) ) {
				$config['embed_model'] = 'nomic-embed-text';
			}
		}

		if ( 'openai' === $embed_provider ) {
			if ( '' === (string) $config['embed_endpoint'] ) {
				$config['embed_endpoint'] = self::OPENAI_EMBED_ENDPOINT;
			}
			if ( '' === (string) $config['embed_model'] ) {
				$config['embed_model'] = 'text-embedding-3-small';
			}
		} elseif ( 'ollama' === $embed_provider ) {
			if ( '' === (string) $config['embed_model'] ) {
				$config['embed_model'] = 'nomic-embed-text';
			}
		} elseif ( 'none' === $embed_provider || 'cloudflare' === $embed_provider ) {
			// 'none' → substring fallback; 'cloudflare' → the RAG Worker embeds
			// server-side with Workers AI, so WP never calls /embeddings.
			$config['embed_endpoint'] = '';
		}

		return $config;
	}

	/**
	 * Headers for a chat request. OpenRouter gets the attribution headers it
	 * documents (HTTP-Referer + X-Title) — harmless elsewhere but only sent
	 * for that provider.
	 *
	 * @param array<string,mixed> $cfg Resolved config (see config()).
	 * @return array<string,string>
	 */
	public static function chat_headers( array $cfg ): array {
		$headers = array( 'Content-Type' => 'application/json' );
		if ( ! empty( $cfg['api_key'] ) ) {
			$headers['Authorization'] = 'Bearer ' . $cfg['api_key'];
		}
		if ( ( $cfg['provider'] ?? '' ) === 'openrouter' ) {
			$headers['HTTP-Referer'] = function_exists( 'home_url' ) ? home_url( '/' ) : 'https://guns2ammo.com/';
			$headers['X-Title']      = 'Guns2Ammo POS';
		}
		return $headers;
	}

	/** Whether a WP-side embeddings call is possible with the current config. */
	public static function embeddings_enabled( ?array $cfg = null ): bool {
		$cfg = $cfg ?? self::config();
		if ( ( $cfg['mode'] ?? '' ) !== 'live' ) {
			return false;
		}
		if ( in_array( (string) ( $cfg['embed_provider'] ?? '' ), array( 'none', 'cloudflare' ), true ) ) {
			return false;
		}
		return ! empty( $cfg['embed_endpoint'] );
	}

	/**
	 * @param array<int,array<string,mixed>> $messages  OpenAI-style messages.
	 * @param array<int,array<string,mixed>> $tools     Tool specs.
	 * @param array<string,mixed>            $overrides Payload overrides (e.g. max_tokens for a cheap ping).
	 */
	public static function chat( array $messages, array $tools = array(), array $overrides = array() ): array {
		$cfg   = self::config();
		$start = microtime( true );

		if ( $cfg['mode'] !== 'live' || ! $cfg['chat_endpoint'] ) {
			return self::stub_chat( $messages, $tools, $start );
		}

		$payload = array_merge(
			array(
				'model'       => $cfg['chat_model'],
				'messages'    => $messages,
				'temperature' => (float) $cfg['temperature'],
				'max_tokens'  => (int) $cfg['max_tokens'],
			),
			$overrides
		);
		if ( $tools ) {
			$payload['tools']       = $tools;
			$payload['tool_choice'] = 'auto';
		}
		$res = SafeHttp::post(
			(string) $cfg['chat_endpoint'],
			array(
				'headers' => self::chat_headers( $cfg ),
				'body'    => wp_json_encode( $payload ),
				'timeout' => (int) $cfg['request_timeout'],
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'         => false,
				'error'      => $res->get_error_message(),
				'latency_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
			);
		}
		$status  = (int) wp_remote_retrieve_response_code( $res );
		$rawBody = (string) wp_remote_retrieve_body( $res );
		$body    = json_decode( $rawBody, true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		// OpenAI-compatible endpoints (OpenRouter, Ollama, vLLM, …) return the
		// real failure reason under `error` (HTTP 4xx/5xx) — e.g. an invalid
		// model slug, a bad/empty API key, or no credits. Surface it instead of
		// a generic "malformed_response" so the misconfiguration is visible.
		if ( $status >= 400 || isset( $body['error'] ) ) {
			return array(
				'ok'         => false,
				'error'      => self::describe_api_error( $status, $body, $rawBody ),
				'response'   => $body,
				'latency_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
			);
		}
		$choice = $body['choices'][0]['message'] ?? null;
		if ( ! $choice ) {
			return array(
				'ok'         => false,
				'error'      => 'malformed_response: ' . self::snippet( $rawBody ),
				'response'   => $body,
				'latency_ms' => (int) ( ( microtime( true ) - $start ) * 1000 ),
			);
		}
		return array(
			'ok'                => true,
			'content'           => (string) ( $choice['content'] ?? '' ),
			'tool_calls'        => $choice['tool_calls'] ?? array(),
			'prompt_tokens'     => (int) ( $body['usage']['prompt_tokens'] ?? 0 ),
			'completion_tokens' => (int) ( $body['usage']['completion_tokens'] ?? 0 ),
			'latency_ms'        => (int) ( ( microtime( true ) - $start ) * 1000 ),
			'model'             => $cfg['chat_model'],
		);
	}

	/**
	 * Cheap chat round-trip so the settings screen can verify the provider,
	 * key and model before anyone talks to the agent. Returns the provider's
	 * own error message on failure (bad key, unknown model slug, no credits).
	 */
	public static function test_connection(): array {
		$cfg = self::config();
		if ( $cfg['mode'] !== 'live' ) {
			return array(
				'ok'    => false,
				'error' => 'Gateway is in stub mode — set mode to Live and pick a provider first.',
			);
		}
		if ( ! $cfg['chat_endpoint'] ) {
			return array(
				'ok'    => false,
				'error' => 'No chat endpoint configured for the selected provider.',
			);
		}
		$res = self::chat(
			array(
				array(
					'role'    => 'user',
					'content' => 'Reply with the single word: ok',
				),
			),
			array(),
			array(
				'max_tokens'  => 8,
				'temperature' => 0,
			)
		);
		return array(
			'ok'         => ! empty( $res['ok'] ),
			'error'      => $res['error'] ?? null,
			'provider'   => $cfg['provider'],
			'model'      => $cfg['chat_model'],
			'endpoint'   => $cfg['chat_endpoint'],
			'content'    => $res['content'] ?? '',
			'latency_ms' => $res['latency_ms'] ?? null,
		);
	}

	public static function embed( string $text ): ?array {
		$cfg = self::config();
		if ( ! self::embeddings_enabled( $cfg ) ) {
			return null;
		}
		$payload = array(
			'model' => $cfg['embed_model'],
			'input' => $text,
		);
		// Embeddings may live on a different provider than chat (e.g. chat on
		// OpenRouter, embeddings on OpenAI) — prefer the dedicated key.
		$key = (string) ( $cfg['embed_api_key'] !== '' ? $cfg['embed_api_key'] : $cfg['api_key'] );
		$res = SafeHttp::post(
			(string) $cfg['embed_endpoint'],
			array(
				'headers' => array_filter(
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => $key !== '' ? 'Bearer ' . $key : null,
					)
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true ) ?: array();
		// OpenAI-compatible responses expose the vector under the first data entry.
		$vec = $body['data'][0]['embedding'] ?? null;
		// Ollama embeddings responses expose the vector at the top level instead.
		$vec = $vec ?? ( $body['embedding'] ?? null );
		return is_array( $vec ) ? $vec : null;
	}

	/**
	 * Turn an OpenAI-compatible error envelope into a readable one-liner.
	 *
	 * @param array<string,mixed> $body Decoded JSON response body.
	 */
	private static function describe_api_error( int $status, array $body, string $rawBody ): string {
		$err = $body['error'] ?? null;
		if ( is_array( $err ) ) {
			$msg  = trim( (string) ( $err['message'] ?? '' ) );
			$code = trim( (string) ( $err['code'] ?? $err['type'] ?? '' ) );
			$out  = 'HTTP ' . $status;
			if ( $msg !== '' ) {
				$out .= ': ' . $msg;
			}
			if ( $code !== '' ) {
				$out .= ' [' . $code . ']';
			}
			return $msg === '' && $code === '' ? $out . ': ' . self::snippet( $rawBody ) : $out;
		}
		if ( is_string( $err ) && trim( $err ) !== '' ) {
			return 'HTTP ' . $status . ': ' . trim( $err );
		}
		return 'HTTP ' . $status . ': ' . self::snippet( $rawBody );
	}

	private static function snippet( string $raw ): string {
		$s = trim( (string) preg_replace( '/\s+/', ' ', $raw ) );
		if ( $s === '' ) {
			return 'empty response body';
		}
		return strlen( $s ) > 200 ? substr( $s, 0, 200 ) . '…' : $s;
	}

	/**
	 * Stub mode lets the agent loop run with no LLM. It picks one tool
	 * from the last user message via simple keyword matching, so QA can
	 * walk the popup-confirmation flow without an LLM available.
	 */
	private static function stub_chat( array $messages, array $tools, float $start ): array {
		$last_user = '';
		foreach ( array_reverse( $messages ) as $m ) {
			if ( ( $m['role'] ?? '' ) === 'user' ) {
				$last_user = (string) ( $m['content'] ?? '' );
				break; }
		}
		$tool_call = null;
		$available = array_column( $tools, 'function' );
		$names     = array_map( static fn( $f ) => $f['name'] ?? '', is_array( $available ) ? $available : array() );
		$lower     = strtolower( $last_user );

		// Keyword → tool routing. Ordered most-specific first so e.g.
		// "membership" wins over a bare "find". This is what lets the agent
		// scan bookings, memberships and products usefully even before a live
		// LLM is configured (stub mode).
		$pick = null;
		foreach ( array(
			'email invoice'   => 'cart.email_invoice',
			'create invoice'  => 'cart.create_invoice',
			'booking'         => 'booking.lookup',
			'reservation'     => 'booking.lookup',
			'lane booking'    => 'booking.lookup',
			'membership'      => 'membership.lookup',
			'member '         => 'membership.lookup',
			'renewal'         => 'membership.lookup',
			'who renews'      => 'membership.lookup',
			'find a customer' => 'customer.lookup',
			'lookup customer' => 'customer.lookup',
			'find ammo'       => 'inventory.find',
			'in stock'        => 'inventory.find',
			'product'         => 'inventory.find',
			'inventory'       => 'inventory.find',
			'sales today'     => 'reports.sales',
			'how many'        => 'reports.sales',
		) as $needle => $tool ) {
			if ( strpos( $lower, $needle ) !== false && in_array( $tool, $names, true ) ) {
				$pick = $tool;
				break;
			}
		}
		if ( $pick ) {
			$tool_call = array(
				array(
					'id'       => 'call_' . wp_generate_password( 8, false, false ),
					'type'     => 'function',
					'function' => array(
						'name'      => $pick,
						'arguments' => wp_json_encode( array( 'q' => $last_user ) ),
					),
				),
			);
		}

		return array(
			'ok'                => true,
			'content'           => $pick
				? "I'll use the {$pick} tool to handle that. (LLM not configured — running in stub mode.)"
				: 'Stub agent: configure g2a_pos_ai_gateway → mode=live and pick a provider (OpenRouter recommended) in AI Settings to enable full reasoning. The tool registry and audit ledger are live; you can still wire the popup flow.',
			'tool_calls'        => $tool_call ?: array(),
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'latency_ms'        => (int) ( ( microtime( true ) - $start ) * 1000 ),
			'model'             => 'stub',
		);
	}
}
