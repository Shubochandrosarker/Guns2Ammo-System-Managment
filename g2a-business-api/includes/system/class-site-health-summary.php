<?php
/**
 * Pure classification/aggregation helpers for the GET /system/site-health
 * endpoint. Kept free of any WP_Site_Health / rest_do_request calls so the
 * counting logic is unit-testable against a plain array of test results —
 * the same shape WP core's Site Health REST tests return
 * (`{status: 'good'|'recommended'|'critical', ...}`).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\System;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Site_Health_Summary {
	/**
	 * Buckets a list of WP Site Health test results into pass/fail counts.
	 *
	 * @param array<int, array{status?: string}> $results
	 * @return array{criticalIssues: int, recommendedImprovements: int, good: int}
	 */
	public static function tally( array $results ): array {
		$critical    = 0;
		$recommended = 0;
		$good        = 0;

		foreach ( $results as $result ) {
			$status = strtolower( (string) ( is_array( $result ) ? ( $result['status'] ?? '' ) : '' ) );
			if ( 'critical' === $status ) {
				++$critical;
			} elseif ( 'recommended' === $status ) {
				++$recommended;
			} else {
				++$good;
			}
		}

		return array(
			'criticalIssues'          => $critical,
			'recommendedImprovements' => $recommended,
			'good'                    => $good,
		);
	}

	/**
	 * The best-effort fallback tally used when core Site Health internals
	 * aren't reachable in-process — derived only from cheap constants/facts
	 * so it never has to run an actual "test".
	 *
	 * @param array{debugDisplay?: bool, hasAuthKey?: bool} $facts
	 * @return array{criticalIssues: int, recommendedImprovements: int}
	 */
	public static function fallback_tally( array $facts ): array {
		$critical    = 0;
		$recommended = 0;

		if ( ! empty( $facts['debugDisplay'] ) ) {
			// WP_DEBUG_DISPLAY on is a recommended fix, not a critical outage.
			++$recommended;
		}
		if ( empty( $facts['hasAuthKey'] ) ) {
			++$critical;
		}

		return array(
			'criticalIssues'          => $critical,
			'recommendedImprovements' => $recommended,
		);
	}
}
