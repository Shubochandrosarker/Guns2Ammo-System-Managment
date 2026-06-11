<?php

namespace G2A\POS\Database;

/**
 * Append-only ledger of tender lines per POS order. Supports split tender —
 * one order can have N rows (cash + card, store credit + card + gift card,
 * trade-in credit + cash, etc.).
 *
 * `captured` is the active state. `voided` zeroes out the row (line cancelled
 * before the order closed). `refunded` / `partially_refunded` represent
 * post-completion returns; `refunded_amount` carries the running total.
 */
final class TenderRepository extends Repository {

	public function add( int $order_id, array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_pos_tender_lines' ),
			array(
				'pos_order_id'        => $order_id,
				'tender_method'       => sanitize_key( $data['tender_method'] ?? 'cash' ),
				'amount'              => (float) ( $data['amount'] ?? 0 ),
				'change_due'          => (float) ( $data['change_due'] ?? 0 ),
				'reference'           => isset( $data['reference'] ) ? sanitize_text_field( (string) $data['reference'] ) : null,
				'external_ref'        => isset( $data['external_ref'] ) ? sanitize_text_field( (string) $data['external_ref'] ) : null,
				'status'              => sanitize_key( $data['status'] ?? 'captured' ),
				'register_session_id' => isset( $data['register_session_id'] ) ? (int) $data['register_session_id'] : null,
				'actor_id'            => (int) ( get_current_user_id() ?: ( $data['actor_id'] ?? 0 ) ),
				'captured_at'         => $data['captured_at'] ?? $now,
				'notes'               => isset( $data['notes'] ) ? sanitize_text_field( (string) $data['notes'] ) : null,
				'created_at'          => $now,
				'updated_at'          => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_pos_tender_lines')} WHERE id = %d",
				$id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function list_for_order( int $order_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_pos_tender_lines')}
             WHERE pos_order_id = %d ORDER BY id ASC",
				$order_id
			),
			ARRAY_A
		) ?: array();
	}

	public function void( int $id, ?string $reason = null ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_pos_tender_lines' ),
			array(
				'status'     => 'voided',
				'voided_at'  => $this->now(),
				'notes'      => $reason ? sanitize_text_field( $reason ) : null,
				'updated_at' => $this->now(),
			),
			array(
				'id'     => $id,
				'status' => 'captured',
			)
		);
	}

	public function refund( int $id, float $amount ): array {
		global $wpdb;
		$row = $this->find( $id );
		if ( ! $row ) {
			return array(
				'ok'    => false,
				'error' => 'not_found',
			);
		}
		if ( $row['status'] === 'voided' ) {
			return array(
				'ok'    => false,
				'error' => 'voided_line_cannot_refund',
			);
		}
		$captured  = (float) $row['amount'];
		$already   = (float) $row['refunded_amount'];
		$remaining = max( 0.0, $captured - $already );
		if ( $amount <= 0 || $amount > $remaining + 0.0001 ) {
			return array(
				'ok'        => false,
				'error'     => 'amount_out_of_range',
				'remaining' => $remaining,
			);
		}
		$new_refunded = $already + $amount;
		$new_status   = $new_refunded >= $captured - 0.0001 ? 'refunded' : 'partially_refunded';
		$wpdb->update(
			$this->table( 'g2a_pos_tender_lines' ),
			array(
				'refunded_amount' => $new_refunded,
				'status'          => $new_status,
				'updated_at'      => $this->now(),
			),
			array( 'id' => $id )
		);
		return array(
			'ok'              => true,
			'refunded_amount' => $new_refunded,
			'status'          => $new_status,
		);
	}

	/**
	 * Effective amount paid on an order =
	 *   SUM(amount) for rows where status IN (captured, partially_refunded, refunded)
	 *   - SUM(refunded_amount).
	 * Voided lines don't count at all.
	 */
	public function captured_total( int $order_id ): float {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount), 0) AS total,
                    COALESCE(SUM(refunded_amount), 0) AS refunded
             FROM {$this->table('g2a_pos_tender_lines')}
             WHERE pos_order_id = %d AND status IN ('captured','partially_refunded','refunded')",
				$order_id
			),
			ARRAY_A
		);
		return (float) ( $row['total'] ?? 0 ) - (float) ( $row['refunded'] ?? 0 );
	}
}
