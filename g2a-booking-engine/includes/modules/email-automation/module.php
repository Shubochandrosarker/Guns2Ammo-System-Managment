<?php
/**
 * Module manifest: Email Automation.
 *
 * @package G2AB\Modules\EmailAutomation
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Load the bootstrap class.
require_once __DIR__ . '/class-email-engine.php';
require_once __DIR__ . '/class-email-cron.php';
require_once __DIR__ . '/class-email-settings.php';
require_once __DIR__ . '/class-email-automation.php';

return array(
	'id'             => 'email_automation',
	'name'           => 'Email Automation',
	'desc'           => 'Automated transactional emails for every booking lifecycle event — confirmed, paid, reminder, cancelled, no-show, completed.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'notifications',
	'icon'           => 'M',
	'color'          => '#4A5D3A',
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_Email_Automation',
	'configure'      => 'admin.php?page=g2ab-settings&tab=email_automation',
);
