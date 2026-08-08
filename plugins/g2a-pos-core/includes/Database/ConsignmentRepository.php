<?php

namespace G2A\POS\Database;

final class ConsignmentRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_consignments' ),
			array(
				'consignment_number'   => $data['consignment_number'] ?? $this->generate_number(),
				'consignor_name'       => sanitize_text_field( $data['consignor_name'] ?? '' ),
				'consignor_id_type'    => sanitize_text_field( $data['consignor_id_type'] ?? '' ),
				'consignor_id_number'  => sanitize_text_field( $data['consignor_id_number'] ?? '' ),
				'consignor_phone'      => sanitize_text_field( $data['consignor_phone'] ?? '' ),
				'consignor_email'      => sanitize_email( $data['consignor_email'] ?? '' ),
				'consignor_address'    => sanitize_text_field( $data['consignor_address'] ?? '' ),
				'firearm_make'         => sanitize_text_field( $data['firearm_make'] ?? '' ),
				'firearm_model'        => sanitize_text_field( $data['firearm_model'] ?? '' ),
				'firearm_serial'       => sanitize_text_field( $data['firearm_serial'] ?? '' ),
				'firearm_type'         => sanitize_text_field( $data['firearm_type'] ?? '' ),
				'firearm_caliber'      => sanitize_text_field( $data['firearm_caliber'] ?? '' ),
				'condition_notes'      => sanitize_textarea_field( $data['condition_notes'] ?? '' ),
				'asking_price'         => (float) ( $data['asking_price'] ?? 0 ),
				'min_acceptable_price' => isset( $data['min_acceptable_price'] ) ? (float) $data['min_acceptable_price'] : null,
				'commission_percent'   => (float) ( $data['commission_percent'] ?? 20.0 ),
				'status'               => sanitize_key( $data['status'] ?? 'received' ),
				'received_at'          => $now,
				'actor_id'             => get_current_user_id(),
				'location_id'          => (int) ( $data['location_id'] ?? 1 ),
				'notes'                => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'           => $now,
				'updated_at'           => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function mark_sold( int $id, float $sold_price, ?int $pos_order_id = null ): array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT commission_percent FROM {$this->table('g2a_consignments')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return array();
		}
		$commission = $sold_price * ( (float) $row['commission_percent'] / 100.0 );
		$payout     = $sold_price - $commission;
		$wpdb->update(
			$this->table( 'g2a_consignments' ),
			array(
				'sold_price'    => $sold_price,
				'sold_at'       => $this->now(),
				'payout_amount' => $payout,
				'pos_order_id'  => $pos_order_id,
				'status'        => 'sold',
				'updated_at'    => $this->now(),
			),
			array( 'id' => $id )
		);
		return array(
			'sold_price' => $sold_price,
			'commission' => $commission,
			'payout'     => $payout,
		);
	}

	public function record_payout( int $id, string $reference ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_consignments' ),
			array(
				'payout_paid_at'   => $this->now(),
				'payout_reference' => sanitize_text_field( $reference ),
				'status'           => 'paid_out',
				'updated_at'       => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function list( array $filters = array(), int $limit = 100 ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_consignments' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, min( 500, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_consignments')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	private function generate_number(): string {
		return 'CON-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 5, false, false ) );
	}
}
