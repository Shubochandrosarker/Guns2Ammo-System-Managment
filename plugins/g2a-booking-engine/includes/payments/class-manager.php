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

	/*
	 * `pick_for_type()` and its `allowed_ids_for_type()` helper were removed in
	 * 1.12.0. Nothing called either one: every payable path resolves its
	 * gateway through G2AB_Checkout_Policy::pick_online_gateway(), which
	 * rejects offline gateways outright. The dead method defaulted to
	 * `pay_in_store` when `g2ab_payment_gateway_default` was unset, so wiring
	 * it up would have handed an offline gateway to a public booking — the
	 * exact failure this release closes. Gateway selection has one authority.
	 */
}
