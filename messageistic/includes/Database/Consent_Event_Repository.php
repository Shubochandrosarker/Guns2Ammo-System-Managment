<?php
/**
 * Append-only consent evidence ledger.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

defined( 'ABSPATH' ) || exit;

final class Consent_Event_Repository {
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_consent_events';
    }

    public static function record( array $data ): int {
        global $wpdb;
        $row = [
            'contact_id'         => (int) ( $data['contact_id'] ?? 0 ),
            'phone'              => sanitize_text_field( (string) ( $data['phone'] ?? '' ) ),
            'consent_type'       => sanitize_key( (string) ( $data['consent_type'] ?? 'transactional' ) ),
            'event_type'         => sanitize_key( (string) ( $data['event_type'] ?? 'granted' ) ),
            'source'             => sanitize_key( (string) ( $data['source'] ?? 'manual' ) ),
            'disclosure_version' => sanitize_text_field( (string) ( $data['disclosure_version'] ?? '' ) ),
            'disclosure_text'    => sanitize_textarea_field( (string) ( $data['disclosure_text'] ?? '' ) ),
            'source_url'         => esc_url_raw( (string) ( $data['source_url'] ?? '' ) ),
            'ip_address'         => sanitize_text_field( (string) ( $data['ip_address'] ?? '' ) ),
            'user_agent'         => sanitize_text_field( (string) ( $data['user_agent'] ?? '' ) ),
            'evidence'           => wp_json_encode( (array) ( $data['evidence'] ?? [] ) ),
            'recorded_by'        => (int) ( $data['recorded_by'] ?? get_current_user_id() ),
            'created_at'         => current_time( 'mysql' ),
        ];
        $wpdb->insert( self::table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function has_active_consent( int $contact_id, string $type ): bool {
        global $wpdb;
        $table = self::table();
        $event = $wpdb->get_row( $wpdb->prepare(
            "SELECT event_type, source, evidence FROM {$table} WHERE contact_id = %d AND consent_type = %s ORDER BY id DESC LIMIT 1",
            $contact_id,
            sanitize_key( $type )
        ), ARRAY_A );
        if ( ! is_array( $event ) || ! in_array( $event['event_type'], [ 'granted', 'confirmed' ], true ) ) {
            return false;
        }
        $evidence = json_decode( (string) $event['evidence'], true );
        return 'legacy_migration' !== $event['source'] && empty( $evidence['review_required'] );
    }

    public static function for_contact( int $contact_id ): array {
        global $wpdb;
        $table = self::table();
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE contact_id = %d ORDER BY id DESC", $contact_id ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
