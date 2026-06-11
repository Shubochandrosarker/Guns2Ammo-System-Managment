<?php
/**
 * Serializes pilot policy checks and message reservations across workers.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

defined( 'ABSPATH' ) || exit;

final class Pilot_Send_Lock {

    public static function acquire( int $timeout = 5 ): bool {
        global $wpdb;
        $name = 'messageistic_pilot_send_' . get_current_blog_id();
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout ) ) );
    }

    public static function release(): void {
        global $wpdb;
        $name = 'messageistic_pilot_send_' . get_current_blog_id();
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }
}
