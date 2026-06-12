<?php

namespace G2A\POS\Integrations\Membership;

/**
 * Memberistic Membership Solutions adapter — the membership system Guns 2
 * Ammo actually runs. Reads the wp_memberistic_* tables directly (1-2 SQL
 * hits per lookup) and, when the G2A Booking Engine is installed, surfaces
 * the customer's upcoming range bookings for the cashier.
 *
 * Registered FIRST in RangeMembership::providers() so auto-detect prefers
 * it over PMPro/MemberPress relics that may still have stale tables.
 */
final class MemberisticProvider implements Provider {

	public function slug(): string {
		return 'memberistic'; }
	public function label(): string {
		return 'Memberistic Membership Solutions'; }

	public function detect(): bool {
		if ( defined( 'MEMBERISTIC_VERSION' ) || class_exists( '\\WordPressistic\\Memberistic\\Plugin' ) ) {
			return true;
		}
		global $wpdb;
		$t = $wpdb->prefix . 'memberistic_memberships';
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
		$m  = $wpdb->prefix . 'memberistic_memberships';
		$pl = $wpdb->prefix . 'memberistic_plans';
		$pp = $wpdb->prefix . 'memberistic_people';

		// Owner OR linked person; active rows preferred, newest wins.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.id, m.status, m.renewal_date, m.end_date,
				        pl.name AS plan_name, pl.slug AS plan_slug, pl.benefits AS plan_benefits
				 FROM {$m} m
				 LEFT JOIN {$pl} pl ON pl.id = m.plan_id
				 LEFT JOIN {$pp} pp ON pp.membership_id = m.id
				 WHERE m.primary_user_id = %d OR pp.wp_user_id = %d
				 ORDER BY ( m.status = 'active' ) DESC, m.id DESC
				 LIMIT 1",
				$customer_id,
				$customer_id
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$benefits = array();
		if ( ! empty( $row['plan_benefits'] ) ) {
			$decoded  = json_decode( (string) $row['plan_benefits'], true );
			$benefits = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'active'     => in_array( (string) $row['status'], array( 'active', 'past_due' ), true ),
			'plan'       => (string) ( $row['plan_name'] ?? '' ),
			'plan_slug'  => (string) ( $row['plan_slug'] ?? '' ),
			'expires_at' => $row['end_date'] ?: ( $row['renewal_date'] ?: null ),
			'member_id'  => (string) (int) $row['id'],
			'discounts'  => $benefits,
			'source'     => 'memberistic',
		);
	}

	public function bookings( int $customer_id, int $days_ahead = 30 ): array {
		global $wpdb;
		$bt = $wpdb->prefix . 'g2ab_bookings';
		$rt = $wpdb->prefix . 'g2ab_resources';

		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(1) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s',
				DB_NAME,
				$bt
			)
		);
		if ( ! $exists ) {
			return array();
		}

		$user  = function_exists( 'get_user_by' ) ? get_user_by( 'id', $customer_id ) : null;
		$email = $user ? (string) $user->user_email : '';

		$days = max( 1, min( 365, $days_ahead ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.uuid, b.start_at, b.end_at, b.status, r.name AS resource_name
				 FROM {$bt} b
				 LEFT JOIN {$rt} r ON r.id = b.resource_id
				 WHERE ( b.user_id = %d OR ( %s <> '' AND b.customer_email = %s ) )
				   AND b.status NOT IN ( 'cancelled', 'no_show', 'expired' )
				   AND b.start_at BETWEEN %s AND DATE_ADD(%s, INTERVAL %d DAY)
				 ORDER BY b.start_at ASC
				 LIMIT 25",
				$customer_id,
				$email,
				$email,
				current_time( 'mysql' ),
				current_time( 'mysql' ),
				$days
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'        => (string) $row['uuid'],
				'title'     => (string) ( $row['resource_name'] ?: 'Range booking' ),
				'starts_at' => (string) $row['start_at'],
				'ends_at'   => (string) $row['end_at'],
				'status'    => (string) $row['status'],
			);
		}
		return $out;
	}
}
