<?php
/**
 * WooCommerce → Messageistic bridge.
 *
 * Emits triggers and upserts a contact when an order completes.
 *
 * @package Messageistic\Integrations\WooCommerce
 */

namespace Messageistic\Integrations\WooCommerce;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class WooCommerce_Integration {

    public function register(): void {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }
        add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ], 10, 1 );
    }

    public function on_order_completed( int $order_id ): void {
        if ( ! function_exists( 'wc_get_order' ) ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $cc = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => (string) $order->get_billing_first_name(),
            'last_name'     => (string) $order->get_billing_last_name(),
            'email'         => (string) $order->get_billing_email(),
            'phone'         => (string) $order->get_billing_phone(),
            'source'        => 'woocommerce',
            'customer_type' => 'customer',
        ], $cc );

        do_action( 'messageistic_conversion', [
            'contact_id'     => $contact_id,
            'conversion_type' => 'purchase',
            'external_id'    => 'woocommerce:' . $order_id,
            'value'          => (float) $order->get_total(),
            'currency'       => (string) $order->get_currency(),
            'metadata'       => [ 'order_number' => (string) $order->get_order_number() ],
        ] );

        do_action( 'messageistic_trigger', 'woocommerce.order_completed', [
            'contact_id' => $contact_id,
            'extras'     => [
                'order_id' => (string) $order->get_order_number(),
            ],
        ] );
    }
}
