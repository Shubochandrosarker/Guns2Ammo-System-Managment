<?php
/**
 * Generic OpenAI-compatible AI client.
 *
 * Despite the file name (kept for back-compat), this class talks to ANY
 * OpenAI-compatible /chat/completions endpoint. Built-in providers:
 *
 *   - OpenRouter (free models, but their free tier has provider-routing
 *     gotchas — that's why we added the others)
 *   - OpenAI     (gpt-4o-mini etc. — cheapest production-grade option)
 *   - Groq       (free, very fast llama / mixtral / gemma)
 *   - Together   (free + cheap models)
 *   - Custom     (user-supplied endpoint + model)
 *
 * All providers share the OpenAI chat-completions payload shape so this
 * client doesn't need provider-specific branches at request time.
 *
 * @package G2AB\Modules\AIAutoReply
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class G2AB_OpenRouter_Client {

	const OPTION_KEY      = 'g2ab_openrouter_api_key';
	const OPTION_MODEL    = 'g2ab_openrouter_model';
	const OPTION_PROVIDER = 'g2ab_ai_provider';
	const OPTION_ENDPOINT = 'g2ab_ai_endpoint';

	/**
	 * Provider catalog. Each entry has:
	 *   - label:        UI name
	 *   - endpoint:     /chat/completions URL
	 *   - keys_url:     where to grab a key
	 *   - keys_hint:    one-liner on the key (free? paid? tier?)
	 *   - models:       array of model_id => label (preferred order, first = default)
	 *
	 * Edit this catalog to track providers as their model lineups change.
	 */
	public static function providers() {
		return array(
			'openrouter' => array(
				'label'     => 'OpenRouter (free + paid)',
				'endpoint'  => 'https://openrouter.ai/api/v1/chat/completions',
				'keys_url'  => 'https://openrouter.ai/keys',
				'keys_hint' => 'Free tier: requires a one-time email verification. Some free models occasionally show "No allowed providers" — switch model or use Groq below.',
				'models'    => array(
					'meta-llama/llama-3.3-70b-instruct:free' => 'Llama 3.3 70B (Free, best quality)',
					'meta-llama/llama-3.1-8b-instruct:free'  => 'Llama 3.1 8B (Free, fast)',
					'google/gemma-2-9b-it:free'              => 'Gemma 2 9B (Free)',
					'mistralai/mistral-7b-instruct:free'     => 'Mistral 7B (Free)',
					'openai/gpt-4o-mini'                     => 'OpenAI GPT-4o mini (paid via OpenRouter)',
				),
			),
			'openai' => array(
				'label'     => 'OpenAI (paid, official)',
				'endpoint'  => 'https://api.openai.com/v1/chat/completions',
				'keys_url'  => 'https://platform.openai.com/api-keys',
				'keys_hint' => 'GPT-4o mini is the cheapest production model ($0.15 / $0.60 per million tokens). No free tier.',
				'models'    => array(
					'gpt-4o-mini'    => 'GPT-4o mini (cheap, fast)',
					'gpt-4o'         => 'GPT-4o (high quality)',
					'gpt-3.5-turbo'  => 'GPT-3.5 Turbo (legacy, cheap)',
				),
			),
			'groq' => array(
				'label'     => 'Groq (free, fast)',
				'endpoint'  => 'https://api.groq.com/openai/v1/chat/completions',
				'keys_url'  => 'https://console.groq.com/keys',
				'keys_hint' => 'Free tier with generous rate limits. No credit card required. Recommended if OpenRouter free is misbehaving.',
				'models'    => array(
					'llama-3.3-70b-versatile'  => 'Llama 3.3 70B (Free, best quality)',
					'llama-3.1-8b-instant'     => 'Llama 3.1 8B (Free, fastest)',
					'gemma2-9b-it'             => 'Gemma 2 9B (Free)',
					'mixtral-8x7b-32768'       => 'Mixtral 8x7B (Free, big context)',
				),
			),
			'together' => array(
				'label'     => 'Together AI (free + paid)',
				'endpoint'  => 'https://api.together.xyz/v1/chat/completions',
				'keys_url'  => 'https://api.together.ai/settings/api-keys',
				'keys_hint' => 'Free $1 credit on signup. Some models tagged as free-tier.',
				'models'    => array(
					'meta-llama/Llama-3.3-70B-Instruct-Turbo' => 'Llama 3.3 70B Turbo',
					'meta-llama/Llama-3.2-3B-Instruct-Turbo'  => 'Llama 3.2 3B Turbo',
					'mistralai/Mixtral-8x7B-Instruct-v0.1'    => 'Mixtral 8x7B Instruct',
				),
			),
			'custom' => array(
				'label'     => 'Custom (OpenAI-compatible endpoint)',
				'endpoint'  => '',
				'keys_url'  => '',
				'keys_hint' => 'Enter your own OpenAI-compatible /chat/completions endpoint and model id. Works with self-hosted llama.cpp, vLLM, LM Studio, etc.',
				'models'    => array(),
			),
		);
	}

	/**
	 * Currently selected provider key (e.g. "groq").
	 */
	public function provider_key() {
		$saved = (string) get_option( self::OPTION_PROVIDER, 'openrouter' );
		$catalog = self::providers();
		return isset( $catalog[ $saved ] ) ? $saved : 'openrouter';
	}

	public function provider_config() {
		$catalog = self::providers();
		return $catalog[ $this->provider_key() ];
	}

	public function endpoint() {
		$key = $this->provider_key();
		if ( 'custom' === $key ) {
			$saved = (string) get_option( self::OPTION_ENDPOINT, '' );
			return $saved ?: '';
		}
		$cfg = $this->provider_config();
		return $cfg['endpoint'];
	}

	public function api_key() {
		if ( defined( 'G2AB_OPENROUTER_KEY' ) && G2AB_OPENROUTER_KEY ) {
			return G2AB_OPENROUTER_KEY;
		}
		return (string) get_option( self::OPTION_KEY, '' );
	}

	public function model() {
		$saved = (string) get_option( self::OPTION_MODEL, '' );
		if ( '' !== $saved ) {
			return $saved;
		}
		$cfg    = $this->provider_config();
		$models = $cfg['models'];
		return $models ? (string) array_key_first( $models ) : 'gpt-4o-mini';
	}

	public function is_configured() {
		return '' !== $this->api_key() && '' !== $this->endpoint();
	}

	/**
	 * Send a chat completion request.
	 *
	 * @param array $messages Array of {role, content} entries.
	 * @param array $opts     Optional: model, temperature, max_tokens, system.
	 * @return string|WP_Error Generated text or WP_Error.
	 */
	public function chat( $messages, $opts = array() ) {
		if ( '' === $this->api_key() ) {
			return new WP_Error( 'no_api_key', __( 'AI provider API key is not set.', 'g2a-booking' ) );
		}
		$endpoint = $this->endpoint();
		if ( '' === $endpoint ) {
			return new WP_Error( 'no_endpoint', __( 'AI provider endpoint is empty. Pick a provider or enter a custom endpoint URL.', 'g2a-booking' ) );
		}

		$opts = wp_parse_args( $opts, array(
			'model'       => $this->model(),
			'temperature' => 0.4,
			'max_tokens'  => 600,
			'system'      => '',
		) );

		if ( ! empty( $opts['system'] ) ) {
			array_unshift( $messages, array( 'role' => 'system', 'content' => $opts['system'] ) );
		}

		$payload = array(
			'model'       => $opts['model'],
			'messages'    => $messages,
			'temperature' => (float) $opts['temperature'],
			'max_tokens'  => (int) $opts['max_tokens'],
		);

		// OpenRouter-specific routing hint: try multiple providers if the
		// first one isn't allowed. Harmless on other endpoints.
		if ( 'openrouter' === $this->provider_key() ) {
			$payload['provider'] = array( 'allow_fallbacks' => true );
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $this->api_key(),
			'Content-Type'  => 'application/json',
		);
		// Optional attribution headers — only OpenRouter cares but they're cheap.
		if ( 'openrouter' === $this->provider_key() ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = get_bloginfo( 'name' ) . ' — G2A Booking';
		}

		$response = wp_remote_post( $endpoint, array(
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => $headers,
			'body'      => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code >= 400 ) {
			$msg = $json['error']['message']
				?? ( $json['error']['code'] ?? null )
				?? sprintf( /* translators: %d HTTP code */ __( 'AI provider HTTP %d', 'g2a-booking' ), $code );
			// Surface OpenRouter's friendliest message verbatim.
			if ( is_array( $msg ) ) $msg = wp_json_encode( $msg );
			return new WP_Error( 'ai_provider_error', (string) $msg, array( 'response' => $json, 'http_code' => $code ) );
		}

		$content = $json['choices'][0]['message']['content'] ?? '';
		if ( '' === $content ) {
			return new WP_Error( 'ai_provider_empty', __( 'Empty response from AI provider.', 'g2a-booking' ), array( 'response' => $json ) );
		}

		return trim( $content );
	}

	/**
	 * Quick connection test — sends "ping" prompt.
	 */
	public function test_connection() {
		return $this->chat( array(
			array( 'role' => 'user', 'content' => 'Reply with the single word: pong.' ),
		), array( 'max_tokens' => 8, 'temperature' => 0 ) );
	}

	/**
	 * Back-compat — old code reads FREE_MODELS directly.
	 * Returns the current provider's models.
	 */
	public static function get_models_for_current_provider() {
		$instance = new self();
		$cfg = $instance->provider_config();
		return $cfg['models'];
	}
}

// Keep the old FREE_MODELS constant readable for any external code that
// imported it; populate from OpenRouter's catalog at class-load time.
if ( ! defined( 'G2AB_OPENROUTER_FREE_MODELS' ) ) {
	$_g2ab_or = G2AB_OpenRouter_Client::providers();
	define( 'G2AB_OPENROUTER_FREE_MODELS', $_g2ab_or['openrouter']['models'] );
	unset( $_g2ab_or );
}
