<?php
/**
 * Post-booking upsell for Ladies Tuesday attendees.
 *
 * When someone books a Ladies Tuesday slot we draft two follow-up emails
 * per attendee:
 *   1) "Bring a friend next time" ($15 for the +1)
 *   2) "Take the CCW class" (positions the graduated next step)
 *
 * We look at bookings created in the last hour to avoid drafting duplicates
 * on every cron tick.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ladies_Upsell_Handler extends Handler_Base {
	public static function slug(): string {
		return 'post-booking-upsell-ladies';
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

		$since = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, email, name FROM {$table} WHERE type = %s AND created_at >= %s",
				'Ladies Tuesday',
				$since
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return 'No fresh Ladies Tuesday bookings this run.';
		}

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
			$name = (string) ( $row['name'] ?? '' );
			$store->enqueue(
				sprintf( 'Ladies Tuesday follow-up for %s', $name ?: $email ),
				$email,
				'Bring a friend next Ladies Tuesday — $15',
				self::body_bring_friend( $name )
			);
			$store->enqueue(
				sprintf( 'Ladies Tuesday CCW invite for %s', $name ?: $email ),
				$email,
				'Ready to carry? Our CCW class is Ladies-Tuesday-friendly',
				self::body_ccw( $name )
			);
			$drafted += 2;
		}
		return sprintf( 'Drafted %d Ladies Tuesday upsell email%s.', $drafted, 1 === $drafted ? '' : 's' );
	}

	public static function body_bring_friend( string $name ): string {
		return sprintf(
			"Hi %s,\n\n" .
			"So glad you booked Ladies Tuesday! If you'd like to bring a friend next time, we've got a member-priced +1 for \$15/hr.\n\n" .
			"Book here: https://guns2ammo.com/ladies-tuesday/?ref=friend\n\n" .
			"See you soon!\n",
			'' === $name ? 'there' : $name
		);
	}

	public static function body_ccw( string $name ): string {
		return sprintf(
			"Hi %s,\n\n" .
			"Once Ladies Tuesday feels comfortable, our CCW class is a natural next step — small class sizes, all female-taught blocks, and it counts toward the AZ permit.\n\n" .
			"Details + schedule: https://guns2ammo.com/ccw-class/\n\n" .
			"Any questions, just reply.\n",
			'' === $name ? 'there' : $name
		);
	}
}
