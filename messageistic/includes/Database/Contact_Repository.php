<?php
/**
 * Repository for `wp_messageistic_contacts`.
 *
 * @package Messageistic\Database
 */

namespace Messageistic\Database;

use Messageistic\Helpers\Phone_Normalizer;

defined( 'ABSPATH' ) || exit;

final class Contact_Repository {

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'messageistic_contacts';
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function create( array $data ): int {
        global $wpdb;
        $row = self::prepare_row( $data );
        $row['created_at'] = $row['updated_at'] = current_time( 'mysql' );
        $wpdb->insert( self::table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, array $data ): int {
        global $wpdb;
        $row               = self::prepare_row( $data, true );
        $row['updated_at'] = current_time( 'mysql' );
        return (int) $wpdb->update( self::table(), $row, [ 'id' => $id ] );
    }

    public static function find( int $id ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    public static function find_by_normalized_phone( string $normalized ): ?array {
        global $wpdb;
        $table = self::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE normalized_phone = %s LIMIT 1", $normalized ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Upsert by normalized phone — matches on phone, then merges new fields.
     *
     * @param array<string,mixed> $data
     */
    public static function upsert_by_phone( array $data, string $default_country_code = '+1' ): int {
        $phone      = (string) ( $data['phone'] ?? '' );
        $normalized = Phone_Normalizer::normalize( $phone, $default_country_code );
        if ( '' === $normalized ) {
            return self::create( $data );
        }
        $data['normalized_phone'] = $normalized;
        $existing                 = self::find_by_normalized_phone( $normalized );
        if ( $existing ) {
            self::update( (int) $existing['id'], $data );
            return (int) $existing['id'];
        }
        return self::create( $data );
    }

    public static function set_opt_out( int $id, bool $opted_out ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'opt_out' => $opted_out ? 1 : 0, 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
    }

    public static function set_consent( int $id, bool $consent, string $source = '' ): void {
        global $wpdb;
        $wpdb->update(
            self::table(),
            [
                'sms_consent'    => $consent ? 1 : 0,
                'consent_source' => $source,
                'consent_date'   => $consent ? current_time( 'mysql' ) : null,
                'updated_at'     => current_time( 'mysql' ),
            ],
            [ 'id' => $id ]
        );
    }

    /**
     * Paginated query.
     *
     * @param array<string,mixed> $args { search, source, opt_out, consent, page, per_page }
     * @return array{rows:array<int,array<string,mixed>>,total:int}
     */
    public static function paginate( array $args = [] ): array {
        global $wpdb;
        $table    = self::table();
        $where    = [ '1=1' ];
        $params   = [];
        $page     = max( 1, (int) ( $args['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );

        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR normalized_phone LIKE %s)';
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ( ! empty( $args['location_id'] ) ) {
            $where[]  = 'location_id = %d';
            $params[] = (int) $args['location_id'];
        }
        if ( isset( $args['source'] ) && '' !== $args['source'] ) {
            $where[]  = 'source = %s';
            $params[] = (string) $args['source'];
        }
        if ( isset( $args['opt_out'] ) && '' !== $args['opt_out'] ) {
            $where[]  = 'opt_out = %d';
            $params[] = (int) (bool) $args['opt_out'];
        }
        if ( isset( $args['consent'] ) && '' !== $args['consent'] ) {
            $where[]  = 'sms_consent = %d';
            $params[] = (int) (bool) $args['consent'];
        }

        $where_sql = implode( ' AND ', $where );
        $offset    = ( $page - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

        $total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql );
        $rows  = $wpdb->get_results(
            $wpdb->prepare( $rows_sql, ...array_merge( $params, [ $per_page, $offset ] ) ),
            ARRAY_A
        );
        // phpcs:enable

        return [ 'rows' => is_array( $rows ) ? $rows : [], 'total' => $total ];
    }

    public static function touch_last_message( int $id ): void {
        global $wpdb;
        $wpdb->update( self::table(), [ 'last_message_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
    }

    /**
     * @param array<string,mixed> $data
     */
    private static function prepare_row( array $data, bool $partial = false ): array {
        $allowed = [
            'location_id'          => 'int',
            'first_name'           => 'string',
            'last_name'            => 'string',
            'email'                => 'string',
            'phone'                => 'string',
            'normalized_phone'     => 'string',
            'source'               => 'string',
            'tags'                 => 'json',
            'customer_type'        => 'string',
            'sms_consent'          => 'bool',
            'consent_source'       => 'string',
            'consent_date'         => 'string',
            'age_verified'         => 'bool',
            'age_verified_at'      => 'string',
            'date_of_birth'        => 'string',
            'opt_out'              => 'bool',
            'active_provider'      => 'string',
            'provider_contact_id'  => 'string',
            'last_message_at'      => 'string',
        ];
        $row = [];
        foreach ( $allowed as $key => $type ) {
            if ( $partial && ! array_key_exists( $key, $data ) ) {
                continue;
            }
            $val = $data[ $key ] ?? '';
            switch ( $type ) {
                case 'int':
                    $row[ $key ] = (int) $val;
                    break;
                case 'bool':
                    $row[ $key ] = ! empty( $val ) ? 1 : 0;
                    break;
                case 'json':
                    $row[ $key ] = is_string( $val ) ? $val : wp_json_encode( (array) $val );
                    break;
                default:
                    $row[ $key ] = (string) $val;
            }
        }
        return $row;
    }
}
