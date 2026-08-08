<?php

namespace G2A\POS\Database;

final class ClassPaymentRepository extends Repository {

	public function record( int $enrollment_id, float $amount, string $method, ?string $reference = null ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_class_payments' ),
			array(
				'enrollment_id'  => $enrollment_id,
				'amount'         => $amount,
				'payment_method' => sanitize_text_field( $method ),
				'reference'      => $reference ? sanitize_text_field( $reference ) : null,
				'actor_id'       => get_current_user_id(),
				'received_at'    => $now,
				'created_at'     => $now,
			)
		);
		$pmt_id = (int) $wpdb->insert_id;

		// Update the enrollment paid_amount and amount_due.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT e.paid_amount, e.amount_due, c.price_amount
             FROM {$this->table('g2a_class_enrollments')} e
             JOIN {$this->table('g2a_class_sessions')} s ON s.id = e.session_id
             JOIN {$this->table('g2a_classes')} c ON c.id = s.class_id
             WHERE e.id = %d",
				$enrollment_id
			),
			ARRAY_A
		);
		if ( $row ) {
			$new_paid = (float) $row['paid_amount'] + $amount;
			$new_due  = max( 0, (float) $row['price_amount'] - $new_paid );
			$wpdb->update(
				$this->table( 'g2a_class_enrollments' ),
				array(
					'paid_amount' => $new_paid,
					'amount_due'  => $new_due,
					'updated_at'  => $now,
				),
				array( 'id' => $enrollment_id )
			);
		}
		return $pmt_id;
	}

	public function list_for_enrollment( int $enrollment_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_class_payments')} WHERE enrollment_id = %d ORDER BY id",
				$enrollment_id
			),
			ARRAY_A
		) ?: array();
	}
}
