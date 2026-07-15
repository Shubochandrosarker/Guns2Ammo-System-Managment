<?php
/**
 * System-health snapshot for GET /system/health (Phase C contract).
 *
 * Booleans, counts, versions, and cron timestamps ONLY — no credentials,
 * no filesystem paths, no hostnames. Anything sensitive stays behind the
 * wp-admin screens of the plugin that owns it.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Auth\Session_Installer;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class System_Health_Provider extends Analytics_Provider_Base {
	/**
	 * Required-plugin roster: directory slug => human name.
	 */
	public const REQUIRED_PLUGINS = array(
		'g2a-booking-engine'               => 'G2A Booking Engine',
		'memberistic-membership-solutions' => 'Memberistic Membership Solutions',
		'verifyistic'                      => 'Verifyistic',
		'woocommerce'                      => 'WooCommerce',
		'advanced-ffl-checkout'            => 'Advanced FFL Checkout',
		'messageistic'                     => 'Messageistic',
		'g2a-pos-core'                     => 'G2A POS Core',
	);

	/**
	 * Known scheduled jobs the dashboard cares about.
	 */
	public const KNOWN_CRON_HOOKS = array(
		'memberistic_daily_expire_memberships',
		'memberistic_daily_renewal_reminders',
		'memberistic_daily_waiver_followup',
		'memberistic_daily_backfill_renewals',
		'memberistic_daily_prune_logs',
		'g2aba_sessions_cleanup',
		'g2aba_generate_insights',
		'g2ab_cleanup_expired_reservations',
	);

	public function source_slug(): string {
		return 'g2a-business-api';
	}

	public function is_available(): bool {
		// The health report is about THIS install; it always has something
		// honest to say.
		return true;
	}

	protected function empty_summary(): array {
		return array(
			'plugins'      => array(),
			'cron'         => array(),
			'sessions'     => array(
				'table'       => false,
				'activeCount' => 0,
			),
			'audit'        => array( 'recentFailures24h' => 0 ),
			'integrations' => array(
				'stripe' => array( 'configured' => false ),
				'woo'    => array( 'active' => false ),
			),
		);
	}

	/**
	 * Range-free convenience (the /system/health route has no window).
	 */
	public function report(): array {
		$today = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		return $this->summary( new Range( $today, $today ) );
	}

	protected function build_summary( Range $range ): array {
		return array(
			'plugins'       => $this->plugin_rows(),
			'cron'          => $this->cron_rows(),
			'sessions'      => $this->sessions_block(),
			'audit'         => array( 'recentFailures24h' => self::count_recent_failures( $this->audit_entries() ) ),
			'integrations'  => array(
				'stripe' => array( 'configured' => $this->stripe_configured() ),
				'woo'    => array( 'active' => function_exists( 'wc_get_orders' ) ),
			),
			'lastUpdatedAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * @return array<int, array{slug:string, name:string, active:bool, version:string}>
	 */
	private function plugin_rows(): array {
		$active_plugins = (array) get_option( 'active_plugins', array() );

		$installed = array();
		if ( ! function_exists( 'get_plugins' )
			&& defined( 'ABSPATH' )
			&& file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'get_plugins' ) ) {
			$installed = (array) get_plugins();
		}

		$rows = array();
		foreach ( self::REQUIRED_PLUGINS as $slug => $name ) {
			$active  = false;
			$version = ''; // Frontend types version as string; '' = unknown.

			foreach ( $active_plugins as $file ) {
				if ( 0 === strpos( (string) $file, $slug . '/' ) ) {
					$active = true;
					break;
				}
			}
			foreach ( $installed as $file => $meta ) {
				if ( 0 === strpos( (string) $file, $slug . '/' ) && ! empty( $meta['Version'] ) ) {
					$version = (string) $meta['Version'];
					break;
				}
			}

			$rows[] = array(
				'slug'    => $slug,
				'name'    => $name,
				'active'  => $active,
				'version' => $version,
			);
		}
		return $rows;
	}

	/**
	 * @return array<int, array{hook:string, nextRunAt:?string}>
	 */
	private function cron_rows(): array {
		$rows = array();
		foreach ( self::KNOWN_CRON_HOOKS as $hook ) {
			$next = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( $hook ) : false;
			$rows[] = array(
				'hook'      => $hook,
				'nextRunAt' => $next ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $next ) : null,
			);
		}
		return $rows;
	}

	private function sessions_block(): array {
		global $wpdb;

		$table  = Session_Installer::table_name();
		$exists = $this->table_exists( $table );

		$active = 0;
		if ( $exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$active = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE revoked_at IS NULL AND expires_at > %s",
					gmdate( 'Y-m-d H:i:s' )
				)
			);
		}

		return array(
			'table'       => $exists,
			'activeCount' => $active,
		);
	}

	private function audit_entries(): array {
		$raw = get_option( 'g2aba_audit_log', array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Count audit entries from the last 24h whose kind carries a failure
	 * marker (email.send_failed, session_login_failed, ...).
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @param array<int, array<string, mixed>> $entries Audit-log rows.
	 * @param int|null                         $now     Unix ts override for tests.
	 */
	public static function count_recent_failures( array $entries, ?int $now = null ): int {
		$now    = $now ?? time();
		$cutoff = $now - DAY_IN_SECONDS;
		$count  = 0;
		foreach ( $entries as $entry ) {
			$kind = strtolower( (string) ( $entry['kind'] ?? '' ) );
			if ( false === strpos( $kind, 'fail' ) ) {
				continue;
			}
			$ts = strtotime( (string) ( $entry['ts'] ?? '' ) );
			if ( $ts && $ts >= $cutoff ) {
				$count++;
			}
		}
		return $count;
	}

	private function stripe_configured(): bool {
		try {
			if ( class_exists( '\WordPressistic\Memberistic\Payments\Stripe_Service' )
				&& is_callable( array( '\WordPressistic\Memberistic\Payments\Stripe_Service', 'is_enabled' ) ) ) {
				return (bool) \WordPressistic\Memberistic\Payments\Stripe_Service::is_enabled();
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		return false;
	}
}
