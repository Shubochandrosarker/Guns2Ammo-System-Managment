<?php
/**
 * Minimal Anthropic Messages API client.
 *
 * Deliberately narrow — the plugin only needs "give me a JSON response for
 * this prompt." No streaming, no tool-use, no vision. If a future feature
 * needs more, it should carry its own client rather than growing this one.
 *
 * The API key is fetched from Secrets under `model:<id>` where <id> is the
 * connection id chosen for insights (default: `anthropic-primary`).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Integrations;

use WordPressistic\G2ABA\Secrets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Anthropic_Client {
	private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
	// Last resort only — completions use the connection's configured
	// `modelName` (see resolve_model()); this pin is what keeps a
	// half-configured connection deterministic.
	private const FALLBACK_MODEL = 'claude-opus-4-8';

	private string $connection_id;

	public function __construct( string $connection_id = 'anthropic-primary' ) {
		$this->connection_id = $connection_id;
	}

	public function is_configured(): bool {
		return Secrets::has( 'model:' . $this->connection_id );
	}

	/**
	 * The model id completions default to: the connection record's
	 * `modelName` from the g2aba_models option (the same store the
	 * Models_Controller writes), falling back to FALLBACK_MODEL when the
	 * connection has no modelName configured.
	 */
	public function resolve_model(): string {
		$raw = get_option( 'g2aba_models', array() );
		if ( is_array( $raw ) && isset( $raw[ $this->connection_id ] ) && is_array( $raw[ $this->connection_id ] ) ) {
			$name = trim( (string) ( $raw[ $this->connection_id ]['modelName'] ?? '' ) );
			if ( '' !== $name ) {
				return $name;
			}
		}
		return self::FALLBACK_MODEL;
	}

	/**
	 * Ask Claude to return a raw text response. Caller parses.
	 *
	 * @throws \RuntimeException When transport fails or Anthropic returns non-2xx.
	 */
	public function complete( string $prompt, string $model = '', int $max_tokens = 2048 ): string {
		$key = Secrets::get( 'model:' . $this->connection_id );
		if ( null === $key ) {
			throw new \RuntimeException( 'Anthropic API key is not configured' );
		}

		$body = array(
			'model'      => '' === $model ? $this->resolve_model() : $model,
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		$resp = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			throw new \RuntimeException( 'Anthropic transport: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 !== $code ) {
			throw new \RuntimeException( 'Anthropic HTTP ' . $code . ': ' . wp_remote_retrieve_body( $resp ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $json ) || empty( $json['content'] ) ) {
			throw new \RuntimeException( 'Anthropic response missing content' );
		}

		$text = '';
		foreach ( $json['content'] as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}
		if ( '' === $text ) {
			throw new \RuntimeException( 'Anthropic response had no text blocks' );
		}
		return $text;
	}
}
