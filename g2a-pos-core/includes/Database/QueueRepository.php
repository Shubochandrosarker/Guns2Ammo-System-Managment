<?php

namespace G2A\POS\Database;

final class QueueRepository extends Repository {

	private const CLAIM_LOCK_NAME = 'g2a_pos_queue_claim_lock';

	public function enqueue( string $type, array $payload, int $delay_seconds = 0 ): int {
		global $wpdb;

		$run_after = gmdate( 'Y-m-d H:i:s', time() + max( 0, $delay_seconds ) );

		$wpdb->insert(
			$this->table( 'g2a_sync_queue' ),
			array(
				'queue_type'   => sanitize_key( $type ),
				'payload'      => wp_json_encode( $payload ),
				'status'       => 'pending',
				'attempts'     => 0,
				'max_attempts' => 5,
				'run_after'    => $run_after,
				'created_at'   => $this->now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function claim_batch( int $limit = 25 ): array {
		global $wpdb;

		$table     = $this->table( 'g2a_sync_queue' );
		$now       = gmdate( 'Y-m-d H:i:s' );
		$lock_name = self::CLAIM_LOCK_NAME;

		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 1)', $lock_name ) );
		if ( $locked !== 1 ) {
			return array();
		}

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = 'pending' AND run_after <= %s ORDER BY id ASC LIMIT %d",
					$now,
					$limit
				),
				ARRAY_A
			);

			if ( ! $rows ) {
				return array();
			}

			$ids = array_map( static fn( $r ) => (int) $r['id'], $rows );
			$ids = array_values( array_filter( $ids, static fn( int $id ) => $id > 0 ) );
			if ( $ids === array() ) {
				return array();
			}
			// IDs are validated integers above; placeholders are still used for the timestamp.
			$id_list = implode( ',', $ids );

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status='processing', locked_at=%s, attempts=attempts+1 WHERE status='pending' AND id IN ({$id_list})",
					$now
				)
			);

			return $wpdb->get_results(
				"SELECT * FROM {$table} WHERE status='processing' AND id IN ({$id_list}) ORDER BY id ASC",
				ARRAY_A
			) ?: array();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	public function mark_done( int $id ): void {
		global $wpdb;

		$wpdb->update(
			$this->table( 'g2a_sync_queue' ),
			array(
				'status'       => 'processed',
				'processed_at' => gmdate( 'Y-m-d H:i:s' ),
				'last_error'   => null,
			),
			array( 'id' => $id )
		);
	}

	public function mark_failed( int $id, string $error ): void {
		global $wpdb;

		$table = $this->table( 'g2a_sync_queue' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT attempts, max_attempts FROM {$table} WHERE id=%d", $id ), ARRAY_A );

		if ( ! $row ) {
			return;
		}

		$status = ( (int) $row['attempts'] >= (int) $row['max_attempts'] ) ? 'failed' : 'pending';

		$wpdb->update(
			$table,
			array(
				'status'     => $status,
				'last_error' => wp_trim_words( $error, 40, '' ),
				'run_after'  => gmdate( 'Y-m-d H:i:s', time() + 30 ),
			),
			array( 'id' => $id )
		);
	}
}
