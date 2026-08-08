<?php
/**
 * Booking reminder handler (24h before start).
 *
 * Reads the G2A Booking Engine table for bookings whose `start_at` falls
 * inside the next-24h window we haven't reminded yet. For each match, we
 * enqueue a friendly reminder draft addressed to the booking's email (or
 * the associated user's email if only a user_id is stored).
 *
 * When the table is absent we no-op with a diagnostic string.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Reminder_Handler extends Handler_Base {
	public static function slug(): string {
		return 'booking-reminder-24h';
	}

	public static function run(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return 'Skipped — WordPress DB layer unavailable.';
		}

		$table = $wpdb->prefix . 'g2ab_bookings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 'Skipped — Booking Engine table not present.';
		}

		$now      = gmdate( 'Y-m-d H:i:s' );
		$window   = gmdate( 'Y-m-d H:i:s', strtotime( '+25 hours' ) );

		// Any status other than cancelled/refunded gets a reminder — the
		// customer explicitly asked for the slot.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, email, name, type, start_at FROM {$table} WHERE status IN ('paid','completed','pending','unpaid') AND start_at BETWEEN %s AND %s",
				$now,
				$window
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$drafted = 0;
		$store   = new Email_Draft_Store();
		foreach ( $rows as $row ) {
			$email = self::resolve_email( (string) ( $row['email'] ?? '' ), (int) ( $row['user_id'] ?? 0 ) );
			if ( '' === $email ) {
				continue;
			}
			$name    = (string) ( $row['name'] ?? '' );
			$type    = (string) ( $row['type'] ?? 'booking' );
			$start   = (string) ( $row['start_at'] ?? '' );
			$store->enqueue(
				sprintf( 'Booking reminder for %s (#%s)', $name ?: $email, (string) ( $row['id'] ?? '' ) ),
				$email,
				self::subject_for( $type, $start ),
				self::compose_body( $name, $type, $start ),
				\WordPressistic\G2ABA\Ops\Opt_Out_Store::CATEGORY_TRANSACTIONAL
			);
			$drafted++;
		}

		if ( 0 === $drafted ) {
			return 'No bookings due in the next 24h.';
		}
		return sprintf( 'Drafted %d booking reminder%s.', $drafted, 1 === $drafted ? '' : 's' );
	}

	private static function resolve_email( string $email, int $user_id ): string {
		if ( '' !== $email ) {
			return $email;
		}
		if ( $user_id <= 0 ) {
			return '';
		}
		$user = get_userdata( $user_id );
		return $user ? (string) $user->user_email : '';
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function subject_for( string $type, string $start_at ): string {
		return sprintf(
			'Reminder — your Guns2Ammo %s is tomorrow%s',
			'' === $type ? 'booking' : $type,
			'' === $start_at ? '' : ' at ' . substr( $start_at, 11, 5 )
		);
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_body( string $name, string $type, string $start_at ): string {
		return sprintf(
			"Hi %s,\n\n" .
			"Just a friendly reminder that your Guns2Ammo %s is booked for %s.\n\n" .
			"Need to reschedule or cancel? Manage it here: https://guns2ammo.com/account/bookings/\n\n" .
			"See you soon!\n",
			'' === $name ? 'there' : $name,
			'' === $type ? 'booking' : $type,
			'' === $start_at ? 'tomorrow' : substr( $start_at, 0, 16 )
		);
	}
}
