<?php
/**
 * Waiver coverage counts — COUNTS ONLY, never names/emails/phones (PII
 * stays inside Memberistic; this API only reports how many).
 *
 * Two stores can contribute (per docs/VERIFYISTIC_CURRENT_STATE.md):
 *
 *  1. `{prefix}memberistic_people.waiver_status` — live member roster.
 *     Values written by Memberistic: 'signed', 'expired', 'missing'.
 *     Buckets derived here:
 *       current     = signed AND (waiver_expires_at IS NULL OR > now)
 *       expiring30d = signed AND waiver_expires_at within the next 30 days
 *                     (subset of current — a heads-up, not a third state)
 *       expired     = status 'expired' OR (signed AND waiver_expires_at < now
 *                     where the daily expiry cron hasn't flipped it yet)
 *       missing     = status 'missing' / empty / anything unrecognised
 *  2. `{prefix}memberistic_waivers_archive` — the imported Otter archive
 *     (total + is_current counts only).
 *
 * `stores` + the sources() list reflect which of the two actually
 * contributed, so the dashboard can say "roster only, archive missing".
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Waiver_Summary_Provider extends Analytics_Provider_Base {
	public function source_slug(): string {
		return 'memberistic-membership-solutions';
	}

	/**
	 * Store-level source identifiers that actually contributed.
	 *
	 * @return array<int, string>
	 */
	public function sources(): array {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return array();
		}
		$out = array();
		if ( $this->table_exists( $wpdb->prefix . 'memberistic_people' ) ) {
			$out[] = 'memberistic-people';
		}
		if ( $this->table_exists( $wpdb->prefix . 'memberistic_waivers_archive' ) ) {
			$out[] = 'memberistic-waivers-archive';
		}
		return $out;
	}

	public function is_available(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		// Either store is enough to say something honest.
		return $this->table_exists( $wpdb->prefix . 'memberistic_people' )
			|| $this->table_exists( $wpdb->prefix . 'memberistic_waivers_archive' );
	}

	protected function empty_summary(): array {
		return array(
			// Frontend contract keys (dashboard-app types/api.ts):
			// signed = currently-valid waivers; pending = people needing a
			// (re-)signature = missing + expired.
			'signed'  => 0,
			'pending' => 0,
			'counts'  => array(
				'current'     => 0,
				'expiring30d' => 0,
				'expired'     => 0,
				'missing'     => 0,
			),
			'archive' => array(
				'total'   => 0,
				'current' => 0,
			),
			'stores'  => array(
				'people'  => false,
				'archive' => false,
			),
		);
	}

	protected function empty_detail(): array {
		return array_merge(
			$this->empty_summary(),
			array(
				'expiring'        => array(
					'd30' => 0,
					'd60' => 0,
					'd90' => 0,
				),
				'signings' => array(
					'archive' => array(),
					'people'  => array(),
				),
			)
		);
	}

	/**
	 * Detail payload for GET /analytics/waivers — still COUNTS ONLY, no PII.
	 *
	 * Adds:
	 *  - expiring{d30,d60,d90}: cumulative windows — signed roster waivers
	 *    whose waiver_expires_at falls within the next 30/60/90 days (d30 is
	 *    a subset of d60 is a subset of d90).
	 *  - signings: signings per calendar month for the last 12 months,
	 *    reported PER SOURCE (archive = memberistic_waivers_archive.signed_at,
	 *    people = memberistic_people.waiver_signed_at). The two stores overlap
	 *    for imported members, so they are labelled separately instead of
	 *    being summed into a number nobody can reconcile.
	 */
	protected function build_detail( Range $range ): array {
		global $wpdb;

		$out = $this->build_summary( $range );

		$people  = $wpdb->prefix . 'memberistic_people';
		$archive = $wpdb->prefix . 'memberistic_waivers_archive';

		$has_people  = $this->table_exists( $people );
		$has_archive = $this->table_exists( $archive );

		$now = current_time( 'mysql' );

		// Expiring buckets — one CASE aggregate over the roster.
		$expiring = array(
			'd30' => 0,
			'd60' => 0,
			'd90' => 0,
		);
		if ( $has_people ) {
			$h30 = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +30 days' ) );
			$h60 = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +60 days' ) );
			$h90 = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +90 days' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN waiver_expires_at > %s AND waiver_expires_at <= %s THEN 1 ELSE 0 END) AS d30,
						SUM(CASE WHEN waiver_expires_at > %s AND waiver_expires_at <= %s THEN 1 ELSE 0 END) AS d60,
						SUM(CASE WHEN waiver_expires_at > %s AND waiver_expires_at <= %s THEN 1 ELSE 0 END) AS d90
					 FROM {$people}
					 WHERE waiver_status = 'signed' AND waiver_expires_at IS NOT NULL",
					$now,
					$h30,
					$now,
					$h60,
					$now,
					$h90
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				$expiring['d30'] = (int) ( $row['d30'] ?? 0 );
				$expiring['d60'] = (int) ( $row['d60'] ?? 0 );
				$expiring['d90'] = (int) ( $row['d90'] ?? 0 );
			}
		}

		// Monthly signings, last 12 calendar months, one GROUP BY per store.
		$window_start = gmdate( 'Y-m-01 00:00:00', strtotime( $now . ' -11 months' ) );
		$months       = self::last_twelve_months( $now );

		$archive_series = array();
		if ( $has_archive ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows           = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE_FORMAT(signed_at, '%%Y-%%m') AS m, COUNT(*) AS c
					 FROM {$archive}
					 WHERE signed_at IS NOT NULL AND signed_at >= %s AND signed_at <= %s
					 GROUP BY DATE_FORMAT(signed_at, '%%Y-%%m')",
					$window_start,
					$now
				),
				ARRAY_A
			);
			$archive_series = self::fill_monthly_series( $months, (array) $rows );
		}

		$people_series = array();
		if ( $has_people ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows          = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT DATE_FORMAT(waiver_signed_at, '%%Y-%%m') AS m, COUNT(*) AS c
					 FROM {$people}
					 WHERE waiver_signed_at IS NOT NULL AND waiver_signed_at >= %s AND waiver_signed_at <= %s
					 GROUP BY DATE_FORMAT(waiver_signed_at, '%%Y-%%m')",
					$window_start,
					$now
				),
				ARRAY_A
			);
			$people_series = self::fill_monthly_series( $months, (array) $rows );
		}

		$out['expiring']        = $expiring;
		$out['signings'] = array(
			'archive' => $archive_series,
			'people'  => $people_series,
		);

		return $out;
	}

	/**
	 * The last 12 calendar months, oldest first, ending with $now's month.
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @return array<int, string> 'YYYY-MM' labels.
	 */
	public static function last_twelve_months( string $now ): array {
		$out    = array();
		$anchor = strtotime( gmdate( 'Y-m-01 00:00:00', strtotime( $now ) ?: time() ) );
		for ( $i = 11; $i >= 0; $i-- ) {
			$out[] = gmdate( 'Y-m', strtotime( "-{$i} months", $anchor ) );
		}
		return $out;
	}

	/**
	 * Expand sparse (m, c) rows into a dense 12-month series.
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @param array<int, string>               $months 'YYYY-MM' labels, oldest first.
	 * @param array<int, array<string, mixed>> $rows   Sparse GROUP BY month rows.
	 * @return array<int, array{month:string, count:int}>
	 */
	public static function fill_monthly_series( array $months, array $rows ): array {
		$by_month = array();
		foreach ( $rows as $row ) {
			$by_month[ (string) ( $row['m'] ?? '' ) ] = (int) ( $row['c'] ?? 0 );
		}
		$out = array();
		foreach ( $months as $month ) {
			$out[] = array(
				'month' => $month,
				'count' => $by_month[ $month ] ?? 0,
			);
		}
		return $out;
	}

	protected function build_summary( Range $range ): array {
		global $wpdb;

		$people  = $wpdb->prefix . 'memberistic_people';
		$archive = $wpdb->prefix . 'memberistic_waivers_archive';

		$has_people  = $this->table_exists( $people );
		$has_archive = $this->table_exists( $archive );

		$now      = current_time( 'mysql' );
		$horizon  = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' +30 days' ) );

		$counts       = $this->empty_summary()['counts'];
		$last_updated = null;

		if ( $has_people ) {
			// One bounded aggregate: bucket every person row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT
						SUM(CASE WHEN waiver_status = 'signed'
							AND (waiver_expires_at IS NULL OR waiver_expires_at > %s)
							THEN 1 ELSE 0 END) AS current_count,
						SUM(CASE WHEN waiver_status = 'signed'
							AND waiver_expires_at IS NOT NULL
							AND waiver_expires_at > %s AND waiver_expires_at <= %s
							THEN 1 ELSE 0 END) AS expiring_count,
						SUM(CASE WHEN waiver_status = 'expired'
							OR (waiver_status = 'signed' AND waiver_expires_at IS NOT NULL AND waiver_expires_at <= %s)
							THEN 1 ELSE 0 END) AS expired_count,
						SUM(CASE WHEN waiver_status NOT IN ('signed', 'expired')
							OR waiver_status IS NULL
							THEN 1 ELSE 0 END) AS missing_count,
						MAX(COALESCE(updated_at, created_at)) AS last_updated
					 FROM {$people}",
					$now,
					$now,
					$horizon,
					$now
				),
				ARRAY_A
			);
			if ( is_array( $row ) ) {
				$counts['current']     = (int) ( $row['current_count'] ?? 0 );
				$counts['expiring30d'] = (int) ( $row['expiring_count'] ?? 0 );
				$counts['expired']     = (int) ( $row['expired_count'] ?? 0 );
				$counts['missing']     = (int) ( $row['missing_count'] ?? 0 );
				$last_updated          = $row['last_updated'] ?? null;
			}
		}

		$archive_total   = 0;
		$archive_current = 0;
		if ( $has_archive ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$arow = $wpdb->get_row(
				"SELECT COUNT(*) AS total, SUM(CASE WHEN is_current = 1 THEN 1 ELSE 0 END) AS current_count FROM {$archive}",
				ARRAY_A
			);
			if ( is_array( $arow ) ) {
				$archive_total   = (int) ( $arow['total'] ?? 0 );
				$archive_current = (int) ( $arow['current_count'] ?? 0 );
			}
		}

		return array(
			'signed'        => $counts['current'],
			'pending'       => $counts['missing'] + $counts['expired'],
			'counts'        => $counts,
			'archive'       => array(
				'total'   => $archive_total,
				'current' => $archive_current,
			),
			'stores'        => array(
				'people'  => $has_people,
				'archive' => $has_archive,
			),
			'lastUpdatedAt' => self::iso_from_db( $last_updated ),
		);
	}
}
