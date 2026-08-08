<?php
/**
 * Simple insert-only logger writing to wp_messageistic_logs.
 *
 * @package Messageistic\Helpers
 */

namespace Messageistic\Helpers;

defined( 'ABSPATH' ) || exit;

final class Logger {

    /**
     * Write a log row. All fields except $event_type are optional.
     *
     * @param array<string,mixed> $args
     */
    public static function log( string $event_type, array $args = [] ): void {
        global $wpdb;

        $args = wp_parse_args(
            $args,
            [
                'level'               => 'info',
                'contact_id'          => null,
                'message_id'          => null,
                'provider_key'        => '',
                'provider_message_id' => '',
                'status'              => '',
                'error_message'       => null,
                'raw_response'        => null,
                'context'             => null,
                'user_id'             => null,
            ]
        );

        $row = [
            'event_type'          => substr( $event_type, 0, 80 ),
            'level'               => substr( (string) $args['level'], 0, 20 ),
            'contact_id'          => $args['contact_id'] ? (int) $args['contact_id'] : null,
            'message_id'          => $args['message_id'] ? (int) $args['message_id'] : null,
            'provider_key'        => substr( (string) $args['provider_key'], 0, 40 ),
            'provider_message_id' => substr( (string) $args['provider_message_id'], 0, 190 ),
            'status'              => substr( (string) $args['status'], 0, 40 ),
            'error_message'       => null === $args['error_message'] ? null : (string) $args['error_message'],
            'raw_response'        => self::encode( $args['raw_response'] ),
            'context'             => self::encode( $args['context'] ),
            'user_id'             => $args['user_id'] ? (int) $args['user_id'] : null,
            'created_at'          => current_time( 'mysql' ),
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert( $wpdb->prefix . 'messageistic_logs', $row );
    }

    private static function encode( $value ): ?string {
        if ( null === $value ) {
            return null;
        }
        if ( is_string( $value ) ) {
            return $value;
        }
        return wp_json_encode( $value );
    }
}
