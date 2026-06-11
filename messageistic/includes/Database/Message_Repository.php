<?php
/**
 * Repository for `wp_messageistic_messages`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Message_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_messages';
    }

    public static function create( array $data ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = [
            'location_id'           => ! empty( $data['location_id'] ) ? (int) $data['location_id'] : null,
            'conversation_id'       => isset( $data['conversation_id'] ) ? (int) $data['conversation_id'] : null,
            'contact_id'            => (int) ( $data['contact_id'] ?? 0 ),
            'campaign_id'           => isset( $data['campaign_id'] ) ? (int) $data['campaign_id'] : null,
            'automation_id'         => isset( $data['automation_id'] ) ? (int) $data['automation_id'] : null,
            'classification'        => sanitize_key( (string) ( $data['classification'] ?? 'transactional' ) ),
            'idempotency_key'       => ! empty( $data['idempotency_key'] ) ? sanitize_text_field( (string) $data['idempotency_key'] ) : null,
            'direction'             => (string) ( $data['direction'] ?? 'outbound' ),
            'body'                  => (string) ( $data['body'] ?? '' ),
            'from_number'           => (string) ( $data['from_number'] ?? '' ),
            'to_number'             => (string) ( $data['to_number'] ?? '' ),
            'status'                => (string) ( $data['status'] ?? 'queued' ),
            'provider_key'          => (string) ( $data['provider_key'] ?? '' ),
            'provider_message_id'   => (string) ( $data['provider_message_id'] ?? '' ),
            'provider_status'       => (string) ( $data['provider_status'] ?? '' ),
            'provider_raw_response' => isset( $data['provider_raw_response'] ) ? ( is_string( $data['provider_raw_response'] ) ? $data['provider_raw_response'] : wp_json_encode( $data['provider_raw_response'] ) ) : null,
            'error_message'         => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
            'sent_at'               => $data['sent_at'] ?? null,
            'delivered_at'          => $data['delivered_at'] ?? null,
            'created_at'            => $now,
            'updated_at'            => $now,
        ];
        $wpdb->insert( self::table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): int {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql' );
        if ( isset( $data['provider_raw_response'] ) && ! is_string( $data['provider_raw_response'] ) ) {
            $data['provider_raw_response'] = wp_json_encode( $data['provider_raw_response'] );
        }
        return (int) $wpdb->update( self::table(), $data, [ 'id' => $id ] );
    }

    public static function find_by_provider_message_id( string $provider_key, string $provider_message_id ): ?array {
        global $wpdb;
        if ( '' === $provider_message_id ) {
            return null;
        }
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE provider_key = %s AND provider_message_id = %s LIMIT 1", $provider_key, $provider_message_id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }


    public static function find_by_idempotency_key( string $key ): ?array {
        global $wpdb;
        if ( '' === $key ) {
            return null;
        }
        $table = self::table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s LIMIT 1", $key ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function outbound_attempt_count_since( string $since ): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE direction = 'outbound' AND created_at >= %s", $since ) );
    }

    public static function outbound_count_since( string $since ): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE direction = 'outbound' AND created_at >= %s AND status NOT IN ('failed','blocked')", $since ) );
    }

    public static function contact_outbound_count_since( int $contact_id, string $since ): int {
        global $wpdb;
        $table = self::table();
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE contact_id = %d AND direction = 'outbound' AND created_at >= %s AND status NOT IN ('failed','blocked')", $contact_id, $since ) );
    }

    public static function for_contact( int $contact_id, int $limit = 50 ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d ORDER BY id DESC LIMIT %d", $contact_id, $limit ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
