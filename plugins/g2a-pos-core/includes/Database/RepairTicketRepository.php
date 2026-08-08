<?php

namespace G2A\POS\Database;

final class RepairTicketRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_repair_tickets' ),
			array(
				'ticket_number'       => $data['ticket_number'] ?? $this->generate_number(),
				'customer_id'         => $data['customer_id'] ?? null,
				'customer_name'       => sanitize_text_field( $data['customer_name'] ?? '' ),
				'customer_phone'      => sanitize_text_field( $data['customer_phone'] ?? '' ),
				'customer_email'      => sanitize_email( $data['customer_email'] ?? '' ),
				'firearm_make'        => sanitize_text_field( $data['firearm_make'] ?? '' ),
				'firearm_model'       => sanitize_text_field( $data['firearm_model'] ?? '' ),
				'firearm_serial'      => sanitize_text_field( $data['firearm_serial'] ?? '' ),
				'firearm_caliber'     => sanitize_text_field( $data['firearm_caliber'] ?? '' ),
				'firearm_type'        => sanitize_text_field( $data['firearm_type'] ?? '' ),
				'intake_photo_urls'   => ! empty( $data['intake_photo_urls'] ) ? wp_json_encode( $data['intake_photo_urls'] ) : null,
				'problem_description' => sanitize_textarea_field( $data['problem_description'] ?? '' ),
				'diagnosis'           => sanitize_textarea_field( $data['diagnosis'] ?? '' ),
				'estimate_amount'     => isset( $data['estimate_amount'] ) ? (float) $data['estimate_amount'] : null,
				'location_id'         => (int) ( $data['location_id'] ?? 1 ),
				'received_at'         => $data['received_at'] ?? $now,
				'promised_at'         => $data['promised_at'] ?? null,
				'assigned_to'         => isset( $data['assigned_to'] ) ? (int) $data['assigned_to'] : null,
				'status'              => sanitize_key( $data['status'] ?? 'intake' ),
				'actor_id'            => get_current_user_id(),
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
			'diagnosis',
			'estimate_amount',
			'estimate_approved',
			'estimate_approved_at',
			'status',
			'assigned_to',
			'promised_at',
			'completed_at',
			'returned_at',
			'parts_total',
			'labor_total',
			'grand_total',
			'notes',
			'signature_data',
		);
		$set     = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$set[ $k ] = $fields[ $k ];
			}
		}
		if ( ! $set ) {
			return false;
		}
		$set['updated_at'] = $this->now();
		return (bool) $wpdb->update( $this->table( 'g2a_repair_tickets' ), $set, array( 'id' => $id ) );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$t   = $this->table( 'g2a_repair_tickets' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['lines'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_repair_lines')} WHERE ticket_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_repair_tickets' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		if ( ! empty( $filters['q'] ) ) {
			$where[] = '(ticket_number LIKE %s OR customer_name LIKE %s OR firearm_serial LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $filters['q'] ) . '%';
			$args[]  = $like;
			$args[]  = $like;
			$args[]  = $like;
		}
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) .
				' ORDER BY id DESC LIMIT %d OFFSET %d';
		$args[] = max( 1, min( 200, $limit ) );
		$args[] = max( 0, $offset );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function add_line( int $ticket_id, array $line ): int {
		global $wpdb;
		$qty  = (float) ( $line['quantity'] ?? 1 );
		$unit = (float) ( $line['unit_price'] ?? 0 );
		$wpdb->insert(
			$this->table( 'g2a_repair_lines' ),
			array(
				'ticket_id'     => $ticket_id,
				'line_type'     => sanitize_key( $line['line_type'] ?? 'labor' ),
				'description'   => sanitize_text_field( $line['description'] ?? '' ),
				'product_id'    => isset( $line['product_id'] ) ? (int) $line['product_id'] : null,
				'quantity'      => $qty,
				'unit_price'    => $unit,
				'line_total'    => $qty * $unit,
				'technician_id' => isset( $line['technician_id'] ) ? (int) $line['technician_id'] : null,
				'performed_at'  => $line['performed_at'] ?? null,
				'created_at'    => $this->now(),
			)
		);
		$id = (int) $wpdb->insert_id;
		$this->recompute_totals( $ticket_id );
		return $id;
	}

	public function recompute_totals( int $ticket_id ): void {
		global $wpdb;
		$lines_t = $this->table( 'g2a_repair_lines' );
		$totals  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(CASE WHEN line_type='part' THEN line_total ELSE 0 END),0) AS parts,
                    COALESCE(SUM(CASE WHEN line_type='labor' THEN line_total ELSE 0 END),0) AS labor,
                    COALESCE(SUM(line_total),0) AS grand
             FROM {$lines_t} WHERE ticket_id = %d",
				$ticket_id
			),
			ARRAY_A
		);
		if ( ! $totals ) {
			return;
		}
		$wpdb->update(
			$this->table( 'g2a_repair_tickets' ),
			array(
				'parts_total' => (float) $totals['parts'],
				'labor_total' => (float) $totals['labor'],
				'grand_total' => (float) $totals['grand'],
				'updated_at'  => $this->now(),
			),
			array( 'id' => $ticket_id )
		);
	}

	private function generate_number(): string {
		return 'RT-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 5, false, false ) );
	}
}
