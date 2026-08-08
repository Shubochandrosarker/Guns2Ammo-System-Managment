<?php
/**
 * G2AB_Booking_Activity — small wrapper around the g2ab_booking_activity table.
 *
 * Stores a row per state-changing action (status change, reschedule, cancel,
 * staff note). The calendar's booking detail panel reads these to show a
 * human-readable history.
 *
 * @package G2AB\Services
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Booking_Activity {

	/**
	 * Record an activity row.
	 *
	 * @param int    $booking_id Booking row id.
	 * @param string $action     Short verb: status_changed|rescheduled|cancelled|noted|...
	 * @param mixed  $old_value  Optional previous value (will be JSON-encoded).
	 * @param mixed  $new_value  Optional new value (will be JSON-encoded).
	 * @param string $note       Optional human-readable note.
	 * @param int    $user_id    Acting user (defaults to current user).
	 * @return int|false Inserted row id, or false on failure.
	 */
	public static function log( $booking_id, $action, $old_value = null, $new_value = null, $note = '', $user_id = null ) {
		global $wpdb;
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}
		$table = $wpdb->prefix . 'g2ab_booking_activity';
		$ok    = $wpdb->insert( $table, array(
			'booking_id' => (int) $booking_id,
			'action'     => sanitize_key( $action ),
			'old_value'  => null === $old_value ? null : wp_json_encode( $old_value ),
			'new_value'  => null === $new_value ? null : wp_json_encode( $new_value ),
			'user_id'    => $user_id ? (int) $user_id : null,
			'note'       => sanitize_textarea_field( (string) $note ),
			'created_at' => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' ) );

		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Return activity rows for a booking, newest first.
	 *
	 * @param int $booking_id
	 * @param int $limit
	 * @return array
	 */
	public static function for_booking( $booking_id, $limit = 50 ) {
		global $wpdb;
		$table = $wpdb->prefix . 'g2ab_booking_activity';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, action, old_value, new_value, user_id, note, created_at
			   FROM {$table}
			  WHERE booking_id = %d
			  ORDER BY created_at DESC
			  LIMIT %d",
			(int) $booking_id,
			(int) $limit
		) );
	}
}
