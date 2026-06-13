<?php

namespace G2A\POS\Ai;

use G2A\POS\Support\SafeHttp;
use G2A\POS\Support\SecretStore;

/**
 * Thin client over a local LLM endpoint (Ollama / vLLM / llama.cpp /
 * any OpenAI-compatible /chat/completions). Endpoint + model are set
 * under the `g2a_pos_ai_gateway` option — see /docker/README.md in the
 * plugin for a one-command stack.
 *
 * If no endpoint is configured we fall back to `mode: stub` which lets
 * the rest of the agent (tool registry, audit, action queue, RAG
 * retrieval) exercise end-to-end without an LLM running yet.
 */
final class Gateway {

	public static function config(): array {
		$defaults          = array(
			'mode'            => 'stub',
			'chat_endpoint'   => '',
			'embed_endpoint'  => '',
			'chat_model'      => 'qwen2.5:7b-instruct',
			'embed_model'     => 'nomic-embed-text',
			'api_key'         => '',
			'temperature'     => 0.2,
			'max_tokens'      => 800,
			'request_timeout' => 60,
		);
		$config            = array_merge( $defaults, (array) get_option( 'g2a_pos_ai_gateway', array() ) );
		$config['api_key'] = SecretStore::open( (string) ( $config['api_key'] ?? '' ) );
		return $config;
	}

	public static function chat( array $messages, array $tools = array() ): array {
		$cfg   = self::config();
		$start = microtime( true );

		if ( $cfg['mode'] !== 'live' || ! $cfg['chat_endpoint'] ) {
			return self::stub_chat( $messages, $tools, $start );
		}

		$payload = array(
			'model'       => $cfg['chat_model'],
			'messages'    => $messages,
			'temperature' => (float) $cfg['temperature'],
			'max_tokens'  => (int) $cfg['max_tokens'],
		);
		if ( $tools ) {
			$payload['tools']       = $tools;
			$payload['tool_choice'] = 'auto';
		}
		$res = SafeHttp::post(
			(string) $cfg['chat_endpoint'],
			array(
				'headers' => array_filter(
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => $cfg['api_key'] ? 'Bearer ' . $cfg['api_key'] : null,
					)
				),
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
		$body   = json_decode( (string) wp_remote_retrieve_body( $res ), true ) ?: array();
		$choice = $body['choices'][0]['message'] ?? null;
		if ( ! $choice ) {
			return array(
				'ok'         => false,
				'error'      => 'malformed_response',
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

	public static function embed( string $text ): ?array {
		$cfg = self::config();
		if ( $cfg['mode'] !== 'live' || ! $cfg['embed_endpoint'] ) {
			return null;
		}
		$payload = array(
			'model' => $cfg['embed_model'],
			'input' => $text,
		);
		$res     = SafeHttp::post(
			(string) $cfg['embed_endpoint'],
			array(
				'headers' => array_filter(
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => $cfg['api_key'] ? 'Bearer ' . $cfg['api_key'] : null,
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
				: 'Stub agent: configure g2a_pos_ai_gateway → mode=live and point chat_endpoint at your Ollama / vLLM instance to enable full reasoning. The tool registry and audit ledger are live; you can still wire the popup flow.',
			'tool_calls'        => $tool_call ?: array(),
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'latency_ms'        => (int) ( ( microtime( true ) - $start ) * 1000 ),
			'model'             => 'stub',
		);
	}
}
