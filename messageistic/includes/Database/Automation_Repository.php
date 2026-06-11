<?php
/**
 * Repository for `wp_messageistic_automations`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Automation_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_automations';
    }

    public static function create( array $data ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = self::row( $data );
        $row['created_at'] = $row['updated_at'] = $now;
        $row['created_by'] = get_current_user_id();
        $wpdb->insert( self::table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): int {
        global $wpdb;
        $row = self::row( $data );
        $row['updated_at'] = current_time( 'mysql' );
        return (int) $wpdb->update( self::table(), $row, [ 'id' => $id ] );
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( self::table(), [ 'id' => $id ] );
    }

    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<int,array<string,mixed>> */
    public static function active_for_trigger( string $trigger_key ): array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE is_active = 1 AND trigger_key = %s", $trigger_key ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function bump_run( int $id ): void {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET run_count = run_count + 1, last_run_at = %s, updated_at = %s WHERE id = %d", current_time( 'mysql' ), current_time( 'mysql' ), $id ) );
    }

    private static function row( array $data ): array {
        return [
            'location_id'   => ! empty( $data['location_id'] ) ? (int) $data['location_id'] : null,
            'version'       => max( 1, (int) ( $data['version'] ?? 1 ) ),
            'name'          => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'trigger_key'   => sanitize_key( (string) ( $data['trigger_key'] ?? '' ) ),
            'conditions'    => is_string( $data['conditions'] ?? null ) ? $data['conditions'] : wp_json_encode( (array) ( $data['conditions'] ?? [] ) ),
            'delay_seconds' => max( 0, (int) ( $data['delay_seconds'] ?? 0 ) ),
            'template_id'   => isset( $data['template_id'] ) ? (int) $data['template_id'] : null,
            'actions'       => is_string( $data['actions'] ?? null ) ? $data['actions'] : wp_json_encode( (array) ( $data['actions'] ?? [] ) ),
            'is_active'     => ! empty( $data['is_active'] ) ? 1 : 0,
        ];
    }
}
