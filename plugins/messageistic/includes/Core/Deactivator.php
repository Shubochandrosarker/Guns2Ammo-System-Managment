<?php
/**
 * Deactivation handler.
 *
 * @package Messageistic\Core
 */

namespace Messageistic\Core;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'messageistic_cron_heartbeat' );
        wp_clear_scheduled_hook( 'messageistic_privacy_cleanup' );
        wp_clear_scheduled_hook( 'messageistic_pilot_daily_monitor' );
        wp_clear_scheduled_hook( 'messageistic_release_due_campaigns' );

        $timestamp = wp_next_scheduled( 'messageistic_provider_health_check' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'messageistic_provider_health_check' );
        }
    }
}
