<?php
/**
 * WordPress privacy integration and retention cleanup.
 *
 * @package Messageistic\Privacy
 */

namespace Messageistic\Privacy;

use Messageistic\Database\Contact_Repository;

defined( 'ABSPATH' ) || exit;

final class Privacy_Manager {
    public const CLEANUP_HOOK = 'messageistic_privacy_cleanup';

    public static function register(): void {
        add_filter( 'wp_privacy_personal_data_exporters', [ self::class, 'exporters' ] );
        add_filter( 'wp_privacy_personal_data_erasers', [ self::class, 'erasers' ] );
        add_action( self::CLEANUP_HOOK, [ self::class, 'cleanup' ] );
        if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
            wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
        }
    }

    public static function exporters( array $exporters ): array {
        $exporters['messageistic'] = [ 'exporter_friendly_name' => __( 'Messageistic messaging data', 'messageistic' ), 'callback' => [ self::class, 'export' ] ];
        return $exporters;
    }

    public static function erasers( array $erasers ): array {
        $erasers['messageistic'] = [ 'eraser_friendly_name' => __( 'Messageistic messaging data', 'messageistic' ), 'callback' => [ self::class, 'erase' ] ];
        return $erasers;
    }

    public static function export( string $email, int $page = 1 ): array {
        global $wpdb;
        $contacts = Contact_Repository::table();
        $messages = $wpdb->prefix . 'messageistic_messages';
        $contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contacts} WHERE email = %s LIMIT 1", sanitize_email( $email ) ), ARRAY_A );
        if ( ! $contact ) {
            return [ 'data' => [], 'done' => true ];
        }
        $items = [ [ 'group_id' => 'messageistic-contact', 'group_label' => __( 'Messageistic contact', 'messageistic' ), 'item_id' => 'contact-' . $contact['id'], 'data' => self::fields( $contact ) ] ];
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$messages} WHERE contact_id = %d ORDER BY id ASC LIMIT 100 OFFSET %d", $contact['id'], ( $page - 1 ) * 100 ), ARRAY_A );
        foreach ( (array) $rows as $row ) {
            $items[] = [ 'group_id' => 'messageistic-messages', 'group_label' => __( 'Messageistic messages', 'messageistic' ), 'item_id' => 'message-' . $row['id'], 'data' => self::fields( $row ) ];
        }
        return [ 'data' => $items, 'done' => count( (array) $rows ) < 100 ];
    }

    public static function erase( string $email, int $page = 1 ): array {
        global $wpdb;
        $contacts = Contact_Repository::table();
        $contact = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$contacts} WHERE email = %s LIMIT 1", sanitize_email( $email ) ), ARRAY_A );
        if ( ! $contact ) {
            return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
        }
        $id = (int) $contact['id'];
        $wpdb->update( $contacts, [ 'first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'normalized_phone' => 'erased-' . $id, 'tags' => null, 'date_of_birth' => null, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
        $wpdb->update( $wpdb->prefix . 'messageistic_messages', [ 'body' => '[erased]', 'from_number' => '', 'to_number' => '', 'provider_raw_response' => null, 'updated_at' => current_time( 'mysql' ) ], [ 'contact_id' => $id ] );
        return [ 'items_removed' => true, 'items_retained' => true, 'messages' => [ __( 'Consent and opt-out evidence was retained for legal compliance.', 'messageistic' ) ], 'done' => true ];
    }

    public static function cleanup(): void {
        global $wpdb;
        $settings = (array) get_option( 'messageistic_general_settings', [] );
        $cutoffs = [
            $wpdb->prefix . 'messageistic_messages' => max( 1, (int) ( $settings['message_retention_days'] ?? 365 ) ),
            $wpdb->prefix . 'messageistic_logs' => max( 1, (int) ( $settings['log_retention_days'] ?? 90 ) ),
            $wpdb->prefix . 'messageistic_consent_events' => max( 365, (int) ( $settings['consent_retention_days'] ?? 2555 ) ),
        ];
        foreach ( $cutoffs as $table => $days ) {
            $cutoff = wp_date( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
            if ( str_ends_with( $table, 'messageistic_messages' ) ) {
                $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET body = '[expired]', from_number = '', to_number = '', provider_raw_response = NULL, updated_at = %s WHERE created_at < %s AND body <> '[expired]' LIMIT 1000", current_time( 'mysql' ), $cutoff ) );
            } else {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 1000", $cutoff ) );
            }
        }
        self::cleanup_imports();
    }

    private static function cleanup_imports(): void {
        $uploads = wp_upload_dir();
        $dir = trailingslashit( $uploads['basedir'] ) . 'messageistic-imports';
        foreach ( (array) glob( trailingslashit( $dir ) . '*.csv' ) as $file ) {
            if ( is_file( $file ) && filemtime( $file ) < time() - DAY_IN_SECONDS ) {
                wp_delete_file( $file );
            }
        }
    }

    private static function fields( array $row ): array {
        $out = [];
        foreach ( $row as $name => $value ) {
            if ( in_array( $name, [ 'provider_raw_response', 'evidence' ], true ) ) {
                continue;
            }
            $out[] = [ 'name' => ucwords( str_replace( '_', ' ', $name ) ), 'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ];
        }
        return $out;
    }
}
