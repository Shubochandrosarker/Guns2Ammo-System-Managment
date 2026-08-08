<?php
/**
 * WooCommerce Bridge — module bootstrap.
 *
 * @package G2AB\Modules\WooBridge
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Module_Woo_Bridge {

	private static $instance = null;
	private $sync = null;

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// Register the WC gateway adapter via the gateway manager.
		add_action( 'g2ab_register_gateways', array( $this, 'register_gateway' ) );

		// Bidirectional sync — only if WC is active.
		if ( class_exists( 'WooCommerce' ) ) {
			$this->sync = new G2AB_Woo_Sync();
		}

		if ( is_admin() ) new G2AB_Woo_Settings();
	}

	public function register_gateway( $manager ) {
		if ( ! class_exists( 'WooCommerce' ) ) return;
		if ( method_exists( $manager, 'register' ) ) {
			$manager->register( new G2AB_Gateway_Woocommerce() );
		}
	}
}
