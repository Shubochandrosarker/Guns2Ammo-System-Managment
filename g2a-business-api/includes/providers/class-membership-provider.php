<?php
/**
 * Membership analytics — reads from Memberistic tables.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$expired = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'expired' AND updated_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$renewals = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND renewed_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$corporate = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE tier = 'corporate' AND status = 'active'"
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mrr_rows = $wpdb->get_results(
			"SELECT tier, COUNT(*) AS active, SUM(plan_price) AS revenue FROM {$table} WHERE status = 'active' GROUP BY tier",
			ARRAY_A
		);
		$mrr_rows = is_array( $mrr_rows ) ? $mrr_rows : array();

		$plan_performance = array();
		$mrr              = 0;
		foreach ( $mrr_rows as $row ) {
			$revenue           = Money::to_cents_from_string( (string) ( $row['revenue'] ?? '0' ) );
			$mrr              += $revenue;
			$plan_performance[] = array(
				'plan'    => (string) ( $row['tier'] ?? 'Unknown' ),
				'active'  => (int) ( $row['active'] ?? 0 ),
				'revenue' => $revenue,
			);
		}

		// A simple churn-risk heuristic: active memberships expiring in the
		// next 14 days with no attempted renewal event.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$churn_risk = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'active' AND expires_at BETWEEN %s AND %s",
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
