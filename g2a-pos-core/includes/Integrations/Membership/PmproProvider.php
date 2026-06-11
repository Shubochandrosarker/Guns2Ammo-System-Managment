<?php

namespace G2A\POS\Integrations\Membership;

/** Paid Memberships Pro adapter — schema docs: paidmembershipspro.com. */
final class PmproProvider implements Provider {

	public function slug(): string {
		return 'pmpro'; }
	public function label(): string {
		return 'Paid Memberships Pro'; }

	public function detect(): bool {
		global $wpdb;
		$t = $wpdb->prefix . 'pmpro_memberships_users';
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$t
			)
		);
	}

	public function membership( int $customer_id ): ?array {
		global $wpdb;
		$mu  = $wpdb->prefix . 'pmpro_memberships_users';
		$ml  = $wpdb->prefix . 'pmpro_membership_levels';
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT mu.id, mu.membership_id, mu.status, mu.startdate, mu.enddate,
                    ml.name AS plan_name
             FROM {$mu} mu
             LEFT JOIN {$ml} ml ON ml.id = mu.membership_id
             WHERE mu.user_id = %d AND mu.status = 'active'
             ORDER BY mu.id DESC LIMIT 1",
				$customer_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}
		return array(
			'active'     => true,
			'plan'       => (string) ( $row['plan_name'] ?? '' ),
			'plan_slug'  => (string) ( $row['membership_id'] ?? '' ),
			'expires_at' => ! empty( $row['enddate'] ) && $row['enddate'] !== '0000-00-00 00:00:00' ? $row['enddate'] : null,
			'member_id'  => (string) ( $row['id'] ?? '' ),
			'discounts'  => array(),
			'source'     => $this->slug(),
		);
	}

	public function bookings( int $customer_id, int $days_ahead = 30 ): array {
		return array();
	}
}
