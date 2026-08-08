<?php

namespace G2A\POS\Database;

/**
 * Brass buyback: customer brings spent brass, gets paid in cash or store
 * credit. When payout_method=store_credit we push a loyalty transaction
 * and link it via store_credit_tx_id.
 */
final class BrassBuybackRepository extends Repository {

	public function record( array $data ): array {
		global $wpdb;
		$now          = $this->now();
		$payout_cents = (int) round( ( (float) ( $data['payout'] ?? 0 ) ) * 100 );

		$store_credit_tx_id = null;
		$method             = sanitize_key( $data['payout_method'] ?? 'store_credit' );
		if ( $method === 'store_credit' && ! empty( $data['customer_id'] ) && $payout_cents > 0 ) {
			$result = ( new LoyaltyRepository() )->adjust(
				(int) $data['customer_id'],
				'brass_buyback',
				0,
				$payout_cents,
				array( 'reason' => 'Brass buyback ' . sanitize_text_field( $data['caliber'] ?? '' ) )
			);
			if ( ! empty( $result['ok'] ) ) {
				$store_credit_tx_id = (int) ( $wpdb->get_var(
					"SELECT id FROM {$this->table('g2a_loyalty_transactions')} ORDER BY id DESC LIMIT 1"
				) );
			}
		}

		$wpdb->insert(
			$this->table( 'g2a_brass_buybacks' ),
			array(
				'customer_name'        => sanitize_text_field( $data['customer_name'] ?? '' ),
				'customer_id'          => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'caliber'              => sanitize_text_field( $data['caliber'] ?? '' ),
				'weight_lbs'           => isset( $data['weight_lbs'] ) ? (float) $data['weight_lbs'] : null,
				'count_rounds'         => isset( $data['count_rounds'] ) ? (int) $data['count_rounds'] : null,
				'rate_per_lb_cents'    => isset( $data['rate_per_lb_cents'] ) ? (int) $data['rate_per_lb_cents'] : null,
				'rate_per_round_cents' => isset( $data['rate_per_round_cents'] ) ? (int) $data['rate_per_round_cents'] : null,
				'payout_cents'         => $payout_cents,
				'payout_method'        => $method,
				'store_credit_tx_id'   => $store_credit_tx_id,
				'received_by'          => get_current_user_id(),
				'received_at'          => $now,
				'created_at'           => $now,
			)
		);
		return array(
			'id'                 => (int) $wpdb->insert_id,
			'payout_cents'       => $payout_cents,
			'store_credit_tx_id' => $store_credit_tx_id,
		);
	}

	public function list( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_brass_buybacks')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
