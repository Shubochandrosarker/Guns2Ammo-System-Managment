<?php
/**
 * Single entry point for sending SMS through the active provider.
 *
 * Responsibilities:
 *  - Resolve contact by id or phone
 *  - Block opted-out / no-consent contacts
 *  - Persist a `messages` row before/after the provider call
 *  - Bump conversation activity
 *  - Emit logs
 *
 * @package Messageistic\Messaging
 */

namespace Messageistic\Messaging;

use Messageistic\Compliance\Policy_Engine;
use Messageistic\Compliance\Pilot_Send_Lock;
use Messageistic\Core\Plugin;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Conversation_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Database\Optout_Repository;
use Messageistic\Database\Provider_Meta_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Helpers\Logger;
use Messageistic\Helpers\Phone_Normalizer;
use Messageistic\Locations\Location_Repository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class SMS_Service {

    /**
     * @param array<string,mixed> $args { contact_id?, phone?, body, campaign_id?, automation_id?, classification?, idempotency_key? }
     * @return array<string,mixed>|WP_Error
     */
    public function send( array $args ) {
        $contact = $this->resolve_contact( $args );
        if ( is_wp_error( $contact ) ) {
            return $contact;
        }

        $general     = (array) get_option( 'messageistic_general_settings', [] );
        $template_id = (int) ( $args['template_id'] ?? 0 );
        $body        = trim( (string) ( $args['body'] ?? '' ) );
        if ( $template_id > 0 && ( '' === $body || ! empty( $general['pilot_mode_enabled'] ) ) ) {
            $template = Template_Repository::find( $template_id );
            if ( $template ) {
                $body = Template_Parser::render( (string) $template['body'], $contact, (array) ( $args['template_extras'] ?? [] ) );
            }
        }
        if ( '' === $body ) {
            return new WP_Error( 'messageistic_empty_body', __( 'Message body or an approved template is required.', 'messageistic' ) );
        }

        $providers   = Plugin::instance()->providers();
        $provider    = $providers->get_active();
        $location_id = (int) ( $args['location_id'] ?? $contact['location_id'] ?? 0 );
        $location    = $location_id ? Location_Repository::find( $location_id ) : null;
        if ( $location && ! empty( $location['provider_key'] ) && sanitize_key( (string) $location['provider_key'] ) !== $provider->get_key() ) {
            return new WP_Error( 'messageistic_location_provider_mismatch', __( 'The selected location is configured for a different provider.', 'messageistic' ) );
        }

        // Serialize the policy check (Policy_Engine::evaluate()'s daily/contact
        // frequency COUNT(*) reads) and the message row that satisfies it into
        // one atomic step for EVERY send, not just pilot-mode ones — otherwise
        // two concurrent sends can both read a count under the limit and both
        // proceed, silently exceeding the configured caps.
        $pilot_locked = Pilot_Send_Lock::acquire();
        if ( ! $pilot_locked ) {
            return new WP_Error( 'messageistic_send_lock_timeout', __( 'Could not reserve a send slot. Please retry.', 'messageistic' ) );
        }

        $idempotency_key = sanitize_text_field( (string) ( $args['idempotency_key'] ?? '' ) );
        if ( $idempotency_key ) {
            $existing = Message_Repository::find_by_idempotency_key( $idempotency_key );
            if ( $existing ) {
                if ( $pilot_locked ) {
                    Pilot_Send_Lock::release();
                }
                return [ 'message_id' => (int) $existing['id'], 'result' => [ 'status' => $existing['status'], 'idempotent' => true ] ];
            }
        }

        $from           = ! empty( $general['pilot_mode_enabled'] ) ? (string) ( $general['pilot_sender'] ?? '' ) : (string) ( $location['sender'] ?? $general['business_phone'] ?? '' );
        $classification = (string) ( $args['classification'] ?? 'transactional' );
        $args['sender'] = $from;
        $args['template_id'] = $template_id;
        $args['template_approved'] = $template_id > 0 && Template_Repository::is_approved( $template_id, $classification );
        $args['provider_settings'] = method_exists( $provider, 'get_settings' ) ? $provider->get_settings() : [];
        $args['location_timezone'] = (string) ( $location['timezone'] ?? '' );

        $policy = Policy_Engine::evaluate( $contact, $args, $provider->get_key() );
        if ( is_wp_error( $policy ) ) {
            Logger::log( 'sms.blocked', [
                'level'         => 'warning',
                'contact_id'    => (int) $contact['id'],
                'provider_key'  => $provider->get_key(),
                'status'        => 'blocked',
                'error_message' => $policy->get_error_message(),
                'context'       => [ 'code' => $policy->get_error_code(), 'classification' => $args['classification'] ?? 'transactional' ],
            ] );
            if ( $pilot_locked ) {
                Pilot_Send_Lock::release();
            }
            return $policy;
        }
        $payload = [
            'to'         => (string) $contact['normalized_phone'],
            'from'       => $from,
            'body'       => $body,
            'contact_id' => Provider_Meta_Repository::get_provider_object_id( 'contact', (int) $contact['id'], $provider->get_key() ) ?: ( $contact['provider_contact_id'] ?? '' ),
        ];

        $conversation_id = Conversation_Repository::get_or_create( (int) $contact['id'] );

        $message_id = Message_Repository::create( [
            'location_id'     => $location_id ?: null,
            'conversation_id' => $conversation_id,
            'contact_id'      => (int) $contact['id'],
            'campaign_id'     => $args['campaign_id'] ?? null,
            'automation_id'   => $args['automation_id'] ?? null,
            'classification'  => $policy['classification'],
            'idempotency_key' => $idempotency_key,
            'direction'       => 'outbound',
            'body'            => $body,
            'from_number'     => $from,
            'to_number'       => (string) $contact['normalized_phone'],
            'status'          => 'queued',
            'provider_key'    => $provider->get_key(),
        ] );
        if ( $pilot_locked ) {
            Pilot_Send_Lock::release();
        }
        if ( ! $message_id ) {
            return new WP_Error( 'messageistic_message_reservation_failed', __( 'Could not reserve the outbound message.', 'messageistic' ) );
        }

        $result = $providers->send( $payload );

        if ( is_wp_error( $result ) ) {
            Message_Repository::update( $message_id, [
                'status'        => 'failed',
                'error_message' => $result->get_error_message(),
            ] );
            return $result;
        }

        Message_Repository::update( $message_id, [
            'status'                => $result['status'] ?? 'sent',
            'provider_message_id'   => (string) ( $result['provider_message_id'] ?? '' ),
            'provider_status'       => (string) ( $result['status'] ?? '' ),
            'provider_raw_response' => $result['raw'] ?? null,
            'sent_at'               => current_time( 'mysql' ),
        ] );

        Conversation_Repository::bump( $conversation_id );
        Contact_Repository::touch_last_message( (int) $contact['id'] );

        Logger::log( 'sms.outbound', [
            'contact_id'          => (int) $contact['id'],
            'message_id'          => $message_id,
            'provider_key'        => $provider->get_key(),
            'provider_message_id' => (string) ( $result['provider_message_id'] ?? '' ),
            'status'              => (string) ( $result['status'] ?? '' ),
        ] );

        return [ 'message_id' => $message_id, 'result' => $result ];
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|WP_Error
     */
    private function resolve_contact( array $args ) {
        if ( ! empty( $args['contact_id'] ) ) {
            $contact = Contact_Repository::find( (int) $args['contact_id'] );
            if ( ! $contact ) {
                return new WP_Error( 'messageistic_contact_not_found', __( 'Contact not found.', 'messageistic' ) );
            }
            return $contact;
        }

        $phone = (string) ( $args['phone'] ?? '' );
        if ( '' === $phone ) {
            return new WP_Error( 'messageistic_no_recipient', __( 'A contact_id or phone is required.', 'messageistic' ) );
        }
        $cc         = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $normalized = Phone_Normalizer::normalize( $phone, $cc );
        $contact    = Contact_Repository::find_by_normalized_phone( $normalized );
        if ( $contact ) {
            return $contact;
        }
        $id = Contact_Repository::create( [
            'phone'            => $phone,
            'normalized_phone' => $normalized,
            'source'           => (string) ( $args['source'] ?? 'manual' ),
        ] );
        return Contact_Repository::find( $id );
    }
}
