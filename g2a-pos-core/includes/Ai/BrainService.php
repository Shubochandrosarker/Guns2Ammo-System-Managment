<?php

namespace G2A\POS\Ai;

use G2A\POS\Support\SafeHttp;

use G2A\POS\Database\AiBrainRepository;

/**
 * Brain ingestion + retrieval. Ingestion pipeline:
 *   raw → normalize → chunk → (optionally embed) → upsert document/chunks.
 *
 * Retrieval pipeline: embed query → cosine over chunks (if embeddings
 * available); fall back to substring search if no embedding model is
 * configured.
 */
final class BrainService {

	public static function ingest_text( string $label, string $body, array $opts = array() ): array {
		$body = self::normalize( $body );
		if ( $body === '' ) {
			return array(
				'ok'    => false,
				'error' => 'empty_body',
			);
		}
		$hash   = hash( 'sha256', $body );
		$repo   = new AiBrainRepository();
		$doc_id = $repo->upsert_document(
			array(
				'source_type'  => $opts['source_type'] ?? 'text',
				'source_label' => $label,
				'source_uri'   => $opts['source_uri'] ?? '',
				'content_hash' => $hash,
				'tags'         => $opts['tags'] ?? '',
				'scope'        => $opts['scope'] ?? 'public',
				'metadata'     => $opts['metadata'] ?? null,
			)
		);
		$chunks = self::chunk( $body, (int) ( $opts['chunk_tokens'] ?? 400 ) );
		$cfg    = Gateway::config();
		$model  = null;
		$packed = array();
		foreach ( $chunks as $i => $text ) {
			$entry = array(
				'chunk_index' => $i,
				'text'        => $text,
				'token_count' => (int) ( str_word_count( $text ) * 1.3 ),
			);
			if ( $cfg['mode'] === 'live' && ! empty( $cfg['embed_endpoint'] ) ) {
				$vec = Gateway::embed( $text );
				if ( $vec ) {
					$entry['embedding'] = $vec;
					$model              = $cfg['embed_model'];
				}
			}
			$packed[] = $entry;
		}
		$count = $repo->add_chunks( $doc_id, $packed, $model );
		return array(
			'ok'          => true,
			'document_id' => $doc_id,
			'chunks'      => $count,
			'embedded'    => $model !== null,
		);
	}

	public static function ingest_url( string $url, array $opts = array() ): array {
		$res = SafeHttp::get(
			$url,
			array(
				'timeout'             => 15,
				'user-agent'          => 'G2A-POS-Brain/2.0',
				'limit_response_size' => 2 * MB_IN_BYTES,
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'ok'    => false,
				'error' => $res->get_error_message(),
			);
		}
		$body = wp_remote_retrieve_body( $res );
		if ( (int) wp_remote_retrieve_response_code( $res ) >= 400 ) {
			return array(
				'ok'    => false,
				'error' => 'http_' . wp_remote_retrieve_response_code( $res ),
			);
		}
		// Strip HTML to text.
		$text  = trim( html_entity_decode( strip_tags( $body ), ENT_QUOTES, 'UTF-8' ) );
		$label = $opts['label'] ?? parse_url( $url, PHP_URL_HOST ) . parse_url( $url, PHP_URL_PATH );
		return self::ingest_text(
			(string) $label,
			$text,
			array_merge(
				$opts,
				array(
					'source_type' => 'url',
					'source_uri'  => $url,
				)
			)
		);
	}

	public static function retrieve( string $query, int $k = 5 ): array {
		$repo = new AiBrainRepository();
		$cfg  = Gateway::config();
		if ( $cfg['mode'] === 'live' && ! empty( $cfg['embed_endpoint'] ) ) {
			$vec = Gateway::embed( $query );
			if ( $vec ) {
				return $repo->search( $vec, $k, $cfg['embed_model'] );
			}
		}
		return $repo->search_text( $query, $k );
	}

	public static function normalize( string $text ): string {
		$text = preg_replace( "/\r\n|\r/", "\n", $text );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( (string) $text );
	}

	public static function chunk( string $text, int $target_tokens = 400 ): array {
		// ~1 token ≈ 4 characters for English; we use words as the unit so
		// boundaries land on real word breaks. Approx. 400 tokens ≈ 280 words.
		$target_words = max( 50, (int) ( $target_tokens * 0.7 ) );
		$overlap      = (int) max( 20, $target_words * 0.15 );
		$words        = preg_split( '/\s+/', $text );
		if ( ! $words ) {
			return array();
		}
		$chunks = array();
		$i      = 0;
		$count  = count( $words );
		while ( $i < $count ) {
			$piece    = array_slice( $words, $i, $target_words );
			$chunks[] = implode( ' ', $piece );
			$i       += $target_words - $overlap;
		}
		return $chunks;
	}
}
