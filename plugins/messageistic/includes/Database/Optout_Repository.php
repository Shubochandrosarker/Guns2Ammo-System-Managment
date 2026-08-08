<?php
/**
 * Repository for `wp_messageistic_optouts`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Optout_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_optouts';
    }

    public static function record( array $data ): int {
        global $wpdb;
        $row = [
            'contact_id'        => isset( $data['contact_id'] ) ? (int) $data['contact_id'] : null,
            'phone'             => (string) ( $data['phone'] ?? '' ),
            'normalized_phone'  => (string) ( $data['normalized_phone'] ?? '' ),
            'keyword'           => (string) ( $data['keyword'] ?? '' ),
            'provider_key'      => (string) ( $data['provider_key'] ?? '' ),
            'source'            => (string) ( $data['source'] ?? 'inbound' ),
            'note'              => isset( $data['note'] ) ? (string) $data['note'] : null,
            'created_at'        => current_time( 'mysql' ),
        ];
        $wpdb->insert( self::table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function is_opted_out_phone( string $normalized ): bool {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE normalized_phone = %s", $normalized ) );
        return $count > 0;
    }
}
