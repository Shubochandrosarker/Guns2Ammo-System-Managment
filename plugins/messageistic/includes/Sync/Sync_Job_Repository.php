<?php
/**
 * Persists sync job state in a single option (one active job per provider key).
 *
 * Job shape:
 * [
 *   'id'             => string  (uuid),
 *   'provider_key'   => string,
 *   'kind'           => string  ('contacts'|'inbound_contacts'|'campaigns'),
 *   'status'         => string  ('queued'|'running'|'completed'|'failed'|'cancelled'),
 *   'total'          => int|null,
 *   'fetched'        => int,
 *   'imported'       => int,
 *   'updated'        => int,
 *   'skipped'        => int,
 *   'failed'         => int,
 *   'page'           => int,
 *   'cursor'         => string|null,
 *   'next_page_uri'  => string|null,
 *   'list_id'        => string|null,
 *   'campaign_id'    => string|null,
 *   'last_error'     => string|null,
 *   'started_at'     => string,
 *   'updated_at'     => string,
 * ]
 *
 * @package Messageistic\Sync
 */

namespace Messageistic\Sync;

defined( 'ABSPATH' ) || exit;

final class Sync_Job_Repository {

    private const OPTION = 'messageistic_sync_jobs';

    /** @return array<string,array<string,mixed>> */
    public static function all(): array {
        $jobs = get_option( self::OPTION, [] );
        return is_array( $jobs ) ? $jobs : [];
    }

    public static function get( string $provider_key ): ?array {
        $jobs = self::all();
        return $jobs[ $provider_key ] ?? null;
    }

    public static function save( array $job ): void {
        $jobs = self::all();
        $job['updated_at'] = current_time( 'mysql' );
        $jobs[ (string) $job['provider_key'] ] = $job;
        update_option( self::OPTION, $jobs, false );
    }

    public static function delete( string $provider_key ): void {
        $jobs = self::all();
        unset( $jobs[ $provider_key ] );
        update_option( self::OPTION, $jobs, false );
    }

    public static function start( string $provider_key, string $kind, array $args = [] ): array {
        $job = [
            'id'            => wp_generate_uuid4(),
            'provider_key'  => $provider_key,
            'kind'          => $kind,
            'status'        => 'queued',
            'total'         => null,
            'fetched'       => 0,
            'imported'      => 0,
            'updated'       => 0,
            'skipped'       => 0,
            'failed'        => 0,
            'page'          => 1,
            'cursor'        => null,
            'next_page_uri' => null,
            'list_id'       => isset( $args['list_id'] ) ? (string) $args['list_id'] : null,
            'campaign_id'   => isset( $args['campaign_id'] ) ? (string) $args['campaign_id'] : null,
            'last_error'    => null,
            'started_at'    => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        ];
        self::save( $job );
        return $job;
    }
}
