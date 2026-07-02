<?php

namespace G2A\POS\Database;

/**
 * Knowledge-base ingestion store. A document is the unit of provenance
 * (one PDF, one URL crawl, one CSV import); chunks are the embedded
 * retrieval units. Embeddings are stored as packed float32 binary in
 * LONGBLOB so we can do cosine retrieval directly in PHP for small
 * brains; larger ones can be mirrored to Qdrant via the Docker stack.
 */
final class AiBrainRepository extends Repository {

	public function upsert_document( array $data ): int {
		global $wpdb;
		$hash = (string) ( $data['content_hash'] ?? '' );
		if ( ! $hash ) {
			return 0;
		}
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_ai_brain_documents')} WHERE content_hash = %s",
				$hash
			)
		);
		$now      = $this->now();
		$payload  = array(
			'source_type'  => sanitize_key( $data['source_type'] ?? 'manual' ),
			'source_label' => sanitize_text_field( $data['source_label'] ?? 'unnamed' ),
			'source_uri'   => esc_url_raw( $data['source_uri'] ?? '' ),
			'content_hash' => $hash,
			'language'     => sanitize_text_field( $data['language'] ?? 'en' ),
			'tags'         => sanitize_text_field( $data['tags'] ?? '' ),
			'scope'        => sanitize_key( $data['scope'] ?? 'public' ),
			'metadata'     => ! empty( $data['metadata'] ) ? wp_json_encode( $data['metadata'] ) : null,
			'status'       => sanitize_key( $data['status'] ?? 'ingested' ),
			'actor_id'     => get_current_user_id() ?: null,
			'ingested_at'  => $now,
			'updated_at'   => $now,
		);
		if ( $existing ) {
			$wpdb->update( $this->table( 'g2a_ai_brain_documents' ), $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at']  = $now;
		$payload['chunk_count'] = 0;
		$wpdb->insert( $this->table( 'g2a_ai_brain_documents' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public function add_chunks( int $document_id, array $chunks, ?string $embedding_model = null ): int {
		global $wpdb;
		$now   = $this->now();
		$count = 0;
		foreach ( $chunks as $i => $chunk ) {
			$text = (string) ( $chunk['text'] ?? '' );
			if ( $text === '' ) {
				continue;
			}
			$embedding = $chunk['embedding'] ?? null;
			$dim       = null;
			$packed    = null;
			if ( is_array( $embedding ) ) {
				$dim    = count( $embedding );
				$packed = pack( 'g*', ...array_map( 'floatval', $embedding ) );
			}
			$wpdb->insert(
				$this->table( 'g2a_ai_brain_chunks' ),
				array(
					'document_id'     => $document_id,
					'chunk_index'     => (int) ( $chunk['chunk_index'] ?? $i ),
					'text_content'    => $text,
					'token_count'     => isset( $chunk['token_count'] ) ? (int) $chunk['token_count'] : null,
					'embedding'       => $packed,
					'embedding_model' => $embedding_model,
					'embedding_dim'   => $dim,
					'created_at'      => $now,
				)
			);
			++$count;
		}
		$wpdb->update(
			$this->table( 'g2a_ai_brain_documents' ),
			array(
				'chunk_count' => $count,
				'updated_at'  => $now,
			),
			array( 'id' => $document_id )
		);
		return $count;
	}

	public function list_documents( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_type, source_label, source_uri, language, chunk_count,
                    tags, scope, status, ingested_at
             FROM {$this->table('g2a_ai_brain_documents')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	/** Document ids whose comma-separated tags contain $tag (exact tag match). */
	public function document_ids_by_tag( string $tag ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $tag ) . '%';
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_ai_brain_documents')} WHERE tags LIKE %s",
				$like
			)
		) ?: array();
		$out  = array();
		foreach ( $rows as $id ) {
			$out[] = (int) $id;
		}
		return $out;
	}

	public function delete_document( int $id ): bool {
		global $wpdb;
		$wpdb->delete( $this->table( 'g2a_ai_brain_chunks' ), array( 'document_id' => $id ) );
		return (bool) $wpdb->delete( $this->table( 'g2a_ai_brain_documents' ), array( 'id' => $id ) );
	}

	/**
	 * Naive in-PHP cosine retrieval. Pulls every chunk with the same
	 * embedding model + dim and ranks; fine for thousands of chunks,
	 * swap for a vector DB when the brain grows.
	 */
	public function search( array $query_embedding, int $limit = 5, ?string $model = null ): array {
		global $wpdb;
		$dim = count( $query_embedding );
		if ( $dim === 0 ) {
			return array();
		}
		$where = array( 'embedding IS NOT NULL', 'embedding_dim = %d' );
		$args  = array( $dim );
		if ( $model ) {
			$where[] = 'embedding_model = %s';
			$args[]  = $model;
		}
		$sql  = "SELECT c.id, c.document_id, c.text_content, c.embedding,
                       d.source_type, d.source_label, d.source_uri
                FROM {$this->table('g2a_ai_brain_chunks')} c
                JOIN {$this->table('g2a_ai_brain_documents')} d ON d.id = c.document_id
                WHERE " . implode( ' AND ', $where );
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();

		$q_norm = 0.0;
		foreach ( $query_embedding as $v ) {
			$q_norm += $v * $v;
		}
		$q_norm = sqrt( $q_norm );
		if ( $q_norm === 0.0 ) {
			return array();
		}

		$scored = array();
		foreach ( $rows as $r ) {
			$vec = array_values( unpack( 'g*', (string) $r['embedding'] ) );
			if ( count( $vec ) !== $dim ) {
				continue;
			}
			$dot  = 0.0;
			$norm = 0.0;
			for ( $i = 0; $i < $dim; $i++ ) {
				$dot  += $vec[ $i ] * $query_embedding[ $i ];
				$norm += $vec[ $i ] * $vec[ $i ];
			}
			$denom = $q_norm * sqrt( $norm );
			$score = $denom > 0 ? $dot / $denom : 0.0;
			unset( $r['embedding'] );
			$r['score'] = $score;
			$scored[]   = $r;
		}
		usort( $scored, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		return array_slice( $scored, 0, max( 1, min( 50, $limit ) ) );
	}

	/**
	 * Keyword retrieval — the fallback used whenever no embedding endpoint is
	 * configured. Tokenizes the query and ranks chunks by how many distinct
	 * terms they contain, so a natural-language question ("what are your range
	 * hours?") matches relevant chunks instead of requiring the whole phrase to
	 * appear verbatim.
	 */
	public function search_text( string $query, int $limit = 5 ): array {
		global $wpdb;
		$limit  = max( 1, min( 50, $limit ) );
		$chunks = $this->table( 'g2a_ai_brain_chunks' );
		$docs   = $this->table( 'g2a_ai_brain_documents' );
		$tokens = self::tokenize( $query );

		if ( ! $tokens ) {
			$like = '%' . $wpdb->esc_like( trim( $query ) ) . '%';
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.document_id, c.text_content, d.source_type, d.source_label, d.source_uri
                     FROM {$chunks} c JOIN {$docs} d ON d.id = c.document_id
                     WHERE c.text_content LIKE %s ORDER BY c.id DESC LIMIT %d",
					$like,
					$limit
				),
				ARRAY_A
			) ?: array();
		}

		// Rank by number of distinct query terms present in each chunk.
		$score_parts = array();
		$where_parts = array();
		$score_args  = array();
		$where_args  = array();
		foreach ( $tokens as $t ) {
			$like          = '%' . $wpdb->esc_like( $t ) . '%';
			$score_parts[] = '(CASE WHEN c.text_content LIKE %s THEN 1 ELSE 0 END)';
			$score_args[]  = $like;
			$where_parts[] = 'c.text_content LIKE %s';
			$where_args[]  = $like;
		}
		$score = implode( ' + ', $score_parts );
		$where = implode( ' OR ', $where_parts );
		$sql   = "SELECT c.id, c.document_id, c.text_content, d.source_type, d.source_label, d.source_uri,
                         ({$score}) AS match_score
                  FROM {$chunks} c JOIN {$docs} d ON d.id = c.document_id
                  WHERE {$where}
                  ORDER BY match_score DESC, c.id DESC
                  LIMIT %d";
		$args  = array_merge( $score_args, $where_args, array( $limit ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	/**
	 * Split a query into lowercase keyword tokens (drops short words and common
	 * stopwords), capped so the generated SQL stays small.
	 *
	 * @return array<int,string>
	 */
	private static function tokenize( string $query ): array {
		$stop  = array(
			'the','and','for','are','you','your','our','with','what','when','where','which','who','how',
			'does','did','can','could','will','would','from','that','this','have','has','had','was','were',
			'about','into','out','get','got','any','all','please','tell','show','give','need','want','there',
			'here','they','them','its','his','her','but','not','than','then','too','use','using','able',
		);
		$words = preg_split( '/[^a-z0-9]+/', strtolower( $query ) ) ?: array();
		$out   = array();
		foreach ( $words as $w ) {
			if ( strlen( $w ) >= 3 && ! in_array( $w, $stop, true ) ) {
				$out[ $w ] = true;
			}
		}
		return array_slice( array_keys( $out ), 0, 8 );
	}
}
