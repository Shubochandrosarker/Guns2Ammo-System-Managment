<?php
/**
 * Dashboard page controller — gathers stats and renders the template.
 *
 * @package Messageistic\Admin
 */

namespace Messageistic\Admin;

use Messageistic\Core\Permissions;
use Messageistic\Core\Plugin;
use Messageistic\Monitoring\Pilot_Monitor;

defined( 'ABSPATH' ) || exit;

final class Dashboard_Page {

    public static function render(): void {
        if ( ! current_user_can( Permissions::VIEW ) ) {
            wp_die( esc_html__( 'You do not have permission to view this page.', 'messageistic' ) );
        }

        $providers = Plugin::instance()->providers();
        $health    = $providers->get_health();
        $stats     = self::collect_stats();
        $recent    = self::recent_activity();
        $series    = self::messages_by_day( 7 );
        $pilot_settings = (array) get_option( 'messageistic_general_settings', [] );
        $pilot_snapshot = Pilot_Monitor::snapshot();

        include MESSAGEISTIC_DIR . 'templates/admin/dashboard.php';
    }

    /**
     * @return array<string,mixed>
     */
    private static function collect_stats(): array {
        $cached = get_transient( 'messageistic_dashboard_stats' );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        global $wpdb;
        $m   = $wpdb->prefix . 'messageistic_messages';
        $c   = $wpdb->prefix . 'messageistic_contacts';
        $cp  = $wpdb->prefix . 'messageistic_campaigns';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $total_sent   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE direction = 'outbound'" );
        $delivered    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'delivered'" );
        $failed       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE status = 'failed'" );
        $replies      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE direction = 'inbound'" );
        $contacts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE opt_out = 0" );
        $opted_out    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$c} WHERE opt_out = 1" );
        $active_camps = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cp} WHERE status IN ('scheduled','sending')" );

        // Trend windows: last 7 days vs the 7 days before.
        $last7  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE direction='outbound' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" );
        $prev7  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$m} WHERE direction='outbound' AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" );

        $delivery_rate = $total_sent > 0 ? round( ( $delivered / $total_sent ) * 100, 1 ) : 0.0;
        $fail_rate     = $total_sent > 0 ? round( ( $failed / $total_sent ) * 100, 1 )    : 0.0;
        // phpcs:enable

        $stats = [
            'total_sent'     => $total_sent,
            'delivered'      => $delivered,
            'failed'         => $failed,
            'replies'        => $replies,
            'contacts'       => $contacts,
            'opted_out'      => $opted_out,
            'active_camps'   => $active_camps,
            'cost_estimate'  => round( $total_sent * 0.0075, 2 ),
            'delivery_rate'  => $delivery_rate,
            'fail_rate'      => $fail_rate,
            'trend_sent_pct' => self::delta_pct( $last7, $prev7 ),
        ];

        set_transient( 'messageistic_dashboard_stats', $stats, MINUTE_IN_SECONDS );
        return $stats;
    }

    /**
     * Per-day outbound + inbound counts for the last $days.
     *
     * @return array<int,array{day:string,outbound:int,inbound:int}>
     */
    private static function messages_by_day( int $days ): array {
        global $wpdb;
        $m = $wpdb->prefix . 'messageistic_messages';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) AS d, direction, COUNT(*) AS n
             FROM {$m}
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
             GROUP BY d, direction
             ORDER BY d ASC",
            $days
        ), ARRAY_A );

        $bucket = [];
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            $key             = gmdate( 'Y-m-d', strtotime( "-{$i} days" ) );
            $bucket[ $key ]  = [ 'day' => $key, 'outbound' => 0, 'inbound' => 0 ];
        }
        foreach ( (array) $rows as $r ) {
            $d = (string) $r['d'];
            if ( ! isset( $bucket[ $d ] ) ) {
                continue;
            }
            $key = 'inbound' === $r['direction'] ? 'inbound' : 'outbound';
            $bucket[ $d ][ $key ] = (int) $r['n'];
        }
        return array_values( $bucket );
    }

    private static function delta_pct( int $current, int $previous ): float {
        if ( $previous <= 0 ) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round( ( ( $current - $previous ) / $previous ) * 100, 1 );
    }

    /**
     * @return array<int,array<string,string>>
     */
    private static function recent_activity(): array {
        global $wpdb;
        $logs = $wpdb->prefix . 'messageistic_logs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( "SELECT event_type, level, provider_key, status, created_at FROM {$logs} ORDER BY id DESC LIMIT 10", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
