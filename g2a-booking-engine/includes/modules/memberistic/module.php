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
	'default_active' => false,
	'bootstrap'      => 'G2AB_Module_Memberistic',
	'configure'      => 'admin.php?page=g2ab-settings&tab=memberistic',
);
