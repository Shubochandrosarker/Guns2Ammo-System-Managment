<?php

namespace G2A\POS\Database;

final class MembershipRepository extends Repository {

	public function create( array $data ): int {
		global $wpdb;
		$now = $this->now();
		$wpdb->insert(
			$this->table( 'g2a_memberships' ),
			array(
				'customer_id'              => isset( $data['customer_id'] ) ? (int) $data['customer_id'] : null,
				'waiver_id'                => isset( $data['waiver_id'] ) ? (int) $data['waiver_id'] : null,
				'tier_code'                => sanitize_key( $data['tier_code'] ?? 'standard' ),
				'tier_label'               => sanitize_text_field( $data['tier_label'] ?? 'Standard' ),
				'price_amount'             => (float) ( $data['price_amount'] ?? 0 ),
				'billing_cycle'            => sanitize_key( $data['billing_cycle'] ?? 'monthly' ),
				'status'                   => sanitize_key( $data['status'] ?? 'active' ),
				'started_at'               => $data['started_at'] ?? $now,
				'renews_at'                => $data['renews_at'] ?? $this->next_renewal( $data['billing_cycle'] ?? 'monthly' ),
				'external_provider'        => sanitize_text_field( $data['external_provider'] ?? '' ),
				'external_subscription_id' => sanitize_text_field( $data['external_subscription_id'] ?? '' ),
				'notes'                    => sanitize_textarea_field( $data['notes'] ?? '' ),
				'created_at'               => $now,
				'updated_at'               => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function cancel( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->update(
			$this->table( 'g2a_memberships' ),
			array(
				'status'       => 'cancelled',
				'cancelled_at' => $this->now(),
				'updated_at'   => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	public function list_active( int $limit = 200 ): array {
		global $wpdb;
		$t = $this->table( 'g2a_memberships' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE status = 'active' ORDER BY renews_at ASC LIMIT %d",
				$limit
			),
			ARRAY_A
		) ?: array();
	}

	public function due_for_billing( \DateTimeInterface $until ): array {
		global $wpdb;
		$t = $this->table( 'g2a_memberships' );
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE status = 'active' AND renews_at <= %s",
				$until->format( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		) ?: array();
	}

	public function record_invoice( int $membership_id, float $amount, string $period_start, string $period_end ): int {
		global $wpdb;
		$wpdb->insert(
			$this->table( 'g2a_membership_invoices' ),
			array(
				'membership_id' => $membership_id,
				'amount'        => $amount,
				'period_start'  => $period_start,
				'period_end'    => $period_end,
				'status'        => 'open',
				'created_at'    => $this->now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function bump_renewal( int $membership_id, string $cycle ): void {
		global $wpdb;
		$wpdb->update(
			$this->table( 'g2a_memberships' ),
			array(
				'renews_at'  => $this->next_renewal( $cycle ),
				'updated_at' => $this->now(),
			),
			array( 'id' => $membership_id )
		);
	}

	private function next_renewal( string $cycle ): string {
		$map  = array(
			'monthly'   => '+1 month',
			'quarterly' => '+3 months',
			'annual'    => '+1 year',
			'weekly'    => '+1 week',
		);
		$expr = $map[ $cycle ] ?? '+1 month';
		return date( 'Y-m-d H:i:s', strtotime( $expr ) );
	}
}
