<?php
/**
 * Production cron schedules, heartbeat, and operational warning.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

defined( 'ABSPATH' ) || exit;

final class Cron_Manager {
    public const HEARTBEAT = 'messageistic_cron_heartbeat';

    public static function register(): void {
        add_filter( 'cron_schedules', [ self::class, 'schedules' ] );
        add_action( self::HEARTBEAT, [ self::class, 'heartbeat' ] );
        add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
        if ( ! wp_next_scheduled( self::HEARTBEAT ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, 'messageistic_minute', self::HEARTBEAT );
        }
    }

    public static function schedules( array $schedules ): array {
        $schedules['messageistic_minute'] = [ 'interval' => MINUTE_IN_SECONDS, 'display' => __( 'Every minute (Messageistic)', 'messageistic' ) ];
        return $schedules;
    }

    public static function heartbeat(): void {
        update_option( 'messageistic_cron_last_run', time(), false );
        do_action( 'messageistic_release_due_campaigns' );
    }

    public static function admin_notice(): void {
        if ( ! current_user_can( Permissions::MANAGE_SETTINGS ) ) {
            return;
        }
        $last = (int) get_option( 'messageistic_cron_last_run', 0 );
        if ( $last && time() - $last <= 5 * MINUTE_IN_SECONDS ) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Messageistic background jobs have not run in the last five minutes. Configure a real system scheduler to run: wp cron event run --due-now', 'messageistic' ) . '</p></div>';
    }
}
