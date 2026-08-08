<?php

namespace G2A\POS\Database;

/**
 * Gift card store. Only the hash of the code is unique-indexed for
 * scan/lookup; the cleartext code is stored separately so the merchant
 * can still print it on a receipt, but lookups never depend on it.
 */
final class GiftCardRepository extends Repository {

	public function issue( array $data ): array {
		global $wpdb;
		$code  = sanitize_text_field( $data['code'] ?? $this->generate_code() );
		$hash  = hash( 'sha256', $code );
		$cents = (int) round( ( (float) ( $data['amount'] ?? 0 ) ) * 100 );
		if ( $cents <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_amount',
			);
		}
		$now = $this->now();
		$ok  = $wpdb->insert(
			$this->table( 'g2a_gift_cards' ),
			array(
				'code'                  => $code,
				'code_hash'             => $hash,
				'initial_balance_cents' => $cents,
				'balance_cents'         => $cents,
				'currency'              => sanitize_text_field( $data['currency'] ?? 'USD' ),
				'status'                => 'active',
				'customer_id'           => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'issued_by'             => get_current_user_id(),
				'issued_at'             => $now,
				'expires_at'            => $data['expires_at'] ?? null,
				'notes'                 => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'            => $now,
				'updated_at'            => $now,
			)
		);
		if ( $ok === false ) {
			return array(
				'ok'    => false,
				'error' => 'duplicate_code',
			);
		}
		$card_id = (int) $wpdb->insert_id;
		$this->record_tx( $card_id, 'issue', $cents, $cents, $data['pos_order_id'] ?? null );
		return array(
			'ok'            => true,
			'id'            => $card_id,
			'code'          => $code,
			'balance_cents' => $cents,
		);
	}

	public function find_by_code( string $code ): ?array {
		global $wpdb;
		$hash = hash( 'sha256', sanitize_text_field( $code ) );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_gift_cards')} WHERE code_hash = %s",
				$hash
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function redeem( string $code, int $cents, ?int $pos_order_id = null ): array {
		global $wpdb;
		$hash = hash( 'sha256', sanitize_text_field( $code ) );
		$now  = $this->now();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, balance_cents, status, expires_at FROM {$this->table('g2a_gift_cards')}
                 WHERE code_hash = %s FOR UPDATE",
					$hash
				),
				ARRAY_A
			);
			if ( ! $row ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'not_found',
				);
			}
			if ( $row['status'] !== 'active' ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'card_not_active',
				);
			}
			if ( ! empty( $row['expires_at'] ) && strtotime( $row['expires_at'] ) < time() ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'card_expired',
				);
			}
			$bal   = (int) $row['balance_cents'];
			$apply = min( $cents, $bal );
			if ( $apply <= 0 ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'zero_balance',
				);
			}
			$new_bal = $bal - $apply;
			$wpdb->update(
				$this->table( 'g2a_gift_cards' ),
				array(
					'balance_cents' => $new_bal,
					'last_used_at'  => $now,
					'status'        => $new_bal === 0 ? 'depleted' : 'active',
					'updated_at'    => $now,
				),
				array( 'id' => (int) $row['id'] )
			);
			$this->record_tx( (int) $row['id'], 'redeem', -$apply, $new_bal, $pos_order_id );
			$wpdb->query( 'COMMIT' );
			return array(
				'ok'            => true,
				'gift_card_id'  => (int) $row['id'],
				'applied_cents' => $apply,
				'balance_cents' => $new_bal,
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	/**
	 * Put value back on a card (voided/reversed tender). Uses the same
	 * locked transaction as redeem() and appends to the transaction ledger,
	 * so the reversal is atomic and auditable. Re-activates a depleted card.
	 */
	public function credit( int $card_id, int $cents, ?int $pos_order_id = null, string $tx_type = 'void_refund' ): array {
		global $wpdb;
		if ( $card_id <= 0 || $cents <= 0 ) {
			return array(
				'ok'    => false,
				'error' => 'invalid_credit',
			);
		}
		$now = $this->now();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, balance_cents, status FROM {$this->table('g2a_gift_cards')}
                 WHERE id = %d FOR UPDATE",
					$card_id
				),
				ARRAY_A
			);
			if ( ! $row ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'not_found',
				);
			}
			if ( ! in_array( $row['status'], array( 'active', 'depleted' ), true ) ) {
				$wpdb->query( 'ROLLBACK' );
				return array(
					'ok'    => false,
					'error' => 'card_not_creditable',
				);
			}
			$new_bal = (int) $row['balance_cents'] + $cents;
			$wpdb->update(
				$this->table( 'g2a_gift_cards' ),
				array(
					'balance_cents' => $new_bal,
					'status'        => 'active',
					'updated_at'    => $now,
				),
				array( 'id' => (int) $row['id'] )
			);
			$this->record_tx( (int) $row['id'], $tx_type, $cents, $new_bal, $pos_order_id );
			$wpdb->query( 'COMMIT' );
			return array(
				'ok'            => true,
				'gift_card_id'  => (int) $row['id'],
				'balance_cents' => $new_bal,
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}
	}

	public function list( int $limit = 100 ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, code, balance_cents, initial_balance_cents, currency, status,
                    customer_id, issued_at, expires_at, last_used_at
             FROM {$this->table('g2a_gift_cards')} ORDER BY id DESC LIMIT %d",
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
	}

	private function record_tx( int $card_id, string $type, int $delta_cents, int $balance_after, ?int $pos_order_id ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_gift_card_transactions' ),
			array(
				'gift_card_id'        => $card_id,
				'tx_type'             => sanitize_key( $type ),
				'delta_cents'         => $delta_cents,
				'balance_after_cents' => $balance_after,
				'pos_order_id'        => $pos_order_id,
				'actor_id'            => get_current_user_id() ?: null,
				'created_at'          => $this->now(),
			)
		);
	}

	private function generate_code(): string {
		// Four 4-char groups, ambiguous chars stripped.
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code     = '';
		for ( $g = 0; $g < 4; $g++ ) {
			for ( $i = 0; $i < 4; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			if ( $g < 3 ) {
				$code .= '-';
			}
		}
		return $code;
	}
}
