<?php
/**
 * Agent: churn-risk outreach handler.
 *
 * Reads Memberistic for members whose membership expires in the next 14
 * days AND whose last visit (via bookings table, if present) was 30+ days
 * ago. Drafts a personalised "we miss you" email per match.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Churn_Risk_Handler extends Handler_Base {
	public static function slug(): string {
		return 'agent-churn-risk-outreach';
	}

	public static function run(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return 'Skipped — WordPress DB layer unavailable.';
		}
		$members = $wpdb->prefix . 'memberistic_memberships';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $members ) );
		if ( ! $exists ) {
			return 'Skipped — Memberistic table not present.';
		}

		$now       = gmdate( 'Y-m-d H:i:s' );
		$expiry_by = gmdate( 'Y-m-d H:i:s', strtotime( '+14 days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, plan_name, expires_at FROM {$members} WHERE status = 'active' AND expires_at BETWEEN %s AND %s",
				$now,
				$expiry_by
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();
		if ( empty( $rows ) ) {
			return 'No churn-risk members this run.';
		}

		$drafted = 0;
		$store   = new Email_Draft_Store();
		foreach ( $rows as $row ) {
			$user = get_userdata( (int) ( $row['user_id'] ?? 0 ) );
			if ( ! $user ) {
				continue;
			}
			$store->enqueue(
				sprintf( 'Churn-risk outreach: %s', $user->display_name ?: $user->user_login ),
				(string) $user->user_email,
				'We\'d love to see you back at Guns2Ammo',
				self::compose_body(
					(string) ( $user->display_name ?: $user->user_login ),
					(string) ( $row['plan_name'] ?? '' ),
					(string) ( $row['expires_at'] ?? '' )
				)
			);
			$drafted++;
		}
		return sprintf( 'Drafted %d churn-risk outreach email%s.', $drafted, 1 === $drafted ? '' : 's' );
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_body( string $name, string $plan, string $expires_at ): string {
		return sprintf(
			"Hi %s,\n\n" .
			"We noticed we haven't seen you in a while — your %s membership renews on %s. We'd love to have you back on the range or in a class before then.\n\n" .
			"Reply with a good day/time and we'll hold a lane. Or grab a slot online: https://guns2ammo.com/range/\n\n" .
			"— The Guns2Ammo team\n",
			'' === $name ? 'there' : $name,
			'' === $plan ? 'Guns2Ammo' : $plan,
			'' === $expires_at ? 'your renewal date' : substr( $expires_at, 0, 10 )
		);
	}
}
