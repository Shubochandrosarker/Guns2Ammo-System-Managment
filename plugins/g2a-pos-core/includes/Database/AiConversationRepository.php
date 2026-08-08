<?php

namespace G2A\POS\Database;

final class AiConversationRepository extends Repository {

	public function create( int $user_id, array $opts = array() ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_ai_conversations' ),
			array(
				'user_id'             => $user_id,
				'channel'             => sanitize_key( $opts['channel'] ?? 'pos' ),
				'register_session_id' => isset( $opts['register_session_id'] ) ? (int) $opts['register_session_id'] : null,
				'title'               => sanitize_text_field( $opts['title'] ?? '' ),
				'status'              => 'active',
				'model_name'          => sanitize_text_field( $opts['model_name'] ?? '' ),
				'metadata'            => ! empty( $opts['metadata'] ) ? wp_json_encode( $opts['metadata'] ) : null,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_ai_conversations')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function list_for_user( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, channel, title, status, model_name, created_at, updated_at
             FROM {$this->table('g2a_ai_conversations')}
             WHERE user_id = %d ORDER BY id DESC LIMIT %d",
				$user_id,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	public function add_message( int $conversation_id, array $message ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_ai_messages' ),
			array(
				'conversation_id'   => $conversation_id,
				'role'              => sanitize_key( $message['role'] ?? 'user' ),
				'content'           => (string) ( $message['content'] ?? '' ),
				'tool_calls'        => ! empty( $message['tool_calls'] ) ? wp_json_encode( $message['tool_calls'] ) : null,
				'tool_results'      => ! empty( $message['tool_results'] ) ? wp_json_encode( $message['tool_results'] ) : null,
				'citations'         => ! empty( $message['citations'] ) ? wp_json_encode( $message['citations'] ) : null,
				'prompt_tokens'     => isset( $message['prompt_tokens'] ) ? (int) $message['prompt_tokens'] : null,
				'completion_tokens' => isset( $message['completion_tokens'] ) ? (int) $message['completion_tokens'] : null,
				'latency_ms'        => isset( $message['latency_ms'] ) ? (int) $message['latency_ms'] : null,
				'created_at'        => $now,
			)
		);
		$msg_id = (int) $wpdb->insert_id;
		$wpdb->update(
			$this->table( 'g2a_ai_conversations' ),
			array( 'updated_at' => $now ),
			array( 'id' => $conversation_id )
		);
		return $msg_id;
	}

	public function messages( int $conversation_id, int $limit = 100 ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_ai_messages')}
             WHERE conversation_id = %d ORDER BY id ASC LIMIT %d",
				$conversation_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
		foreach ( $rows as &$r ) {
			foreach ( array( 'tool_calls', 'tool_results', 'citations' ) as $f ) {
				if ( ! empty( $r[ $f ] ) ) {
					$r[ $f ] = json_decode( (string) $r[ $f ], true );
				}
			}
		}
		return $rows;
	}

	public function record_tool_call( int $conversation_id, ?int $message_id, array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_ai_tool_calls' ),
			array(
				'conversation_id'       => $conversation_id,
				'message_id'            => $message_id,
				'tool_name'             => sanitize_text_field( $data['tool_name'] ?? '' ),
				'arguments'             => ! empty( $data['arguments'] ) ? wp_json_encode( $data['arguments'] ) : null,
				'result'                => ! empty( $data['result'] ) ? wp_json_encode( $data['result'] ) : null,
				'status'                => sanitize_key( $data['status'] ?? 'pending' ),
				'requires_confirmation' => ! empty( $data['requires_confirmation'] ) ? 1 : 0,
				'latency_ms'            => isset( $data['latency_ms'] ) ? (int) $data['latency_ms'] : null,
				'error_message'         => $data['error_message'] ?? null,
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function update_tool_call( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array(
			'result',
			'status',
			'confirmed_by',
			'confirmed_at',
			'denied_at',
			'error_message',
			'latency_ms',
		);
		$set     = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$set[ $k ] = is_array( $fields[ $k ] ) ? wp_json_encode( $fields[ $k ] ) : $fields[ $k ];
			}
		}
		if ( ! $set ) {
			return false;
		}
		$set['updated_at'] = $this->now();
		return (bool) $wpdb->update( $this->table( 'g2a_ai_tool_calls' ), $set, array( 'id' => $id ) );
	}

	public function record_feedback( int $message_id, int $rating, ?string $comment, int $actor_id ): bool {
		global $wpdb;
		$now      = $this->now();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_ai_feedback')} WHERE message_id = %d AND actor_id = %d",
				$message_id,
				$actor_id
			)
		);
		$payload  = array(
			'message_id' => $message_id,
			'rating'     => max( -1, min( 1, $rating ) ),
			'comment'    => $comment ? sanitize_text_field( $comment ) : null,
			'actor_id'   => $actor_id,
		);
		if ( $existing ) {
			return (bool) $wpdb->update( $this->table( 'g2a_ai_feedback' ), $payload, array( 'id' => (int) $existing ) );
		}
		$payload['created_at'] = $now;
		return (bool) $wpdb->insert( $this->table( 'g2a_ai_feedback' ), $payload );
	}
}
