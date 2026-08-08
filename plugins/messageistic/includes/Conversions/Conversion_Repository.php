<?php
/** Conversion attribution repository. @package Messageistic\Conversions */
namespace Messageistic\Conversions;
defined( 'ABSPATH' ) || exit;
final class Conversion_Repository {
    public static function record( array $data ): int {
        global $wpdb;
        $messages = $wpdb->prefix . 'messageistic_messages';
        $contact = (int) ( $data['contact_id'] ?? 0 );
        $lookback = max( 1, (int) ( $data['lookback_days'] ?? 30 ) );
        $attribution = $wpdb->get_row( $wpdb->prepare( "SELECT id,campaign_id,automation_id,location_id FROM {$messages} WHERE contact_id=%d AND direction='outbound' AND created_at >= %s ORDER BY created_at DESC LIMIT 1", $contact, wp_date( 'Y-m-d H:i:s', time() - $lookback * DAY_IN_SECONDS ) ), ARRAY_A );
        $external_id = sanitize_text_field( (string) ( $data['external_id'] ?? '' ) );
        if ( '' === $external_id ) {
            $external_id = wp_generate_uuid4();
        }
        $row = [
            'contact_id' => $contact,
            'location_id' => (int) ( $data['location_id'] ?? ( $attribution['location_id'] ?? 0 ) ) ?: null,
            'message_id' => (int) ( $attribution['id'] ?? 0 ) ?: null,
            'campaign_id' => (int) ( $attribution['campaign_id'] ?? 0 ) ?: null,
            'automation_id' => (int) ( $attribution['automation_id'] ?? 0 ) ?: null,
            'conversion_type' => sanitize_key( (string) ( $data['conversion_type'] ?? 'purchase' ) ),
            'external_id' => $external_id,
            'value' => (float) ( $data['value'] ?? 0 ),
            'currency' => strtoupper( sanitize_key( (string) ( $data['currency'] ?? 'USD' ) ) ),
            'metadata' => wp_json_encode( (array) ( $data['metadata'] ?? [] ) ),
            'converted_at' => $data['converted_at'] ?? current_time( 'mysql' ),
            'created_at' => current_time( 'mysql' ),
        ];
        $wpdb->insert( $wpdb->prefix . 'messageistic_conversion_events', $row );
        return (int) $wpdb->insert_id;
    }
}
