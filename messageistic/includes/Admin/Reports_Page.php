<?php
/**
 * Reports — provider-aware aggregate stats.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Monitoring\Pilot_Monitor;

defined( 'ABSPATH' ) || exit;

final class Reports_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::VIEW ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $pilot_action = sanitize_key( wp_unslash( $_POST['action'] ?? '' ) );
        if ( 'acknowledge_pilot' === $pilot_action && current_user_can( Permissions::MANAGE ) ) {
            check_admin_referer( 'messageistic_acknowledge_pilot' );
            Pilot_Monitor::acknowledge();
        } elseif ( 'run_pilot_monitor' === $pilot_action && current_user_can( Permissions::MANAGE ) ) {
            check_admin_referer( 'messageistic_run_pilot_monitor' );
            Pilot_Monitor::refresh();
        }

        global $wpdb;
        $m = $wpdb->prefix . 'messageistic_messages';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $by_provider = $wpdb->get_results( "SELECT provider_key, status, COUNT(*) AS n FROM {$m} GROUP BY provider_key, status ORDER BY provider_key", ARRAY_A );

        $matrix = [];
        foreach ( (array) $by_provider as $r ) {
            $key = $r['provider_key'] ?: '—';
            $matrix[ $key ][ $r['status'] ] = (int) $r['n'];
        }

        $conversions_table = $wpdb->prefix . 'messageistic_conversion_events';
        $locations_table = $wpdb->prefix . 'messageistic_locations';
        $conversion_summary = (array) $wpdb->get_results( "SELECT conversion_type, currency, COUNT(*) AS conversions, SUM(value) AS revenue FROM {$conversions_table} GROUP BY conversion_type, currency ORDER BY conversions DESC", ARRAY_A );
        $conversion_by_location = (array) $wpdb->get_results( "SELECT COALESCE(l.name, 'Unassigned') AS location_name, COUNT(*) AS conversions, SUM(c.value) AS revenue, c.currency FROM {$conversions_table} c LEFT JOIN {$locations_table} l ON l.id=c.location_id GROUP BY c.location_id,c.currency ORDER BY conversions DESC", ARRAY_A );

        $pilot_settings = (array) get_option( 'messageistic_general_settings', [] );
        $pilot_snapshot = Pilot_Monitor::snapshot();

        include MESSAGEISTIC_DIR . 'templates/admin/reports.php';
    }
}
