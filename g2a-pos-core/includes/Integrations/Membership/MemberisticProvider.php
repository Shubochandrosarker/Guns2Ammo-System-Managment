<?php

namespace G2A\POS\Integrations\Membership;

/**
 * Memberistic Membership Solutions adapter (WPistic in-house plugin).
 *
 * Memberistic stores memberships in {$prefix}memberistic_memberships
 * (primary_user_id = WP user id) with linked household members in
 * {$prefix}memberistic_people (wp_user_id). Plans live in
 * {$prefix}memberistic_plans (name/slug/benefits JSON).
 *
 * Note: Memberistic also ships its own POS bridge that answers the
 * g2a_pos_membership_lookup filter. That filter still wins (resolution
 * order is filter → pinned provider → auto-detect); this adapter is the
 * fallback for sites where the bridge toggle is off, and it powers
 * provider listing/pinning in the POS Membership settings UI.
 */
final class MemberisticProvider implements Provider {

	/** Membership statuses treated as "counts at the counter". */
	private const ACTIVE_STATUSES = array( 'active', 'past_due' );

	public function slug(): string {
		return 'memberistic';
	}

	public function label(): string {
		return 'Memberistic Memberships';
	}

	public function detect(): bool {
		if ( class_exists( '\\WordPressistic\\Memberistic\\Plugin' ) || defined( 'MEMBERISTIC_VERSION' ) ) {
			return true;
		}
		return $this->table_exists( 'memberistic_memberships' );
	}

	public function membership( int $customer_id ): ?array {
		global $wpdb;
		if ( $customer_id <= 0 || ! $this->table_exists( 'memberistic_memberships' ) ) {
			return null;
		}

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$people      = $wpdb->prefix . 'memberistic_people';
		$join_people = $this->table_exists( 'memberistic_people' );

		$where = 'm.primary_user_id = %d';
		$args  = array( $customer_id );
		if ( $join_people ) {
			$where  = "(m.primary_user_id = %d OR m.id IN (SELECT pp.membership_id FROM {$people} pp WHERE pp.wp_user_id = %d AND pp.status = 'active'))";
			$args[] = $customer_id;
		}

		// Prefer an active row; fall back to the most recent one so the
		// cashier still sees lapsed status instead of "no membership".
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT m.* FROM {$memberships} m
				 WHERE {$where}
				 ORDER BY FIELD(m.status, 'active', 'past_due') DESC, m.id DESC
				 LIMIT 1",
				...$args
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$plan      = $this->plan_row( (int) $row['plan_id'] );
		$discounts = array();
		if ( $plan && ! empty( $plan['benefits'] ) ) {
			$decoded   = json_decode( (string) $plan['benefits'], true );
			$discounts = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'active'     => in_array( (string) $row['status'], self::ACTIVE_STATUSES, true ),
			'plan'       => $plan ? (string) $plan['name'] : (string) $row['plan_id'],
			'plan_slug'  => $plan ? (string) $plan['slug'] : (string) $row['plan_id'],
			'expires_at' => $row['end_date'] ?: ( $row['renewal_date'] ?: null ),
			'member_id'  => (string) $row['id'],
			'discounts'  => $discounts,
			'source'     => $this->slug(),
		);
	}

	public function bookings( int $customer_id, int $days_ahead = 30 ): array {
		// Bookings come from the G2A Booking Engine integration
		// (BookingEngineSync), not from Memberistic itself.
		return array();
	}

	private function plan_row( int $plan_id ): ?array {
		global $wpdb;
		if ( $plan_id <= 0 || ! $this->table_exists( 'memberistic_plans' ) ) {
			return null;
		}
		$plans = $wpdb->prefix . 'memberistic_plans';
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT id, name, slug, benefits FROM {$plans} WHERE id = %d", $plan_id ),
			ARRAY_A
		);
		return $row ?: null;
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $wpdb ) ) {
			return false;
		}
		$full = $wpdb->prefix . $table;
		if ( ! array_key_exists( $full, $cache ) ) {
			$cache[ $full ] = (bool) $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $full ) )
			);
		}
		return $cache[ $full ];
	}
}
