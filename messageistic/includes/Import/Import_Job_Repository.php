<?php
/**
 * Option-backed CSV import job state.
 *
 * Job shape:
 * [
 *   'id'         => string (uuid),
 *   'kind'       => 'contacts'|'templates'|'campaigns',
 *   'file_path'  => string,
 *   'file_name'  => string,
 *   'status'     => 'queued'|'running'|'completed'|'failed'|'cancelled',
 *   'total_bytes'=> int,
 *   'offset'     => int,
 *   'fetched'    => int,
 *   'imported'   => int,
 *   'updated'    => int,
 *   'skipped'    => int,
 *   'failed'     => int,
 *   'mapping'    => array<string,string>,  source header → target field
 *   'errors'     => array<int,string>,
 *   'last_error' => string|null,
 *   'started_at' => string,
 *   'updated_at' => string,
 * ]
 *
 * @package Messageistic\Import
 */

namespace Messageistic\Import;

defined( 'ABSPATH' ) || exit;

final class Import_Job_Repository {

    private const OPTION = 'messageistic_import_jobs';

    /** @return array<string,array<string,mixed>> */
    public static function all(): array {
        $jobs = get_option( self::OPTION, [] );
        return is_array( $jobs ) ? $jobs : [];
    }

    public static function get( string $kind ): ?array {
        $jobs = self::all();
        return $jobs[ $kind ] ?? null;
    }

    public static function save( array $job ): void {
        $jobs = self::all();
        $job['updated_at'] = current_time( 'mysql' );
        $jobs[ (string) $job['kind'] ] = $job;
        update_option( self::OPTION, $jobs, false );
    }

    public static function delete( string $kind ): void {
        $jobs = self::all();
        if ( ! empty( $jobs[ $kind ]['file_path'] ) && file_exists( $jobs[ $kind ]['file_path'] ) ) {
            @unlink( $jobs[ $kind ]['file_path'] );
        }
        unset( $jobs[ $kind ] );
        update_option( self::OPTION, $jobs, false );
    }

    public static function start( string $kind, string $file_path, string $file_name, array $mapping = [] ): array {
        $job = [
            'id'          => wp_generate_uuid4(),
            'kind'        => $kind,
            'file_path'   => $file_path,
            'file_name'   => $file_name,
            'status'      => 'queued',
            'total_bytes' => file_exists( $file_path ) ? (int) filesize( $file_path ) : 0,
            'offset'      => 0,
            'fetched'     => 0,
            'imported'    => 0,
            'updated'     => 0,
            'skipped'     => 0,
            'failed'      => 0,
            'mapping'     => $mapping,
            'errors'      => [],
            'last_error'  => null,
            'started_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
        ];
        self::save( $job );
        return $job;
    }
}
