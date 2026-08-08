<?php

namespace G2A\POS\Database;

final class MessagingRepository extends Repository {

	public function queue( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_messaging_outbox' ),
			array(
				'channel'             => sanitize_key( $data['channel'] ?? 'email' ),
				'template_key'        => sanitize_text_field( $data['template_key'] ?? '' ),
				'to_address'          => sanitize_text_field( $data['to_address'] ?? '' ),
				'from_address'        => sanitize_text_field( $data['from_address'] ?? '' ),
				'subject'             => sanitize_text_field( $data['subject'] ?? '' ),
				'body'                => (string) ( $data['body'] ?? '' ),
				'related_entity_type' => sanitize_key( $data['related_entity_type'] ?? '' ),
				'related_entity_id'   => isset( $data['related_entity_id'] ) ? (int) $data['related_entity_id'] : null,
				'status'              => 'queued',
				'scheduled_at'        => $data['scheduled_at'] ?? $now,
				'actor_id'            => get_current_user_id() ?: null,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function mark_sent( int $id, ?string $provider_message_id = null, ?string $provider = null ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_messaging_outbox' ),
			array(
				'status'              => 'sent',
				'sent_at'             => $this->now(),
				'provider_message_id' => $provider_message_id,
				'provider'            => $provider,
				'updated_at'          => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function mark_failed( int $id, string $error ): bool {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table('g2a_messaging_outbox')}
             SET attempts = attempts + 1, status = 'failed', last_error = %s, updated_at = %s
             WHERE id = %d",
				$error,
				$this->now(),
				$id
			)
		);
		return true;
	}

	public function due_now( int $limit = 25 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_messaging_outbox')}
             WHERE status IN ('queued','failed') AND scheduled_at <= %s AND attempts < 5
             ORDER BY scheduled_at ASC LIMIT %d",
				$this->now(),
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public function recent( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, channel, template_key, to_address, subject, status, attempts,
                    scheduled_at, sent_at, last_error, created_at
             FROM {$this->table('g2a_messaging_outbox')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	public function upsert_template( array $data ): int {
		global $wpdb;
		$key     = sanitize_text_field( $data['template_key'] ?? '' );
		$channel = sanitize_key( $data['channel'] ?? 'email' );
		if ( ! $key ) {
			return 0;
		}
		$now      = $this->now();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_messaging_templates')}
             WHERE template_key = %s AND channel = %s",
				$key,
				$channel
			)
		);
		$payload  = array(
			'template_key' => $key,
			'channel'      => $channel,
			'subject'      => sanitize_text_field( $data['subject'] ?? '' ),
			'body'         => (string) ( $data['body'] ?? '' ),
			'enabled'      => ! empty( $data['enabled'] ) ? 1 : 0,
			'updated_by'   => get_current_user_id() ?: null,
			'updated_at'   => $now,
		);
		if ( $existing ) {
			$wpdb->update( $this->table( 'g2a_messaging_templates' ), $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $this->table( 'g2a_messaging_templates' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public function templates(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_messaging_templates')} ORDER BY template_key, channel",
			ARRAY_A
		) ?: array();
	}

	public function find_template( string $key, string $channel ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_messaging_templates')}
             WHERE template_key = %s AND channel = %s AND enabled = 1",
				sanitize_text_field( $key ),
				sanitize_key( $channel )
			),
			ARRAY_A
		);
		return $row ?: null;
	}
}
