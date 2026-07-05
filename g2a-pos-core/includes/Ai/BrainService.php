<?php

namespace G2A\POS\Ai;

use G2A\POS\Support\SafeHttp;

use G2A\POS\Database\AiBrainRepository;

/**
 * Brain ingestion + retrieval. Ingestion pipeline:
 *   raw → normalize → chunk → (optionally embed) → upsert document/chunks.
 *
 * Two retrieval backends, picked via g2a_pos_ai_gateway → brain_backend:
 *
 *   local (default)  — embed query WP-side → cosine over packed-float32
 *                      chunks in MySQL; substring fallback when no embedding
 *                      endpoint is configured.
 *   cloudflare       — chunks are pushed to the Cloudflare Worker RAG service
 *                      (see /cloudflare-rag-worker), which embeds with
 *                      Workers AI and stores vectors in Vectorize. The
 *                      document + chunk rows are still stored locally as the
 *                      source of truth, so a Worker outage degrades to the
 *                      local substring search instead of failing.
 *
 * Re-ingesting identical content is cheap: the body hash is compared against
 * the stored document and the chunk/embed/push work is skipped when nothing
 * changed, so the nightly refresh only re-embeds real edits.
 */
final class BrainService {

	/** Document status once its chunks are confirmed upserted in Vectorize. */
	public const STATUS_SYNCED_CLOUDFLARE = 'synced_cloudflare';

	/** @return string 'local'|'cloudflare' */
	public static function backend( ?array $cfg = null ): string {
		$cfg = $cfg ?? Gateway::config();
		return CloudflareBrain::configured( $cfg ) ? 'cloudflare' : 'local';
	}

	public static function ingest_text( string $label, string $body, array $opts = array() ): array {
		$body = self::normalize( $body );
		if ( $body === '' ) {
			return array(
				'ok'    => false,
				'error' => 'empty_body',
			);
		}
		$hash    = hash( 'sha256', $body );
		$repo    = new AiBrainRepository();
		$cfg     = Gateway::config();
		$backend = self::backend( $cfg );

		$doc_fields = array(
			'source_type'  => $opts['source_type'] ?? 'text',
			'source_label' => $label,
			'source_uri'   => $opts['source_uri'] ?? '',
			'content_hash' => $hash,
			'tags'         => $opts['tags'] ?? '',
			'scope'        => $opts['scope'] ?? 'public',
			'metadata'     => $opts['metadata'] ?? null,
		);

		// Hash gate: identical content that is already fully processed for the
		// active backend is a no-op (refresh label/tags only). This keeps the
		// nightly refresh from re-embedding / re-pushing an unchanged corpus.
		$existing = $repo->find_document_by_hash( $hash );
		if ( $existing && (int) $existing['chunk_count'] > 0 && empty( $opts['force'] ) ) {
			$done = 'cloudflare' === $backend
				? ( ( $existing['status'] ?? '' ) === self::STATUS_SYNCED_CLOUDFLARE )
				: ( ! Gateway::embeddings_enabled( $cfg ) || $repo->document_has_embeddings( (int) $existing['id'] ) );
			if ( $done ) {
				$doc_fields['status'] = (string) $existing['status'];
				$doc_id               = $repo->upsert_document( $doc_fields );
				return array(
					'ok'          => true,
					'document_id' => $doc_id,
					'chunks'      => (int) $existing['chunk_count'],
					'embedded'    => 'cloudflare' === $backend || $repo->document_has_embeddings( $doc_id ),
					'skipped'     => true,
				);
			}
		}

		$doc_id = $repo->upsert_document( $doc_fields );
		$chunks = self::chunk( $body, (int) ( $opts['chunk_tokens'] ?? 400 ) );
		$model  = null;
		$packed = array();
		foreach ( $chunks as $i => $text ) {
			$entry = array(
				'chunk_index' => $i,
				'text'        => $text,
				'token_count' => (int) ( str_word_count( $text ) * 1.3 ),
			);
			// Local backend embeds WP-side; the Cloudflare Worker embeds
			// server-side with Workers AI, so we skip the (unavailable or
			// paid) embedding endpoint entirely.
			if ( 'local' === $backend && Gateway::embeddings_enabled( $cfg ) ) {
				$vec = Gateway::embed( $text );
				if ( $vec ) {
					$entry['embedding'] = $vec;
					$model              = $cfg['embed_model'];
				}
			}
			$packed[] = $entry;
		}
		$count  = $repo->add_chunks( $doc_id, $packed, $model );
		$result = array(
			'ok'          => true,
			'document_id' => $doc_id,
			'chunks'      => $count,
			'embedded'    => $model !== null,
		);

		if ( 'cloudflare' === $backend ) {
			$push = CloudflareBrain::ingest_chunks( $doc_id, $packed, $doc_fields );
			if ( ! empty( $push['ok'] ) ) {
				$repo->set_document_status( $doc_id, self::STATUS_SYNCED_CLOUDFLARE );
				$result['embedded'] = true;
			} else {
				// Kept locally (search_text still finds it); the migrate
				// action re-pushes anything not yet synced.
				$result['notice'] = 'cloudflare_ingest_failed: ' . (string) ( $push['error'] ?? 'unknown' );
			}
		}
		return $result;
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

	/**
	 * @param ?string $scope Restricts retrieval to documents ingested under
	 *                       this scope (e.g. 'staff'). Null (default)
	 *                       searches every scope — the existing in-store POS
	 *                       assistant behavior is unchanged.
	 */
	public static function retrieve( string $query, int $k = 5, ?string $scope = null ): array {
		return self::retrieve_with_meta( $query, $k, $scope )['hits'];
	}

	/**
	 * Retrieval with backend provenance, so callers (the /ai/brain/search
	 * endpoint) can surface which backend answered and whether a Cloudflare
	 * failure forced a local fallback.
	 *
	 * Scope filtering only applies to the local backends — the Cloudflare
	 * Worker path has no scope concept yet, so a scoped query against the
	 * cloudflare backend still queries the full Vectorize index.
	 *
	 * @return array{hits:array<int,array<string,mixed>>,backend:string,notice:?string}
	 */
	public static function retrieve_with_meta( string $query, int $k = 5, ?string $scope = null ): array {
		$repo = new AiBrainRepository();
		$cfg  = Gateway::config();

		if ( 'cloudflare' === self::backend( $cfg ) ) {
			$error = null;
			$hits  = CloudflareBrain::query( $query, $k, $error );
			if ( $hits !== null ) {
				return array(
					'hits'    => $hits,
					'backend' => 'cloudflare',
					'notice'  => null,
				);
			}
			return array(
				'hits'    => $repo->search_text( $query, $k, $scope ),
				'backend' => 'local_fallback',
				'notice'  => 'Cloudflare Worker unavailable (' . (string) $error . ') — using local keyword search.',
			);
		}

		if ( Gateway::embeddings_enabled( $cfg ) ) {
			$vec = Gateway::embed( $query );
			if ( $vec ) {
				return array(
					'hits'    => $repo->search( $vec, $k, $cfg['embed_model'], $scope ),
					'backend' => 'local_vector',
					'notice'  => null,
				);
			}
		}
		return array(
			'hits'    => $repo->search_text( $query, $k, $scope ),
			'backend' => 'local_text',
			'notice'  => null,
		);
	}

	/**
	 * Delete a document everywhere: local rows always; the Worker's vectors
	 * too when the Cloudflare backend is active (best-effort — a Worker error
	 * still deletes locally and reports the notice).
	 */
	public static function delete_document( int $id ): array {
		$repo   = new AiBrainRepository();
		$doc    = $repo->find_document( $id );
		$notice = null;
		if ( $doc && CloudflareBrain::configured() ) {
			$res = CloudflareBrain::delete_document( $id, (int) $doc['chunk_count'] );
			if ( empty( $res['ok'] ) ) {
				$notice = 'cloudflare_delete_failed: ' . (string) ( $res['error'] ?? 'unknown' );
			}
		}
		return array(
			'ok'     => $repo->delete_document( $id ),
			'notice' => $notice,
		);
	}

	/**
	 * Re-push local chunks to the Cloudflare Worker in bounded batches so the
	 * caller can loop until done (progress-safe: order by id + offset).
	 *
	 * @return array{ok:bool,error?:string,total?:int,offset?:int,next_offset?:int,done?:bool,pushed_documents?:int,pushed_chunks?:int,failed_documents?:int,last_error?:?string}
	 */
	public static function migrate_to_cloudflare( int $offset = 0, int $limit = 20 ): array {
		if ( ! CloudflareBrain::configured() ) {
			return array(
				'ok'    => false,
				'error' => 'Set brain backend to Cloudflare and configure the Worker URL + token first.',
			);
		}
		$repo   = new AiBrainRepository();
		$total  = $repo->count_documents();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$docs   = $repo->documents_page( $offset, $limit );

		$pushed_docs   = 0;
		$pushed_chunks = 0;
		$failed        = 0;
		$last_error    = null;
		foreach ( $docs as $doc ) {
			$doc_id = (int) $doc['id'];
			$chunks = $repo->chunks_for_document( $doc_id );
			if ( ! $chunks ) {
				continue;
			}
			$res = CloudflareBrain::ingest_chunks(
				$doc_id,
				$chunks,
				array(
					'label'       => (string) $doc['source_label'],
					'source_type' => (string) $doc['source_type'],
					'source_uri'  => (string) ( $doc['source_uri'] ?? '' ),
					'tags'        => (string) ( $doc['tags'] ?? '' ),
					'scope'       => (string) ( $doc['scope'] ?? 'public' ),
				)
			);
			if ( ! empty( $res['ok'] ) ) {
				$repo->set_document_status( $doc_id, self::STATUS_SYNCED_CLOUDFLARE );
				++$pushed_docs;
				$pushed_chunks += count( $chunks );
			} else {
				++$failed;
				$last_error = (string) ( $res['error'] ?? 'unknown' );
			}
		}

		$next = $offset + count( $docs );
		return array(
			'ok'               => true,
			'total'            => $total,
			'offset'           => $offset,
			'next_offset'      => $next,
			'done'             => count( $docs ) < $limit || $next >= $total,
			'pushed_documents' => $pushed_docs,
			'pushed_chunks'    => $pushed_chunks,
			'failed_documents' => $failed,
			'last_error'       => $last_error,
		);
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
