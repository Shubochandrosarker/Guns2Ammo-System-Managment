<?php

namespace G2A\POS\Database;

final class CycleCountRepository extends Repository {

	public function open( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_cycle_counts' ),
			array(
				'count_code'  => $data['count_code'] ?? $this->generate_code(),
				'location_id' => (int) ( $data['location_id'] ?? 1 ),
				'scope_type'  => sanitize_key( $data['scope_type'] ?? 'category' ),
				'scope_value' => sanitize_text_field( $data['scope_value'] ?? '' ),
				'count_type'  => sanitize_key( $data['count_type'] ?? 'cycle' ),
				'status'      => 'open',
				'opened_by'   => get_current_user_id(),
				'opened_at'   => $now,
				'items_total' => count( $data['items'] ?? array() ),
				'notes'       => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'  => $now,
				'updated_at'  => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		foreach ( ( $data['items'] ?? array() ) as $i ) {
			$wpdb->insert(
				$this->table( 'g2a_cycle_count_items' ),
				array(
					'count_id'      => $id,
					'product_id'    => (int) ( $i['product_id'] ?? 0 ),
					'sku'           => sanitize_text_field( $i['sku'] ?? '' ),
					'serial_number' => sanitize_text_field( $i['serial_number'] ?? '' ),
					'expected_qty'  => (float) ( $i['expected_qty'] ?? 0 ),
					'unit_cost'     => isset( $i['unit_cost'] ) ? (float) $i['unit_cost'] : null,
					'status'        => 'pending',
					'created_at'    => $now,
					'updated_at'    => $now,
				)
			);
		}
		return $id;
	}

	public function record( int $count_id, int $item_id, float $counted, ?string $notes = null ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT expected_qty, unit_cost FROM {$this->table('g2a_cycle_count_items')} WHERE id = %d AND count_id = %d",
				$item_id,
				$count_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		$variance       = $counted - (float) $row['expected_qty'];
		$variance_value = $variance * (float) ( $row['unit_cost'] ?? 0 );
		$now            = $this->now();
		$wpdb->update(
			$this->table( 'g2a_cycle_count_items' ),
			array(
				'counted_qty'    => $counted,
				'variance_qty'   => $variance,
				'variance_value' => $variance_value,
				'counted_by'     => get_current_user_id(),
				'counted_at'     => $now,
				'status'         => 'counted',
				'notes'          => $notes ? sanitize_text_field( $notes ) : null,
				'updated_at'     => $now,
			),
			array( 'id' => $item_id )
		);

		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(CASE WHEN status='counted' THEN 1 END) AS counted,
                    COALESCE(SUM(variance_qty),0) AS vq,
                    COALESCE(SUM(variance_value),0) AS vv
             FROM {$this->table('g2a_cycle_count_items')} WHERE count_id = %d",
				$count_id
			),
			ARRAY_A
		);
		$wpdb->update(
			$this->table( 'g2a_cycle_counts' ),
			array(
				'items_counted'  => (int) $totals['counted'],
				'variance_units' => (float) $totals['vq'],
				'variance_value' => (float) $totals['vv'],
				'updated_at'     => $now,
			),
			array( 'id' => $count_id )
		);

		return array(
			'ok'             => true,
			'variance_qty'   => $variance,
			'variance_value' => $variance_value,
		);
	}

	public function close( int $count_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_cycle_counts' ),
			array(
				'status'     => 'closed',
				'closed_by'  => get_current_user_id(),
				'closed_at'  => $this->now(),
				'updated_at' => $this->now(),
			),
			array( 'id' => $count_id )
		);
	}

	public function find( int $count_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_cycle_counts')} WHERE id = %d",
				$count_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['items'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_cycle_count_items')} WHERE count_id = %d ORDER BY id",
				$count_id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_cycle_counts')} ORDER BY id DESC LIMIT 100",
			ARRAY_A
		) ?: array();
	}

	private function generate_code(): string {
		return 'CC-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
	}
}
