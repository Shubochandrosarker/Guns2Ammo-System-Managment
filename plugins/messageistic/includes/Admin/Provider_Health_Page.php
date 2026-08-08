<?php
/**
 * Settings → Provider Health: connection state, capabilities, last successful
 * call, sync controls per provider.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Core\Plugin;
use Messageistic\Database\Contact_Repository;
use Messageistic\Sync\Sync_Job_Repository;
use Messageistic\Sync\Sync_Service;

defined( 'ABSPATH' ) || exit;

final class Provider_Health_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::MANAGE_PROVIDERS ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $notice = '';
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';

        if ( 'sync_start' === $action ) {
            check_admin_referer( 'messageistic_sync' );
            $result = Sync_Service::start(
                sanitize_key( (string) ( $_POST['provider'] ?? '' ) ),
                [
                    'kind'        => sanitize_key( (string) ( $_POST['kind'] ?? '' ) ),
                    'list_id'     => sanitize_text_field( (string) ( $_POST['list_id'] ?? '' ) ),
                    'campaign_id' => sanitize_text_field( (string) ( $_POST['campaign_id'] ?? '' ) ),
                ]
            );
            $notice = is_wp_error( $result )
                ? sprintf( /* translators: %s: error */ __( 'Sync failed to start: %s', 'messageistic' ), $result->get_error_message() )
                : __( 'Sync started.', 'messageistic' );
        } elseif ( 'sync_cancel' === $action ) {
            check_admin_referer( 'messageistic_sync' );
            Sync_Service::cancel( sanitize_key( (string) ( $_REQUEST['provider'] ?? '' ) ) );
            $notice = __( 'Sync cancelled.', 'messageistic' );
        }

        $providers = Plugin::instance()->providers();
        $jobs      = Sync_Job_Repository::all();
        $contacts_total = self::contacts_total();

        include MESSAGEISTIC_DIR . 'templates/admin/provider-health.php';
    }

    private static function contacts_total(): array {
        global $wpdb;
        $t = Contact_Repository::table();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT active_provider, COUNT(*) AS n FROM {$t} GROUP BY active_provider", ARRAY_A );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $out[ $r['active_provider'] ?: '—' ] = (int) $r['n'];
        }
        return $out;
    }
}
