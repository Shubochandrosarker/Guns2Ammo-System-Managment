<?php
/**
 * Daily delivery and opt-out monitoring for controlled pilot mode.
 *
 * @package Messageistic\Monitoring
 */

namespace Messageistic\Monitoring;

use Messageistic\Core\Permissions;

defined( 'ABSPATH' ) || exit;

final class Pilot_Monitor {
    public const HOOK            = 'messageistic_pilot_daily_monitor';
    public const OPTION_SNAPSHOT = 'messageistic_pilot_monitor_snapshot';
    public const OPTION_REVIEWED = 'messageistic_pilot_monitor_reviewed_at';

    public static function register(): void {
        add_action( self::HOOK, [ self::class, 'run' ] );
        add_action( 'admin_notices', [ self::class, 'admin_notice' ] );
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    /** @return array<string,mixed> */
    public static function run(): array {
        return self::refresh( true );
    }

    /** @return array<string,mixed> */
    public static function refresh( bool $notify = false ): array {
        $settings = (array) get_option( 'messageistic_general_settings', [] );
        if ( empty( $settings['pilot_mode_enabled'] ) ) {
            return [];
        }

        $snapshot = self::collect();
        update_option( self::OPTION_SNAPSHOT, $snapshot, false );

        $email = sanitize_email( (string) ( $settings['admin_notification_email'] ?? get_option( 'admin_email' ) ) );
        if ( $notify && $email ) {
            wp_mail(
                $email,
                sprintf( __( '[%s] Messageistic pilot daily report', 'messageistic' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
                self::email_body( $snapshot )
            );
        }
        return $snapshot;
    }

    /** @return array<string,mixed> */
    public static function collect(): array {
        global $wpdb;
        $messages = $wpdb->prefix . 'messageistic_messages';
        $optouts  = $wpdb->prefix . 'messageistic_optouts';
        $settings = (array) get_option( 'messageistic_general_settings', [] );
        $provider = sanitize_key( (string) ( $settings['pilot_provider'] ?? '' ) );
        $sender   = (string) ( $settings['pilot_sender'] ?? '' );
        $since    = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

        $statuses = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT status, COUNT(*) AS total FROM {$messages} WHERE direction = 'outbound' AND provider_key = %s AND from_number = %s AND created_at >= %s GROUP BY status",
                $provider,
                $sender,
                $since
            ),
            ARRAY_A
        );
        $counts = [];
        foreach ( (array) $statuses as $row ) {
            $counts[ $row['status'] ] = (int) $row['total'];
        }
        $sent         = array_sum( $counts );
        $delivered    = (int) ( $counts['delivered'] ?? 0 );
        $failed       = (int) ( $counts['failed'] ?? 0 );
        $optout_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$optouts} WHERE provider_key = %s AND created_at >= %s", $provider, $since ) );

        $finalized      = $delivered + $failed;
        $awaiting       = (int) ( $counts['queued'] ?? 0 ) + (int) ( $counts['sent'] ?? 0 ) + (int) ( $counts['sending'] ?? 0 );
        $delivery_rate = $finalized ? round( 100 * $delivered / $finalized, 2 ) : 0;
        $failure_rate  = $sent ? round( 100 * $failed / $sent, 2 ) : 0;
        $optout_rate   = $sent ? round( 100 * $optout_count / $sent, 2 ) : 0;
        $alerts        = [];
        if ( $finalized >= 20 && $delivery_rate < 90 ) {
            $alerts[] = __( 'Delivery rate is below 90%.', 'messageistic' );
        }
        if ( $failure_rate > 5 ) {
            $alerts[] = __( 'Failure rate is above 5%.', 'messageistic' );
        }
        if ( $awaiting > 20 ) {
            $alerts[] = __( 'More than 20 messages are still awaiting a final delivery status.', 'messageistic' );
        }
        if ( $optout_rate > 2 ) {
            $alerts[] = __( 'Opt-out rate is above 2%.', 'messageistic' );
        }

        return [
            'period_start'    => $since,
            'business_name'   => (string) ( $settings['business_name'] ?? '' ),
            'location'        => (string) ( $settings['pilot_location_name'] ?? '' ) . ', ' . (string) ( $settings['pilot_location_state'] ?? '' ),
            'provider'        => $provider,
            'sender'          => $sender,
            'generated_at'    => current_time( 'mysql' ),
            'sent'            => $sent,
            'delivered'       => $delivered,
            'failed'          => $failed,
            'queued'          => (int) ( $counts['queued'] ?? 0 ),
            'awaiting_delivery' => $awaiting,
            'optouts'         => $optout_count,
            'delivery_rate'   => $delivery_rate,
            'failure_rate'    => $failure_rate,
            'optout_rate'     => $optout_rate,
            'alerts'          => $alerts,
            'requires_review' => true,
        ];
    }

    public static function acknowledge(): void {
        update_option( self::OPTION_REVIEWED, time(), false );
        $snapshot = (array) get_option( self::OPTION_SNAPSHOT, [] );
        $snapshot['requires_review'] = false;
        $snapshot['reviewed_at']     = current_time( 'mysql' );
        $snapshot['reviewed_by']     = get_current_user_id();
        update_option( self::OPTION_SNAPSHOT, $snapshot, false );
    }

    /** @return array<string,mixed> */
    public static function snapshot(): array {
        return (array) get_option( self::OPTION_SNAPSHOT, [] );
    }

    public static function admin_notice(): void {
        $settings = (array) get_option( 'messageistic_general_settings', [] );
        $snapshot = self::snapshot();
        if ( empty( $settings['pilot_mode_enabled'] ) || empty( $snapshot['requires_review'] ) || ! current_user_can( Permissions::VIEW ) ) {
            return;
        }
        $url = admin_url( 'admin.php?page=messageistic-reports' );
        echo '<div class="notice notice-warning"><p>' . esc_html__( 'Messageistic pilot delivery and opt-out report requires daily human review.', 'messageistic' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Review report', 'messageistic' ) . '</a></p></div>';
    }

    private static function email_body( array $snapshot ): string {
        return sprintf(
            "Period start: %s\nSent: %d\nDelivered: %d (%.2f%%)\nFailed: %d (%.2f%%)\nQueued: %d\nAwaiting final status: %d\nOpt-outs: %d (%.2f%%)\n\nReview and acknowledge this report in WordPress Admin > Messageistic > Reports.",
            (string) $snapshot['period_start'],
            (int) $snapshot['sent'],
            (int) $snapshot['delivered'],
            (float) $snapshot['delivery_rate'],
            (int) $snapshot['failed'],
            (float) $snapshot['failure_rate'],
            (int) $snapshot['queued'],
            (int) $snapshot['awaiting_delivery'],
            (int) $snapshot['optouts'],
            (float) $snapshot['optout_rate']
        ) . ( empty( $snapshot['alerts'] ) ? '' : "\n\nALERTS:\n- " . implode( "\n- ", (array) $snapshot['alerts'] ) );
    }
}
