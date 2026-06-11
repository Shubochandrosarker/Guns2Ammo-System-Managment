<?php
/**
 * Picks up scheduled campaigns whose schedule_at has passed and enqueues them.
 *
 * Hooked to the existing hourly `messageistic_provider_health_check` cron
 * event (we piggyback rather than register a new one to keep cron lean).
 *
 * @package Messageistic\Campaigns
 */

namespace Messageistic\Campaigns;

use Messageistic\Database\Campaign_Repository;

defined( 'ABSPATH' ) || exit;

final class Campaign_Scheduler {

    public const HOOK = 'messageistic_release_due_campaigns';

    public static function register(): void {
        add_action( 'messageistic_provider_health_check', [ self::class, 'release_due' ] );
        add_action( self::HOOK, [ self::class, 'release_due' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::HOOK );
        }
    }

    public static function release_due(): void {
        global $wpdb;
        $table = Campaign_Repository::table();
        $now   = current_time( 'mysql' );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE status = 'scheduled' AND schedule_at IS NOT NULL AND schedule_at <= %s",
            $now
        ) );
        foreach ( (array) $ids as $id ) {
            Campaign_Repository::set_status( (int) $id, 'sending' );
            Campaign_Queue::enqueue( (int) $id );
        }
    }
}
