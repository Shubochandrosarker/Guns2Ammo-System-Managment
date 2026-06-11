<?php

namespace G2A\POS\Database;

/**
 * Two-step action queue. When the agent picks a sensitive tool (send
 * email, void a sale, fire NICS), it enqueues a pending row here; the
 * UI shows the confirmation popup and posts back allow/deny.
 */
final class AiPendingActionRepository extends Repository {

	public function enqueue( int $conversation_id, int $tool_call_id, string $tool_name, array $arguments, array $preview, int $requested_by, ?int $ttl_minutes = 10 ): int {
		global $wpdb;
		$now     = $this->now();
		$expires = $ttl_minutes ? date( 'Y-m-d H:i:s', strtotime( "+{$ttl_minutes} minutes" ) ) : null;
		$wpdb->insert(
			$this->table( 'g2a_ai_pending_actions' ),
			array(
				'conversation_id' => $conversation_id,
				'tool_call_id'    => $tool_call_id,
				'tool_name'       => sanitize_text_field( $tool_name ),
				'arguments'       => wp_json_encode( $arguments ),
				'preview_payload' => wp_json_encode( $preview ),
				'requested_by'    => $requested_by,
				'status'          => 'pending',
				'expires_at'      => $expires,
				'created_at'      => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_ai_pending_actions')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'arguments', 'preview_payload' ) as $f ) {
			if ( ! empty( $row[ $f ] ) ) {
				$row[ $f ] = json_decode( (string) $row[ $f ], true );
			}
		}
		return $row;
	}

	public function decide( int $id, string $decision, int $actor_id ): bool {
		global $wpdb;
		$decision = $decision === 'approve' ? 'approved' : 'denied';
		return (bool) $wpdb->update(
			$this->table( 'g2a_ai_pending_actions' ),
			array(
				'status'     => $decision,
				'decided_by' => $actor_id,
				'decided_at' => $this->now(),
			),
			array(
				'id'     => $id,
				'status' => 'pending',
			)
		);
	}

	public function pending_for_user( int $user_id, int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_ai_pending_actions')}
             WHERE status = 'pending' AND requested_by = %d
             AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY id DESC LIMIT %d",
				$user_id,
				max( 1, min( 50, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
