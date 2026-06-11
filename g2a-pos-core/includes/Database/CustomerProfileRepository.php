<?php

namespace G2A\POS\Database;

final class CustomerProfileRepository extends Repository {

	public function find( int $customer_id ): ?array {
		global $wpdb;
		if ( $customer_id <= 0 ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table('g2a_customer_profiles')} WHERE customer_id = %d",
				$customer_id
			),
			ARRAY_A
		);
		return $row ?: null;
	}

	public function upsert( int $customer_id, array $fields ): array {
		global $wpdb;
		if ( $customer_id <= 0 ) {
			return array();
		}

		$allowed = array(
			'marketing_email_optin',
			'marketing_sms_optin',
			'marketing_optin_source',
			'do_not_sell',
			'denied_buyer',
			'denied_reason',
			'preferred_caliber',
			'preferred_brand',
			'internal_notes',
		);
		$set     = array();
		foreach ( $allowed as $k ) {
			if ( array_key_exists( $k, $fields ) ) {
				$set[ $k ] = $fields[ $k ];
			}
		}

		$existing = $this->find( $customer_id );
		if ( $existing ) {
			$set['updated_at'] = $this->now();
			$wpdb->update( $this->table( 'g2a_customer_profiles' ), $set, array( 'customer_id' => $customer_id ) );
		} else {
			$set['customer_id'] = $customer_id;
			$set['created_at']  = $this->now();
			$set['updated_at']  = $this->now();
			$wpdb->insert( $this->table( 'g2a_customer_profiles' ), $set );
		}

		return $this->find( $customer_id ) ?? array();
	}

	public function recompute_lifetime( int $customer_id ): array {
		global $wpdb;
		if ( $customer_id <= 0 ) {
			return array();
		}
		$orders = $this->table( 'g2a_pos_orders' );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS c, COALESCE(SUM(grand_total),0) AS total,
                    MIN(created_at) AS first_at, MAX(created_at) AS last_at
             FROM {$orders}
             WHERE customer_id = %d AND status NOT IN ('void','cancelled','refunded')",
				$customer_id
			),
			ARRAY_A
		);

		$existing = $this->find( $customer_id );
		$payload  = array(
			'lifetime_orders' => (int) ( $row['c'] ?? 0 ),
			'lifetime_spend'  => (float) ( $row['total'] ?? 0 ),
			'first_order_at'  => $row['first_at'] ?? null,
			'last_order_at'   => $row['last_at'] ?? null,
		);

		if ( $existing ) {
			$payload['updated_at'] = $this->now();
			$wpdb->update( $this->table( 'g2a_customer_profiles' ), $payload, array( 'customer_id' => $customer_id ) );
		} else {
			$payload['customer_id'] = $customer_id;
			$payload['created_at']  = $this->now();
			$payload['updated_at']  = $this->now();
			$wpdb->insert( $this->table( 'g2a_customer_profiles' ), $payload );
		}

		return $this->find( $customer_id ) ?? array();
	}

	public function purchase_history( int $customer_id, int $limit = 50 ): array {
		global $wpdb;
		$orders = $this->table( 'g2a_pos_orders' );
		$items  = $this->table( 'g2a_pos_order_items' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.id, o.grand_total, o.payment_status, o.compliance_state,
                    o.created_at, COUNT(i.id) AS line_count
             FROM {$orders} o
             LEFT JOIN {$items} i ON i.pos_order_id = o.id
             WHERE o.customer_id = %d
             GROUP BY o.id
             ORDER BY o.id DESC
             LIMIT %d",
				$customer_id,
				max( 1, min( 500, $limit ) )
			),
			ARRAY_A
		) ?: array();
		return $rows;
	}
}
