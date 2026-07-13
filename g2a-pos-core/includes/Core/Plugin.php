<?php

namespace G2A\POS\Core;

use G2A\POS\API\Routes;
use G2A\POS\Admin\Assets;
use G2A\POS\Admin\Menu;
use G2A\POS\Auth\JWT;
use G2A\POS\Compliance\ATF\NICS;
use G2A\POS\Ai\WebsiteKnowledgeSeeder;
use G2A\POS\Database\Migrator;
use G2A\POS\Integrations\BookingEngineSync;
use G2A\POS\Integrations\WooCatalogSync;
use G2A\POS\Integrations\WooSync;
use G2A\POS\Inventory\SyncService;
use G2A\POS\Messaging\MessagingService;
use G2A\POS\Permissions\Roles;
use G2A\POS\Pricing\MapPricingService;
use G2A\POS\Queue\Worker;
use G2A\POS\Reporting\KpiService;
use G2A\POS\Shipping\CarrierRegistry;
use G2A\POS\Support\Cron;
use G2A\POS\Support\Logger;
use G2A\POS\Support\SecurityHeaders;
use G2A\POS\Support\Privacy;
use G2A\POS\Wholesalers\WholesalerRegistry;

final class Plugin {

	private const CRON_HOOK                 = 'g2a_pos_process_queue';
	private const NICS_CRON_HOOK            = 'g2a_pos_nics_default_proceed';
	private const DISTRIBUTOR_SYNC_HOOK     = 'g2a_pos_distributor_sync_daily';
	private const DISTRIBUTOR_HOURLY_HOOK   = 'g2a_pos_distributor_sync_hourly';
	private const WHOLESALER_INV_HOOK       = 'g2a_pos_wholesaler_inventory_sync';
	private const MESSAGING_FLUSH_HOOK      = 'g2a_pos_messaging_flush';
	private const KPI_SNAPSHOT_HOOK         = 'g2a_pos_kpi_snapshot_daily';
	private const MEMBERSHIP_BILLING_HOOK   = 'g2a_pos_membership_billing_daily';
	private const LAYAWAY_REMINDER_HOOK     = 'g2a_pos_layaway_reminders_daily';
	private const COMPLIANCE_CALENDAR_HOOK  = 'g2a_pos_compliance_calendar_daily';
	private const VENDOR_PRICE_CAPTURE_HOOK = 'g2a_pos_vendor_price_capture_weekly';
	private const WOO_CATALOG_SYNC_HOOK     = WooCatalogSync::CRON_HOOK;
	private const BRAIN_SITE_REFRESH_HOOK   = WebsiteKnowledgeSeeder::CRON_HOOK;

	private static ?self $instance = null;

	public static function instance(): self {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		Cron::register_intervals();
		JWT::bootstrap();
		SecurityHeaders::boot();
		Privacy::boot();
		self::maybe_upgrade();

		add_action( 'rest_api_init', array( Routes::class, 'register' ) );
		add_action( 'rest_api_init', array( \G2A\POS\API\SettingsController::class, 'register_routes' ) );
		add_action( self::CRON_HOOK, array( Worker::class, 'process_pending' ) );
		add_action(
			self::NICS_CRON_HOOK,
			static function (): void {
				NICS::apply_default_proceeds();
			}
		);
		add_action(
			self::DISTRIBUTOR_SYNC_HOOK,
			static function (): void {
				SyncService::run_due( 'daily' );
			}
		);
		add_action(
			self::DISTRIBUTOR_HOURLY_HOOK,
			static function (): void {
				SyncService::run_due( 'hourly' );
			}
		);
		add_action( self::WHOLESALER_INV_HOOK, array( self::class, 'cron_wholesaler_inventory_sync' ) );
		add_action(
			self::MESSAGING_FLUSH_HOOK,
			static function (): void {
				MessagingService::flush( 50 );
			}
		);
		add_action(
			self::KPI_SNAPSHOT_HOOK,
			static function (): void {
				( new KpiService() )->persist_snapshot();
			}
		);
		add_action( self::MEMBERSHIP_BILLING_HOOK, array( self::class, 'cron_membership_billing' ) );
		add_action( self::LAYAWAY_REMINDER_HOOK, array( self::class, 'cron_layaway_reminders' ) );
		add_action( self::COMPLIANCE_CALENDAR_HOOK, array( self::class, 'cron_compliance_calendar' ) );
		add_action( self::VENDOR_PRICE_CAPTURE_HOOK, array( self::class, 'cron_vendor_price_capture' ) );
		add_action( 'admin_menu', array( Menu::class, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( Assets::class, 'enqueue' ) );
		WooSync::boot();
		WooCatalogSync::boot();
		BookingEngineSync::boot();
		WebsiteKnowledgeSeeder::boot();
		WholesalerRegistry::boot();
		MapPricingService::boot();
		CarrierRegistry::boot();
		\G2A\POS\Ai\ToolRegistry::boot();
	}

	public static function cron_membership_billing(): void {
		$memberships = new \G2A\POS\Database\MembershipRepository();
		foreach ( $memberships->due_for_billing( new \DateTimeImmutable( 'now' ) ) as $m ) {
			$start = date( 'Y-m-d', strtotime( $m['renews_at'] ?? 'now' ) );
			$end   = date( 'Y-m-d', strtotime( $start . ' +1 month -1 day' ) );
			$memberships->record_invoice( (int) $m['id'], (float) $m['price_amount'], $start, $end );
			$memberships->bump_renewal( (int) $m['id'], (string) $m['billing_cycle'] );
		}
	}

	public static function cron_compliance_calendar(): void {
		$repo = new \G2A\POS\Database\ComplianceCalendarRepository();
		foreach ( $repo->due_for_reminder( new \DateTimeImmutable( 'now' ) ) as $row ) {
			$vars  = array(
				'title'  => $row['title'] ?? '',
				'due_at' => $row['due_at'] ?? '',
			);
			$admin = get_option( 'admin_email' );
			if ( $admin ) {
				MessagingService::queue_from_template(
					'compliance_reminder',
					'email',
					$admin,
					$vars,
					array(
						'related_entity_type' => 'compliance_calendar',
						'related_entity_id'   => (int) $row['id'],
					)
				);
			}
			$repo->mark_reminded( (int) $row['id'] );
		}
	}

	public static function cron_vendor_price_capture(): void {
		$repo = new \G2A\POS\Database\WholesalerRepository();
		$cap  = new \G2A\POS\Database\VendorPriceHistoryRepository();
		foreach ( $repo->all() as $w ) {
			if ( ( $w['status'] ?? '' ) !== 'active' ) {
				continue;
			}
			try {
				$cap->capture( (int) $w['id'] );
			} catch ( \Throwable $e ) {
				Logger::exception( 'Vendor price capture failed', $e, array( 'wholesaler_id' => (int) $w['id'] ) );
			}
		}
	}

	public static function cron_layaway_reminders(): void {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT id, customer_name, customer_email, customer_phone, balance_due, expires_at
             FROM {$wpdb->prefix}g2a_layaways
             WHERE status = 'active' AND expires_at IS NOT NULL
             AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)",
			ARRAY_A
		) ?: array();
		foreach ( $rows as $row ) {
			$vars = array(
				'name'    => $row['customer_name'],
				'balance' => number_format( (float) $row['balance_due'], 2 ),
				'expires' => $row['expires_at'],
			);
			if ( ! empty( $row['customer_email'] ) ) {
				MessagingService::queue_from_template(
					'layaway_reminder',
					'email',
					$row['customer_email'],
					$vars,
					array(
						'related_entity_type' => 'layaway',
						'related_entity_id'   => (int) $row['id'],
					)
				);
			}
			if ( ! empty( $row['customer_phone'] ) ) {
				MessagingService::queue_from_template(
					'layaway_reminder',
					'sms',
					$row['customer_phone'],
					$vars,
					array(
						'related_entity_type' => 'layaway',
						'related_entity_id'   => (int) $row['id'],
					)
				);
			}
		}
	}

	public static function cron_wholesaler_inventory_sync(): void {
		$repo = new \G2A\POS\Database\WholesalerRepository();
		foreach ( $repo->all() as $w ) {
			if ( ( $w['status'] ?? '' ) !== 'active' ) {
				continue;
			}
			$provider = WholesalerRegistry::get( (string) $w['provider_code'] );
			if ( ! $provider || ! $provider->supportsRealTimeInventory() ) {
				continue;
			}
			try {
				// syncInventory() reports failures via ['ok' => false] rather
				// than throwing — only stamp last_sync_at on a real success,
				// otherwise a down account looks freshly synced forever.
				$result = $provider->syncInventory( (int) $w['id'] );
				if ( ! empty( $result['ok'] ) ) {
					$repo->markSyncedNow( (int) $w['id'] );
				} else {
					Logger::error(
						'Wholesaler cron sync failed',
						array(
							'wholesaler_id' => (int) $w['id'],
							'error'         => (string) ( $result['error'] ?? 'unknown' ),
						)
					);
				}
			} catch ( \Throwable $e ) {
				Logger::exception( 'Wholesaler sync failed', $e, array( 'wholesaler_id' => (int) $w['id'] ) );
			}
		}
	}

	/**
	 * Idempotently (re)schedule every recurring event the plugin depends on.
	 * Shared by activate() and maybe_upgrade() so an install that missed the
	 * activation hook (plugin-file replace, aborted activation, cleared cron
	 * array) self-heals on the next boot instead of silently never running
	 * NICS default-proceeds, KPI snapshots, billing, reminders, or syncs.
	 */
	private static function schedule_all_crons(): void {
		// Register the custom 'minute' interval first — scheduling a 'minute'
		// event before the interval exists silently fails.
		Cron::register_intervals();

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'minute', self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::NICS_CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::NICS_CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::DISTRIBUTOR_SYNC_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::DISTRIBUTOR_SYNC_HOOK );
		}
		if ( ! wp_next_scheduled( self::DISTRIBUTOR_HOURLY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::DISTRIBUTOR_HOURLY_HOOK );
		}
		if ( ! wp_next_scheduled( self::WHOLESALER_INV_HOOK ) ) {
			// Stagger first run 5 minutes out so it doesn't fight the current request, then run hourly.
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::WHOLESALER_INV_HOOK );
		}
		if ( ! wp_next_scheduled( self::MESSAGING_FLUSH_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'minute', self::MESSAGING_FLUSH_HOOK );
		}
		if ( ! wp_next_scheduled( self::KPI_SNAPSHOT_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::KPI_SNAPSHOT_HOOK );
		}
		if ( ! wp_next_scheduled( self::MEMBERSHIP_BILLING_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::MEMBERSHIP_BILLING_HOOK );
		}
		if ( ! wp_next_scheduled( self::LAYAWAY_REMINDER_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::LAYAWAY_REMINDER_HOOK );
		}
		if ( ! wp_next_scheduled( self::COMPLIANCE_CALENDAR_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::COMPLIANCE_CALENDAR_HOOK );
		}
		if ( ! wp_next_scheduled( self::VENDOR_PRICE_CAPTURE_HOOK ) ) {
			wp_schedule_event( time() + 6 * HOUR_IN_SECONDS, 'weekly', self::VENDOR_PRICE_CAPTURE_HOOK );
		}
		if ( ! wp_next_scheduled( self::WOO_CATALOG_SYNC_HOOK ) ) {
			// Nightly full WooCommerce catalog re-scan.
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::WOO_CATALOG_SYNC_HOOK );
		}
		if ( ! wp_next_scheduled( self::BRAIN_SITE_REFRESH_HOOK ) ) {
			// Nightly website-knowledge refresh (full-site crawl) for the AI brain.
			wp_schedule_event( self::next_nightly_run(), 'daily', self::BRAIN_SITE_REFRESH_HOOK );
		}
	}

	private static function maybe_upgrade(): void {
		// Upgrade/self-heal work never runs on public front-end requests.
		// The schema migration creates ~90 tables via dbDelta; before this
		// gate existed, a plugin-file replace meant EVERY visitor request
		// re-ran the full migration (no lock, version option written only at
		// the very end) — concurrent dbDelta storms took whole sites down on
		// shared hosting. Admin, cron and WP-CLI contexts are frequent enough
		// for the self-heal duties too.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		// Self-heal every recurring event: older versions scheduled some hooks
		// only during activation (which never re-runs on a plugin-file
		// replace) and scheduled the minute-interval events before the
		// 'minute' interval existed, so several jobs never ran.
		self::schedule_all_crons();

		// Seed the default website knowledge pack once per pack version —
		// ASYNC ONLY. Seeding embeds documents over HTTP (tens of seconds
		// against a slow AI endpoint); running it inline here put that work
		// inside ordinary visitor requests: the request died on
		// max_execution_time before the seeded flag was written, so EVERY
		// following request repeated the hang and the whole site read as
		// down until the plugin was deleted. Schedule a cron event instead.
		if ( ! WebsiteKnowledgeSeeder::is_seeded() && ! wp_next_scheduled( WebsiteKnowledgeSeeder::SEED_EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, WebsiteKnowledgeSeeder::SEED_EVENT );
		}

		// One-time migration: move the AI brain refresh from weekly to a nightly
		// (daily) schedule and kick an immediate full-site crawl. Idempotent via
		// its own option, so it also applies on a plugin-file replace where the
		// activation hook does not run.
		if ( get_option( 'g2a_pos_brain_cron_schedule' ) !== 'nightly-v1' ) {
			wp_clear_scheduled_hook( WebsiteKnowledgeSeeder::CRON_HOOK );
			wp_schedule_event( self::next_nightly_run(), 'daily', WebsiteKnowledgeSeeder::CRON_HOOK );
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, WebsiteKnowledgeSeeder::CRON_HOOK );
			update_option( 'g2a_pos_brain_cron_schedule', 'nightly-v1', false );
		}

		if ( get_option( 'g2a_pos_core_db_version' ) === G2A_POS_CORE_VERSION ) {
			return;
		}
		self::run_migrations();
	}

	/**
	 * Run the schema migration + role sync exactly once at a time.
	 *
	 * A cross-request lock prevents the migration stampede that used to take
	 * sites down: without it, every request between "files replaced" and
	 * "version option saved" ran the full ~90-table dbDelta concurrently.
	 * The request is also detached from the client and freed from the PHP
	 * time limit so a slow shared host can finish the initial install.
	 */
	private static function run_migrations(): void {
		if ( ! self::acquire_migration_lock() ) {
			return; // Another request is already migrating.
		}
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled function on some hosts.
		}
		try {
			Migrator::run();
			Roles::register_roles();
			Roles::register_caps();
		} finally {
			self::release_migration_lock();
		}
	}

	private const MIGRATION_LOCK = 'g2a_pos_migration_lock';

	/**
	 * Atomic lock via add_option (unique key on option_name makes a double
	 * insert impossible). A lock older than 15 minutes is treated as left
	 * behind by a killed request and taken over.
	 */
	private static function acquire_migration_lock(): bool {
		if ( add_option( self::MIGRATION_LOCK, (string) time(), '', false ) ) {
			return true;
		}
		$started = (int) get_option( self::MIGRATION_LOCK );
		if ( $started > 0 && ( time() - $started ) > 15 * MINUTE_IN_SECONDS ) {
			update_option( self::MIGRATION_LOCK, (string) time(), false );
			return true;
		}
		return false;
	}

	private static function release_migration_lock(): void {
		delete_option( self::MIGRATION_LOCK );
	}

	/** Timestamp for the next ~3:15 AM in the site's timezone (true nightly run). */
	private static function next_nightly_run(): int {
		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		try {
			$now = new \DateTimeImmutable( 'now', $tz );
		} catch ( \Throwable $e ) {
			return time() + 3 * HOUR_IN_SECONDS;
		}
		$run = $now->setTime( 3, 15 );
		if ( $run <= $now ) {
			$run = $run->modify( '+1 day' );
		}
		return $run->getTimestamp();
	}

	public static function activate(): void {
		// Locked + detached + untimed: the initial install creates ~90 tables
		// and must survive shared-hosting execution limits.
		self::run_migrations();

		// schedule_all_crons() registers the custom 'minute' interval before
		// scheduling — activation runs before our plugins_loaded boot, so
		// without that the 'minute' events would silently fail to schedule.
		self::schedule_all_crons();

		// Populate the brain shortly after install without blocking activation.
		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, self::BRAIN_SITE_REFRESH_HOOK );

		// Seed the knowledge pack async too — inline seeding here made the
		// ACTIVATION request itself hang whenever the embedding endpoint
		// was slow or unreachable.
		if ( ! WebsiteKnowledgeSeeder::is_seeded() && ! wp_next_scheduled( WebsiteKnowledgeSeeder::SEED_EVENT ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, WebsiteKnowledgeSeeder::SEED_EVENT );
		}
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::NICS_CRON_HOOK );
		wp_clear_scheduled_hook( self::DISTRIBUTOR_SYNC_HOOK );
		wp_clear_scheduled_hook( self::DISTRIBUTOR_HOURLY_HOOK );
		wp_clear_scheduled_hook( self::WHOLESALER_INV_HOOK );
		wp_clear_scheduled_hook( self::MESSAGING_FLUSH_HOOK );
		wp_clear_scheduled_hook( self::KPI_SNAPSHOT_HOOK );
		wp_clear_scheduled_hook( self::MEMBERSHIP_BILLING_HOOK );
		wp_clear_scheduled_hook( self::LAYAWAY_REMINDER_HOOK );
		wp_clear_scheduled_hook( self::COMPLIANCE_CALENDAR_HOOK );
		wp_clear_scheduled_hook( self::VENDOR_PRICE_CAPTURE_HOOK );
		wp_clear_scheduled_hook( self::WOO_CATALOG_SYNC_HOOK );
		wp_clear_scheduled_hook( self::BRAIN_SITE_REFRESH_HOOK );
	}
}
