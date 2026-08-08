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

	public const ALLOWED_INTERVALS = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

	/**
	 * Bump this whenever `default_records()` gains/changes a record in a way
	 * that needs to reach an already-activated install. `Activator::activate()`
	 * only fires on activation, never on a plugin *update* — see
	 * `Leads_Installer::maybe_install()` for the version-gate idiom this
	 * mirrors.
	 */
	public const DEFS_VERSION = '1.1.0';

	private const DEFS_VERSION_OPTION = 'g2aba_automation_defs_version';

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
	 * Re-run `seed_defaults()` + `Cron_Scheduler::sync_all()` +
	 * `Agent_Store::seed_defaults()` only when the stored defs version is
	 * behind `DEFS_VERSION` — the version-gated self-heal so an
	 * already-activated install picks up new/changed automations and agent
	 * behaviours on the next request, not just on a fresh activation. Cheap
	 * (one `get_option()`) to call on every request when the version already
	 * matches, so it's safe to wire into `Plugin::run()`.
	 */
	public static function maybe_reseed(): void {
		if ( get_option( self::DEFS_VERSION_OPTION ) === self::DEFS_VERSION ) {
			return;
		}

		self::seed_defaults();
		Cron_Scheduler::sync_all();
		\WordPressistic\G2ABA\Agents\Agent_Store::seed_defaults();

		update_option( self::DEFS_VERSION_OPTION, self::DEFS_VERSION, false );
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

	/**
	 * Patch mutable fields on an automation. Accepts `interval` (allow-listed)
	 * and `status`. Unknown keys are dropped; empty patch is a no-op that
	 * still returns the fresh record so callers get a canonical shape back.
	 *
	 * Returns null when the slug is unknown so the REST controller can 404.
	 *
	 * @param array{interval?:string,status?:string} $patch
	 */
	public function patch_schedule( string $slug, array $patch ): ?array {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) || ! isset( $raw[ $slug ] ) ) {
			return null;
		}
		if ( isset( $patch['interval'] ) ) {
			$candidate = strtolower( (string) $patch['interval'] );
			if ( ! in_array( $candidate, self::ALLOWED_INTERVALS, true ) ) {
				return null;
			}
			$raw[ $slug ]['interval'] = $candidate;
		}
		if ( isset( $patch['status'] ) ) {
			$candidate = (string) $patch['status'];
			if ( ! in_array( $candidate, array( self::STATUS_ACTIVE, self::STATUS_PAUSED ), true ) ) {
				return null;
			}
			$raw[ $slug ]['status'] = $candidate;
		}
		update_option( self::OPTION, $raw, false );
		return $raw[ $slug ];
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
			'agent-refresh-hourly' => array(
				'slug'       => 'agent-refresh-hourly',
				'name'       => 'Agent: hourly refresh',
				'category'   => 'ai',
				'trigger'    => 'Every hour',
				'action'     => 'Draft replies for new enquiries',
				'interval'   => 'hourly',
				'handler'    => 'g2aba_run_agent_refresh_hourly',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
			'agent-refresh-daily' => array(
				'slug'       => 'agent-refresh-daily',
				'name'       => 'Agent: daily refresh',
				'category'   => 'ai',
				'trigger'    => 'Every day',
				'action'     => 'Refresh Analyst/Booking/Membership/Store/Sales/SEO agents',
				'interval'   => 'daily',
				'handler'    => 'g2aba_run_agent_refresh_daily',
				'status'     => self::STATUS_ACTIVE,
				'lastRun'    => null,
				'lastResult' => null,
				'runsLast7d' => 0,
			),
		);
	}
}
