<?php

namespace G2A\POS\Database;

final class LaneMaintenanceRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_lane_maintenance' ),
			array(
				'ticket_number'       => $data['ticket_number'] ?? $this->generate_number(),
				'lane_code'           => sanitize_text_field( $data['lane_code'] ?? '' ),
				'location_id'         => (int) ( $data['location_id'] ?? 1 ),
				'ticket_type'         => sanitize_key( $data['ticket_type'] ?? 'cleaning' ),
				'severity'            => sanitize_key( $data['severity'] ?? 'normal' ),
				'status'              => 'open',
				'description'         => sanitize_textarea_field( $data['description'] ?? '' ),
				'equipment_failed'    => sanitize_text_field( $data['equipment_failed'] ?? '' ),
				'lane_out_of_service' => ! empty( $data['lane_out_of_service'] ) ? 1 : 0,
				'opened_by'           => get_current_user_id(),
				'opened_at'           => $now,
				'notes'               => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array(
			'status',
			'severity',
			'description',
			'equipment_failed',
			'parts_used',
			'labor_minutes',
			'cost_amount',
			'lane_out_of_service',
			'assigned_to',
			'in_progress_at',
			'closed_at',
			'closed_by',
			'notes',
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
		return (bool) $wpdb->update( $this->table( 'g2a_lane_maintenance' ), $set, array( 'id' => $id ) );
	}

	public function start( int $id ): bool {
		return $this->update(
			$id,
			array(
				'status'         => 'in_progress',
				'in_progress_at' => $this->now(),
			)
		);
	}

	public function close( int $id, ?int $labor_minutes = null, ?float $cost = null, ?array $parts = null, ?string $notes = null ): bool {
		return $this->update(
			$id,
			array(
				'status'              => 'done',
				'closed_at'           => $this->now(),
				'closed_by'           => get_current_user_id(),
				'labor_minutes'       => $labor_minutes,
				'cost_amount'         => $cost,
				'parts_used'          => $parts,
				'notes'               => $notes,
				'lane_out_of_service' => 0,
			)
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_lane_maintenance')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( $row && ! empty( $row['parts_used'] ) ) {
			$row['parts_used'] = json_decode( (string) $row['parts_used'], true );
		}
		return $row ?: null;
	}

	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_lane_maintenance' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		if ( ! empty( $filters['lane_code'] ) ) {
			$where[] = 'lane_code = %s';
			$args[]  = sanitize_text_field( $filters['lane_code'] );
		}
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function lanes_out_of_service( int $location_id = 0 ): array {
		global $wpdb;
		$t = $this->table( 'g2a_lane_maintenance' );
		if ( $location_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					"SELECT lane_code, ticket_number, ticket_type, opened_at
                 FROM {$t} WHERE lane_out_of_service = 1 AND status != 'done' AND location_id = %d
                 ORDER BY opened_at ASC",
					$location_id
				),
				ARRAY_A
			) ?: array();
		}
		return $wpdb->get_results(
			"SELECT lane_code, ticket_number, ticket_type, opened_at
             FROM {$t} WHERE lane_out_of_service = 1 AND status != 'done' ORDER BY opened_at ASC",
			ARRAY_A
		) ?: array();
	}

	private function generate_number(): string {
		return 'LM-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
	}
}
