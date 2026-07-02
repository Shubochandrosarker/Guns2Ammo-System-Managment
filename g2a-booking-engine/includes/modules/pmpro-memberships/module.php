<?php
/**
 * Module manifest: Paid Memberships Pro.
 *
 * @package G2AB\Modules\PMPro
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-pmpro-memberships.php';
require_once __DIR__ . '/class-pmpro-settings.php';

return array(
	'id'             => 'pmpro_memberships',
	'name'           => 'Paid Memberships Pro Discounts',
	'desc'           => 'Map PMPro membership levels to booking discounts, including 100% member pricing for free bookings.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'integrations',
	'icon'           => 'P',
	'color'          => '#179BD7',
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_PMPro_Memberships',
	'configure'      => 'admin.php?page=g2ab-settings&tab=pmpro_memberships',
);
