<?php

namespace G2A\POS\Database;

/**
 * Trade-in firearm intake. Auto-issues store credit (via LoyaltyRepository)
 * when accepted, writes an acquisition entry in the bound book (caller
 * passes the bound_book_id back), and lets the credit be applied across
 * one or more later orders (credit_applied_amount).
 */
final class TradeInRepository extends Repository {

	public function intake( array $data ): array {
		global $wpdb;
		$now       = $this->now();
		$appraisal = (float) ( $data['appraisal_amount'] ?? 0 );
		$credit    = (float) ( $data['credit_amount'] ?? $appraisal );

		$wpdb->insert(
			$this->table( 'g2a_tradeins' ),
			array(
				'tradein_number'     => $data['tradein_number'] ?? $this->generate_number(),
				'customer_id'        => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'customer_name'      => sanitize_text_field( $data['customer_name'] ?? '' ),
				'customer_id_type'   => sanitize_text_field( $data['customer_id_type'] ?? '' ),
				'customer_id_number' => sanitize_text_field( $data['customer_id_number'] ?? '' ),
				'manufacturer'       => sanitize_text_field( $data['manufacturer'] ?? '' ),
				'model'              => sanitize_text_field( $data['model'] ?? '' ),
				'serial_number'      => sanitize_text_field( $data['serial_number'] ?? '' ),
				'firearm_type'       => sanitize_text_field( $data['firearm_type'] ?? '' ),
				'caliber'            => sanitize_text_field( $data['caliber'] ?? '' ),
				'condition_grade'    => sanitize_text_field( $data['condition_grade'] ?? '' ),
				'appraisal_amount'   => $appraisal,
				'credit_amount'      => $credit,
				'status'             => 'received',
				'received_by'        => get_current_user_id(),
				'received_at'        => $now,
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		$tradein_id = (int) $wpdb->insert_id;

		$tx_id = null;
		if ( ! empty( $data['customer_id'] ) && $credit > 0 ) {
			$result = ( new LoyaltyRepository() )->adjust(
				(int) $data['customer_id'],
				'tradein_credit',
				0,
				(int) round( $credit * 100 ),
				array( 'reason' => 'Trade-in ' . $data['manufacturer'] . ' ' . $data['model'] )
			);
			if ( ! empty( $result['ok'] ) ) {
				$tx_id = (int) ( $wpdb->get_var(
					"SELECT id FROM {$this->table('g2a_loyalty_transactions')} ORDER BY id DESC LIMIT 1"
				) );
				$wpdb->update(
					$this->table( 'g2a_tradeins' ),
					array(
						'store_credit_tx_id' => $tx_id,
						'updated_at'         => $now,
					),
					array( 'id' => $tradein_id )
				);
			}
		}
		return array(
			'id'                 => $tradein_id,
			'credit_amount'      => $credit,
			'store_credit_tx_id' => $tx_id,
		);
	}

	public function attach_bound_book( int $tradein_id, int $bound_book_id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_tradeins' ),
			array(
				'bound_book_id' => $bound_book_id,
				'updated_at'    => $this->now(),
			),
			array( 'id' => $tradein_id )
		);
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_tradeins')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function list(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_tradeins')} ORDER BY id DESC LIMIT 200",
			ARRAY_A
		) ?: array();
	}

	private function generate_number(): string {
		return 'TI-' . date( 'Ymd' ) . '-' . strtoupper( wp_generate_password( 4, false, false ) );
	}
}
