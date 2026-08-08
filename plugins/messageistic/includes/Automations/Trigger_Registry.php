<?php
/**
 * Catalogue of automation trigger keys + labels.
 *
 * Integration classes (Phase 9) emit these via do_action( 'messageistic_trigger', $key, $context ).
 *
 * @package Messageistic\Automations
 */

namespace Messageistic\Automations;

defined( 'ABSPATH' ) || exit;

final class Trigger_Registry {

    /** @return array<string,string> */
    public static function all(): array {
        return apply_filters( 'messageistic_triggers', [
            'booking.created'                => __( 'Booking created', 'messageistic' ),
            'booking.cancelled'              => __( 'Booking cancelled', 'messageistic' ),
            'booking.rescheduled'            => __( 'Booking rescheduled', 'messageistic' ),
            'booking.reminder_24h'           => __( '24 hours before booking', 'messageistic' ),
            'booking.reminder_2h'            => __( '2 hours before booking', 'messageistic' ),
            'membership.created'             => __( 'Membership created', 'messageistic' ),
            'membership.expiring_soon'       => __( 'Membership expiring soon', 'messageistic' ),
            'membership.expired'             => __( 'Membership expired', 'messageistic' ),
            'woocommerce.order_completed'    => __( 'WooCommerce order completed', 'messageistic' ),
            'ffl.status_changed'             => __( 'FFL transfer status changed', 'messageistic' ),
            'waiver.incomplete'              => __( 'Waiver incomplete', 'messageistic' ),
            'waiver.signed'                  => __( 'Waiver signed', 'messageistic' ),
            'waiver.renewal_due'             => __( 'Waiver renewal due', 'messageistic' ),
            'kiosk.checkin_completed'        => __( 'KIOSK check-in completed', 'messageistic' ),
            'customer.inactive_x_days'       => __( 'Customer inactive for X days', 'messageistic' ),
        ] );
    }
}
