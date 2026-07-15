<?php
/**
 * Membership aggregates from Memberistic.
 *
 * REVENUE RECOGNITION — `{prefix}memberistic_payments.status`:
 * only `completed` rows count (the same rule Memberistic's own
 * Payments_Repository uses for its revenue queries), recognised on
 * `paid_at` (falling back to `created_at` when paid_at was never
 * stamped). `pending`, `failed`, `refunded` are excluded — Memberistic
 * refunds flip the row's status rather than tracking a partial refund
 * amount, so a refunded row simply stops counting.
 *
 * Lifecycle counts come straight off `memberistic_memberships.status`
 * (active / pending / past_due / expired / cancelled — the vocabulary the
 * Memberships_Repository writes).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Membership_Analytics_Provider extends Analytics_Provider_Base {
	/** Membership lifecycle statuses always present in the counts map. */
	public const LIFECYCLE_STATUSES = array( 'active', 'pending', 'past_due', 'expired', 'cancelled' );

	/** Payment status recognised as revenue. */
	public const REVENUE_PAYMENT_STATUS = 'completed';

	public function source_slug(): string {
		return 'memberistic-membership-solutions';
	}

	public function is_available(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		return $this->table_exists( $wpdb->prefix . 'memberistic_memberships' );
	}

	protected function empty_summary(): array {
		return array(
			// Frontend contract keys (dashboard-app types/api.ts).
			'active'           => 0,
			'new'              => 0,
			'expired'          => 0,
			'mrrCents'         => 0,
			// Richer detail.
			'counts'           => self::zeroed_counts(),
			'newInRange'       => 0,
			'cancelledInRange' => 0,
			'revenueCents'     => 0,
			'planBreakdown'    => array(),
		);
	}

	protected function build_summary( Range $range ): array {
		global $wpdb;

		$memberships = $wpdb->prefix . 'memberistic_memberships';
		$plans       = $wpdb->prefix . 'memberistic_plans';
		$payments    = $wpdb->prefix . 'memberistic_payments';
		$has_plans    = $this->table_exists( $plans );
		$has_payments = $this->table_exists( $payments );
		list( $from, $to ) = self::range_bounds( $range );

		// Lifecycle counts (point-in-time, whole table — single aggregate).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $wpdb->get_results(
			"SELECT status, COUNT(*) AS c FROM {$memberships} GROUP BY status",
			ARRAY_A
		);
		$counts = self::zeroed_counts();
		foreach ( (array) $status_rows as $row ) {
			$status = strtolower( (string) ( $row['status'] ?? '' ) );
			$count  = (int) ( $row['c'] ?? 0 );
			if ( array_key_exists( $status, $counts ) ) {
				$counts[ $status ] += $count;
			} else {
				$counts['other'] += $count;
			}
		}

		// New + cancelled inside the window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new_in_range = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$memberships} WHERE created_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cancelled_in_range = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$memberships}
				 WHERE status = 'cancelled'
				   AND COALESCE(cancelled_at, updated_at, created_at) BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		// Plan breakdown: active membership count per plan + completed
		// payment revenue attributed to the plan inside the window.
		$plan_rows = array();
		if ( $has_plans ) {
			$revenue_join = $has_payments
				? "LEFT JOIN (
						SELECT m2.plan_id AS plan_id, SUM(pay.amount) AS revenue_sum
						FROM {$payments} pay
						INNER JOIN {$memberships} m2 ON m2.id = pay.membership_id
						WHERE pay.status = 'completed'
						  AND COALESCE(pay.paid_at, pay.created_at) BETWEEN %s AND %s
						GROUP BY m2.plan_id
					) rev ON rev.plan_id = p.id"
				: '';
			$revenue_col  = $has_payments ? 'COALESCE(rev.revenue_sum, 0)' : '0';

			$sql = "SELECT p.id AS plan_id,
					COALESCE(NULLIF(p.name, ''), CONCAT('Plan #', p.id)) AS name,
					COALESCE(mc.c, 0) AS count,
					{$revenue_col} AS revenue_sum
				 FROM {$plans} p
				 LEFT JOIN (
					SELECT plan_id, COUNT(*) AS c FROM {$memberships}
					WHERE status = 'active' GROUP BY plan_id
				 ) mc ON mc.plan_id = p.id
				 {$revenue_join}
				 ORDER BY count DESC, p.id ASC
				 LIMIT 50";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$plan_rows = $has_payments
				? $wpdb->get_results( $wpdb->prepare( $sql, $from, $to ), ARRAY_A )
				: $wpdb->get_results( $sql, ARRAY_A );
		}

		$plan_breakdown = array();
		foreach ( (array) $plan_rows as $row ) {
			$plan_breakdown[] = array(
				'planId'       => (int) ( $row['plan_id'] ?? 0 ),
				'name'         => (string) ( $row['name'] ?? '' ),
				'count'        => (int) ( $row['count'] ?? 0 ),
				'revenueCents' => Money::to_cents_from_string( (string) ( $row['revenue_sum'] ?? '0' ) ),
			);
		}

		// Completed-payment revenue for the window (whole source, not per-plan,
		// so unattributed payments still count).
		$revenue_cents = 0;
		if ( $has_payments ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$revenue_raw = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount), 0) FROM {$payments}
					 WHERE status = %s AND COALESCE(paid_at, created_at) BETWEEN %s AND %s",
					self::REVENUE_PAYMENT_STATUS,
					$from,
					$to
				)
			);
			$revenue_cents = Money::to_cents_from_string( (string) $revenue_raw );
		}

		// MRR: monthly run-rate over ACTIVE memberships — monthly billing at
		// face value, annual billing divided by 12; when billing_amount was
		// never stamped, fall back to the plan's list price for the row's
		// cycle. 0 (with counts still real) when neither is derivable.
		$mrr_cents = $this->mrr_cents( $memberships, $plans, $has_plans );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_updated = $wpdb->get_var( "SELECT MAX(COALESCE(updated_at, created_at)) FROM {$memberships}" );

		return array(
			'active'           => $counts['active'],
			'new'              => $new_in_range,
			'expired'          => $counts['expired'],
			'mrrCents'         => $mrr_cents,
			'counts'           => $counts,
			'newInRange'       => $new_in_range,
			'cancelledInRange' => $cancelled_in_range,
			'revenueCents'     => $revenue_cents,
			'planBreakdown'    => $plan_breakdown,
			'lastUpdatedAt'    => self::iso_from_db( $last_updated ),
		);
	}

	/**
	 * Monthly run-rate in cents over active memberships. Annual cycles are
	 * normalised to /12 in SQL; the DECIMAL dollar sum converts to cents
	 * once, in PHP.
	 */
	private function mrr_cents( string $memberships, string $plans, bool $has_plans ): int {
		global $wpdb;

		$annual_cycles = "('annual','annually','yearly','year')";
		if ( $has_plans ) {
			$sql = "SELECT COALESCE(SUM(
					CASE WHEN LOWER(COALESCE(m.billing_cycle, '')) IN {$annual_cycles}
						THEN COALESCE(NULLIF(m.billing_amount, 0), p.annual_price, 0) / 12
						ELSE COALESCE(NULLIF(m.billing_amount, 0), p.monthly_price, 0)
					END), 0)
				FROM {$memberships} m
				LEFT JOIN {$plans} p ON p.id = m.plan_id
				WHERE m.status = 'active'";
		} else {
			$sql = "SELECT COALESCE(SUM(
					CASE WHEN LOWER(COALESCE(billing_cycle, '')) IN {$annual_cycles}
						THEN COALESCE(billing_amount, 0) / 12
						ELSE COALESCE(billing_amount, 0)
					END), 0)
				FROM {$memberships}
				WHERE status = 'active'";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- table names trusted, no user input.
		$dollars = (string) $wpdb->get_var( $sql );
		return \WordPressistic\G2ABA\Money::to_cents_from_string( $dollars );
	}

	/**
	 * @internal Exposed for tests.
	 * @return array<string, int>
	 */
	public static function zeroed_counts(): array {
		$counts = array_fill_keys( self::LIFECYCLE_STATUSES, 0 );
		$counts['other'] = 0;
		return $counts;
	}
}
