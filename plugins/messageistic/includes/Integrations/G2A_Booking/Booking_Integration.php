<?php
/**
 * G2A Booking Engine → Messageistic bridge.
 *
 * Listens to the hooks the booking engine actually fires (`g2ab_*`). Those
 * hooks pass a booking ID (or, from the Woo bridge, a row object) rather
 * than a contact array, so this adapter hydrates the booking row before
 * emitting a `messageistic_trigger` for the Automation engine.
 *
 * Gated by the same "SMS Notifications (Messageistic)" module toggle on the
 * Memberistic → Integrations screen when Memberistic is installed; without
 * Memberistic the bridge stays available so booking SMS can run standalone.
 *
 * @package Messageistic\Integrations\G2A_Booking
 */

namespace Messageistic\Integrations\G2A_Booking;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class Booking_Integration {

    public function register(): void {
        add_action( 'g2ab_booking_created',   [ $this, 'on_created' ], 10, 2 );
        add_action( 'g2ab_booking_cancelled', [ $this, 'on_cancelled' ], 10, 2 );
        add_action( 'g2ab_booking_confirmed', [ $this, 'on_confirmed' ], 10, 1 );
        add_action( 'g2ab_booking_paid',      [ $this, 'on_paid' ], 10, 2 );
    }

    public function on_created( $booking, $context = [] ): void {
        $this->emit( 'booking.created', $booking );
    }

    public function on_cancelled( $booking, $context = [] ): void {
        $this->emit( 'booking.cancelled', $booking );
    }

    public function on_confirmed( $booking ): void {
        $this->emit( 'booking.confirmed', $booking );
    }

    public function on_paid( $booking, $context = [] ): void {
        $this->emit( 'booking.paid', $booking );
    }

    /**
     * When Memberistic is present its "SMS Notifications (Messageistic)"
     * integration toggle is the single switch for all customer texting.
     */
    private function enabled(): bool {
        $registry = '\\WordPressistic\\Memberistic\\Integrations\\Integrations_Registry';
        if ( class_exists( $registry ) && method_exists( $registry, 'is_enabled' ) ) {
            return (bool) $registry::is_enabled( 'sms_reminders' );
        }
        return true;
    }

    /**
     * @param int|object $booking Booking ID or full row object (the Woo
     *                            bridge passes the object directly).
     */
    private function emit( string $trigger, $booking ): void {
        if ( ! $this->enabled() ) {
            return;
        }

        $row = is_object( $booking ) ? $booking : $this->hydrate( (int) $booking );
        if ( ! $row || empty( $row->customer_phone ) ) {
            return;
        }

        $full  = trim( (string) ( $row->customer_name ?? '' ) );
        $parts = '' === $full ? [] : preg_split( '/\s+/', $full );
        $first = $parts ? array_shift( $parts ) : '';
        $last  = $parts ? implode( ' ', $parts ) : '';

        $cc         = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => $first,
            'last_name'     => $last,
            'email'         => (string) ( $row->customer_email ?? '' ),
            'phone'         => (string) $row->customer_phone,
            'source'        => 'g2a_booking',
            'customer_type' => 'booking',
        ], $cc );

        // start_at is stored as site-local wall-clock time, so parse it in
        // the site timezone and format directly (no UTC round-trip).
        $start = (string) ( $row->start_at ?? '' );
        $dt    = $start ? date_create_immutable( $start, wp_timezone() ) : false;

        do_action( 'messageistic_trigger', $trigger, [
            'contact_id' => $contact_id,
            'booking_id' => (int) ( $row->id ?? 0 ),
            'extras'     => [
                'booking_date'   => $dt ? $dt->format( get_option( 'date_format', 'F j, Y' ) ) : $start,
                'booking_time'   => $dt ? $dt->format( get_option( 'time_format', 'g:i a' ) ) : '',
                'booking_status' => (string) ( $row->status ?? '' ),
                'booking_uuid'   => (string) ( $row->uuid ?? '' ),
                'party_size'     => (string) (int) ( $row->party_size ?? 1 ),
            ],
        ] );
    }

    private function hydrate( int $booking_id ): ?object {
        if ( $booking_id <= 0 ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, uuid, customer_name, customer_email, customer_phone, start_at, end_at, status, party_size
             FROM {$wpdb->prefix}g2ab_bookings WHERE id = %d LIMIT 1",
            $booking_id
        ) );
        return $row ?: null;
    }
}
