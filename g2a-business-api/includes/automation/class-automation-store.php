<?php
/**
 * Persistent automation store.
 *
 * An automation is a scheduled action WordPress will fire on its own.
 * Each record carries: `slug` (stable key + WP-Cron hook name),
 * `category`, `name`, `trigger` (a human sentence like "24h before
 * booking start"), `action` (also a human sentence), `interval` (one of
 * hourly/twicedaily/daily/weekly, matching wp_get_schedules()), a
 * `handler` (a stable callable identifier — an action hook or a wp-cron
 * hook name), and mutable `status` (`active` | `paused`), `lastRun`,
 * `lastResult`, `runsLast7d`.
 *
 * The store is deliberately dumb — it owns persistence only. Scheduling
 * lives in Cron_Scheduler so the two responsibilities are independently
 * testable.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Automation_Store {
	private const OPTION = 'g2aba_automations';

	public const STATUS_ACTIVE = 'active';
	public const STATUS_PAUSED = 'paused';

	/**
	 * Seed the store with the automations the client asked for. Idempotent —
	 * safe to call on every activation. Existing status is preserved so
	 * reactivating the plugin doesn't re-enable something an owner paused.
	 */
	public static function seed_defaults(): void {
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();

		$defaults = self::default_records();
		foreach ( $defaults as $slug => $rec ) {
			if ( isset( $existing[ $slug ] ) && is_array( $existing[ $slug ] ) ) {
				// Preserve status + counters; merge everything else from the seed.
				$existing[ $slug ] = array_merge(
					$rec,
					array(
						'status'     => (string) ( $existing[ $slug ]['status'] ?? $rec['status'] ),
						'lastRun'    => $existing[ $slug ]['lastRun'] ?? null,
						'lastResult' => $existing[ $slug ]['lastResult'] ?? null,
						'runsLast7d' => (int) ( $existing[ $slug ]['runsLast7d'] ?? 0 ),
					)
				);
			} else {
				$existing[ $slug ] = $rec;
			}
		}
		update_option( self::OPTION, $existing, false );
	}

	/**
	 * @return array<int, array>
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) ? array_values( $raw ) : array();
	}

	public function find( string $slug ): ?array {
		$raw = get_option( self::OPTION, array() );
		return is_array( $raw ) && isset( $raw[ $slug ] ) ? $raw[ $slug ] : null;
	}

	public function set_status( string $slug, string $status ): ?array {
		if ( ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
			return null;
		}
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $slug ] ) ) {
			return null;
		}
		$raw[ $slug ]['status'] = $status;
		update_option( self::OPTION, $raw, false );
		return $raw[ $slug ];
	}

	public function record_run( string $slug, string $result ): ?array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $slug ] ) ) {
			return null;
		}
		$raw[ $slug ]['lastRun']    = gmdate( 'c' );
		$raw[ $slug ]['lastResult'] = $result;
		$raw[ $slug ]['runsLast7d'] = (int) ( $raw[ $slug ]['runsLast7d'] ?? 0 ) + 1;
		update_option( self::OPTION, $raw, false );
		return $raw[ $slug ];
	}

	/**
	 * @return array<string, array>
	 */
	public static function default_records(): array {
		return array(
			'booking-reminder-24h' => array(
				'slug'       => 'booking-reminder-24h',
				'name'       => 'Booking reminder (24h before)',
				'category'   => 'booking',
				'trigger'    => '24h before booking start',
				'action'     => 'Send SMS + email',
				'interval'   => 'hourly',
				'handler'    => 'g2aba_run_booking_reminder',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'waiver-reminder' => array(
				'slug'       => 'waiver-reminder',
				'name'       => 'Waiver reminder',
				'category'   => 'waiver',
				'trigger'    => 'Booking made w/o signed waiver',
				'action'     => 'Send waiver-link email',
				'interval'   => 'hourly',
				'handler'    => 'g2aba_run_waiver_reminder',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'membership-renewal-reminders' => array(
				'slug'       => 'membership-renewal-reminders',
				'name'       => 'Membership renewal 30/7/1-day',
				'category'   => 'membership',
				'trigger'    => 'Days before expiry',
				'action'     => 'Send renewal reminder',
				'interval'   => 'daily',
				'handler'    => 'g2aba_run_membership_renewal',
				'status'     => self::STATUS_PAUSED,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'abandoned-inquiry-alert' => array(
				'slug'       => 'abandoned-inquiry-alert',
				'name'       => 'Abandoned inquiry alert',
				'category'   => 'sales',
				'trigger'    => 'Contact form has no reply after 48h',
				'action'     => 'Notify staff channel',
				'interval'   => 'hourly',
				'handler'    => 'g2aba_run_abandoned_inquiry',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'low-stock-alert' => array(
				'slug'       => 'low-stock-alert',
				'name'       => 'Low-stock alert',
				'category'   => 'staff',
				'trigger'    => 'Product stock <= threshold',
				'action'     => 'Notify manager email',
				'interval'   => 'twicedaily',
				'handler'    => 'g2aba_run_low_stock',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'seo-click-drop-alert' => array(
				'slug'       => 'seo-click-drop-alert',
				'name'       => 'SEO click-drop alert',
				'category'   => 'seo',
				'trigger'    => 'Page clicks drop 25% vs previous week',
				'action'     => 'Create SEO task',
				'interval'   => 'daily',
				'handler'    => 'g2aba_run_seo_drop_alert',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'weekly-business-report' => array(
				'slug'       => 'weekly-business-report',
				'name'       => 'Weekly business report',
				'category'   => 'reports',
				'trigger'    => 'Every Monday 07:00',
				'action'     => 'Email + Slack summary',
				'interval'   => 'weekly',
				'handler'    => 'g2aba_run_weekly_report',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'post-booking-upsell-ladies' => array(
				'slug'       => 'post-booking-upsell-ladies',
				'name'       => 'Post-booking upsell (Ladies Tuesday)',
				'category'   => 'email',
				'trigger'    => 'Ladies Tuesday booking created',
				'action'     => 'Send friend / CCW upsell email',
				'interval'   => 'hourly',
				'handler'    => 'g2aba_run_ladies_upsell',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'agent-churn-risk-outreach' => array(
				'slug'       => 'agent-churn-risk-outreach',
				'name'       => 'Agent: churn-risk outreach',
				'category'   => 'agents',
				'trigger'    => 'Membership churn score elevated',
				'action'     => 'Draft outreach email',
				'interval'   => 'daily',
				'handler'    => 'g2aba_run_agent_churn_risk',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
		);
	}
}
