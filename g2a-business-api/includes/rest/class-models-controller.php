<?php
/**
 * GET  /model-connections
 * POST /model-connections/{id}/test
 *
 * Provider API keys are stored via the Secrets class and never leave PHP.
 * The REST payload replaces them with a static mask so the dashboard has
 * something to render.
 *
 * The test call now dispatches per-provider — each provider has its own
 * cheapest possible probe (Anthropic /messages 1-token, OpenAI /models,
 * Gemini list-models, OpenRouter /models, Ollama /api/tags, custom smoke
 * GET). All probes measure latency and surface HTTP body errors in a
 * consistent shape.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Models_Controller extends REST_Controller {
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/model-connections',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/model-connections/(?P<id>[a-z0-9_-]+)/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
			)
		);
	}

	public function list() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
		$raw = get_option( 'g2aba_models', array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$out = array();
		foreach ( $raw as $id => $record ) {
			$record         = is_array( $record ) ? $record : array();
			$record['id']   = (string) $id;
			$record['keyMasked'] = Secrets::has( 'model:' . $id ) ? Secrets::mask( 'model:' . $id ) : '(not set)';
			// Never leak the plaintext key even by accident.
			unset( $record['apiKey'], $record['apiKeyPlain'] );
			$out[] = $record;
		}
		return $this->ok( $out );
	}

	public function test( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = get_option( 'g2aba_models', array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $id ] ) ) {
			return new \WP_Error(
				'g2aba_model_not_found',
				__( 'Unknown model id.', 'g2a-business-api' ),
				array( 'status' => 404 )
			);
		}

		$record   = is_array( $raw[ $id ] ) ? $raw[ $id ] : array();
		$provider = strtolower( (string) ( $record['provider'] ?? 'custom' ) );
		$plain    = Secrets::get( 'model:' . $id );

		if ( self::provider_requires_key( $provider ) && null === $plain ) {
			return $this->ok(
				array(
					'ok'       => false,
					'provider' => $provider,
					'error'    => __( 'No API key stored for this model connection.', 'g2a-business-api' ),
				)
			);
		}

		$plan = self::plan_probe( $provider, $record, (string) ( $plain ?? '' ) );
		if ( isset( $plan['error'] ) ) {
			return $this->ok(
				array( 'ok' => false, 'provider' => $provider, 'error' => (string) $plan['error'] )
			);
		}

		return $this->ok( $this->execute_probe( $provider, $plan ) );
	}

	/**
	 * Public for tests. Builds the HTTP call for each provider without
	 * actually executing it — every branch is deterministic and pure.
	 *
	 * @return array{
	 *   method:string,
	 *   url:string,
	 *   headers:array<string,string>,
	 *   body?:string,
	 *   probeName?:string,
	 *   error?:string,
	 * }
	 */
	public static function plan_probe( string $provider, array $record, string $key ): array {
		switch ( $provider ) {
			case 'anthropic':
				return array(
					'method'    => 'POST',
					'url'       => 'https://api.anthropic.com/v1/messages',
					'headers'   => array(
						'x-api-key'         => $key,
						'anthropic-version' => '2023-06-01',
						'content-type'      => 'application/json',
					),
					'body'      => wp_json_encode( array(
						'model'      => (string) ( $record['modelName'] ?? 'claude-3-haiku-20240307' ),
						'max_tokens' => 1,
						'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
					) ),
					'probeName' => 'anthropic:messages',
				);

			case 'openai':
				return array(
					'method'    => 'GET',
					'url'       => 'https://api.openai.com/v1/models',
					'headers'   => array( 'authorization' => 'Bearer ' . $key ),
					'probeName' => 'openai:list-models',
				);

			case 'openrouter':
				return array(
					'method'    => 'GET',
					'url'       => 'https://openrouter.ai/api/v1/models',
					'headers'   => array( 'authorization' => 'Bearer ' . $key ),
					'probeName' => 'openrouter:list-models',
				);

			case 'gemini':
				$model = (string) ( $record['modelName'] ?? 'gemini-1.5-flash' );
				return array(
					'method'    => 'GET',
					'url'       => sprintf(
						'https://generativelanguage.googleapis.com/v1beta/models/%s?key=%s',
						rawurlencode( $model ),
						rawurlencode( $key )
					),
					'headers'   => array(),
					'probeName' => 'gemini:get-model',
				);

			case 'ollama':
				$base = (string) ( $record['apiBaseUrl'] ?? '' );
				if ( '' === $base ) {
					return array( 'error' => 'Missing apiBaseUrl for Ollama connection.' );
				}
				return array(
					'method'    => 'GET',
					'url'       => trailingslashit( $base ) . 'api/tags',
					'headers'   => array(),
					'probeName' => 'ollama:tags',
				);

			case 'custom':
			default:
				$base = (string) ( $record['apiBaseUrl'] ?? '' );
				if ( '' === $base ) {
					return array( 'error' => 'Missing apiBaseUrl' );
				}
				$headers = array();
				if ( '' !== $key ) {
					$headers['authorization'] = 'Bearer ' . $key;
				}
				return array(
					'method'    => 'GET',
					'url'       => esc_url_raw( trailingslashit( $base ) ),
					'headers'   => $headers,
					'probeName' => 'custom:root',
				);
		}
	}

	public static function provider_requires_key( string $provider ): bool {
		return in_array(
			$provider,
			array( 'anthropic', 'openai', 'openrouter', 'gemini' ),
			true
		);
	}

	private function execute_probe( string $provider, array $plan ): array {
		$start = microtime( true );
		$args  = array(
			'timeout'     => 8,
			'redirection' => 2,
			'headers'     => (array) ( $plan['headers'] ?? array() ),
		);
		if ( isset( $plan['body'] ) ) {
			$args['body'] = (string) $plan['body'];
		}

		$res = 'POST' === strtoupper( (string) $plan['method'] )
			? wp_remote_post( (string) $plan['url'], $args )
			: wp_remote_get( (string) $plan['url'], $args );

		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $res ) ) {
			return array(
				'ok'       => false,
				'provider' => $provider,
				'probe'    => (string) ( $plan['probeName'] ?? '' ),
				'error'    => $res->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code >= 200 && $code < 300 ) {
			return array(
				'ok'        => true,
				'provider'  => $provider,
				'probe'     => (string) ( $plan['probeName'] ?? '' ),
				'latencyMs' => $ms,
				'httpCode'  => $code,
			);
		}
		$body_raw = wp_remote_retrieve_body( $res );
		return array(
			'ok'        => false,
			'provider'  => $provider,
			'probe'     => (string) ( $plan['probeName'] ?? '' ),
			'latencyMs' => $ms,
			'httpCode'  => $code,
			'error'     => self::extract_error_summary( is_string( $body_raw ) ? $body_raw : '' ),
		);
	}

	public static function extract_error_summary( string $body ): string {
		if ( '' === $body ) {
			return 'HTTP error with empty body.';
		}
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			// Anthropic / OpenAI / OpenRouter all use { error: { message } }.
			if ( isset( $decoded['error']['message'] ) ) {
				return (string) $decoded['error']['message'];
			}
			// Gemini uses { error: { status, message } }.
			if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) ) {
				return (string) $decoded['error'];
			}
			if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) ) {
				return (string) $decoded['message'];
			}
		}
		return substr( $body, 0, 240 );
	}
}
