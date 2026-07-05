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

	/** Find an existing document row by its body hash (dedupe / skip re-embed). */
	public function find_document_by_hash( string $hash ): ?array {
		global $wpdb;
		if ( $hash === '' ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_type, source_label, chunk_count, status
	             FROM {$this->table('g2a_ai_brain_documents')} WHERE content_hash = %s",
				$hash
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public function set_document_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_ai_brain_documents' ),
			array(
				'status'     => sanitize_key( $status ),
				'updated_at' => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function document_has_embeddings( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$this->table('g2a_ai_brain_chunks')}
	             WHERE document_id = %d AND embedding IS NOT NULL LIMIT 1",
				$id
			)
		);
	}

	public function add_chunks( int $document_id, array $chunks, ?string $embedding_model = null ): int {
		global $wpdb;
		$now   = $this->now();
		$count = 0;
		// Replace semantics: callers always pass the full chunk set for the
		// document, so drop any previous chunks first — re-ingesting the same
		// document must never accumulate duplicates.
		$wpdb->delete( $this->table( 'g2a_ai_brain_chunks' ), array( 'document_id' => $document_id ) );
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

	/**
	 * A page of documents for batched work (Cloudflare migration). Ordered by
	 * id so offsets stay stable while the batch loop runs.
	 */
	public function documents_page( int $offset = 0, int $limit = 25 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, source_type, source_label, source_uri, chunk_count, tags, scope, status
	             FROM {$this->table('g2a_ai_brain_documents')} ORDER BY id ASC LIMIT %d OFFSET %d",
				max( 1, min( 200, $limit ) ),
				max( 0, $offset )
			),
			ARRAY_A
		) ?: array();
	}

	public function count_documents(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table('g2a_ai_brain_documents')}" );
	}

	/** All chunks (text only) for one document, in chunk order. */
	public function chunks_for_document( int $document_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT chunk_index, text_content AS text
	             FROM {$this->table('g2a_ai_brain_chunks')} WHERE document_id = %d ORDER BY chunk_index ASC",
				$document_id
			),
			ARRAY_A
		) ?: array();
	}

	/**
	 * Aggregate brain stats for the dashboard: document/chunk totals, how much
	 * of the corpus is embedded, and a per-source_type breakdown.
	 *
	 * @param ?string $scope When set, every count is restricted to documents
	 *                       whose `scope` column matches exactly. Null
	 *                       (default) reports across every scope, unchanged
	 *                       from before.
	 *
	 * @return array{documents:int,chunks:int,embedded_chunks:int,embedded_pct:float,by_source_type:array<int,array{source_type:string,documents:int,chunks:int}>}
	 */
	public function stats( ?string $scope = null ): array {
		global $wpdb;
		$docs_table   = $this->table( 'g2a_ai_brain_documents' );
		$chunks_table = $this->table( 'g2a_ai_brain_chunks' );

		if ( null === $scope ) {
			$docs            = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$docs_table}" );
			$chunks          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table}" );
			$embedded_chunks = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$chunks_table} WHERE embedding IS NOT NULL" );
			$by_type         = $wpdb->get_results(
				"SELECT source_type, COUNT(*) AS documents, COALESCE(SUM(chunk_count), 0) AS chunks
		         FROM {$docs_table} GROUP BY source_type ORDER BY documents DESC",
				ARRAY_A
			) ?: array();
		} else {
			$docs            = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$docs_table} WHERE scope = %s", $scope ) );
			$chunks          = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$chunks_table} c JOIN {$docs_table} d ON d.id = c.document_id WHERE d.scope = %s",
					$scope
				)
			);
			$embedded_chunks = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$chunks_table} c JOIN {$docs_table} d ON d.id = c.document_id
			         WHERE c.embedding IS NOT NULL AND d.scope = %s",
					$scope
				)
			);
			$by_type         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT source_type, COUNT(*) AS documents, COALESCE(SUM(chunk_count), 0) AS chunks
			         FROM {$docs_table} WHERE scope = %s GROUP BY source_type ORDER BY documents DESC",
					$scope
				),
				ARRAY_A
			) ?: array();
		}
		foreach ( $by_type as &$row ) {
			$row['documents'] = (int) $row['documents'];
			$row['chunks']    = (int) $row['chunks'];
		}
		unset( $row );
		return array(
			'documents'       => $docs,
			'chunks'          => $chunks,
			'embedded_chunks' => $embedded_chunks,
			'embedded_pct'    => $chunks > 0 ? round( 100.0 * $embedded_chunks / $chunks, 1 ) : 0.0,
			'by_source_type'  => $by_type,
		);
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

	public function find_document( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, source_type, source_label, source_uri, chunk_count, tags, scope, status
	             FROM {$this->table('g2a_ai_brain_documents')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
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
	 *
	 * @param ?string $scope When set, restricts the candidate chunks to
	 *                       documents whose `scope` column matches exactly
	 *                       (e.g. 'staff' vs 'public'). Null (default)
	 *                       searches every scope, unchanged from before.
	 */
	public function search( array $query_embedding, int $limit = 5, ?string $model = null, ?string $scope = null ): array {
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
		if ( null !== $scope ) {
			$where[] = 'd.scope = %s';
			$args[]  = $scope;
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
	 *
	 * @param ?string $scope When set, restricts results to documents whose
	 *                       `scope` column matches exactly. Null (default)
	 *                       searches every scope, unchanged from before.
	 */
	public function search_text( string $query, int $limit = 5, ?string $scope = null ): array {
		global $wpdb;
		$limit       = max( 1, min( 50, $limit ) );
		$chunks      = $this->table( 'g2a_ai_brain_chunks' );
		$docs        = $this->table( 'g2a_ai_brain_documents' );
		$tokens      = self::tokenize( $query );
		$scope_where = null !== $scope ? ' AND d.scope = %s' : '';

		if ( ! $tokens ) {
			$like = '%' . $wpdb->esc_like( trim( $query ) ) . '%';
			$args = array( $like );
			if ( null !== $scope ) {
				$args[] = $scope;
			}
			$args[] = $limit;
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT c.id, c.document_id, c.text_content, d.source_type, d.source_label, d.source_uri
                     FROM {$chunks} c JOIN {$docs} d ON d.id = c.document_id
                     WHERE c.text_content LIKE %s{$scope_where} ORDER BY c.id DESC LIMIT %d",
					$args
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
                  WHERE ({$where}){$scope_where}
                  ORDER BY match_score DESC, c.id DESC
                  LIMIT %d";
		$args  = array_merge( $score_args, $where_args, null !== $scope ? array( $scope ) : array(), array( $limit ) );
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
