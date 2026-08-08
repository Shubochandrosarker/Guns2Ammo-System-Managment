<?php

namespace G2A\POS\Database;

/**
 * Serialized inventory movement between locations. On `ship()` we write a
 * bound-book disposition entry against the source location; on `receive()`
 * we write an acquisition entry against the destination, linking both via
 * the transfer item record so an ACE audit can see the chain.
 */
final class LocationTransferRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_location_transfers' ),
			array(
				'transfer_number'  => $data['transfer_number'] ?? $this->generate_number(),
				'from_location_id' => (int) ( $data['from_location_id'] ?? 0 ),
				'to_location_id'   => (int) ( $data['to_location_id'] ?? 0 ),
				'status'           => 'pending',
				'carrier'          => sanitize_text_field( $data['carrier'] ?? '' ),
				'tracking_number'  => sanitize_text_field( $data['tracking_number'] ?? '' ),
				'actor_id'         => get_current_user_id(),
				'notes'            => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
		$transfer_id = (int) $wpdb->insert_id;

		foreach ( ( $data['items'] ?? array() ) as $i ) {
			$wpdb->insert(
				$this->table( 'g2a_location_transfer_items' ),
				array(
					'transfer_id'   => $transfer_id,
					'product_id'    => isset( $i['product_id'] ) ? (int) $i['product_id'] : null,
					'sku'           => sanitize_text_field( $i['sku'] ?? '' ),
					'serial_number' => sanitize_text_field( $i['serial_number'] ?? '' ),
					'description'   => sanitize_text_field( $i['description'] ?? '' ),
					'quantity'      => (float) ( $i['quantity'] ?? 1 ),
					'created_at'    => $now,
				)
			);
		}
		return $transfer_id;
	}

	public function ship( int $transfer_id, int $actor_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_location_transfers' ),
			array(
				'status'     => 'in_transit',
				'shipped_at' => $this->now(),
				'shipped_by' => $actor_id,
				'updated_at' => $this->now(),
			),
			array(
				'id'     => $transfer_id,
				'status' => 'pending',
			)
		);
	}

	public function receive( int $transfer_id, int $actor_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_location_transfers' ),
			array(
				'status'      => 'received',
				'received_at' => $this->now(),
				'received_by' => $actor_id,
				'updated_at'  => $this->now(),
			),
			array(
				'id'     => $transfer_id,
				'status' => 'in_transit',
			)
		);
	}

	public function attach_bound_book( int $item_id, ?int $debit_id, ?int $credit_id ): void {
		global $wpdb;
		$set = array();
		if ( $debit_id !== null ) {
			$set['bound_book_debit_id'] = $debit_id;
		}
		if ( $credit_id !== null ) {
			$set['bound_book_credit_id'] = $credit_id;
		}
		if ( ! $set ) {
			return;
		}
		$wpdb->update( $this->table( 'g2a_location_transfer_items' ), $set, array( 'id' => $item_id ) );
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_location_transfers')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['items'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_location_transfer_items')} WHERE transfer_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_location_transfers')} ORDER BY id DESC LIMIT 200",
			ARRAY_A
		) ?: array();
	}

	private function generate_number(): string {
		return 'TX-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
	}
}
