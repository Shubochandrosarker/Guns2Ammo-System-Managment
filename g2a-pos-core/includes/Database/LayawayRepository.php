<?php

namespace G2A\POS\Database;

final class LayawayRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now         = $this->now();
		$items       = $data['items'] ?? array();
		$grand_total = (float) ( $data['grand_total'] ?? array_sum(
			array_map(
				static function ( $i ) {
					return (float) ( $i['unit_price'] ?? 0 ) * (float) ( $i['quantity'] ?? 1 );
				},
				$items
			)
		) );
		$deposit     = (float) ( $data['deposit_amount'] ?? 0 );

		$wpdb->insert(
			$this->table( 'g2a_layaways' ),
			array(
				'layaway_number' => $data['layaway_number'] ?? $this->generate_number( $data['layaway_type'] ?? 'layaway' ),
				'layaway_type'   => sanitize_key( $data['layaway_type'] ?? 'layaway' ),
				'customer_id'    => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'customer_name'  => sanitize_text_field( $data['customer_name'] ?? '' ),
				'customer_phone' => sanitize_text_field( $data['customer_phone'] ?? '' ),
				'customer_email' => sanitize_email( $data['customer_email'] ?? '' ),
				'items'          => wp_json_encode( $items ),
				'grand_total'    => $grand_total,
				'deposit_amount' => $deposit,
				'paid_amount'    => $deposit,
				'balance_due'    => max( 0, $grand_total - $deposit ),
				'status'         => sanitize_key( $data['status'] ?? 'active' ),
				'opened_at'      => $now,
				'expires_at'     => $data['expires_at'] ?? null,
				'location_id'    => (int) ( $data['location_id'] ?? 1 ),
				'actor_id'       => get_current_user_id(),
				'notes'          => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'     => $now,
				'updated_at'     => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_layaways')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		$row['items']    = json_decode( (string) $row['items'], true ) ?: array();
		$row['payments'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_layaway_payments')} WHERE layaway_id = %d ORDER BY id ASC",
				$id
			),
			ARRAY_A
		) ?: array();
		return $row;
	}

	public function list( array $filters = array(), int $limit = 50 ): array {
		global $wpdb;
		$t     = $this->table( 'g2a_layaways' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['status'] ) ) {
			$where[] = 'status = %s';
			$args[]  = sanitize_key( $filters['status'] );
		}
		if ( ! empty( $filters['layaway_type'] ) ) {
			$where[] = 'layaway_type = %s';
			$args[]  = sanitize_key( $filters['layaway_type'] );
		}
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$args[] = max( 1, min( 200, $limit ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array();
	}

	public function record_payment( int $layaway_id, float $amount, string $method, array $opts = array() ): int {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT grand_total, paid_amount, status FROM {$this->table('g2a_layaways')} WHERE id = %d",
				$layaway_id
			),
			ARRAY_A
		);
		if ( ! $row || $row['status'] !== 'active' ) {
			return 0;
		}
		$new_paid = (float) $row['paid_amount'] + $amount;
		$balance  = max( 0, (float) $row['grand_total'] - $new_paid );
		$now      = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_layaway_payments' ),
			array(
				'layaway_id'          => $layaway_id,
				'amount'              => $amount,
				'payment_method'      => sanitize_text_field( $method ),
				'reference'           => sanitize_text_field( $opts['reference'] ?? '' ),
				'register_session_id' => isset( $opts['register_session_id'] ) ? (int) $opts['register_session_id'] : null,
				'actor_id'            => get_current_user_id(),
				'received_at'         => $now,
				'created_at'          => $now,
			)
		);
		$payment_id = (int) $wpdb->insert_id;

		$update = array(
			'paid_amount' => $new_paid,
			'balance_due' => $balance,
			'updated_at'  => $now,
		);
		if ( $balance <= 0.001 ) {
			$update['status']       = 'paid';
			$update['completed_at'] = $now;
		}
		$wpdb->update( $this->table( 'g2a_layaways' ), $update, array( 'id' => $layaway_id ) );
		return $payment_id;
	}

	public function cancel( int $layaway_id, string $reason, float $forfeited = 0.0 ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_layaways' ),
			array(
				'status'              => 'cancelled',
				'cancellation_reason' => sanitize_text_field( $reason ),
				'forfeited_amount'    => $forfeited,
				'cancelled_at'        => $this->now(),
				'updated_at'          => $this->now(),
			),
			array(
				'id'     => $layaway_id,
				'status' => 'active',
			)
		);
	}

	private function generate_number( string $type ): string {
		$prefix = $type === 'special_order' ? 'SO' : ( $type === 'consignment' ? 'CN' : 'LW' );
		return $prefix . '-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 5, false, false ) );
	}
}
