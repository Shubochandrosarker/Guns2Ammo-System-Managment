<?php
/**
 * Cron scheduler — drives renewal reminders, auto-expiry, and waiver
 * follow-up emails for memberships that need attention.
 *
 * Hooks (all daily):
 *  - memberistic_daily_renewal_reminders → emails members 30/7/1 days out.
 *  - memberistic_daily_expire_memberships → flips active memberships past
 *    their renewal_date into the 'expired' status.
 *  - memberistic_daily_waiver_followup → emails active members whose waiver
 *    is still missing.
 *
 * Schedules are registered on plugins_loaded and on activation; deactivation
 * clears them.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Emails\Email_Service;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scheduler {
	const HOOK_RENEWAL_REMINDERS = 'memberistic_daily_renewal_reminders';
	const HOOK_AUTO_EXPIRE       = 'memberistic_daily_expire_memberships';
	const HOOK_WAIVER_FOLLOWUP   = 'memberistic_daily_waiver_followup';

	public static function register() {
		add_action( self::HOOK_RENEWAL_REMINDERS, array( self::class, 'run_renewal_reminders' ) );
		add_action( self::HOOK_AUTO_EXPIRE, array( self::class, 'run_auto_expire' ) );
		add_action( self::HOOK_WAIVER_FOLLOWUP, array( self::class, 'run_waiver_followup' ) );

		self::ensure_scheduled();
	}

	public static function ensure_scheduled() {
		$start = strtotime( 'tomorrow 06:00' );

		foreach (
			array(
				self::HOOK_RENEWAL_REMINDERS,
				self::HOOK_AUTO_EXPIRE,
				self::HOOK_WAIVER_FOLLOWUP,
			) as $hook
		) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( $start ?: time() + DAY_IN_SECONDS, 'daily', $hook );
			}
		}
	}

	public static function clear_scheduled() {
		foreach (
			array(
				self::HOOK_RENEWAL_REMINDERS,
				self::HOOK_AUTO_EXPIRE,
				self::HOOK_WAIVER_FOLLOWUP,
			) as $hook
		) {
			$timestamp = wp_next_scheduled( $hook );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
			}
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Email active members at 30, 7, and 1 day out from renewal_date.
	 *
	 * De-duplication uses a per-membership/per-template option flag so the
	 * same window only fires once per renewal cycle.
	 */
	public static function run_renewal_reminders() {
		$windows = array(
			30 => 'expiring_30_days',
			7  => 'expiring_7_days',
			1  => 'expiring_tomorrow',
		);

		foreach ( $windows as $days => $template ) {
			$rows = Memberships_Repository::get_renewing_in_days( $days );

			foreach ( $rows as $row ) {
				$key   = sprintf( 'memberistic_reminder_%d_%s_%s', (int) $row['id'], $template, gmdate( 'Y-m-d', strtotime( $row['renewal_date'] ) ) );
				$sent  = get_transient( $key );

				if ( $sent ) {
					continue;
				}

				Email_Service::send_membership_email( (int) $row['id'], $template );
				set_transient( $key, 1, DAY_IN_SECONDS * 3 );
			}
		}
	}

	/**
	 * Flip active memberships whose renewal_date has passed into 'expired'
	 * and notify the primary member.
	 */
	public static function run_auto_expire() {
		$expired = Memberships_Repository::get_active_expired();

		foreach ( $expired as $row ) {
			Memberships_Repository::change_status( (int) $row['id'], 'expired' );

			Activity_Repository::log(
				array(
					'membership_id' => (int) $row['id'],
					'activity_type' => 'membership_expired',
					'title'         => __( 'Membership expired automatically', 'memberistic' ),
				)
			);

			Email_Service::send_membership_email( (int) $row['id'], 'membership_expired' );
		}
	}

	/**
	 * Nudge active members whose primary or linked-member waiver is still
	 * missing — once per week per membership.
	 */
	public static function run_waiver_followup() {
		$rows = People_Repository::get_active_missing_waiver();

		foreach ( $rows as $row ) {
			$key = sprintf( 'memberistic_waiver_followup_%d', (int) $row['membership_id'] );

			if ( get_transient( $key ) ) {
				continue;
			}

			Email_Service::send_membership_email( (int) $row['membership_id'], 'waiver_missing' );
			set_transient( $key, 1, WEEK_IN_SECONDS );
		}
	}
}
