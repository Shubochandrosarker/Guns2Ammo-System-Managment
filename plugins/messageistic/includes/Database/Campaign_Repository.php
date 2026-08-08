<?php
/**
 * Repository for `wp_messageistic_campaigns` and `wp_messageistic_campaign_recipients`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Campaign_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_campaigns';
    }

    public static function recipients_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_campaign_recipients';
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

    public static function set_status( int $id, string $status, array $extra = [] ): void {
        global $wpdb;
        $sets = array_merge( [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ], $extra );
        $wpdb->update( self::table(), $sets, [ 'id' => $id ] );
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
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 100", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function add_recipients( int $campaign_id, array $contact_ids ): int {
        global $wpdb;
        $count = 0;
        $now   = current_time( 'mysql' );
        foreach ( array_unique( array_map( 'intval', $contact_ids ) ) as $cid ) {
            if ( $cid <= 0 ) {
                continue;
            }
            $result = $wpdb->insert(
                self::recipients_table(),
                [
                    'campaign_id' => $campaign_id,
                    'contact_id'  => $cid,
                    'status'      => 'pending',
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]
            );
            if ( false !== $result ) {
                $count++;
            }
        }
        $wpdb->update( self::table(), [ 'total_recipients' => $count, 'updated_at' => $now ], [ 'id' => $campaign_id ] );
        return $count;
    }

    /** @return array<int,array<string,mixed>> */
    public static function claim_recipients( int $campaign_id, int $limit = 25 ): array {
        global $wpdb;
        $r = self::recipients_table();
        $now = current_time( 'mysql', true );
        $stale = wp_date( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( "UPDATE {$r} SET status = 'pending', lock_token = '', locked_at = NULL WHERE campaign_id = %d AND status = 'processing' AND locked_at < %s", $campaign_id, $stale ) );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$r} WHERE campaign_id = %d AND status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d", $campaign_id, $now, $limit ) );
        if ( empty( $ids ) ) {
            return [];
        }
        $token = wp_generate_uuid4();
        $claimed = [];
        foreach ( array_map( 'intval', $ids ) as $id ) {
            $updated = $wpdb->query( $wpdb->prepare( "UPDATE {$r} SET status = 'processing', lock_token = %s, locked_at = %s, attempt_count = attempt_count + 1, updated_at = %s WHERE id = %d AND status = 'pending'", $token, $now, $now, $id ) );
            if ( $updated ) {
                $claimed[] = $id;
            }
        }
        if ( empty( $claimed ) ) {
            return [];
        }
        $placeholders = implode( ',', array_fill( 0, count( $claimed ), '%d' ) );
        $query = $wpdb->prepare( "SELECT * FROM {$r} WHERE id IN ({$placeholders}) AND lock_token = %s ORDER BY id ASC", array_merge( $claimed, [ $token ] ) );
        $rows = $wpdb->get_results( $query, ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    public static function update_recipient( int $recipient_id, array $data ): void {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql' );
        $wpdb->update( self::recipients_table(), $data, [ 'id' => $recipient_id ] );
    }

    public static function increment_counter( int $campaign_id, string $field, int $by = 1 ): void {
        global $wpdb;
        $table = self::table();
        $field = preg_replace( '/[^a-z_]/', '', $field );
        if ( '' === $field ) {
            return;
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$field} = {$field} + %d, updated_at = %s WHERE id = %d", $by, current_time( 'mysql' ), $campaign_id ) );
    }

    public static function pending_count( int $campaign_id ): int {
        global $wpdb;
        $r = self::recipients_table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$r} WHERE campaign_id = %d AND status IN ('pending','processing')", $campaign_id ) );
    }

    private static function row( array $data ): array {
        return [
            'location_id'          => ! empty( $data['location_id'] ) ? (int) $data['location_id'] : null,
            'name'                 => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'classification'       => sanitize_key( (string) ( $data['classification'] ?? 'marketing' ) ),
            'type'                 => sanitize_key( (string) ( $data['type'] ?? 'broadcast' ) ),
            'audience'             => is_string( $data['audience'] ?? null ) ? $data['audience'] : wp_json_encode( (array) ( $data['audience'] ?? [] ) ),
            'provider_mode'        => sanitize_key( (string) ( $data['provider_mode'] ?? 'queue' ) ),
            'template_id'          => isset( $data['template_id'] ) && $data['template_id'] ? (int) $data['template_id'] : null,
            'body'                 => (string) ( $data['body'] ?? '' ),
            'schedule_at'          => $data['schedule_at'] ?? null,
            'status'               => sanitize_key( (string) ( $data['status'] ?? 'draft' ) ),
        ];
    }
}
