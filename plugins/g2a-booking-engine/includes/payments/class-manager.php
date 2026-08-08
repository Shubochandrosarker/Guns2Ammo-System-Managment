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
	 * Pick the gateway for a booking type.
	 *
	 * The docblock here used to promise a per-booking-type allowlist that was
	 * never consulted — the global default was always used. There is still no
	 * `allowed_gateways` column on the booking types table, so the allowlist is
	 * opt-in through the `g2ab_allowed_gateways_for_type` filter (or a column, if
	 * one is ever added). With no allowlist the behaviour is the previous one.
	 *
	 * @param object $booking_type Booking type row.
	 * @return object|null
	 */
	public function pick_for_type( $booking_type ) {
		$allowed = $this->allowed_ids_for_type( $booking_type );

		$default_id = get_option( 'g2ab_payment_gateway_default', 'pay_in_store' );
		if ( ! $allowed || in_array( $default_id, $allowed, true ) ) {
			$gw = $this->get( $default_id );
			if ( $gw && method_exists( $gw, 'is_available' ) && $gw->is_available() ) return $gw;
		}

		// Fall back to the first available gateway the type actually permits.
		foreach ( $this->available() as $id => $g ) {
			if ( $allowed && ! in_array( $id, $allowed, true ) ) continue;
			return $g;
		}
		return null;
	}

	/**
	 * Gateway ids a booking type permits, or an empty array for "no restriction".
	 *
	 * @param object $booking_type Booking type row.
	 * @return string[]
	 */
	private function allowed_ids_for_type( $booking_type ) {
		$raw = is_object( $booking_type ) && isset( $booking_type->allowed_gateways ) ? $booking_type->allowed_gateways : '';

		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : explode( ',', $raw );
		}

		$ids = is_array( $raw ) ? array_filter( array_map( 'sanitize_key', array_map( 'trim', $raw ) ) ) : array();

		return array_values( (array) apply_filters( 'g2ab_allowed_gateways_for_type', $ids, $booking_type ) );
	}
}
