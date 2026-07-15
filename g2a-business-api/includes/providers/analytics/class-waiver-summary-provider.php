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
