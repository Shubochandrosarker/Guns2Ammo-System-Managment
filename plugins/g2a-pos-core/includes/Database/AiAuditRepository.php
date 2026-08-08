<?php

namespace G2A\POS\Database;

/**
 * Tamper-evident audit ledger for everything the agent does. Hash-chained
 * the same way as the bound book + signatures so a "did the AI really
 * approve this?" question always has a defensible answer.
 */
final class AiAuditRepository extends Repository {

	public function record( string $event_type, array $payload, ?int $conversation_id = null, ?string $tool_name = null ): int {
		global $wpdb;
		$now             = $this->now();
		$prev_hash       = $wpdb->get_var(
			"SELECT row_hash FROM {$this->table('g2a_ai_audit')} ORDER BY id DESC LIMIT 1"
		);
		$row             = array(
			'conversation_id' => $conversation_id,
			'event_type'      => sanitize_text_field( $event_type ),
			'actor_id'        => get_current_user_id() ?: null,
			'tool_name'       => $tool_name ? sanitize_text_field( $tool_name ) : null,
			'payload'         => wp_json_encode( $payload ),
			'ip_address'      => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
			'user_agent'      => sanitize_text_field( substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ) ),
			'prev_hash'       => $prev_hash,
			'created_at'      => $now,
		);
		$row['row_hash'] = hash( 'sha256', wp_json_encode( $row + array( '_prev' => (string) $prev_hash ) ) );
		$wpdb->insert( $this->table( 'g2a_ai_audit' ), $row );
		return (int) $wpdb->insert_id;
	}

	public function recent( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, conversation_id, event_type, actor_id, tool_name, created_at,
                    LEFT(payload, 240) AS payload_excerpt
             FROM {$this->table('g2a_ai_audit')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	public function verify_chain( int $limit = 5000 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, conversation_id, event_type, actor_id, tool_name, payload, ip_address,
                    user_agent, prev_hash, row_hash, created_at
             FROM {$this->table('g2a_ai_audit')} ORDER BY id ASC LIMIT %d",
				max( 1, min( 50000, $limit ) )
			),
			ARRAY_A
		) ?: array();
		$prev = null;
		$bad  = array();
		foreach ( $rows as $r ) {
			$check  = $r;
			$stored = $check['row_hash'];
			unset( $check['row_hash'] );
			$recalc = hash( 'sha256', wp_json_encode( $check + array( '_prev' => (string) $prev ) ) );
			if ( $recalc !== $stored ) {
				$bad[] = (int) $r['id'];
			}
			$prev = $stored;
		}
		return array(
			'ok'            => count( $bad ) === 0,
			'rows'          => count( $rows ),
			'corrupted_ids' => $bad,
		);
	}
}
