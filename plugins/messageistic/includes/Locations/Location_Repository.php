<?php
/** Multi-location repository. @package Messageistic\Locations */
namespace Messageistic\Locations;

defined( 'ABSPATH' ) || exit;

final class Location_Repository {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_locations';
    }

    public static function all( bool $active_only = false ): array {
        global $wpdb;
        $table = self::table();
        $where = $active_only ? ' WHERE is_active=1' : '';
        return (array) $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY name", ARRAY_A );
    }

    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function save( array $data ): int {
        global $wpdb;
        $id = (int) ( $data['id'] ?? 0 );
        $now = current_time( 'mysql' );
        $row = [
            'name' => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'code' => sanitize_key( (string) ( $data['code'] ?? '' ) ),
            'address' => sanitize_textarea_field( (string) ( $data['address'] ?? '' ) ),
            'city' => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
            'state' => strtoupper( sanitize_text_field( (string) ( $data['state'] ?? '' ) ) ),
            'postal_code' => sanitize_text_field( (string) ( $data['postal_code'] ?? '' ) ),
            'timezone' => sanitize_text_field( (string) ( $data['timezone'] ?? 'America/New_York' ) ),
            'provider_key' => sanitize_key( (string) ( $data['provider_key'] ?? '' ) ),
            'sender' => sanitize_text_field( (string) ( $data['sender'] ?? '' ) ),
            'is_active' => empty( $data['is_active'] ) ? 0 : 1,
            'updated_at' => $now,
        ];
        if ( '' === $row['name'] || '' === $row['code'] ) {
            return 0;
        }
        if ( $id ) {
            $result = $wpdb->update( self::table(), $row, [ 'id' => $id ] );
            return false === $result ? 0 : $id;
        }
        $row['created_at'] = $now;
        return $wpdb->insert( self::table(), $row ) ? (int) $wpdb->insert_id : 0;
    }
}
