<?php
/**
 * Module manifest: Booking Plugin Migration Tool.
 *
 * Migrates bookings, customers, resources, services, and payments from
 * Amelia plus a generic CSV adapter. Bookly and BookingPress are queued
 * as coming-soon adapters. Idempotent (uses external_ref), supports dry-run, batched
 * via Action Scheduler, with full rollback by run_id.
 *
 * @package G2AB\Modules\Migration
 * @since   1.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-migration-logger.php';
require_once __DIR__ . '/adapters/class-adapter-base.php';
require_once __DIR__ . '/adapters/class-adapter-amelia.php';
require_once __DIR__ . '/adapters/class-adapter-bookly.php';
require_once __DIR__ . '/adapters/class-adapter-bookingpress.php';
require_once __DIR__ . '/adapters/class-adapter-csv.php';
require_once __DIR__ . '/class-migration-runner.php';
require_once __DIR__ . '/class-migration-admin.php';
require_once __DIR__ . '/class-migration.php';

return array(
	'id'             => 'migration',
	'name'           => __( 'Migration Tool', 'g2a-booking' ),
	'desc'           => __( 'Migrate bookings from Amelia or CSV. Bookly and BookingPress are marked coming soon. One-click wizard with dry-run preview, batched processing, and rollback.', 'g2a-booking' ),
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'tools',
	'icon'           => 'M',
	'color'          => '#1B5E20',
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_Migration',
	'configure'      => 'admin.php?page=g2ab-migration',
);
