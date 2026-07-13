<?php
/**
 * Module manifest: Advanced FFL Checkout integration.
 *
 * @package G2AB\Modules\FflCheckout
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-ffl-integration.php';

return array(
	'id'             => 'ffl_checkout',
	'name'           => 'Advanced FFL Checkout',
	'desc'           => 'Real pickup-appointment scheduling for the online FFL firearm-transfer checkout plugin. Syncs each receiving dealer to a resource, exposes availability, and pushes confirmed/cancelled bookings back onto the transfer record — replacing the guessed-time calendar invite that plugin used before this module existed.',
	'tier'           => 'free',
	'status'         => 'active',
	'category'       => 'integrations',
	'icon'           => 'F',
	'color'          => '#B45309',
	'default_active' => true,
	'bootstrap'      => 'G2AB_Module_Ffl_Checkout',
);
