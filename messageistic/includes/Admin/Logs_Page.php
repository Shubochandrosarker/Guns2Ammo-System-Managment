<?php
/**
 * Logs page — paginated read-only view of wp_messageistic_logs.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;

defined( 'ABSPATH' ) || exit;

final class Logs_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        global $wpdb;
        $logs_table = $wpdb->prefix . 'messageistic_logs';

        $per_page = 25;
        $page     = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $offset   = ( $page - 1 ) * $per_page;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$logs_table}" );
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, event_type, level, provider_key, status, error_message, created_at FROM {$logs_table} ORDER BY id DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );
        // phpcs:enable

        $rows         = is_array( $rows ) ? $rows : [];
        $total_pages  = max( 1, (int) ceil( $total / $per_page ) );

        include MESSAGEISTIC_DIR . 'templates/admin/logs.php';
    }
}
