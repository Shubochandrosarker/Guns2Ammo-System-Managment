<?php

namespace G2A\POS\Database;

/**
 * Loyalty + store credit ledger.
 *
 * One row per customer in g2a_loyalty_accounts (running balance).
 * One row per movement in g2a_loyalty_transactions (append-only ledger).
 * Both balances mutate together inside a wpdb transaction.
 */
final class LoyaltyRepository extends Repository {

	public function balance( int $customer_id ): array {
		global $wpdb;
		if ( $customer_id <= 0 ) {
			return array(
				'points'       => 0,
				'credit_cents' => 0,
			);
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT balance_points, store_credit_cents, tier_code
             FROM {$this->table('g2a_loyalty_accounts')} WHERE customer_id = %d",
				$customer_id
			),
			ARRAY_A
		);
		return array(
			'points'       => $row ? (int) $row['balance_points'] : 0,
			'credit_cents' => $row ? (int) $row['store_credit_cents'] : 0,
			'tier'         => $row['tier_code'] ?? 'standard',
		);
	}

	public function adjust( int $customer_id, string $tx_type, int $delta_points, int $delta_credit_cents, array $opts = array() ): array {
		global $wpdb;
		if ( $customer_id <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_customer',
			);
		}
		$now    = $this->now();
		$acct_t = $this->table( 'g2a_loyalty_accounts' );
		$tx_t   = $this->table( 'g2a_loyalty_transactions' );

		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT balance_points, store_credit_cents, lifetime_points, lifetime_credit_earned_cents
                 FROM {$acct_t} WHERE customer_id = %d FOR UPDATE",
					$customer_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				$wpdb->insert(
					$acct_t,
					array(
						'customer_id'        => $customer_id,
						'balance_points'     => 0,
						'store_credit_cents' => 0,
						'tier_code'          => 'standard',
						'created_at'         => $now,
						'updated_at'         => $now,
					)
				);
				$row = array(
					'balance_points'               => 0,
					'store_credit_cents'           => 0,
					'lifetime_points'              => 0,
					'lifetime_credit_earned_cents' => 0,
				);
			}
			$new_points = (int) $row['balance_points'] + $delta_points;
			$new_credit = (int) $row['store_credit_cents'] + $delta_credit_cents;
			if ( $new_points < 0 || $new_credit < 0 ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'insufficient_balance',
				);
			}
			$lifetime_points = (int) $row['lifetime_points'] + max( 0, $delta_points );
			$lifetime_credit = (int) $row['lifetime_credit_earned_cents'] + max( 0, $delta_credit_cents );

			$wpdb->update(
				$acct_t,
				array(
					'balance_points'               => $new_points,
					'store_credit_cents'           => $new_credit,
					'lifetime_points'              => $lifetime_points,
					'lifetime_credit_earned_cents' => $lifetime_credit,
					'updated_at'                   => $now,
				),
				array( 'customer_id' => $customer_id )
			);

			$wpdb->insert(
				$tx_t,
				array(
					'customer_id'                => $customer_id,
					'tx_type'                    => sanitize_key( $tx_type ),
					'kind'                       => $delta_credit_cents !== 0 ? 'credit' : 'points',
					'delta_points'               => $delta_points,
					'delta_credit_cents'         => $delta_credit_cents,
					'balance_points_after'       => $new_points,
					'balance_credit_cents_after' => $new_credit,
					'pos_order_id'               => isset( $opts['pos_order_id'] ) ? (int) $opts['pos_order_id'] : null,
					'actor_id'                   => get_current_user_id() ?: null,
					'reason'                     => sanitize_text_field( $opts['reason'] ?? '' ),
					'created_at'                 => $now,
				)
			);

			$wpdb->query( 'COMMIT' );
			return array(
				'ok'           => true,
				'points'       => $new_points,
				'credit_cents' => $new_credit,
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	public function history( int $customer_id, int $limit = 50 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_loyalty_transactions')}
             WHERE customer_id = %d ORDER BY id DESC LIMIT %d",
				$customer_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}
}
