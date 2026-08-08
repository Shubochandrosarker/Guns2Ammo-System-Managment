<?php
/**
 * Owns the schedule side effects of automations.
 *
 * `apply(record)` ensures the underlying WP-Cron event matches the record —
 * scheduled at the right interval when active, unscheduled when paused.
 *
 * Everything that touches wp_schedule_event / wp_next_scheduled /
 * wp_unschedule_event lives here so the store stays a pure data class.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cron_Scheduler {
	private const ALLOWED_INTERVALS = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	public static function apply( array $record ): void {
		$hook     = (string) ( $record['handler'] ?? '' );
		$interval = (string) ( $record['interval'] ?? '' );
		$status   = (string) ( $record['status'] ?? '' );

		if ( '' === $hook ) {
			return;
		}

		self::unschedule_all( $hook );

		if ( Automation_Store::STATUS_ACTIVE !== $status ) {
			return;
		}
		if ( ! in_array( $interval, self::ALLOWED_INTERVALS, true ) ) {
			// Unknown interval — refuse to schedule rather than default to
			// something surprising like "hourly".
			return;
		}
		wp_schedule_event( time() + 300, $interval, $hook );
	}

	public static function unschedule_all( string $hook ): void {
		if ( '' === $hook ) {
			return;
		}
		$ts = wp_next_scheduled( $hook );
		while ( $ts ) {
			wp_unschedule_event( $ts, $hook );
			$ts = wp_next_scheduled( $hook );
		}
	}

	/**
	 * Re-apply every stored automation. Called on activation + when the
	 * settings screen requests a resync.
	 */
	public static function sync_all(): void {
		foreach ( ( new Automation_Store() )->all() as $rec ) {
			self::apply( $rec );
		}
	}
}
