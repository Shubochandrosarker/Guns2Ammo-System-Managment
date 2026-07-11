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
        // The real action advanced-ffl-checkout fires is
        // `wpistic_ffl_transfer_status_changed( $transfer_id, $old_status, $new_status )`
        // — this previously listened for a differently-named action
        // (`ffl_transfer_status_changed`) with a single array argument that
        // advanced-ffl-checkout never fires, so this bridge never ran; FFL
        // transfer SMS worked only via a separate path straight to
        // Verifyistic's webhook dispatcher, bypassing this plugin's TCPA
        // consent/quiet-hours/frequency-cap engine entirely for that message
        // type. Fixed to match the real hook name and signature.
        add_action( 'wpistic_ffl_transfer_status_changed', [ $this, 'on_status_changed' ], 10, 3 );
    }

    public function on_status_changed( int $transfer_id, string $old_status, string $new_status ): void {
        global $wpdb;
        $table    = $wpdb->prefix . 'wpistic_ffl_transfers';
        $transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            "SELECT customer_name, customer_email, customer_phone FROM {$table} WHERE id = %d",
            $transfer_id
        ), ARRAY_A );
        if ( ! $transfer || '' === (string) $transfer['customer_phone'] ) {
            return;
        }

        $name_parts = preg_split( '/\s+/', trim( (string) $transfer['customer_name'] ), 2 );
        $first_name = $name_parts[0] ?? '';
        $last_name  = $name_parts[1] ?? '';

        $cc = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'email'         => (string) $transfer['customer_email'],
            'phone'         => (string) $transfer['customer_phone'],
            'source'        => 'ffl_checkout',
            'customer_type' => 'ffl',
        ], $cc );
        do_action( 'messageistic_trigger', 'ffl.status_changed', [
            'contact_id' => $contact_id,
            'extras'     => [
                'ffl_status'     => $new_status,
                'ffl_old_status' => $old_status,
            ],
        ] );
    }
}
