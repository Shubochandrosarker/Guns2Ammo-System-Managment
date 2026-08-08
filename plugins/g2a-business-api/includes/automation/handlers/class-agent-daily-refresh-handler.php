<?php
/**
 * Agent: daily refresh handler.
 *
 * Refreshes the department agents that don't otherwise run on their own
 * cadence — Analyst, Booking, Membership, Store, Sales, SEO — once a day so
 * their `lastOutput` never goes more than 24h stale. This handler already
 * executes inside a WP-Cron context (see Handler_Base::invoke()), so it
 * calls Agent_Runner::run() directly rather than scheduling another single
 * event.
 *
 * A paused agent is skipped without being called at all; a single agent's
 * run throwing (e.g. a transient Anthropic timeout) must not stop the rest
 * of the batch — each Agent_Runner::run() call is individually try/caught
 * and logged, then the loop continues.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Agents\Agent_Runner;
use WordPressistic\G2ABA\Agents\Agent_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Agent_Daily_Refresh_Handler extends Handler_Base {
	public const AGENT_IDS = array( 'ag-analyst', 'ag-booking', 'ag-member', 'ag-store', 'ag-sales', 'ag-seo' );

	public static function slug(): string {
		return 'agent-refresh-daily';
	}

	public static function run(): string {
		$store = new Agent_Store();

		$refreshed = array();
		$paused    = array();
		$failed    = array();

		foreach ( self::AGENT_IDS as $id ) {
			$agent = $store->find( $id );
			if ( null === $agent || Agent_Store::STATUS_ACTIVE !== ( $agent['status'] ?? '' ) ) {
				$paused[] = $id;
				continue;
			}
			try {
				Agent_Runner::run( $id );
				$refreshed[] = $id;
			} catch ( \Throwable $e ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional: surfaces a per-agent refresh failure without aborting the batch.
				error_log( sprintf( '[G2ABA] agent-refresh-daily failed for %s: %s', $id, $e->getMessage() ) );
				$failed[] = $id;
			}
		}

		return self::summarize( count( $refreshed ), count( self::AGENT_IDS ), $paused, $failed );
	}

	/**
	 * Compose the human-readable summary — pulled out into its own pure
	 * helper so the skip/failure bookkeeping is directly testable without
	 * needing a live Agent_Runner.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<int, string> $paused Agent ids skipped because they aren't active.
	 * @param array<int, string> $failed Agent ids whose Agent_Runner::run() call threw.
	 */
	public static function summarize( int $refreshed_count, int $total, array $paused, array $failed ): string {
		$summary = sprintf( 'Refreshed %d/%d agents', $refreshed_count, $total );

		$notes = array();
		if ( ! empty( $paused ) ) {
			$notes[] = implode( ', ', $paused ) . ' paused';
		}
		if ( ! empty( $failed ) ) {
			$notes[] = implode( ', ', $failed ) . ' failed';
		}
		if ( ! empty( $notes ) ) {
			$summary .= ' (' . implode( '; ', $notes ) . ')';
		}

		return $summary . '.';
	}
}
