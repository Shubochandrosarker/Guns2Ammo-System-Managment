<?php

namespace G2A\POS\Database;

final class ClassRepository extends Repository {

	public function upsert_class( array $data ): int {
		global $wpdb;
		$code = sanitize_text_field( $data['class_code'] ?? '' );
		if ( ! $code ) {
			return 0;
		}
		$now      = $this->now();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table('g2a_classes')} WHERE class_code = %s",
				$code
			)
		);
		$payload  = array(
			'class_code'           => $code,
			'title'                => sanitize_text_field( $data['title'] ?? $code ),
			'description'          => sanitize_textarea_field( $data['description'] ?? '' ),
			'instructor_name'      => sanitize_text_field( $data['instructor_name'] ?? '' ),
			'instructor_user_id'   => isset( $data['instructor_user_id'] ) ? (int) $data['instructor_user_id'] : null,
			'duration_minutes'     => (int) ( $data['duration_minutes'] ?? 120 ),
			'capacity'             => (int) ( $data['capacity'] ?? 12 ),
			'price_amount'         => (float) ( $data['price_amount'] ?? 0 ),
			'deposit_amount'       => (float) ( $data['deposit_amount'] ?? 0 ),
			'prerequisites'        => sanitize_text_field( $data['prerequisites'] ?? '' ),
			'requires_waiver'      => ! empty( $data['requires_waiver'] ) ? 1 : 0,
			'certificate_template' => sanitize_text_field( $data['certificate_template'] ?? '' ),
			'status'               => sanitize_key( $data['status'] ?? 'active' ),
			'updated_at'           => $now,
		);
		if ( $existing ) {
			$wpdb->update( $this->table( 'g2a_classes' ), $payload, array( 'id' => (int) $existing ) );
			return (int) $existing;
		}
		$payload['created_at'] = $now;
		$wpdb->insert( $this->table( 'g2a_classes' ), $payload );
		return (int) $wpdb->insert_id;
	}

	public function schedule_session( int $class_id, array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_class_sessions' ),
			array(
				'class_id'           => $class_id,
				'location_id'        => (int) ( $data['location_id'] ?? 1 ),
				'starts_at'          => sanitize_text_field( $data['starts_at'] ),
				'ends_at'            => sanitize_text_field( $data['ends_at'] ),
				'instructor_user_id' => isset( $data['instructor_user_id'] ) ? (int) $data['instructor_user_id'] : null,
				'status'             => 'scheduled',
				'notes'              => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'         => $now,
				'updated_at'         => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function enroll( int $session_id, array $data ): array {
		global $wpdb;
		$sess = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.*, c.capacity, c.price_amount, c.requires_waiver
             FROM {$this->table('g2a_class_sessions')} s
             JOIN {$this->table('g2a_classes')} c ON c.id = s.class_id
             WHERE s.id = %d",
				$session_id
			),
			ARRAY_A
		);
		if ( ! $sess ) {
			return array(
				'ok'    => false,
				'error' => 'session_not_found',
			);
		}
		if ( (int) $sess['seats_taken'] >= (int) $sess['capacity'] ) {
			return array(
				'ok'    => false,
				'error' => 'session_full',
			);
		}

		$price = (float) $sess['price_amount'];
		$paid  = (float) ( $data['paid_amount'] ?? 0 );
		$due   = max( 0, $price - $paid );

		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_class_enrollments' ),
			array(
				'session_id'        => $session_id,
				'customer_id'       => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'customer_name'     => sanitize_text_field( $data['customer_name'] ?? '' ),
				'customer_phone'    => sanitize_text_field( $data['customer_phone'] ?? '' ),
				'customer_email'    => sanitize_email( $data['customer_email'] ?? '' ),
				'paid_amount'       => $paid,
				'amount_due'        => $due,
				'waiver_id'         => isset( $data['waiver_id'] ) ? (int) $data['waiver_id'] : null,
				'attendance'        => 'enrolled',
				'completion_status' => 'pending',
				'pos_order_id'      => isset( $data['pos_order_id'] ) ? (int) $data['pos_order_id'] : null,
				'notes'             => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$enrollment_id = (int) $wpdb->insert_id;

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$this->table('g2a_class_sessions')} SET seats_taken = seats_taken + 1, updated_at = %s WHERE id = %d",
				$now,
				$session_id
			)
		);

		return array(
			'ok'            => true,
			'enrollment_id' => $enrollment_id,
			'amount_due'    => $due,
		);
	}

	public function list_sessions( array $filters = array() ): array {
		global $wpdb;
		$st    = $this->table( 'g2a_class_sessions' );
		$ct    = $this->table( 'g2a_classes' );
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $filters['from'] ) ) {
			$where[] = 's.starts_at >= %s';
			$args[]  = $filters['from']; }
		if ( ! empty( $filters['to'] ) ) {
			$where[] = 's.starts_at <= %s';
			$args[]  = $filters['to']; }
		$sql = "SELECT s.*, c.title, c.class_code, c.capacity, c.price_amount
                FROM {$st} s JOIN {$ct} c ON c.id = s.class_id
                WHERE " . implode( ' AND ', $where ) . ' ORDER BY s.starts_at ASC LIMIT 200';
		return $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A ) ?: array();
	}

	public function list_classes(): array {
		global $wpdb;
		return $wpdb->get_results(
			"SELECT * FROM {$this->table('g2a_classes')} WHERE status = 'active' ORDER BY title ASC",
			ARRAY_A
		) ?: array();
	}

	public function list_enrollments( int $session_id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_class_enrollments')} WHERE session_id = %d ORDER BY id ASC",
				$session_id
			),
			ARRAY_A
		) ?: array();
	}
}
