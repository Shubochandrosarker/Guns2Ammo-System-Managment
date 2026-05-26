<?php
/**
 * Module manifest: WooCommerce Bridge.
 *
 * @package G2AB\Modules\WooBridge
 */
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-woo-gateway.php';
require_once __DIR__ . '/class-woo-sync.php';
require_once __DIR__ . '/class-woo-settings.php';
require_once __DIR__ . '/class-woo-bridge.php';

return array(
	'id'             => 'woocommerce',
	'name'           => 'WooCommerce Bridge',
	'desc'           => 'Route booking checkout through WooCommerce. Customers pay with any WC gateway (Stripe, PayPal, Square, Authorize.net, manual). Auto-sync booking status with WC order status.',
	'tier'           => 'pro',
	'status'         => 'active',
	'category'       => 'payments',
	'icon'           => 'W',
	'color'          => '#7F54B3',
	'default_active' => false,
	'bootstrap'      => 'G2AB_Module_Woo_Bridge',
	'configure'      => 'admin.php?page=g2ab-settings&tab=woocommerce',
);
