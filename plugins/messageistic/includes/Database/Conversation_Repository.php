<?php
/**
 * Repository for `wp_messageistic_conversations`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Conversation_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_conversations';
    }

    public static function get_or_create( int $contact_id ): int {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE contact_id = %d ORDER BY id DESC LIMIT 1", $contact_id ) );
        if ( $existing ) {
            return $existing;
        }
        $now = current_time( 'mysql' );
        $contacts = $wpdb->prefix . 'messageistic_contacts';
        $location_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT location_id FROM {$contacts} WHERE id = %d", $contact_id ) );
        $wpdb->insert( $table, [
            'contact_id'      => $contact_id,
            'location_id'     => $location_id ?: null,
            'status'          => 'open',
            'last_message_at' => $now,
            'created_at'      => $now,
            'updated_at'      => $now,
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function bump( int $id, bool $unread = false ): void {
        global $wpdb;
        $table = self::table();
        $sets  = [
            'last_message_at' => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        ];
        if ( $unread ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET unread_count = unread_count + 1, last_message_at = %s, updated_at = %s WHERE id = %d", $sets['last_message_at'], $sets['updated_at'], $id ) );
            return;
        }
        $wpdb->update( $table, $sets, [ 'id' => $id ] );
    }

    public static function set_status( int $id, string $status ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
    }

    public static function update_workflow( int $id, array $data ): void {
        global $wpdb;
        $current = $wpdb->get_row( $wpdb->prepare( 'SELECT status,assigned_user_id,priority,location_id FROM ' . self::table() . ' WHERE id=%d', $id ), ARRAY_A );
        $allowed = [];
        foreach ( [ 'status', 'assigned_user_id', 'priority', 'location_id' ] as $key ) {
            if ( array_key_exists( $key, $data ) ) { $allowed[ $key ] = 'assigned_user_id' === $key || 'location_id' === $key ? (int) $data[ $key ] : sanitize_key( (string) $data[ $key ] ); }
        }
        $allowed['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::table(), $allowed, [ 'id' => $id ] );
        foreach ( $allowed as $key => $value ) {
            if ( 'updated_at' !== $key && (string) ( $current[ $key ] ?? '' ) !== (string) $value ) {
                Conversation_Note_Repository::event( $id, $key, (string) ( $current[ $key ] ?? '' ), (string) $value );
            }
        }
    }

    public static function mark_read( int $id ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'unread_count' => 0, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function inbox( int $limit = 50, string $status = '', int $assigned_user_id = 0, int $location_id = 0 ): array {
        global $wpdb;
        $c = self::table();
        $contacts = $wpdb->prefix . 'messageistic_contacts';
        $where = [];
        $params = [];
        if ( '' !== $status ) {
            $where[] = 'c.status = %s';
            $params[] = $status;
        }
        if ( $assigned_user_id > 0 ) {
            $where[] = 'c.assigned_user_id = %d';
            $params[] = $assigned_user_id;
        }
        if ( $location_id > 0 ) {
            $where[] = 'c.location_id = %d';
            $params[] = $location_id;
        }
        $where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
        $params[] = $limit;
        $messages = $wpdb->prefix . 'messageistic_messages';
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, ct.first_name, ct.last_name, ct.normalized_phone,
                (SELECT m.body FROM {$messages} m WHERE m.contact_id = c.contact_id ORDER BY m.id DESC LIMIT 1) AS last_body,
                (SELECT m.direction FROM {$messages} m WHERE m.contact_id = c.contact_id ORDER BY m.id DESC LIMIT 1) AS last_direction
             FROM {$c} c LEFT JOIN {$contacts} ct ON ct.id = c.contact_id {$where_sql} ORDER BY c.last_message_at DESC LIMIT %d",
            ...$params
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function find( int $id ): ?array {
        global $wpdb;
        $c        = self::table();
        $contacts = $wpdb->prefix . 'messageistic_contacts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT c.*, ct.first_name, ct.last_name, ct.normalized_phone, ct.email FROM {$c} c LEFT JOIN {$contacts} ct ON ct.id = c.contact_id WHERE c.id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }
}
