<?php
/**
 * Translates normalized provider webhook events into core actions:
 * insert inbound messages, sync delivery status, record opt-outs.
 *
 * @package Messageistic\Messaging
 */

namespace Messageistic\Messaging;

use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Consent_Event_Repository;
use Messageistic\Database\Conversation_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Database\Optout_Repository;
use Messageistic\Helpers\Logger;
use Messageistic\Helpers\Phone_Normalizer;

defined( 'ABSPATH' ) || exit;

final class Inbound_Handler {

    public function handle( string $provider_key, array $event ): void {
        $raw = (array) ( $event['raw'] ?? [] );

        // Twilio shape: from/to/body at top-level of raw payload (or under 'payload').
        $payload = isset( $raw['payload'] ) && is_array( $raw['payload'] ) ? $raw['payload'] : $raw;
        $from    = (string) ( $raw['from'] ?? $payload['From'] ?? $payload['from'] ?? '' );
        $to      = (string) ( $raw['to'] ?? $payload['To'] ?? $payload['to'] ?? '' );
        $body    = (string) ( $raw['body'] ?? $payload['Body'] ?? $payload['body'] ?? $payload['message'] ?? '' );
        $cc      = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $normalized = Phone_Normalizer::normalize( $from, $cc );
        if ( '' === $normalized ) {
            return;
        }

        $contact = Contact_Repository::find_by_normalized_phone( $normalized );
        if ( ! $contact ) {
            $id = Contact_Repository::create( [
                'phone'            => $from,
                'normalized_phone' => $normalized,
                'source'           => $provider_key . '_inbound',
                'active_provider'  => $provider_key,
            ] );
            $contact = Contact_Repository::find( $id );
        }
        if ( ! $contact ) {
            return;
        }

        $provider_message_id = (string) ( $event['provider_message_id'] ?? '' );
        if ( $provider_message_id && Message_Repository::find_by_provider_message_id( $provider_key, $provider_message_id ) ) {
            return;
        }

        $conversation_id = Conversation_Repository::get_or_create( (int) $contact['id'] );

        $status = (string) ( $event['status'] ?? 'received' );

        if ( 'optout' === $status ) {
            Contact_Repository::set_opt_out( (int) $contact['id'], true );
            Optout_Repository::record( [
                'contact_id'       => (int) $contact['id'],
                'phone'            => $from,
                'normalized_phone' => $normalized,
                'keyword'          => strtoupper( trim( $body ) ),
                'provider_key'     => $provider_key,
                'source'           => 'inbound',
            ] );
            foreach ( [ 'transactional', 'marketing' ] as $consent_type ) {
                Consent_Event_Repository::record( [ 'contact_id' => (int) $contact['id'], 'phone' => $normalized, 'consent_type' => $consent_type, 'event_type' => 'revoked', 'source' => $provider_key . '_keyword', 'evidence' => [ 'keyword' => strtoupper( trim( $body ) ) ] ] );
            }
            Logger::log( 'optout.recorded', [
                'contact_id'   => (int) $contact['id'],
                'provider_key' => $provider_key,
                'status'       => 'opted_out',
            ] );
        } elseif ( 'optin' === $status ) {
            Contact_Repository::set_opt_out( (int) $contact['id'], false );
            Contact_Repository::set_consent( (int) $contact['id'], true, $provider_key . '_keyword' );
            Consent_Event_Repository::record( [ 'contact_id' => (int) $contact['id'], 'phone' => $normalized, 'consent_type' => 'transactional', 'event_type' => 'granted', 'source' => $provider_key . '_keyword', 'evidence' => [ 'keyword' => strtoupper( trim( $body ) ) ] ] );
            Logger::log( 'optin.recorded', [
                'contact_id'   => (int) $contact['id'],
                'provider_key' => $provider_key,
                'status'       => 'opted_in',
            ] );
        } elseif (
            'received' === $status
            && empty( $contact['opt_out'] )
            && apply_filters( 'messageistic_inbound_grants_transactional_consent', true, $contact, $event )
            && ! Consent_Event_Repository::has_active_consent( (int) $contact['id'], 'transactional' )
        ) {
            // A customer-initiated message is consent evidence for transactional
            // replies (the TCPA inquiry principle) — without this, the fail-closed
            // policy engine blocks every reply to a first-time texter. Marketing
            // consent still requires an explicit opt-in, and opt-outs are never
            // overridden here (only a START keyword can do that, above).
            Contact_Repository::set_consent( (int) $contact['id'], true, $provider_key . '_inbound' );
            Consent_Event_Repository::record( [
                'contact_id'   => (int) $contact['id'],
                'phone'        => $normalized,
                'consent_type' => 'transactional',
                'event_type'   => 'granted',
                'source'       => $provider_key . '_inbound',
                'evidence'     => [
                    'reason'              => 'customer_initiated_message',
                    'provider_message_id' => $provider_message_id,
                    'body_excerpt'        => mb_substr( $body, 0, 120 ),
                ],
            ] );
            Logger::log( 'consent.inbound_inquiry', [
                'contact_id'   => (int) $contact['id'],
                'provider_key' => $provider_key,
                'status'       => 'granted',
            ] );
        }

        Message_Repository::create( [
            'location_id'           => ! empty( $contact['location_id'] ) ? (int) $contact['location_id'] : null,
            'conversation_id'       => $conversation_id,
            'contact_id'            => (int) $contact['id'],
            'direction'             => 'inbound',
            'body'                  => $body,
            'from_number'           => $normalized,
            'to_number'             => $to,
            'status'                => 'received',
            'provider_key'          => $provider_key,
            'provider_message_id'   => $provider_message_id,
            'idempotency_key'       => $provider_message_id ? 'inbound:' . $provider_key . ':' . $provider_message_id : null,
            'provider_raw_response' => $event['raw'] ?? null,
        ] );

        Conversation_Repository::bump( $conversation_id, true );
        Contact_Repository::touch_last_message( (int) $contact['id'] );
    }

    public function update_status( string $provider_key, array $event ): void {
        $message_id = (string) ( $event['provider_message_id'] ?? '' );
        if ( '' === $message_id ) {
            return;
        }
        $existing = Message_Repository::find_by_provider_message_id( $provider_key, $message_id );
        if ( ! $existing ) {
            return;
        }
        $status     = (string) ( $event['status'] ?? '' );
        $delivered  = in_array( $status, [ 'delivered', 'read' ], true ) ? current_time( 'mysql' ) : null;
        $local_status = $this->map_status( $status );
        Message_Repository::update( (int) $existing['id'], [
            'status'                => $local_status,
            'provider_status'       => $status,
            'provider_raw_response' => $event['raw'] ?? null,
            'delivered_at'          => $delivered,
        ] );
    }

    private function map_status( string $provider_status ): string {
        return match ( strtolower( $provider_status ) ) {
            'delivered', 'read' => 'delivered',
            'failed', 'undelivered' => 'failed',
            'sent', 'sending' => 'sent',
            'queued', 'accepted' => 'queued',
            default => $provider_status ?: 'unknown',
        };
    }
}
