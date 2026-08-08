<?php
/**
 * Repository for `wp_messageistic_templates`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Template_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_templates';
    }

    public static function create( array $data ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $row = self::row( $data );
        $row['review_status']     = 'pending';
        $row['approved_body_hash'] = '';
        $row['created_at']        = $now;
        $row['updated_at']        = $now;
        $row['created_by']        = get_current_user_id();
        $wpdb->insert( self::table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): int {
        global $wpdb;
        $existing = self::find( $id );
        if ( ! $existing ) {
            return 0;
        }
        $row = self::row( $data );
        $row['updated_at'] = current_time( 'mysql' );
        if ( self::fingerprint( $row ) !== self::fingerprint( $existing ) ) {
            $row['review_status']      = 'pending';
            $row['approved_body_hash'] = '';
            $row['reviewed_by']        = null;
            $row['reviewed_at']        = null;
            $row['review_note']        = '';
        }
        return (int) $wpdb->update( self::table(), $row, [ 'id' => $id ] );
    }

    public static function review( int $id, string $status, string $note = '' ) {
        global $wpdb;
        $template = self::find( $id );
        $status   = sanitize_key( $status );
        if ( ! $template || ! in_array( $status, [ 'approved', 'rejected' ], true ) ) {
            return new \WP_Error( 'messageistic_template_review_invalid', __( 'Invalid template review request.', 'messageistic' ) );
        }
        if ( 'approved' === $status && empty( $template['is_active'] ) ) {
            return new \WP_Error( 'messageistic_template_inactive', __( 'Activate the template before approving it.', 'messageistic' ) );
        }
        return $wpdb->update(
            self::table(),
            [
                'review_status'      => $status,
                'approved_body_hash' => 'approved' === $status ? self::fingerprint( $template ) : '',
                'reviewed_by'        => get_current_user_id(),
                'reviewed_at'        => current_time( 'mysql' ),
                'review_note'        => sanitize_textarea_field( $note ),
                'updated_at'         => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );
    }

    public static function is_approved( int $id, string $classification = '' ): bool {
        $template = self::find( $id );
        if ( ! $template || empty( $template['is_active'] ) || 'approved' !== $template['review_status'] ) {
            return false;
        }
        if ( $classification && sanitize_key( $classification ) !== sanitize_key( (string) $template['classification'] ) ) {
            return false;
        }
        return hash_equals( (string) $template['approved_body_hash'], self::fingerprint( $template ) );
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
        $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY name ASC", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** @param array<string,mixed> $template */
    private static function fingerprint( array $template ): string {
        return hash( 'sha256', sanitize_key( (string) ( $template['classification'] ?? 'transactional' ) ) . "\n" . (string) ( $template['body'] ?? '' ) );
    }

    private static function row( array $data ): array {
        return [
            'location_id'    => ! empty( $data['location_id'] ) ? (int) $data['location_id'] : null,
            'name'           => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'category'       => sanitize_text_field( (string) ( $data['category'] ?? 'general' ) ),
            'classification' => sanitize_key( (string) ( $data['classification'] ?? 'transactional' ) ),
            'body'           => (string) ( $data['body'] ?? '' ),
            'variables'      => is_string( $data['variables'] ?? null ) ? $data['variables'] : wp_json_encode( (array) ( $data['variables'] ?? [] ) ),
            'is_active'      => ! empty( $data['is_active'] ) ? 1 : 0,
        ];
    }
}
