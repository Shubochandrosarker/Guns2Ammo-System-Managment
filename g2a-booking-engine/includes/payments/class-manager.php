<?php
/**
 * Gateway Manager — singleton registry for payment gateways.
 *
 * @package G2AB
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class G2AB_Gateway_Manager {

	private static $instance = null;
	private $gateways = array();

	public static function instance() {
		if ( null === self::$instance ) self::$instance = new self();
		return self::$instance;
	}

	private function __construct() {
		// Register built-in gateways. The autoloader pulls class files lazily.
		// The Woocommerce gateway is intentionally NOT registered here — the
		// WooCommerce Bridge module owns it and registers via the
		// `g2ab_register_gateways` action below so activation state is the
		// single source of truth.
		if ( class_exists( 'G2AB_Gateway_Pay_In_Store' ) ) $this->register( new G2AB_Gateway_Pay_In_Store() );
		if ( class_exists( 'G2AB_Gateway_Stripe' ) )       $this->register( new G2AB_Gateway_Stripe() );
		if ( class_exists( 'G2AB_Gateway_Paypal' ) )       $this->register( new G2AB_Gateway_Paypal() );
		if ( class_exists( 'G2AB_Gateway_Fortis' ) )       $this->register( new G2AB_Gateway_Fortis() );
		if ( class_exists( 'G2AB_Gateway_Authnet' ) )      $this->register( new G2AB_Gateway_Authnet() );
		do_action( 'g2ab_register_gateways', $this );
	}

	public function register( $gateway ) {
		if ( ! is_object( $gateway ) || ! method_exists( $gateway, 'id' ) ) return false;
		// Respect addon-manager activation state when available.
		if ( class_exists( 'G2AB_Addon_Manager' ) ) {
			$mgr = G2AB_Addon_Manager::instance();
			if ( $mgr->get( $gateway->id() ) && ! $mgr->is_active( $gateway->id() ) ) {
				return false;
			}
		}
		$this->gateways[ $gateway->id() ] = $gateway;
		return true;
	}

	public function get( $id ) {
		return isset( $this->gateways[ $id ] ) ? $this->gateways[ $id ] : null;
	}

	public function all() {
		return $this->gateways;
	}

	public function available() {
		$out = array();
		foreach ( $this->gateways as $id => $gw ) {
			if ( method_exists( $gw, 'is_available' ) && $gw->is_available() ) $out[ $id ] = $gw;
		}
		return $out;
	}

	/**
	 * Pick gateway for a booking — checks per-booking-type allowlist or falls back to default.
	 *
	 * @param object $booking_type Booking type row.
	 * @return object|null
	 */
	public function pick_for_type( $booking_type ) {
		$default_id = get_option( 'g2ab_payment_gateway_default', 'pay_in_store' );
		$gw = $this->get( $default_id );
		if ( $gw && method_exists( $gw, 'is_available' ) && $gw->is_available() ) return $gw;
		// Fall back to first available.
		foreach ( $this->available() as $g ) return $g;
		return null;
	}
}
