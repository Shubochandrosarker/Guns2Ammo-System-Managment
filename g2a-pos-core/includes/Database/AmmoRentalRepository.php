<?php

namespace G2A\POS\Database;

final class AmmoRentalRepository extends Repository {

	public function issue( array $data ): int {
		global $wpdb;
		$rounds = max( 0, (int) ( $data['rounds_issued'] ?? 0 ) );
		$unit   = (int) ( $data['unit_price_cents'] ?? 0 );
		$now    = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_ammo_rentals' ),
			array(
				'reservation_id'      => isset( $data['reservation_id'] ) ? (int) $data['reservation_id'] : null,
				'customer_name'       => sanitize_text_field( $data['customer_name'] ?? '' ),
				'caliber'             => sanitize_text_field( $data['caliber'] ?? '' ),
				'rounds_issued'       => $rounds,
				'unit_price_cents'    => $unit,
				'total_charged_cents' => $rounds * $unit,
				'issued_by'           => get_current_user_id(),
				'issued_at'           => $now,
				'notes'               => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function close( int $id, int $rounds_returned, ?int $pos_order_id = null ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_ammo_rentals' ),
			array(
				'rounds_returned' => max( 0, $rounds_returned ),
				'pos_order_id'    => $pos_order_id,
				'closed_at'       => $this->now(),
				'updated_at'      => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function list_open(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_ammo_rentals')} WHERE closed_at IS NULL ORDER BY issued_at DESC",
			ARRAY_A
		) ?: array();
	}

	public function recent( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_ammo_rentals')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
