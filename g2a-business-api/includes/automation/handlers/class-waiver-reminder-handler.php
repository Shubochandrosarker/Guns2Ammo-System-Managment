<?php
/**
 * Waiver reminder handler.
 *
 * Finds bookings that are within the next 24 hours AND whose
 * `waiver_signed_at` is null. Drafts a "please sign the waiver before you
 * come in" email with the waiver link.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Waiver_Reminder_Handler extends Handler_Base {
	public static function slug(): string {
		return 'waiver-reminder';
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

		$now    = gmdate( 'Y-m-d H:i:s' );
		$window = gmdate( 'Y-m-d H:i:s', strtotime( '+25 hours' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, email, name FROM {$table} WHERE waiver_signed_at IS NULL AND status IN ('paid','completed','pending','unpaid') AND start_at BETWEEN %s AND %s",
				$now,
				$window
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$drafted = 0;
		$store   = new Email_Draft_Store();
		foreach ( $rows as $row ) {
			$email = (string) ( $row['email'] ?? '' );
			if ( '' === $email && isset( $row['user_id'] ) ) {
				$user  = get_userdata( (int) $row['user_id'] );
				$email = $user ? (string) $user->user_email : '';
			}
			if ( '' === $email ) {
				continue;
			}
			$store->enqueue(
				sprintf( 'Waiver reminder for booking #%s', (string) ( $row['id'] ?? '' ) ),
				$email,
				'Please sign your Guns2Ammo waiver before your visit',
				self::compose_body( (string) ( $row['name'] ?? '' ) )
			);
			$drafted++;
		}

		if ( 0 === $drafted ) {
			return 'No waiver reminders needed.';
		}
		return sprintf( 'Drafted %d waiver reminder%s.', $drafted, 1 === $drafted ? '' : 's' );
	}

	public static function compose_body( string $name ): string {
		return sprintf(
			"Hi %s,\n\n" .
			"Our records show you don't have a signed waiver on file yet. To check in faster tomorrow, please sign it in advance:\n\n" .
			"https://guns2ammo.com/waiver\n\n" .
			"Takes about a minute. See you soon!\n",
			'' === $name ? 'there' : $name
		);
	}
}
