<?php

namespace G2A\POS\Database;

final class RangeIncidentRepository extends Repository {

	public const SEVERITY = array( 'info', 'warning', 'serious', 'eject', 'banned' );

	public function create( array $data ): int {
		global $wpdb;
		$now      = $this->now();
		$severity = sanitize_key( $data['severity'] ?? 'warning' );
		if ( ! in_array( $severity, self::SEVERITY, true ) ) {
			$severity = 'warning';
		}
		$wpdb->insert(
			$this->table( 'g2a_range_incidents' ),
			array(
				'incident_number'              => $data['incident_number'] ?? $this->generate_number(),
				'location_id'                  => (int) ( $data['location_id'] ?? 1 ),
				'lane_code'                    => sanitize_text_field( $data['lane_code'] ?? '' ),
				'incident_type'                => sanitize_text_field( $data['incident_type'] ?? 'safety' ),
				'severity'                     => $severity,
				'description'                  => sanitize_textarea_field( $data['description'] ?? '' ),
				'occurred_at'                  => sanitize_text_field( $data['occurred_at'] ?? $now ),
				'rso_user_id'                  => isset( $data['rso_user_id'] ) ? (int) $data['rso_user_id'] : null,
				'rso_name'                     => sanitize_text_field( $data['rso_name'] ?? '' ),
				'witness_names'                => sanitize_textarea_field( $data['witness_names'] ?? '' ),
				'action_taken'                 => sanitize_text_field( $data['action_taken'] ?? '' ),
				'escalated_to_law_enforcement' => ! empty( $data['escalated_to_law_enforcement'] ) ? 1 : 0,
				'police_report_number'         => sanitize_text_field( $data['police_report_number'] ?? '' ),
				'attachments'                  => ! empty( $data['attachments'] ) ? wp_json_encode( $data['attachments'] ) : null,
				'status'                       => 'open',
				'reported_by'                  => get_current_user_id(),
				'notes'                        => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'                   => $now,
				'updated_at'                   => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function attach_customer( int $incident_id, array $data ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_range_incident_customers' ),
			array(
				'incident_id'     => $incident_id,
				'customer_id'     => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'customer_name'   => sanitize_text_field( $data['customer_name'] ?? '' ),
				'role'            => sanitize_key( $data['role'] ?? 'involved' ),
				'waiver_id'       => isset( $data['waiver_id'] ) ? (int) $data['waiver_id'] : null,
				'flag_kind'       => isset( $data['flag_kind'] ) ? sanitize_key( $data['flag_kind'] ) : null,
				'flag_applied_at' => ! empty( $data['flag_kind'] ) ? $this->now() : null,
				'notes'           => sanitize_text_field( $data['notes'] ?? '' ),
				'created_at'      => $this->now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function close( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_range_incidents' ),
			array(
				'status'     => 'closed',
				'closed_at'  => $this->now(),
				'closed_by'  => get_current_user_id(),
				'updated_at' => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function mark_customer_flag_applied( int $id ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_range_incidents' ),
			array(
				'customer_flag_applied' => 1,
				'updated_at'            => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_range_incidents')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		if ( ! empty( $row['attachments'] ) ) {
			$row['attachments'] = json_decode( (string) $row['attachments'], true );
		}
		$row['customers'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_range_incident_customers')} WHERE incident_id = %d ORDER BY id",
				$id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list( array $filters = array() ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_range_incidents' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['severity'] ) ) {
			$where[] = 'severity = %s';
			$args[]  = sanitize_key( $filters['severity'] );
		}
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		if ( ! empty( $filters['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
			$where[] = '(description LIKE %s OR incident_number LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		$limit  = max( 1, min( 500, (int) ( $filters['limit'] ?? 100 ) ) );
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY occurred_at DESC LIMIT %d';
		$args[] = $limit;
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function customer_flag_history( int $customer_id, int $limit = 20 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, i.incident_number, i.severity, i.description, i.occurred_at, i.status
             FROM {$this->table('g2a_range_incident_customers')} c
             JOIN {$this->table('g2a_range_incidents')} i ON i.id = c.incident_id
             WHERE c.customer_id = %d ORDER BY i.occurred_at DESC LIMIT %d",
				$customer_id,
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	private function generate_number(): string {
		return 'INC-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 5, false, false ) );
	}
}
