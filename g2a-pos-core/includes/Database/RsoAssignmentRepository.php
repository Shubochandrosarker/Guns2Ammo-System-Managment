<?php

namespace G2A\POS\Database;

final class RsoAssignmentRepository extends Repository {

	public function assign( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_rso_assignments' ),
			array(
				'user_id'     => (int) ( $data['user_id'] ?? 0 ),
				'location_id' => (int) ( $data['location_id'] ?? 1 ),
				'shift_start' => sanitize_text_field( $data['shift_start'] ?? $now ),
				'shift_end'   => sanitize_text_field( $data['shift_end'] ?? $now ),
				'status'      => 'scheduled',
				'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function update_status( int $id, string $status ): bool {
		global $wpdb;
		$set = array(
			'status'     => sanitize_key( $status ),
			'updated_at' => $this->now(),
		);
		if ( $status === 'on_duty' ) {
			$set['checked_in_at'] = $this->now();
		}
		if ( $status === 'off_duty' ) {
			$set['checked_out_at'] = $this->now();
		}
		return (bool) $wpdb->update( $this->table( 'g2a_rso_assignments' ), $set, array( 'id' => $id ) );
	}

	public function on_duty( int $location_id = 0, ?\DateTimeInterface $at = null ): array {
		global $wpdb;
		$at = ( $at ?? new \DateTimeImmutable( 'now' ) )->format( 'Y-m-d H:i:s' );
		$t  = $this->table( 'g2a_rso_assignments' );
		if ( $location_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE location_id = %d AND shift_start <= %s AND shift_end >= %s ORDER BY shift_start ASC",
					$location_id,
					$at,
					$at
				),
				ARRAY_A
			) ?: array();
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE shift_start <= %s AND shift_end >= %s ORDER BY shift_start ASC",
				$at,
				$at
			),
			ARRAY_A
		) ?: array();
	}

	public function list_for_day( string $date ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_rso_assignments')}
             WHERE shift_start BETWEEN %s AND %s ORDER BY shift_start",
				$date . ' 00:00:00',
				$date . ' 23:59:59'
			),
			ARRAY_A
		) ?: array();
	}
}
