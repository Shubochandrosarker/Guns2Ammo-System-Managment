<?php
/**
 * Module manifest: Verifyistic Age Verification integration.
 *
 * @package G2AB\Modules\Verifyistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-verifyistic-integration.php';
require_once __DIR__ . '/class-verifyistic-settings.php';

return array(
	'id'             => 'verifyistic',
	'name'           => 'Verifyistic Age Verification',
	'desc'           => 'Link the Verifyistic age-verification popup to bookings. Auto-accept the liability waiver for age-verified visitors, optionally require verification before booking, and store the verification token + verified DOB on every booking for compliance audit.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'integrations',
	'icon'           => 'V',
	'color'          => '#14b8a6',
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_Verifyistic',
	'configure'      => 'admin.php?page=g2ab-settings&tab=verifyistic',
);
