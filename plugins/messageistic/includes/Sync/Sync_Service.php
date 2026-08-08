<?php
/**
 * Provider → Messageistic sync orchestrator.
 *
 * Headline use case: with API key + business/account ID + (optional) list/
 * campaign ID, scan the provider's entire contact universe and import it
 * into wp_messageistic_contacts.
 *
 * Runs in batches via Action Scheduler when present, WP-Cron otherwise.
 * Job state is stored in a single option keyed by provider so the admin
 * can poll progress and cancel.
 *
 * @package Messageistic\Sync
 */

namespace Messageistic\Sync;

use Messageistic\Core\Plugin;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Message_Repository;
use Messageistic\Database\Provider_Meta_Repository;
use Messageistic\Helpers\Logger;
use Messageistic\Helpers\Phone_Normalizer;
use Messageistic\Providers\OtterText\OtterText_Client;
use Messageistic\Providers\Twilio\Twilio_Client;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Sync_Service {

    public const HOOK = 'messageistic_sync_tick';
    public const PER_BATCH = 100;

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'tick' ], 10, 1 );
    }

    /**
     * Kick off a sync. Returns the new job or a WP_Error.
     *
     * @param array<string,mixed> $args { kind?, list_id?, campaign_id? }
     * @return array<string,mixed>|WP_Error
     */
    public static function start( string $provider_key, array $args = [] ) {
        $providers = Plugin::instance()->providers();
        $provider  = $providers->get( $provider_key );
        if ( ! $provider ) {
            return new WP_Error( 'messageistic_sync_unknown_provider', __( 'Unknown provider.', 'messageistic' ) );
        }

        $existing = Sync_Job_Repository::get( $provider_key );
        if ( $existing && in_array( $existing['status'], [ 'queued', 'running' ], true ) ) {
            return new WP_Error( 'messageistic_sync_already_running', __( 'A sync is already running for this provider.', 'messageistic' ) );
        }

        $kind = (string) ( $args['kind'] ?? ( 'twilio' === $provider_key ? 'inbound_contacts' : 'contacts' ) );
        $job  = Sync_Job_Repository::start( $provider_key, $kind, $args );

        Logger::log( 'sync.started', [
            'provider_key' => $provider_key,
            'status'       => 'queued',
            'context'      => [ 'job_id' => $job['id'], 'kind' => $kind, 'list_id' => $job['list_id'], 'campaign_id' => $job['campaign_id'] ],
        ] );

        self::enqueue( $provider_key );
        return $job;
    }

    public static function cancel( string $provider_key ): void {
        $job = Sync_Job_Repository::get( $provider_key );
        if ( ! $job ) {
            return;
        }
        $job['status']     = 'cancelled';
        $job['last_error'] = __( 'Cancelled by user.', 'messageistic' );
        Sync_Job_Repository::save( $job );
        Logger::log( 'sync.cancelled', [ 'provider_key' => $provider_key, 'context' => [ 'job_id' => $job['id'] ] ] );
    }

    private static function enqueue( string $provider_key, int $delay = 0 ): void {
        if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
            if ( $delay > 0 ) {
                as_schedule_single_action( time() + $delay, self::HOOK, [ $provider_key ], 'messageistic' );
            } else {
                as_enqueue_async_action( self::HOOK, [ $provider_key ], 'messageistic' );
            }
            return;
        }
        wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK, [ $provider_key ] );
    }

    public static function tick( string $provider_key ): void {
        $job = Sync_Job_Repository::get( $provider_key );
        if ( ! $job || in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            return;
        }
        $job['status'] = 'running';
        Sync_Job_Repository::save( $job );

        try {
            $job = match ( $provider_key ) {
                'ottertext' => self::ottertext_batch( $job ),
                'twilio'    => self::twilio_batch( $job ),
                default     => self::fail( $job, 'Unsupported provider for sync.' ),
            };
        } catch ( \Throwable $e ) {
            $job = self::fail( $job, $e->getMessage() );
        }

        Sync_Job_Repository::save( $job );

        if ( 'running' === $job['status'] ) {
            self::enqueue( $provider_key, 5 );
            return;
        }

        Logger::log( 'sync.completed', [
            'provider_key' => $provider_key,
            'status'       => $job['status'],
            'context'      => [
                'job_id'   => $job['id'],
                'fetched'  => $job['fetched'],
                'imported' => $job['imported'],
                'updated'  => $job['updated'],
                'skipped'  => $job['skipped'],
                'failed'   => $job['failed'],
            ],
        ] );
    }

    /** @param array<string,mixed> $job */
    private static function ottertext_batch( array $job ): array {
        $providers = Plugin::instance()->providers();
        $provider  = $providers->get( 'ottertext' );
        $settings  = $provider instanceof \Messageistic\Providers\Abstract_Provider ? $provider->get_settings() : [];

        $check = $provider->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return self::fail( $job, $check->get_error_message() );
        }

        $client = new OtterText_Client( $settings );
        $page   = $job['page'] ?? 1;
        $cursor = $job['cursor'] ?? null;

        $batch = $client->list_contacts(
            (int) $page,
            $cursor,
            self::PER_BATCH,
            $job['list_id'] ?: null,
            $job['campaign_id'] ?: null
        );

        if ( ! empty( $batch['error'] ) ) {
            $err = $batch['error'];
            return self::fail( $job, $err instanceof WP_Error ? $err->get_error_message() : 'Provider error' );
        }

        if ( null === $job['total'] && null !== $batch['total'] ) {
            $job['total'] = (int) $batch['total'];
        }

        foreach ( (array) $batch['items'] as $item ) {
            self::import_contact_row( $job, $item, 'ottertext' );
        }

        $job['fetched'] += count( (array) $batch['items'] );

        if ( $batch['next_cursor'] ) {
            $job['cursor'] = $batch['next_cursor'];
            return $job;
        }
        if ( $batch['next_page'] ) {
            $job['page']   = (int) $batch['next_page'];
            $job['cursor'] = null;
            return $job;
        }
        $job['status'] = 'completed';
        return $job;
    }

    /**
     * Twilio doesn't expose a contact list. We scan inbound Messages and
     * upsert one Messageistic contact per unique sender phone, optionally
     * also recording the historical inbound messages.
     *
     * @param array<string,mixed> $job
     */
    private static function twilio_batch( array $job ): array {
        $providers = Plugin::instance()->providers();
        $provider  = $providers->get( 'twilio' );
        $settings  = $provider instanceof \Messageistic\Providers\Abstract_Provider ? $provider->get_settings() : [];

        $check = $provider->validate_settings( $settings );
        if ( is_wp_error( $check ) ) {
            return self::fail( $job, $check->get_error_message() );
        }
        $client = new Twilio_Client( $settings );

        $args = [
            'page_size' => self::PER_BATCH,
            'direction' => 'inbound',
        ];
        if ( ! empty( $job['next_page_uri'] ) ) {
            $args['next_page_uri'] = (string) $job['next_page_uri'];
        }
        if ( ! empty( $settings['from_number'] ) ) {
            // Filter for messages addressed to OUR number; for MSS users, omit.
            $args['to'] = (string) $settings['from_number'];
        }

        $batch = $client->list_messages( $args );
        if ( is_wp_error( $batch ) ) {
            return self::fail( $job, $batch->get_error_message() );
        }

        foreach ( (array) $batch['items'] as $msg ) {
            self::import_twilio_message_contact( $job, $msg );
        }
        $job['fetched'] += count( (array) $batch['items'] );

        if ( ! empty( $batch['next_page_uri'] ) ) {
            $job['next_page_uri'] = (string) $batch['next_page_uri'];
            return $job;
        }
        $job['next_page_uri'] = null;
        $job['status']        = 'completed';
        return $job;
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $row
     */
    private static function import_contact_row( array &$job, array $row, string $provider_key ): void {
        $cc    = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $phone = (string) ( $row['phone'] ?? $row['phone_number'] ?? $row['mobile'] ?? '' );
        if ( '' === $phone ) {
            $job['skipped']++;
            return;
        }
        $normalized = Phone_Normalizer::normalize( $phone, $cc );
        if ( '' === $normalized ) {
            $job['skipped']++;
            return;
        }
        $existing = Contact_Repository::find_by_normalized_phone( $normalized );
        $data = [
            'first_name'         => (string) ( $row['first_name'] ?? $row['firstName'] ?? $row['fname'] ?? '' ),
            'last_name'          => (string) ( $row['last_name']  ?? $row['lastName']  ?? $row['lname'] ?? '' ),
            'email'              => (string) ( $row['email']      ?? '' ),
            'phone'              => $phone,
            'normalized_phone'   => $normalized,
            'source'             => $provider_key . '_sync',
            'tags'               => is_array( $row['tags'] ?? null ) ? wp_json_encode( $row['tags'] ) : (string) ( $row['tags'] ?? '' ),
            'active_provider'    => $provider_key,
            'provider_contact_id' => (string) ( $row['id'] ?? $row['contact_id'] ?? '' ),
        ];
        if ( $existing ) {
            Contact_Repository::update( (int) $existing['id'], $data );
            $contact_id = (int) $existing['id'];
            $job['updated']++;
        } else {
            $contact_id = Contact_Repository::create( $data );
            $job['imported']++;
        }
        $external_id = (string) ( $row['id'] ?? $row['contact_id'] ?? '' );
        if ( '' !== $external_id ) {
            Provider_Meta_Repository::set( 'contact', $contact_id, $provider_key, $external_id );
        }
    }

    /**
     * @param array<string,mixed> $job
     * @param array<string,mixed> $msg
     */
    private static function import_twilio_message_contact( array &$job, array $msg ): void {
        $cc    = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $from  = (string) ( $msg['from'] ?? $msg['From'] ?? '' );
        $to    = (string) ( $msg['to']   ?? $msg['To']   ?? '' );
        $body  = (string) ( $msg['body'] ?? $msg['Body'] ?? '' );
        $sid   = (string) ( $msg['sid']  ?? '' );
        $status = (string) ( $msg['status'] ?? '' );

        if ( '' === $from ) {
            $job['skipped']++;
            return;
        }
        $normalized = Phone_Normalizer::normalize( $from, $cc );
        if ( '' === $normalized ) {
            $job['skipped']++;
            return;
        }

        $existing = Contact_Repository::find_by_normalized_phone( $normalized );
        if ( $existing ) {
            $contact_id = (int) $existing['id'];
            $job['updated']++;
        } else {
            $contact_id = Contact_Repository::create( [
                'phone'            => $from,
                'normalized_phone' => $normalized,
                'source'           => 'twilio_sync',
                'active_provider'  => 'twilio',
            ] );
            $job['imported']++;
        }

        if ( '' !== $sid && ! Message_Repository::find_by_provider_message_id( 'twilio', $sid ) ) {
            Message_Repository::create( [
                'contact_id'          => $contact_id,
                'direction'           => 'inbound',
                'body'                => $body,
                'from_number'         => $normalized,
                'to_number'           => $to,
                'status'              => $status ?: 'received',
                'provider_key'        => 'twilio',
                'provider_message_id' => $sid,
                'provider_status'     => $status,
            ] );
        }
    }

    /**
     * @param array<string,mixed> $job
     */
    private static function fail( array $job, string $message ): array {
        $job['status']     = 'failed';
        $job['last_error'] = $message;
        return $job;
    }
}
