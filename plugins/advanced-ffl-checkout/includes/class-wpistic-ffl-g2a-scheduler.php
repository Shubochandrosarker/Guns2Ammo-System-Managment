<?php
/**
 * G2A — Scheduler abstraction over Action Scheduler / WP-Cron.
 *
 * Action Scheduler is bundled with WooCommerce 3.5+, so any G2A install
 * already has it. AS gives us:
 *   - Real retry policy on failure
 *   - Per-action logging visible at Tools → Scheduled Actions
 *   - Concurrency guarantees (no double-firing under load)
 *   - Idempotent enqueue via unique_hash on the arg array
 *
 * When AS is not loaded (edge cases: WC deactivated mid-run, bootstrap
 * order issues), we transparently fall back to wp_schedule_*. This keeps
 * the rest of the plugin agnostic — every caller talks to G2A_Scheduler
 * and never has to think about which engine is running.
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class G2A_Scheduler {

	const GROUP = 'wpistic-ffl';

	/** Action Scheduler functions are available. */
	public static function as_available(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Enqueue a one-shot async action. Runs ASAP on the next AS pass
	 * (typically within 1 minute) — never blocks the current request.
	 *
	 * Idempotent: if an identical pending action already exists, we don't
	 * stack a duplicate.
	 *
	 * @param string $hook  Action hook name to fire.
	 * @param array  $args  Positional args passed to the action callback.
	 */
	public static function async( string $hook, array $args = [] ): void {
		if ( self::as_available() ) {
			// AS dedupes via the (hook, args, group) tuple. Pass unique=true.
			if ( ! \as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
				\as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}
		// Fallback: short delay so the current request returns first.
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + 5, $hook, $args );
		}
	}

	/**
	 * Schedule a one-shot action at a specific timestamp.
	 */
	public static function single( int $timestamp, string $hook, array $args = [] ): void {
		if ( self::as_available() ) {
			if ( ! \as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
				\as_schedule_single_action( $timestamp, $hook, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( $timestamp, $hook, $args );
		}
	}

	/**
	 * Ensure a recurring action is scheduled at $interval seconds.
	 * Idempotent — safe to call on every page load.
	 */
	public static function recurring( int $interval_seconds, string $hook, array $args = [], ?int $first_run = null ): void {
		$first_run = $first_run ?: ( time() + 60 );
		if ( self::as_available() ) {
			if ( ! \as_next_scheduled_action( $hook, $args, self::GROUP ) ) {
				\as_schedule_recurring_action( $first_run, $interval_seconds, $hook, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			// Translate seconds to a known WP cron schedule slug when possible.
			$slug = match ( true ) {
				$interval_seconds <= 60      => 'every_minute',
				$interval_seconds <= 3600    => 'hourly',
				$interval_seconds <= 86400   => 'daily',
				default                      => 'monthly',
			};
			wp_schedule_event( $first_run, $slug, $hook, $args );
		}
	}

	/**
	 * Cancel every pending occurrence of $hook. Both engines.
	 */
	public static function unschedule_all( string $hook ): void {
		if ( self::as_available() ) {
			\as_unschedule_all_actions( $hook, [], self::GROUP );
		}
		wp_clear_scheduled_hook( $hook );
	}
}
