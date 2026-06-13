<?php
/**
 * Module manifest: Memberistic Memberships.
 *
 * @package G2AB\Modules\Memberistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-memberistic.php';
require_once __DIR__ . '/class-memberistic-settings.php';

return array(
	'id'             => 'memberistic',
	'name'           => 'Memberistic Memberships',
	'desc'           => 'Map Memberistic plans to booking discounts. Per-plan percent discounts including 100% free bookings for selected plans.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'integrations',
	'icon'           => 'M',
	'color'          => '#5B7BFF',
	// Active by default in the G2A bundle so member pricing / members-only
	// gating works out of the box. Safe no-op when Memberistic isn't installed
	// (the bootstrap checks memberistic_active() before doing anything).
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_Memberistic',
	'configure'      => 'admin.php?page=g2ab-settings&tab=memberistic',
);
