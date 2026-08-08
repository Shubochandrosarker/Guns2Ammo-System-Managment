<?php
/**
 * Replaces {variable} tokens in template bodies.
 *
 * Built-in variables come from the contact + general settings; arbitrary
 * extras can be passed via $extras (e.g. booking_date, order_id).
 *
 * @package Messageistic\Messaging
 */

namespace Messageistic\Messaging;

use Messageistic\Locations\Location_Repository;

defined( 'ABSPATH' ) || exit;

final class Template_Parser {

    /**
     * @param array<string,mixed> $contact
     * @param array<string,mixed> $extras
     */
    public static function render( string $body, array $contact = [], array $extras = [] ): string {
        $general = (array) get_option( 'messageistic_general_settings', [] );
        $first   = (string) ( $contact['first_name'] ?? '' );
        $last    = (string) ( $contact['last_name'] ?? '' );
        $location = ! empty( $contact['location_id'] ) ? Location_Repository::find( (int) $contact['location_id'] ) : null;

        $vars = array_merge(
            [
                'first_name'       => $first,
                'last_name'        => $last,
                'full_name'        => trim( $first . ' ' . $last ),
                'business_name'    => (string) ( $general['business_name'] ?? get_bloginfo( 'name' ) ),
                'phone'            => (string) ( $contact['normalized_phone'] ?? $contact['phone'] ?? '' ),
                'location'         => (string) ( $location['name'] ?? $general['business_location'] ?? '' ),
                'unsubscribe_text' => (string) apply_filters( 'messageistic_unsubscribe_text', __( 'Reply STOP to opt out.', 'messageistic' ) ),
            ],
            $extras
        );

        return preg_replace_callback(
            '/\{([a-z0-9_]+)\}/i',
            static function ( array $m ) use ( $vars ) {
                $key = strtolower( $m[1] );
                return array_key_exists( $key, $vars ) ? (string) $vars[ $key ] : $m[0];
            },
            $body
        );
    }
}
