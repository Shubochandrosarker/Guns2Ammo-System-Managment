<?php
/**
 * GET    /model-connections
 * POST   /model-connections               — create
 * PATCH  /model-connections/{id}          — edit metadata
 * DELETE /model-connections/{id}          — remove connection + secret
 * POST   /model-connections/{id}/key      — set/rotate API key
 * POST   /model-connections/{id}/test     — provider-specific probe
 *
 * Provider API keys are stored via the Secrets class and never leave PHP.
 * The REST payload replaces them with a static mask so the dashboard has
 * something to render.
 *
 * Per-provider testing dispatches to `plan_probe` — each provider has its
 * own cheapest possible smoke test (Anthropic /messages 1-token, OpenAI
 * /models, Gemini list-models, OpenRouter /models, Ollama /api/tags,
 * custom smoke GET). All probes measure latency and surface HTTP body
 * errors in a consistent shape.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Ops\Audit_Log;
use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Models_Controller extends REST_Controller {
	private const OPTION = 'g2aba_models';

	public const ALLOWED_PROVIDERS = array( 'anthropic', 'openai', 'gemini', 'openrouter', 'ollama', 'custom' );
	public const ALLOWED_COST      = array( 'free', 'low', 'medium', 'high' );

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/model-connections',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list' ),
					'permission_callback' => array( $this, 'read_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/model-connections/(?P<id>[a-z0-9_-]+)',
			array(
				array(
					'methods'             => array( 'PATCH', 'PUT' ),
					'callback'            => array( $this, 'update' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'admin_permissions_check' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/model-connections/(?P<id>[a-z0-9_-]+)/key',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_key' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
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
		$raw = self::read_raw();
		$out = array();
		foreach ( $raw as $id => $record ) {
			$out[] = self::project( (string) $id, is_array( $record ) ? $record : array() );
		}
		return $this->ok( $out );
	}

	public function create( \WP_REST_Request $req ) {
		$patch = $req->get_json_params();
		if ( ! is_array( $patch ) ) {
			$patch = array();
		}
		$sanitised = self::sanitise_patch( $patch, true );
		if ( isset( $sanitised['error'] ) ) {
			return new \WP_Error(
				'g2aba_model_invalid',
				(string) $sanitised['error'],
				array( 'status' => 400 )
			);
		}

		$raw = self::read_raw();
		$id  = self::mint_id( $sanitised['data'], array_keys( $raw ) );
		$rec = array_merge(
			array(
				'id'           => $id,
				'status'       => 'untested',
				'fallbackId'   => null,
			),
			$sanitised['data']
		);
		$raw[ $id ] = $rec;
		update_option( self::OPTION, $raw, false );

		if ( isset( $patch['apiKey'] ) && is_string( $patch['apiKey'] ) && '' !== trim( $patch['apiKey'] ) ) {
			Secrets::put( 'model:' . $id, trim( $patch['apiKey'] ) );
		}

		( new Audit_Log() )->record(
			'model.created',
			sprintf( 'Model connection created: %s', $id ),
			array( 'id' => $id, 'provider' => $rec['provider'] )
		);
		return $this->ok( self::project( $id, $rec ), 201 );
	}

	public function update( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = self::read_raw();
		if ( ! isset( $raw[ $id ] ) || ! is_array( $raw[ $id ] ) ) {
			return $this->not_found();
		}

		$patch = $req->get_json_params();
		if ( ! is_array( $patch ) ) {
			$patch = array();
		}
		$sanitised = self::sanitise_patch( $patch, false );
		if ( isset( $sanitised['error'] ) ) {
			return new \WP_Error(
				'g2aba_model_invalid',
				(string) $sanitised['error'],
				array( 'status' => 400 )
			);
		}

		$raw[ $id ] = array_merge( $raw[ $id ], $sanitised['data'] );
		$raw[ $id ]['id'] = $id;
		update_option( self::OPTION, $raw, false );

		( new Audit_Log() )->record(
			'model.updated',
			sprintf( 'Model connection updated: %s', $id ),
			array( 'id' => $id, 'keys' => array_keys( $sanitised['data'] ) )
		);
		return $this->ok( self::project( $id, $raw[ $id ] ) );
	}

	public function delete( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = self::read_raw();
		if ( ! isset( $raw[ $id ] ) ) {
			return $this->not_found();
		}
		unset( $raw[ $id ] );
		update_option( self::OPTION, $raw, false );
		Secrets::forget( 'model:' . $id );

		( new Audit_Log() )->record(
			'model.deleted',
			sprintf( 'Model connection deleted: %s', $id ),
			array( 'id' => $id )
		);
		return $this->ok( array( 'ok' => true, 'id' => $id ) );
	}

	public function set_key( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = self::read_raw();
		if ( ! isset( $raw[ $id ] ) ) {
			return $this->not_found();
		}
		$body = $req->get_json_params();
		$key  = is_array( $body ) ? trim( (string) ( $body['apiKey'] ?? '' ) ) : '';
		if ( '' === $key ) {
			return new \WP_Error(
				'g2aba_model_key_empty',
				__( 'apiKey is required and must be non-empty.', 'g2a-business-api' ),
				array( 'status' => 400 )
			);
		}
		Secrets::put( 'model:' . $id, $key );

		( new Audit_Log() )->record(
			'model.key_rotated',
			sprintf( 'Model API key rotated: %s', $id ),
			array( 'id' => $id )
		);
		return $this->ok( array( 'ok' => true, 'keyMasked' => Secrets::mask( 'model:' . $id ) ) );
	}

	public function test( \WP_REST_Request $req ) {
		$id  = (string) $req->get_param( 'id' );
		$raw = self::read_raw();
		if ( ! isset( $raw[ $id ] ) ) {
			return $this->not_found();
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

	private function not_found(): \WP_Error {
		return new \WP_Error(
			'g2aba_model_not_found',
			__( 'Unknown model id.', 'g2a-business-api' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Read raw records from the option. Always returns an associative
	 * `id => record` array.
	 *
	 * @return array<string, array>
	 */
	private static function read_raw(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Project a stored record into the REST payload — never leaks plaintext.
	 *
	 * @param array<string,mixed> $record
	 */
	public static function project( string $id, array $record ): array {
		$record['id']        = $id;
		$record['keyMasked'] = Secrets::has( 'model:' . $id ) ? Secrets::mask( 'model:' . $id ) : '(not set)';
		unset( $record['apiKey'], $record['apiKeyPlain'] );
		return $record;
	}

	/**
	 * Sanitise a create/update patch. On create, requires provider + displayName +
	 * modelName. On update, unknown/invalid keys are dropped; empty data is fine.
	 *
	 * @return array{data?:array, error?:string}
	 */
	public static function sanitise_patch( array $patch, bool $is_create ): array {
		$out = array();
		if ( isset( $patch['provider'] ) ) {
			$p = strtolower( (string) $patch['provider'] );
			if ( ! in_array( $p, self::ALLOWED_PROVIDERS, true ) ) {
				return array( 'error' => 'Unknown provider.' );
			}
			$out['provider'] = $p;
		}
		if ( isset( $patch['displayName'] ) ) {
			$name = trim( (string) $patch['displayName'] );
			if ( '' === $name ) {
				return array( 'error' => 'displayName cannot be empty.' );
			}
			$out['displayName'] = $name;
		}
		if ( isset( $patch['modelName'] ) ) {
			$m = trim( (string) $patch['modelName'] );
			if ( '' === $m ) {
				return array( 'error' => 'modelName cannot be empty.' );
			}
			$out['modelName'] = $m;
		}
		if ( isset( $patch['apiBaseUrl'] ) ) {
			$url = trim( (string) $patch['apiBaseUrl'] );
			if ( '' !== $url && ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
				return array( 'error' => 'apiBaseUrl must be a valid URL.' );
			}
			$out['apiBaseUrl'] = $url;
		}
		if ( isset( $patch['contextLimit'] ) ) {
			$n = (int) $patch['contextLimit'];
			$out['contextLimit'] = $n < 0 ? 0 : $n;
		}
		if ( isset( $patch['costLevel'] ) ) {
			$c = strtolower( (string) $patch['costLevel'] );
			if ( ! in_array( $c, self::ALLOWED_COST, true ) ) {
				return array( 'error' => 'costLevel must be one of free|low|medium|high.' );
			}
			$out['costLevel'] = $c;
		}
		if ( isset( $patch['useCase'] ) ) {
			$out['useCase'] = trim( (string) $patch['useCase'] );
		}
		if ( array_key_exists( 'fallbackId', $patch ) ) {
			// null explicit-clear, otherwise a string.
			$out['fallbackId'] = null === $patch['fallbackId'] ? null : (string) $patch['fallbackId'];
		}

		if ( $is_create ) {
			foreach ( array( 'provider', 'displayName', 'modelName' ) as $required ) {
				if ( ! isset( $out[ $required ] ) ) {
					return array( 'error' => sprintf( '%s is required.', $required ) );
				}
			}
			if ( ! isset( $out['apiBaseUrl'] ) ) {
				$out['apiBaseUrl'] = '';
			}
			if ( ! isset( $out['contextLimit'] ) ) {
				$out['contextLimit'] = 0;
			}
			if ( ! isset( $out['costLevel'] ) ) {
				$out['costLevel'] = 'medium';
			}
			if ( ! isset( $out['useCase'] ) ) {
				$out['useCase'] = '';
			}
		}
		return array( 'data' => $out );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<int,string>   $taken
	 */
	public static function mint_id( array $data, array $taken ): string {
		$stem = strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', (string) ( $data['provider'] ?? 'model' ) ) );
		$stem = trim( $stem, '-' );
		if ( '' === $stem ) {
			$stem = 'model';
		}
		$i = 1;
		do {
			$candidate = 1 === $i ? $stem : $stem . '-' . $i;
			$i++;
		} while ( in_array( $candidate, $taken, true ) );
		return $candidate;
	}

	// -----------------------------------------------------------------------
	// Test probes — pure planning + effectful execution.
	// -----------------------------------------------------------------------

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
