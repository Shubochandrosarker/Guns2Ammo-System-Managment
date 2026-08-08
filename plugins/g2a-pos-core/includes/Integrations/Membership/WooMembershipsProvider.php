<?php

namespace G2A\POS\Integrations\Membership;

/**
 * WooCommerce Memberships adapter (Skyverge).
 *
 * Memberships are CPTs `wc_user_membership` with post_author = customer user_id
 * and post_parent = membership plan post id (CPT wc_membership_plan).
 * Status is in post_status: wcm-active / wcm-expired / wcm-cancelled / wcm-paused.
 * Expiration is stored in postmeta _end_date.
 */
final class WooMembershipsProvider implements Provider {

	public function slug(): string {
		return 'wc_memberships'; }
	public function label(): string {
		return 'WooCommerce Memberships'; }

	public function detect(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1",
				'wc_membership_plan'
			)
		);
	}

	public function membership( int $customer_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.ID AS membership_id, m.post_parent AS plan_id, p.post_title AS plan_name,
                    (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = m.ID AND meta_key = '_end_date' LIMIT 1) AS end_date
             FROM {$wpdb->posts} m
             LEFT JOIN {$wpdb->posts} p ON p.ID = m.post_parent
             WHERE m.post_type = 'wc_user_membership'
               AND m.post_author = %d
               AND m.post_status = 'wcm-active'
             ORDER BY m.ID DESC LIMIT 1",
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
			'plan_slug'  => (string) ( $row['plan_id'] ?? '' ),
			'expires_at' => ! empty( $row['end_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) $row['end_date'] ) : null,
			'member_id'  => (string) ( $row['membership_id'] ?? '' ),
			'discounts'  => array(),
			'source'     => $this->slug(),
		);
	}

	public function bookings( int $customer_id, int $days_ahead = 30 ): array {
		// WooCommerce Bookings (separate plugin) — query its CPT if present.
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title,
                    MAX(CASE WHEN pm.meta_key = '_booking_start' THEN pm.meta_value END) AS starts_at,
                    MAX(CASE WHEN pm.meta_key = '_booking_end' THEN pm.meta_value END) AS ends_at
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'wc_booking'
               AND p.post_status IN ('confirmed', 'paid', 'in-cart', 'unpaid')
               AND p.post_author = %d
             GROUP BY p.ID
             HAVING starts_at IS NOT NULL
             ORDER BY starts_at ASC
             LIMIT 25",
				$customer_id
			),
			ARRAY_A
		) ?: array();

		return array_values(
			array_filter(
				array_map(
					static function ( $r ) use ( $days_ahead ) {
						$starts = $r['starts_at'] ?: null;
						if ( ! $starts ) {
							return null;
						}
						$ts = is_numeric( $starts ) ? (int) $starts : strtotime( (string) $starts );
						if ( $ts && $ts > time() + $days_ahead * 86400 ) {
							return null;
						}
						return array(
							'id'        => (int) $r['ID'],
							'title'     => (string) $r['post_title'],
							'starts_at' => $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null,
							'ends_at'   => $r['ends_at'] ? gmdate( 'Y-m-d H:i:s', is_numeric( $r['ends_at'] ) ? (int) $r['ends_at'] : strtotime( (string) $r['ends_at'] ) ) : null,
							'status'    => 'confirmed',
						);
					},
					$rows
				)
			)
		);
	}
}
