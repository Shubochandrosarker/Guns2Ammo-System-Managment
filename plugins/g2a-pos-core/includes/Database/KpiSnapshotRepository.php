<?php

namespace G2A\POS\Database;

final class KpiSnapshotRepository extends Repository {

	public function put( string $date, int $location_id, string $metric_key, float $value, array $meta = array() ): void {
		global $wpdb;
		$now      = $this->now();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_kpi_snapshots')}
             WHERE snapshot_date = %s AND location_id = %d AND metric_key = %s",
				$date,
				$location_id,
				$metric_key
			)
		);
		$payload  = array(
			'snapshot_date' => $date,
			'location_id'   => $location_id,
			'metric_key'    => sanitize_text_field( $metric_key ),
			'metric_value'  => $value,
			'metric_meta'   => $meta ? wp_json_encode( $meta ) : null,
			'created_at'    => $now,
		);
		if ( $existing ) {
			$wpdb->update( $this->table( 'g2a_kpi_snapshots' ), $payload, array( 'id' => (int) $existing ) );
		} else {
			$wpdb->insert( $this->table( 'g2a_kpi_snapshots' ), $payload );
		}
	}

	public function range( string $from, string $to, ?string $metric_key = null ): array {
		global $wpdb;
		$t = $this->table( 'g2a_kpi_snapshots' );
		if ( $metric_key ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE snapshot_date BETWEEN %s AND %s AND metric_key = %s ORDER BY snapshot_date",
					$from,
					$to,
					$metric_key
				),
				ARRAY_A
			) ?: array();
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE snapshot_date BETWEEN %s AND %s ORDER BY snapshot_date, metric_key",
				$from,
				$to
			),
			ARRAY_A
		) ?: array();
	}
}
