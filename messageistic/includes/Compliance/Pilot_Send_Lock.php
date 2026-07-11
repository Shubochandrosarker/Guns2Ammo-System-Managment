<?php
/**
 * Serializes policy checks and message reservations across workers/requests
 * via a real MySQL advisory lock (GET_LOCK), so the daily/per-contact send-
 * frequency checks in Policy_Engine::evaluate() and the message row that
 * satisfies them (Message_Repository::create()) happen as one atomic step.
 *
 * Originally only wrapped pilot-mode sends (hence the class name), but the
 * same get-transient-then-set-transient-style non-atomic gap existed for
 * every other send path too — Policy_Engine's daily/contact limit checks are
 * plain COUNT(*) reads with no lock of their own, so two concurrent sends
 * could both read a count under the limit and both proceed. SMS_Service now
 * acquires this lock around every send, not just pilot-mode ones.
 *
 * @package Messageistic\Compliance
 */

namespace Messageistic\Compliance;

defined( 'ABSPATH' ) || exit;

final class Pilot_Send_Lock {

    public static function acquire( int $timeout = 5 ): bool {
        global $wpdb;
        $name = 'messageistic_send_' . get_current_blog_id();
        return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, max( 0, $timeout ) ) );
    }

    public static function release(): void {
        global $wpdb;
        $name = 'messageistic_send_' . get_current_blog_id();
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
    }
}
