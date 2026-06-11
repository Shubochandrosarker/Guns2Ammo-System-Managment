<?php

namespace G2A\POS\Database;

final class DistributorSyncRunRepository extends Repository {

	public function open( int $distributor_id ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_distributor_sync_runs' ),
			array(
				'distributor_id' => $distributor_id,
				'started_at'     => $this->now(),
				'status'         => 'running',
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function close( int $id, array $stats, ?string $error_summary = null ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_distributor_sync_runs' ),
			array(
				'finished_at'   => $this->now(),
				'status'        => $error_summary ? 'failed' : 'success',
				'rows_seen'     => (int) ( $stats['rows_seen'] ?? 0 ),
				'rows_created'  => (int) ( $stats['rows_created'] ?? 0 ),
				'rows_updated'  => (int) ( $stats['rows_updated'] ?? 0 ),
				'rows_skipped'  => (int) ( $stats['rows_skipped'] ?? 0 ),
				'errors_count'  => (int) ( $stats['errors_count'] ?? 0 ),
				'error_summary' => $error_summary,
			),
			array( 'id' => $id )
		);
	}

	public function recent( int $distributor_id, int $limit = 20 ): array {
		global $wpdb;
		$t = $this->table( 'g2a_distributor_sync_runs' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE distributor_id = %d ORDER BY id DESC LIMIT %d",
				$distributor_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}
}
