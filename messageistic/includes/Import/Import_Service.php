<?php
/**
 * CSV bulk-import orchestrator. Handles contacts / templates / campaigns.
 *
 * Each tick processes ~200 CSV rows then reschedules itself until EOF.
 * File path + parse offset are persisted in the job so reads resume after
 * any interruption.
 *
 * @package Messageistic\Import
 */

namespace Messageistic\Import;

use Messageistic\Database\Campaign_Repository;
use Messageistic\Database\Contact_Repository;
use Messageistic\Database\Template_Repository;
use Messageistic\Helpers\Logger;
use Messageistic\Helpers\Phone_Normalizer;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class Import_Service {

    public const HOOK     = 'messageistic_import_tick';
    public const PER_TICK = 200;

    public const KIND_CONTACTS  = 'contacts';
    public const KIND_TEMPLATES = 'templates';
    public const KIND_CAMPAIGNS = 'campaigns';

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'tick' ], 10, 1 );
    }

    /**
     * Sniff a freshly uploaded CSV: return detected headers + a few preview rows.
     *
     * @return array{headers:array<int,string>,preview:array<int,array<string,string>>,suggested_mapping:array<string,string>}|WP_Error
     */
    public static function preview( string $kind, string $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'messageistic_import_no_file', __( 'Uploaded file not found.', 'messageistic' ) );
        }
        try {
            $parser = new CSV_Parser( $file_path );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'messageistic_import_bad_csv', $e->getMessage() );
        }
        $headers = $parser->headers();
        $preview = $parser->read( 5 );
        return [
            'headers'           => $headers,
            'preview'           => $preview,
            'suggested_mapping' => self::guess_mapping( $kind, $headers ),
        ];
    }

    /**
     * Start a job. file_path must already be persisted via Import_File_Handler.
     *
     * @param array<string,string> $mapping source_header => target_field
     * @return array<string,mixed>|WP_Error
     */
    public static function start( string $kind, string $file_path, string $file_name, array $mapping ) {
        if ( ! in_array( $kind, [ self::KIND_CONTACTS, self::KIND_TEMPLATES, self::KIND_CAMPAIGNS ], true ) ) {
            return new WP_Error( 'messageistic_import_kind', __( 'Unsupported import kind.', 'messageistic' ) );
        }
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'messageistic_import_no_file', __( 'Uploaded file not found.', 'messageistic' ) );
        }
        $existing = Import_Job_Repository::get( $kind );
        if ( $existing && in_array( $existing['status'], [ 'queued', 'running' ], true ) ) {
            return new WP_Error( 'messageistic_import_busy', __( 'An import is already running for this kind.', 'messageistic' ) );
        }
        $job = Import_Job_Repository::start( $kind, $file_path, $file_name, $mapping );
        Logger::log( 'import.started', [
            'context' => [ 'job_id' => $job['id'], 'kind' => $kind, 'file' => $file_name ],
        ] );
        self::enqueue( $kind );
        return $job;
    }

    public static function cancel( string $kind ): void {
        $job = Import_Job_Repository::get( $kind );
        if ( ! $job ) {
            return;
        }
        $job['status']     = 'cancelled';
        $job['last_error'] = __( 'Cancelled by user.', 'messageistic' );
        Import_Job_Repository::save( $job );
        Logger::log( 'import.cancelled', [ 'context' => [ 'job_id' => $job['id'], 'kind' => $kind ] ] );
    }

    private static function enqueue( string $kind, int $delay = 0 ): void {
        if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
            if ( $delay > 0 ) {
                as_schedule_single_action( time() + $delay, self::HOOK, [ $kind ], 'messageistic' );
            } else {
                as_enqueue_async_action( self::HOOK, [ $kind ], 'messageistic' );
            }
            return;
        }
        wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK, [ $kind ] );
    }

    public static function tick( string $kind ): void {
        $job = Import_Job_Repository::get( $kind );
        if ( ! $job || in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            return;
        }
        $job['status'] = 'running';
        Import_Job_Repository::save( $job );

        try {
            $job = self::process_batch( $job );
        } catch ( \Throwable $e ) {
            $job['status']     = 'failed';
            $job['last_error'] = $e->getMessage();
        }

        Import_Job_Repository::save( $job );

        if ( 'running' === $job['status'] ) {
            self::enqueue( $kind, 2 );
            return;
        }

        if ( ! empty( $job['file_path'] ) && file_exists( $job['file_path'] ) ) {
            wp_delete_file( $job['file_path'] );
            $job['file_path'] = '';
            Import_Job_Repository::save( $job );
        }

        Logger::log( 'import.completed', [
            'status'  => $job['status'],
            'context' => [
                'job_id'   => $job['id'],
                'kind'     => $kind,
                'fetched'  => $job['fetched'],
                'imported' => $job['imported'],
                'updated'  => $job['updated'],
                'skipped'  => $job['skipped'],
                'failed'   => $job['failed'],
            ],
        ] );
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private static function process_batch( array $job ): array {
        if ( ! file_exists( $job['file_path'] ) ) {
            $job['status']     = 'failed';
            $job['last_error'] = __( 'Source file disappeared mid-import.', 'messageistic' );
            return $job;
        }

        $parser = new CSV_Parser( $job['file_path'] );
        if ( ! empty( $job['offset'] ) ) {
            $parser->seek( (int) $job['offset'] );
        }

        $rows = $parser->read( self::PER_TICK );
        if ( empty( $rows ) ) {
            $job['status'] = 'completed';
            return $job;
        }

        foreach ( $rows as $row ) {
            $mapped = self::apply_mapping( $row, (array) $job['mapping'] );
            $result = match ( $job['kind'] ) {
                self::KIND_CONTACTS  => self::import_contact( $mapped ),
                self::KIND_TEMPLATES => self::import_template( $mapped ),
                self::KIND_CAMPAIGNS => self::import_campaign( $mapped ),
                default              => 'skipped',
            };
            $job['fetched']++;
            if ( 'imported' === $result ) {
                $job['imported']++;
            } elseif ( 'updated' === $result ) {
                $job['updated']++;
            } elseif ( 'skipped' === $result ) {
                $job['skipped']++;
            } else {
                $job['failed']++;
                $job['errors'][] = (string) $result;
                $job['errors']   = array_slice( $job['errors'], -50 );
            }
        }

        $job['offset'] = $parser->tell();
        return $job;
    }

    /**
     * @param array<string,string> $row
     * @param array<string,string> $mapping  source header → canonical field
     * @return array<string,string>
     */
    private static function apply_mapping( array $row, array $mapping ): array {
        if ( empty( $mapping ) ) {
            return $row;
        }
        $out = [];
        foreach ( $mapping as $source => $target ) {
            if ( '' === $target ) {
                continue;
            }
            $out[ $target ] = $row[ strtolower( $source ) ] ?? '';
        }
        return $out;
    }

    /**
     * @param array<string,string> $row
     * @return 'imported'|'updated'|'skipped'|string error message
     */
    private static function import_contact( array $row ) {
        $phone = trim( (string) ( $row['phone'] ?? '' ) );
        if ( '' === $phone ) {
            return 'skipped';
        }
        $cc         = (string) ( get_option( 'messageistic_general_settings' )['default_country_code'] ?? '+1' );
        $normalized = Phone_Normalizer::normalize( $phone, $cc );
        if ( '' === $normalized ) {
            return 'invalid phone: ' . $phone;
        }
        $existing = Contact_Repository::find_by_normalized_phone( $normalized );
        $data = [
            'first_name'       => (string) ( $row['first_name'] ?? '' ),
            'last_name'        => (string) ( $row['last_name'] ?? '' ),
            'email'            => (string) ( $row['email'] ?? '' ),
            'phone'            => $phone,
            'normalized_phone' => $normalized,
            'source'           => (string) ( $row['source'] ?? 'csv_import' ),
            'tags'             => (string) ( $row['tags'] ?? '' ),
            'customer_type'    => (string) ( $row['customer_type'] ?? '' ),
            'sms_consent'      => self::truthy( $row['sms_consent'] ?? '' ),
            'consent_source'   => (string) ( $row['consent_source'] ?? '' ),
            'opt_out'          => self::truthy( $row['opt_out'] ?? '' ),
        ];
        if ( $existing ) {
            Contact_Repository::update( (int) $existing['id'], $data );
            return 'updated';
        }
        Contact_Repository::create( $data );
        return 'imported';
    }

    /**
     * @param array<string,string> $row
     */
    private static function import_template( array $row ) {
        $name = trim( (string) ( $row['name'] ?? '' ) );
        $body = trim( (string) ( $row['body'] ?? '' ) );
        if ( '' === $name || '' === $body ) {
            return 'skipped';
        }
        Template_Repository::create( [
            'name'      => $name,
            'category'  => (string) ( $row['category'] ?? 'general' ),
            'body'      => $body,
            'is_active' => self::truthy( $row['is_active'] ?? '1' ),
        ] );
        return 'imported';
    }

    /**
     * @param array<string,string> $row
     */
    private static function import_campaign( array $row ) {
        $name = trim( (string) ( $row['name'] ?? '' ) );
        $body = trim( (string) ( $row['body'] ?? '' ) );
        if ( '' === $name || '' === $body ) {
            return 'skipped';
        }
        Campaign_Repository::create( [
            'name'          => $name,
            'type'          => (string) ( $row['type'] ?? 'broadcast' ),
            'audience'      => [ 'kind' => (string) ( $row['audience_kind'] ?? 'all' ), 'tag' => (string) ( $row['audience_tag'] ?? '' ) ],
            'provider_mode' => (string) ( $row['provider_mode'] ?? 'queue' ),
            'body'          => $body,
            'schedule_at'   => trim( (string) ( $row['schedule_at'] ?? '' ) ) ?: null,
            'status'        => 'draft',
        ] );
        return 'imported';
    }

    private static function truthy( $value ): bool {
        $v = strtolower( trim( (string) $value ) );
        return in_array( $v, [ '1', 'true', 'yes', 'y', 'on', 'opted in', 'consented' ], true );
    }

    /**
     * @param array<int,string> $headers
     * @return array<string,string>
     */
    private static function guess_mapping( string $kind, array $headers ): array {
        $candidates = match ( $kind ) {
            self::KIND_CONTACTS => [
                'first_name'    => [ 'first_name', 'firstname', 'first', 'fname', 'given_name' ],
                'last_name'     => [ 'last_name', 'lastname', 'last', 'lname', 'surname', 'family_name' ],
                'email'         => [ 'email', 'email_address', 'e-mail' ],
                'phone'         => [ 'phone', 'mobile', 'phone_number', 'cell', 'cellphone', 'number', 'sms' ],
                'tags'          => [ 'tags', 'labels' ],
                'customer_type' => [ 'customer_type', 'type' ],
                'source'        => [ 'source', 'origin' ],
                'sms_consent'   => [ 'sms_consent', 'consent', 'opt_in', 'subscribed' ],
                'opt_out'       => [ 'opt_out', 'unsubscribed' ],
            ],
            self::KIND_TEMPLATES => [
                'name'      => [ 'name', 'title' ],
                'category'  => [ 'category', 'group' ],
                'body'      => [ 'body', 'message', 'content', 'text' ],
                'is_active' => [ 'is_active', 'active', 'enabled' ],
            ],
            self::KIND_CAMPAIGNS => [
                'name'          => [ 'name', 'campaign', 'title' ],
                'type'          => [ 'type' ],
                'body'          => [ 'body', 'message', 'content' ],
                'audience_kind' => [ 'audience', 'audience_kind' ],
                'audience_tag'  => [ 'audience_tag', 'tag' ],
                'provider_mode' => [ 'provider_mode', 'mode' ],
                'schedule_at'   => [ 'schedule_at', 'schedule', 'send_at' ],
            ],
            default => [],
        };

        $map = [];
        foreach ( $headers as $h ) {
            $lower = strtolower( trim( $h ) );
            foreach ( $candidates as $target => $aliases ) {
                if ( in_array( $lower, $aliases, true ) ) {
                    $map[ $h ] = $target;
                    break;
                }
            }
            if ( ! isset( $map[ $h ] ) ) {
                $map[ $h ] = '';
            }
        }
        return $map;
    }

    /**
     * Canonical target fields for each kind, for the mapping UI dropdown.
     *
     * @return array<string,string>
     */
    public static function target_fields( string $kind ): array {
        return match ( $kind ) {
            self::KIND_CONTACTS  => [
                ''               => __( '— Skip —', 'messageistic' ),
                'first_name'     => __( 'First Name', 'messageistic' ),
                'last_name'      => __( 'Last Name', 'messageistic' ),
                'email'          => __( 'Email', 'messageistic' ),
                'phone'          => __( 'Phone (required)', 'messageistic' ),
                'tags'           => __( 'Tags', 'messageistic' ),
                'customer_type'  => __( 'Customer Type', 'messageistic' ),
                'source'         => __( 'Source', 'messageistic' ),
                'sms_consent'    => __( 'SMS Consent', 'messageistic' ),
                'consent_source' => __( 'Consent Source', 'messageistic' ),
                'opt_out'        => __( 'Opt-out', 'messageistic' ),
            ],
            self::KIND_TEMPLATES => [
                ''           => __( '— Skip —', 'messageistic' ),
                'name'       => __( 'Name (required)', 'messageistic' ),
                'category'   => __( 'Category', 'messageistic' ),
                'body'       => __( 'Body (required)', 'messageistic' ),
                'is_active'  => __( 'Active', 'messageistic' ),
            ],
            self::KIND_CAMPAIGNS => [
                ''               => __( '— Skip —', 'messageistic' ),
                'name'           => __( 'Name (required)', 'messageistic' ),
                'type'           => __( 'Type', 'messageistic' ),
                'body'           => __( 'Body (required)', 'messageistic' ),
                'audience_kind'  => __( 'Audience kind', 'messageistic' ),
                'audience_tag'   => __( 'Audience tag', 'messageistic' ),
                'provider_mode'  => __( 'Provider mode', 'messageistic' ),
                'schedule_at'    => __( 'Schedule at', 'messageistic' ),
            ],
            default => [],
        };
    }
}
