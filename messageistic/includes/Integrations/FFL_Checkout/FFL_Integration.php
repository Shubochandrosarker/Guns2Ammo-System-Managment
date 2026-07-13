<?php
/**
 * FFL Checkout → Messageistic bridge.
 *
 * Listens for `wpistic_ffl_transfer_status_changed( $transfer_id,
 * $old_status, $new_status )` — three positional scalars, fired from six
 * call sites across the `advanced-ffl-checkout` plugin's checkout/status-
 * bridge/carrier/portal code — and looks up the transfer row itself
 * (same-site direct-table-read, the same pattern this codebase's other
 * sibling-plugin bridges use).
 *
 * @package Messageistic\Integrations\FFL_Checkout
 */

namespace Messageistic\Integrations\FFL_Checkout;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class FFL_Integration {

    public function register(): void {
        add_action( 'wpistic_ffl_transfer_status_changed', [ $this, 'on_status_changed' ], 10, 3 );
    }

    /**
     * @param int    $transfer_id
     * @param string $old_status
     * @param string $new_status
     */
    public function on_status_changed( $transfer_id, $old_status, $new_status ): void {
        if ( ! class_exists( '\WpisticFFL\DB' ) ) {
            return; // advanced-ffl-checkout isn't active on this site.
        }

        global $wpdb;
        $table    = \WpisticFFL\DB::table( 'transfers' );
        $transfer = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB
            "SELECT customer_name, customer_email, customer_phone, transfer_ref, item_description
             FROM {$table} WHERE id = %d",
            (int) $transfer_id
        ), ARRAY_A );
        if ( ! $transfer || '' === trim( (string) ( $transfer['customer_phone'] ?? '' ) ) ) {
            return; // No usable phone number on this transfer — nothing to text.
        }

        [ $first_name, $last_name ] = self::split_name( (string) ( $transfer['customer_name'] ?? '' ) );

        $cc         = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $contact_id = Contact_Repository::upsert_by_phone( [
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'email'         => (string) ( $transfer['customer_email'] ?? '' ),
            'phone'         => (string) ( $transfer['customer_phone'] ?? '' ),
            'source'        => 'ffl_checkout',
            'customer_type' => 'ffl',
        ], $cc );

        if ( ! $contact_id ) {
            return; // No usable phone number on this transfer — nothing to text.
        }

        do_action( 'messageistic_trigger', 'ffl.status_changed', [
            'contact_id' => $contact_id,
            'extras'     => [
                'ffl_status'   => (string) $new_status,
                'prior_status' => (string) $old_status,
                'transfer_ref' => (string) ( $transfer['transfer_ref'] ?? '' ),
                'item'         => (string) ( $transfer['item_description'] ?? '' ),
            ],
        ] );
    }

    /** @return array{0:string,1:string} */
    private static function split_name( string $full ): array {
        $full = trim( $full );
        if ( '' === $full ) {
            return [ '', '' ];
        }
        $parts = preg_split( '/\s+/', $full, 2 );
        return [ $parts[0], $parts[1] ?? '' ];
    }
}
