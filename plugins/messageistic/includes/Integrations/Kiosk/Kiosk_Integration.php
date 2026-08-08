<?php
/**
 * KIOSK / Waiver → Messageistic bridge.
 *
 * @package Messageistic\Integrations\Kiosk
 */

namespace Messageistic\Integrations\Kiosk;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class Kiosk_Integration {

    public function register(): void {
        add_action( 'g2a_kiosk_checkin_completed', [ $this, 'on_checkin' ], 10, 1 );
        add_action( 'g2a_waiver_incomplete',       [ $this, 'on_waiver_incomplete' ], 10, 1 );
        // Memberistic waiver lifecycle signals (Phases 3 + 5 of the waiver plan).
        add_action( 'memberistic_waiver_signed',      [ $this, 'on_waiver_signed' ], 10, 2 );
        add_action( 'memberistic_waiver_renewal_due', [ $this, 'on_waiver_renewal_due' ], 10, 1 );
    }

    public function on_checkin( array $checkin ): void { $this->emit( 'kiosk.checkin_completed', $checkin ); }
    public function on_waiver_incomplete( array $waiver ): void { $this->emit( 'waiver.incomplete', $waiver ); }

    /**
     * A waiver was just e-signed (guest, kiosk, or member). Confirmation SMS
     * territory — skipped silently when the signer left no phone number.
     *
     * @param int   $signature_id Signature row id (unused here).
     * @param array $sig          The signature row (signer_name, signer_email,
     *                            phone, expires_at, source, station, …).
     */
    public function on_waiver_signed( $signature_id, $sig = [] ): void {
        $sig = is_array( $sig ) ? $sig : [];
        if ( '' === (string) ( $sig['phone'] ?? '' ) ) {
            return;
        }
        $name  = preg_split( '/\s+/', trim( (string) ( $sig['signer_name'] ?? '' ) ) );
        $first = (string) ( $name[0] ?? '' );
        $last  = count( $name ) > 1 ? implode( ' ', array_slice( $name, 1 ) ) : '';
        $expires = ! empty( $sig['expires_at'] )
            ? date_i18n( get_option( 'date_format' ), strtotime( (string) $sig['expires_at'] ) )
            : '';
        $this->emit( 'waiver.signed', [
            'first_name' => $first,
            'last_name'  => $last,
            'email'      => (string) ( $sig['signer_email'] ?? '' ),
            'phone'      => (string) ( $sig['phone'] ?? '' ),
        ], [ 'waiver_expires' => $expires ] );
    }

    /**
     * A member's waiver expires within the reminder window (fired once per
     * signing cycle by Memberistic, alongside its email). SMS version rides
     * the same once-per-cycle guarantee.
     */
    public function on_waiver_renewal_due( array $payload ): void {
        if ( '' === (string) ( $payload['phone'] ?? '' ) ) {
            return;
        }
        $this->emit( 'waiver.renewal_due', $payload, [
            'waiver_link'    => (string) ( $payload['waiver_link'] ?? '' ),
            'waiver_expires' => (string) ( $payload['waiver_expires'] ?? '' ),
        ] );
    }

    private function emit( string $trigger, array $payload, array $extras = [] ): void {
        $cc = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => (string) ( $payload['first_name'] ?? '' ),
            'last_name'     => (string) ( $payload['last_name'] ?? '' ),
            'email'         => (string) ( $payload['email'] ?? '' ),
            'phone'         => (string) ( $payload['phone'] ?? '' ),
            'source'        => 'kiosk',
            'customer_type' => 'kiosk',
        ], $cc );
        do_action( 'messageistic_trigger', $trigger, [
            'contact_id' => $contact_id,
            'extras'     => array_merge(
                [ 'waiver_link' => (string) ( $payload['waiver_link'] ?? '' ) ],
                $extras
            ),
        ] );
    }
}
