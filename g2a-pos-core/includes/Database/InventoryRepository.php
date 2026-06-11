<?php

namespace G2A\POS\Database;

final class InventoryRepository extends Repository {

	public function log( array $row ): int {
		global $wpdb;

		$wpdb->insert(
			$this->table( 'g2a_inventory_logs' ),
			array(
				'product_id'     => (int) $row['product_id'],
				'variation_id'   => isset( $row['variation_id'] ) ? (int) $row['variation_id'] : null,
				'serial_number'  => $row['serial_number'] ?? null,
				'location_id'    => (int) $row['location_id'],
				'event_type'     => sanitize_key( $row['event_type'] ),
				'quantity_delta' => (float) $row['quantity_delta'],
				'before_qty'     => (float) $row['before_qty'],
				'after_qty'      => (float) $row['after_qty'],
				'source_ref'     => $row['source_ref'] ?? null,
				'actor_id'       => get_current_user_id(),
				'metadata'       => ! empty( $row['metadata'] ) ? wp_json_encode( $row['metadata'] ) : null,
				'created_at'     => $this->now(),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function has_event_source( string $source_ref, string $event_type ): bool {
		global $wpdb;

		$table = $this->table( 'g2a_inventory_logs' );
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_ref = %s AND event_type = %s",
				$source_ref,
				sanitize_key( $event_type )
			)
		);

		return $count > 0;
	}

	public function latest_for_product( int $product_id, int $limit = 20 ): array {
		global $wpdb;
		$table = $this->table( 'g2a_inventory_logs' );

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE product_id = %d ORDER BY id DESC LIMIT %d",
				$product_id,
				max( 1, min( 200, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
