<?php
/**
 * WooCommerce bridge foundation.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Activity_Repository;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\Payments_Repository;
use function WordPressistic\Memberistic\memberistic_get_setting;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WooCommerce_Bridge {
	public static function register() {
		add_action( 'woocommerce_order_status_completed', array( self::class, 'sync_completed_order' ) );
		add_action( 'woocommerce_order_refunded', array( self::class, 'sync_refunded_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'sync_refunded_order' ) );
		add_filter( 'memberistic_woocommerce_enabled', array( self::class, 'is_enabled' ) );
		add_action( 'memberistic_ensure_woocommerce_products', array( self::class, 'ensure_default_products' ) );
	}

	public static function is_enabled() {
		return 'yes' === memberistic_get_setting( 'woocommerce_enabled', 'no' ) && class_exists( 'WooCommerce' );
	}

	/**
	 * Create or update the six hidden virtual products that mirror the
	 * Defender / Patriot / Guardian × monthly / annual catalog. Safe to call
	 * repeatedly — products are matched by SKU.
	 *
	 * Called manually from the Integrations settings; not run at boot to keep
	 * activation cheap on hosts without WooCommerce.
	 */
	public static function ensure_default_products() {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return array();
		}

		$plans   = \WordPressistic\Memberistic\Database\Plans_Repository::get_all( array( 'status' => 'active' ) );
		$created = array();

		foreach ( $plans as $plan ) {
			foreach ( array( 'monthly' => (float) $plan['monthly_price'], 'annual' => (float) $plan['annual_price'] ) as $cycle => $price ) {
				if ( $price <= 0 ) {
					continue;
				}

				$sku        = sprintf( 'memberistic-%s-%s', sanitize_title( $plan['slug'] ), $cycle );
				$product_id = wc_get_product_id_by_sku( $sku );

				$product = $product_id ? wc_get_product( $product_id ) : new \WC_Product_Simple();
				$product->set_name( sprintf( '%s Membership — %s', $plan['name'], ucfirst( $cycle ) ) );
				$product->set_sku( $sku );
				$product->set_status( 'publish' );
				$product->set_catalog_visibility( 'hidden' );
				$product->set_virtual( true );
				$product->set_regular_price( $price );
				$product->set_price( $price );
				$product->set_meta_data(
					array(
						'_memberistic_plan_id' => (int) $plan['id'],
						'_memberistic_cycle'   => $cycle,
					)
				);
				$product->update_meta_data( '_memberistic_plan_id', (int) $plan['id'] );
				$product->update_meta_data( '_memberistic_cycle', $cycle );

				$created[] = $product->save();
			}
		}

		return $created;
	}

	/**
	 * Refund / cancel an order — flip its membership to cancelled.
	 */
	public static function sync_refunded_order( $order_id ) {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$membership_id = absint( $order->get_meta( '_memberistic_membership_id' ) );

		if ( ! $membership_id ) {
			return;
		}

		Memberships_Repository::change_status( $membership_id, 'cancelled' );

		Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'membership_cancelled',
				'title'               => __( 'Membership cancelled via WooCommerce refund or cancellation', 'memberistic' ),
				'related_object_type' => 'woo_order',
				'related_object_id'   => $order_id,
			)
		);
	}

	public static function sync_completed_order( $order_id ) {
		if ( ! self::is_enabled() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		$membership_id = absint( $order->get_meta( '_memberistic_membership_id' ) );

		if ( ! $membership_id ) {
			return;
		}

		$membership = Memberships_Repository::get( $membership_id );

		if ( ! $membership ) {
			return;
		}

		Memberships_Repository::update(
			$membership_id,
			array(
				'status'          => 'active',
				'woo_customer_id' => $order->get_customer_id(),
			)
		);

		$payment_id = Payments_Repository::create(
			array(
				'membership_id'   => $membership_id,
				'amount'          => $order->get_total(),
				'currency'        => $order->get_currency(),
				'payment_method'  => $order->get_payment_method(),
				'payment_gateway' => 'woocommerce',
				'woo_order_id'    => $order_id,
				'status'          => 'completed',
				'paid_at'         => current_time( 'mysql' ),
				'raw_response'    => array( 'source' => 'woocommerce_order_completed' ),
			)
		);

		Activity_Repository::log(
			array(
				'membership_id'       => $membership_id,
				'activity_type'       => 'payment_completed',
				'title'               => __( 'WooCommerce order completed', 'memberistic' ),
				'related_object_type' => 'woo_order',
				'related_object_id'   => $order_id,
			)
		);

		do_action( 'memberistic_membership_payment_recorded', $membership_id, $payment_id, 'woocommerce' );
	}
}
