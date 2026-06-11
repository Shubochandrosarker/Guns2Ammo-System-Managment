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
    }

    public function on_checkin( array $checkin ): void { $this->emit( 'kiosk.checkin_completed', $checkin ); }
    public function on_waiver_incomplete( array $waiver ): void { $this->emit( 'waiver.incomplete', $waiver ); }

    private function emit( string $trigger, array $payload ): void {
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
            'extras'     => [ 'waiver_link' => (string) ( $payload['waiver_link'] ?? '' ) ],
        ] );
    }
}
