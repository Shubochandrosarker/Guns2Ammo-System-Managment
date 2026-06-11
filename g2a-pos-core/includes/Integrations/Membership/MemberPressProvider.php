<?php

namespace G2A\POS\Integrations\Membership;

/** MemberPress adapter. */
final class MemberPressProvider implements Provider {

	public function slug(): string {
		return 'memberpress'; }
	public function label(): string {
		return 'MemberPress'; }

	public function detect(): bool {
		global $wpdb;
		$t = $wpdb->prefix . 'mepr_subscriptions';
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
		$t = $wpdb->prefix . 'mepr_subscriptions';
		// MemberPress stores product_id pointing to a wp_posts row of type 'memberpressproduct'.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT s.id, s.product_id, s.status, s.expires_at, p.post_title AS plan_name
             FROM {$t} s
             LEFT JOIN {$wpdb->posts} p ON p.ID = s.product_id
             WHERE s.user_id = %d AND s.status = 'active'
             ORDER BY s.id DESC LIMIT 1",
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
			'plan_slug'  => (string) ( $row['product_id'] ?? '' ),
			'expires_at' => ! empty( $row['expires_at'] ) ? $row['expires_at'] : null,
			'member_id'  => (string) ( $row['id'] ?? '' ),
			'discounts'  => array(),
			'source'     => $this->slug(),
		);
	}

	public function bookings( int $customer_id, int $days_ahead = 30 ): array {
		return array();
	}
}
