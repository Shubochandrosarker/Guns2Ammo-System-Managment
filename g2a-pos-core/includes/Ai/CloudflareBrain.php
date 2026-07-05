<?php

namespace G2A\POS\Ai;

use G2A\POS\Support\SafeHttp;

defined( 'ABSPATH' ) || exit;

/**
 * Client for the Cloudflare Worker RAG service (/cloudflare-rag-worker in the
 * repo root). The Worker embeds chunks with Workers AI (@cf/baai/bge-m3) and
 * stores vectors in Vectorize, so no embedding endpoint is needed WP-side.
 *
 * Configured via the g2a_pos_ai_gateway option:
 *   brain_backend            'cloudflare'
 *   cloudflare_worker_url    https://g2a-rag-worker.<account>.workers.dev
 *   cloudflare_worker_token  bearer token (SecretStore-sealed at rest)
 *
 * Every method degrades gracefully: on any Worker/network error it returns an
 * error envelope (or null for query()) and BrainService falls back to local
 * substring retrieval, so a Worker outage never breaks the agent.
 */
final class CloudflareBrain {

	public static function configured( ?array $cfg = null ): bool {
		$cfg = $cfg ?? Gateway::config();
		return ( $cfg['brain_backend'] ?? 'local' ) === 'cloudflare'
			&& ! empty( $cfg['cloudflare_worker_url'] )
			&& ! empty( $cfg['cloudflare_worker_token'] );
	}

	/**
	 * Push a document's chunks to the Worker for embedding + Vectorize upsert.
	 * Vector ids are "{doc_id}:{chunk_index}" so re-pushing the same document
	 * overwrites in place.
	 *
	 * @param array<int,array<string,mixed>> $chunks   Entries with chunk_index + text.
	 * @param array<string,mixed>            $doc_meta label/source_type/source_uri/tags/scope.
	 * @return array{ok:bool,upserted?:int,error?:string}
	 */
	public static function ingest_chunks( int $doc_id, array $chunks, array $doc_meta = array() ): array {
		$payload = array();
		foreach ( $chunks as $i => $chunk ) {
			$text = (string) ( $chunk['text'] ?? '' );
			if ( $text === '' ) {
				continue;
			}
			$payload[] = array(
				'doc_id'      => $doc_id,
				'chunk_index' => (int) ( $chunk['chunk_index'] ?? $i ),
				'text'        => $text,
				'metadata'    => array(
					'label'       => (string) ( $doc_meta['label'] ?? $doc_meta['source_label'] ?? '' ),
					'source_type' => (string) ( $doc_meta['source_type'] ?? '' ),
					'source_uri'  => (string) ( $doc_meta['source_uri'] ?? '' ),
					'tags'        => (string) ( $doc_meta['tags'] ?? '' ),
					'scope'       => (string) ( $doc_meta['scope'] ?? 'public' ),
				),
			);
		}
		if ( ! $payload ) {
			return array(
				'ok'    => false,
				'error' => 'no_chunks',
			);
		}
		return self::request( 'POST', '/ingest', array( 'chunks' => $payload ) );
	}

	/**
	 * Query the Worker and map hits to the AiBrainRepository::search() row
	 * shape so callers can't tell the backends apart. Null on any error so
	 * BrainService can fall back to local retrieval.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	public static function query( string $query, int $k = 5, ?string &$error = null ): ?array {
		$res = self::request(
			'POST',
			'/query',
			array(
				'query' => $query,
				'k'     => max( 1, min( 20, $k ) ),
			)
		);
		if ( empty( $res['ok'] ) || ! isset( $res['results'] ) || ! is_array( $res['results'] ) ) {
			$error = (string) ( $res['error'] ?? 'malformed_worker_response' );
			return null;
		}
		$hits = array();
		foreach ( $res['results'] as $hit ) {
			if ( ! is_array( $hit ) ) {
				continue;
			}
			$hits[] = array(
				'id'           => (string) ( $hit['id'] ?? '' ),
				'document_id'  => (int) ( $hit['doc_id'] ?? 0 ),
				'text_content' => (string) ( $hit['text'] ?? '' ),
				'source_type'  => (string) ( $hit['source_type'] ?? 'cloudflare' ),
				'source_label' => (string) ( $hit['label'] ?? '' ),
				'source_uri'   => (string) ( $hit['source_uri'] ?? '' ),
				'score'        => (float) ( $hit['score'] ?? 0.0 ),
			);
		}
		return $hits;
	}

	/**
	 * Delete a document's vectors ("{doc_id}:0…N" id prefix).
	 *
	 * @return array{ok:bool,deleted?:int,error?:string}
	 */
	public static function delete_document( int $doc_id, int $chunk_count = 0 ): array {
		return self::request(
			'POST',
			'/delete',
			array(
				'doc_id'      => $doc_id,
				'chunk_count' => max( 0, $chunk_count ),
			)
		);
	}

	/** @return array{ok:bool,error?:string,vectors?:int,dimensions?:int} */
	public static function stats(): array {
		return self::request( 'GET', '/stats' );
	}

	/**
	 * Round-trip test used by the settings screen: hits /stats and reports
	 * the Worker's own error message on failure.
	 */
	public static function test_connection(): array {
		$cfg = Gateway::config();
		if ( empty( $cfg['cloudflare_worker_url'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'No Cloudflare Worker URL configured.',
			);
		}
		if ( empty( $cfg['cloudflare_worker_token'] ) ) {
			return array(
				'ok'    => false,
				'error' => 'No Cloudflare Worker auth token configured.',
			);
		}
		$res        = self::stats();
		$res['url'] = $cfg['cloudflare_worker_url'];
		return $res;
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @return array<string,mixed> Always contains 'ok'.
	 */
	private static function request( string $method, string $path, ?array $body = null ): array {
		$cfg   = Gateway::config();
		$base  = rtrim( (string) $cfg['cloudflare_worker_url'], '/' );
		$token = (string) $cfg['cloudflare_worker_token'];
		if ( $base === '' || $token === '' ) {
			return array(
				'ok'    => false,
				'error' => 'cloudflare_worker_not_configured',
			);
		}
		$args = array(
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'timeout' => 30,
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = 'GET' === $method
			? SafeHttp::get( $base . $path, $args )
			: SafeHttp::post( $base . $path, $args );
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'error' => $res->get_error_message(),
			);
		}
		$status  = (int) wp_remote_retrieve_response_code( $res );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $decoded ) ) {
			$decoded = array();
		}
		if ( $status >= 400 ) {
			return array(
				'ok'    => false,
				'error' => 'HTTP ' . $status . ': ' . (string) ( $decoded['error'] ?? 'worker error' ),
			);
		}
		$decoded['ok'] = $decoded['ok'] ?? true;
		return $decoded;
	}
}
