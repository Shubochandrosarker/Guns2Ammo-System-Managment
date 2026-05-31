<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_DB {

    const TABLE = 'verifyistic_logs';

    /**
     * Create plugin database tables.
     */
    public static function create_tables() {
        global $wpdb;
        $table      = $wpdb->prefix . self::TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            verify_token  VARCHAR(64)  NOT NULL DEFAULT '',
            verify_type   VARCHAR(20)  NOT NULL DEFAULT 'dob',
            first_name    VARCHAR(100) NOT NULL DEFAULT '',
            last_name     VARCHAR(100) NOT NULL DEFAULT '',
            dob           DATE         DEFAULT NULL,
            age_at_verify TINYINT(3)   UNSIGNED NOT NULL DEFAULT 0,
            status        VARCHAR(20)  NOT NULL DEFAULT 'passed',
            ip_address    VARCHAR(45)  NOT NULL DEFAULT '',
            user_agent    TEXT         NOT NULL,
            page_url      TEXT         NOT NULL,
            id_file       VARCHAR(255) NOT NULL DEFAULT '',
            selfie_file   VARCHAR(255) NOT NULL DEFAULT '',
            webhook_sent  TINYINT(1)   NOT NULL DEFAULT 0,
            verified_at   DATETIME     NOT NULL DEFAULT '0000-00-00 00:00:00',
            PRIMARY KEY (id),
            KEY verify_token (verify_token),
            KEY verified_at (verified_at),
            KEY status (status)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'verifyistic_db_version', VERIFYISTIC_VERSION );
    }

    /**
     * Insert a verification record.
     */
    public static function insert_log( array $data ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $defaults = array(
            'verify_token'  => wp_generate_password( 32, false ),
            'verify_type'   => 'dob',
            'first_name'    => '',
            'last_name'     => '',
            'dob'           => null,
            'age_at_verify' => 0,
            'status'        => 'passed',
            'ip_address'    => self::get_client_ip(),
            'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
            'page_url'      => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
            'id_file'       => '',
            'selfie_file'   => '',
            'webhook_sent'  => 0,
            'verified_at'   => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        $wpdb->insert( $table, $data, array(
            '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'
        ) );

        return $wpdb->insert_id;
    }

    /**
     * Get logs with optional filters.
     */
    public static function get_logs( $args = array() ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        $defaults = array(
            'limit'   => 50,
            'offset'  => 0,
            'status'  => '',
            'search'  => '',
            'orderby' => 'verified_at',
            'order'   => 'DESC',
        );
        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where[] = '(first_name LIKE %s OR last_name LIKE %s OR ip_address LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );
        $orderby   = sanitize_sql_orderby( $args['orderby'] . ' ' . $args['order'] ) ?: 'verified_at DESC';
        $limit     = absint( $args['limit'] );
        $offset    = absint( $args['offset'] );

        if ( ! empty( $values ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d",
                array_merge( $values, array( $limit, $offset ) )
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} LIMIT %d OFFSET %d",
                $limit, $offset
            );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_results( $sql );
    }

    /**
     * Get total count.
     */
    public static function get_count( $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
    }

    /**
     * Get today's count.
     */
    public static function get_today_count() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $today = current_time( 'Y-m-d' );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(verified_at) = %s",
            $today
        ) );
    }

    /**
     * Delete a log entry.
     */
    public static function delete_log( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );
    }

    /**
     * Delete all logs.
     */
    public static function delete_all_logs() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        return $wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
    }

    /**
     * Mark webhook as sent.
     */
    public static function mark_webhook_sent( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        $wpdb->update( $table, array( 'webhook_sent' => 1 ), array( 'id' => absint( $id ) ), array( '%d' ), array( '%d' ) );
    }

    /**
     * Get client IP.
     */
    private static function get_client_ip() {
        $ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                // Handle comma-separated IPs
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /**
     * Export logs to CSV string.
     */
    public static function export_csv() {
        $logs = self::get_logs( array( 'limit' => 9999 ) );
        $output = "ID,Type,First Name,Last Name,Date of Birth,Age,Status,IP Address,Page URL,Verified At\n";
        foreach ( $logs as $log ) {
            $row = array(
                $log->id,
                $log->verify_type,
                $log->first_name,
                $log->last_name,
                $log->dob,
                $log->age_at_verify,
                $log->status,
                $log->ip_address,
                $log->page_url,
                $log->verified_at,
            );
            // CSV-injection guard + RFC-4180 quoting.
            $output .= implode( ',', array_map( array( __CLASS__, 'csv_cell' ), $row ) ) . "\n";
        }
        return $output;
    }

    /** Defuse CSV-injection and RFC-4180 quote a cell. */
    public static function csv_cell( $v ) {
        $s = (string) $v;
        if ( '' !== $s ) {
            $first = $s[0];
            if ( '=' === $first || '+' === $first || '-' === $first || '@' === $first || "\t" === $first || "\r" === $first ) {
                $s = "'" . $s;
            }
        }
        if ( false !== strpos( $s, '"' ) || false !== strpos( $s, ',' ) || false !== strpos( $s, "\n" ) ) {
            return '"' . str_replace( '"', '""', $s ) . '"';
        }
        return $s;
    }

    /**
     * On deactivation (logs preserved, tables not dropped).
     */
    public static function deactivate() {
        // Intentionally empty — preserve data on deactivation.
    }
}
