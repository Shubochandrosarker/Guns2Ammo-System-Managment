<?php

namespace G2A\POS\Database;

final class WholesalerSyncRepository extends Repository {

	public function start( int $wholesalerId, string $syncType, string $source, ?string $fileLabel ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_wholesaler_sync_runs' ),
			array(
				'wholesaler_id' => $wholesalerId,
				'sync_type'     => $syncType,
				'source'        => $source,
				'file_label'    => $fileLabel,
				'status'        => 'running',
				'started_at'    => $this->now(),
				'actor_id'      => get_current_user_id(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function update_progress( int $id, array $stats ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_wholesaler_sync_runs' ),
			array(
				'rows_total'   => (int) ( $stats['rows_total'] ?? 0 ),
				'rows_created' => (int) ( $stats['rows_created'] ?? 0 ),
				'rows_updated' => (int) ( $stats['rows_updated'] ?? 0 ),
				'rows_skipped' => (int) ( $stats['rows_skipped'] ?? 0 ),
				'rows_failed'  => (int) ( $stats['rows_failed'] ?? 0 ),
			),
			array( 'id' => $id )
		);
	}

	public function finish( int $id, string $status, array $stats, ?string $error = null ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_wholesaler_sync_runs' ),
			array(
				'status'        => $status,
				'rows_total'    => (int) ( $stats['rows_total'] ?? 0 ),
				'rows_created'  => (int) ( $stats['rows_created'] ?? 0 ),
				'rows_updated'  => (int) ( $stats['rows_updated'] ?? 0 ),
				'rows_skipped'  => (int) ( $stats['rows_skipped'] ?? 0 ),
				'rows_failed'   => (int) ( $stats['rows_failed'] ?? 0 ),
				'summary'       => wp_json_encode( $stats ),
				'error_message' => $error,
				'finished_at'   => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function listRecent( int $wholesalerId, int $limit = 25 ): array {
		global $wpdb;
		$t = $this->table( 'g2a_wholesaler_sync_runs' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE wholesaler_id=%d ORDER BY id DESC LIMIT %d",
				$wholesalerId,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
