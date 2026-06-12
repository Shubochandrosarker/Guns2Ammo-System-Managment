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
		Roles::register_caps();
		SecurityHeaders::boot();
		Privacy::boot();
		self::maybe_upgrade();

		add_action( 'rest_api_init', array( Routes::class, 'register' ) );
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
				$provider->syncInventory( (int) $w['id'] );
				$repo->markSyncedNow( (int) $w['id'] );
			} catch ( \Throwable $e ) {
				Logger::exception( 'Wholesaler sync failed', $e, array( 'wholesaler_id' => (int) $w['id'] ) );
			}
		}
	}

	private static function maybe_upgrade(): void {
		// Seed the default website knowledge pack once per pack version
		// (guarded internally by option g2a_pos_brain_seeded_version).
		WebsiteKnowledgeSeeder::maybe_seed();

		if ( get_option( 'g2a_pos_core_db_version' ) === G2A_POS_CORE_VERSION ) {
			return;
		}
		Migrator::run();
		Roles::register_roles();
		Roles::register_caps();
	}

	public static function activate(): void {
		Migrator::run();
		Roles::register_roles();
		Roles::register_caps();

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
			// Stagger first run 5 minutes after activation so it doesn't fight the activation request, then run hourly.
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
			// Weekly website-knowledge refresh for the AI brain.
			wp_schedule_event( time() + 12 * HOUR_IN_SECONDS, 'weekly', self::BRAIN_SITE_REFRESH_HOOK );
		}

		WebsiteKnowledgeSeeder::maybe_seed();
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
