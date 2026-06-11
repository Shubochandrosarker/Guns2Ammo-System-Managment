<?php
/**
 * Generic provider meta store — `(object_type, object_id, provider_key)` →
 * `(provider_object_id, meta_key, meta_value)`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Provider_Meta_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_provider_meta';
    }

    public static function set( string $object_type, int $object_id, string $provider_key, string $provider_object_id, string $meta_key = '', $meta_value = null ): int {
        global $wpdb;
        $table = self::table();
        $now   = current_time( 'mysql' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE object_type = %s AND object_id = %d AND provider_key = %s AND meta_key = %s LIMIT 1",
                $object_type,
                $object_id,
                $provider_key,
                $meta_key
            )
        );

        $row = [
            'object_type'        => $object_type,
            'object_id'          => $object_id,
            'provider_key'       => $provider_key,
            'provider_object_id' => $provider_object_id,
            'meta_key'           => $meta_key,
            'meta_value'         => null === $meta_value ? null : ( is_string( $meta_value ) ? $meta_value : wp_json_encode( $meta_value ) ),
            'updated_at'         => $now,
        ];

        if ( $existing_id ) {
            $wpdb->update( $table, $row, [ 'id' => $existing_id ] );
            return $existing_id;
        }

        $row['created_at'] = $now;
        $wpdb->insert( $table, $row );
        return (int) $wpdb->insert_id;
    }

    public static function get_provider_object_id( string $object_type, int $object_id, string $provider_key, string $meta_key = '' ): string {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (string) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT provider_object_id FROM {$table} WHERE object_type = %s AND object_id = %d AND provider_key = %s AND meta_key = %s LIMIT 1",
                $object_type,
                $object_id,
                $provider_key,
                $meta_key
            )
        );
    }
}
