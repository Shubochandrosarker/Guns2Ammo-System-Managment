<?php
/**
 * Membership analytics — reads from Memberistic tables.
 *
 * Real `memberistic_memberships` schema (memberistic class-schema.php):
 * plan via `plan_id` → `memberistic_plans` (name + monthly_price /
 * annual_price), money in `billing_amount` with a `billing_cycle`
 * (monthly/annual), lifecycle in `start_date` / `renewal_date` /
 * `end_date`, owner in `primary_user_id`. There is no `tier` column —
 * the plan name serves as the tier.
 *
 * MRR normalises annual billing to a monthly figure (billing_amount / 12)
 * so the headline number is a true monthly run-rate.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Membership_Provider {
	public function analytics( Range $range ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'memberistic_memberships';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return $this->empty_payload( $range );
		}

		$plans = $wpdb->prefix . 'memberistic_plans';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_plans = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $plans ) );

		$from = $range->from . ' 00:00:00';
		$to   = $range->to . ' 23:59:59';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$active = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE status = 'active'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE created_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		// Churn in the window: memberships that ended (end_date, falling back
		// to updated_at for legacy rows) while in the expired state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
					WHERE status = 'expired'
					AND COALESCE(end_date, updated_at, created_at) BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		// Renewals: active memberships whose renewal_date fell in the window.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$renewals = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
					WHERE status = 'active' AND renewal_date BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		// "Corporate" — no tier column exists, so match the plan name/slug.
		$corporate = 0;
		if ( $has_plans ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$corporate = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table} m
					INNER JOIN {$plans} p ON p.id = m.plan_id
					WHERE m.status = 'active'
					AND (p.slug LIKE '%corporate%' OR p.name LIKE '%corporate%')"
			);
		}

		// Per-membership rows for MRR + plan performance. billing_cycle can
		// differ per membership even on the same plan, so normalisation
		// happens per-row in PHP, not in SQL.
		if ( $has_plans ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$active_rows = $wpdb->get_results(
				"SELECT COALESCE(NULLIF(p.name, ''), CONCAT('Plan #', m.plan_id)) AS plan,
					m.billing_cycle, m.billing_amount, p.monthly_price, p.annual_price
				 FROM {$table} m
				 LEFT JOIN {$plans} p ON p.id = m.plan_id
				 WHERE m.status = 'active'",
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$active_rows = $wpdb->get_results(
				"SELECT CONCAT('Plan #', plan_id) AS plan,
					billing_cycle, billing_amount, NULL AS monthly_price, NULL AS annual_price
				 FROM {$table}
				 WHERE status = 'active'",
				ARRAY_A
			);
		}
		$active_rows = is_array( $active_rows ) ? $active_rows : array();

		$plan_performance = self::plan_performance( $active_rows );
		$mrr              = Money::sum_cents( $plan_performance, 'revenue' );

		// Churn-risk heuristic: active memberships whose next renewal (or
		// hard end) lands in the next 14 days.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$churn_risk = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
					WHERE status = 'active'
					AND COALESCE(renewal_date, end_date) BETWEEN %s AND %s",
				gmdate( 'Y-m-d H:i:s' ),
				gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) )
			)
		);

		return array(
			'range'                   => $range->to_array(),
			'active'                  => $active,
			'newThisPeriod'           => $new,
			'expired'                 => $expired,
			'renewals'                => $renewals,
			'corporate'               => $corporate,
			'mrr'                     => $mrr,
			'churnRiskCount'          => $churn_risk,
			'planPerformance'         => $plan_performance,
			'renewalOpportunityCount' => $expired + $churn_risk,
		);
	}

	/**
	 * Group active membership rows by plan with monthly-normalised revenue.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<int, array<string, mixed>> $rows plan, billing_cycle,
	 *        billing_amount, monthly_price, annual_price.
	 * @return array<int, array{plan:string, active:int, revenue:int}>
	 */
	public static function plan_performance( array $rows ): array {
		$by_plan = array();
		foreach ( $rows as $row ) {
			$plan = (string) ( $row['plan'] ?? 'Unknown' );
			if ( ! isset( $by_plan[ $plan ] ) ) {
				$by_plan[ $plan ] = array(
					'plan'    => $plan,
					'active'  => 0,
					'revenue' => 0,
				);
			}
			$by_plan[ $plan ]['active']++;
			$by_plan[ $plan ]['revenue'] += self::monthly_cents(
				(string) ( $row['billing_cycle'] ?? '' ),
				$row['billing_amount'] ?? null,
				$row['monthly_price'] ?? null,
				$row['annual_price'] ?? null
			);
		}
		$by_plan = array_values( $by_plan );
		usort( $by_plan, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );
		return $by_plan;
	}

	/**
	 * Monthly-normalised cents for one membership row.
	 *
	 * Annual billing is divided by 12 so MRR is a genuine monthly run-rate.
	 * When `billing_amount` is missing, fall back to the plan's list price
	 * for the row's billing cycle.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param string      $billing_cycle  monthly|annual|yearly|…
	 * @param string|null $billing_amount Row's billed amount (decimal string).
	 * @param string|null $monthly_price  Plan monthly list price.
	 * @param string|null $annual_price   Plan annual list price.
	 */
	public static function monthly_cents( string $billing_cycle, $billing_amount, $monthly_price, $annual_price ): int {
		$cycle     = strtolower( trim( $billing_cycle ) );
		$is_annual = in_array( $cycle, array( 'annual', 'annually', 'yearly', 'year' ), true );

		$amount = null !== $billing_amount && '' !== (string) $billing_amount
			? (string) $billing_amount
			: (string) ( $is_annual ? ( $annual_price ?? '0' ) : ( $monthly_price ?? '0' ) );

		$cents = Money::to_cents_from_string( $amount );
		return $is_annual ? (int) round( $cents / 12 ) : $cents;
	}

	private function empty_payload( Range $range ): array {
		return array(
			'range'                   => $range->to_array(),
			'active'                  => 0,
			'newThisPeriod'           => 0,
			'expired'                 => 0,
			'renewals'                => 0,
			'corporate'               => 0,
			'mrr'                     => 0,
			'churnRiskCount'          => 0,
			'planPerformance'         => array(),
			'renewalOpportunityCount' => 0,
		);
	}
}
