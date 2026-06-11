<?php
/**
 * FFL Checkout → Messageistic bridge.
 *
 * @package Messageistic\Integrations\FFL_Checkout
 */

namespace Messageistic\Integrations\FFL_Checkout;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class FFL_Integration {

    public function register(): void {
        add_action( 'ffl_transfer_status_changed', [ $this, 'on_status_changed' ], 10, 1 );
    }

    public function on_status_changed( array $transfer ): void {
        $cc = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => (string) ( $transfer['first_name'] ?? '' ),
            'last_name'     => (string) ( $transfer['last_name'] ?? '' ),
            'email'         => (string) ( $transfer['email'] ?? '' ),
            'phone'         => (string) ( $transfer['phone'] ?? '' ),
            'source'        => 'ffl_checkout',
            'customer_type' => 'ffl',
        ], $cc );
        do_action( 'messageistic_trigger', 'ffl.status_changed', [
            'contact_id' => $contact_id,
            'extras'     => [ 'ffl_status' => (string) ( $transfer['status'] ?? '' ) ],
        ] );
    }
}
