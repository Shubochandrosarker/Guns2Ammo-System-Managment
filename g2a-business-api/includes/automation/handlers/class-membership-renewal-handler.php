<?php
/**
 * Membership renewal reminder handler (30/7/1 day cadence).
 *
 * Queries Memberistic's members table for anyone whose active membership
 * expires in exactly 30, 7, or 1 day from today, and drafts a personalised
 * renewal email per matched member.
 *
 * When Memberistic isn't installed the handler no-ops with a diagnostic
 * result — it does NOT throw.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Membership_Renewal_Handler extends Handler_Base {
	private const OFFSETS_DAYS = array( 30, 7, 1 );

	public static function slug(): string {
		return 'membership-renewal-reminders';
	}

	public static function run(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return 'Skipped — WordPress DB layer unavailable.';
		}

		$table = $wpdb->prefix . 'memberistic_memberships';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 'Skipped — Memberistic table not present.';
		}

		$drafts_created = 0;
		$store          = new Email_Draft_Store();

		foreach ( self::OFFSETS_DAYS as $offset ) {
			$target_start = gmdate( 'Y-m-d 00:00:00', strtotime( sprintf( '+%d days', $offset ) ) );
			$target_end   = gmdate( 'Y-m-d 23:59:59', strtotime( sprintf( '+%d days', $offset ) ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, plan_name, expires_at FROM {$table} WHERE status = 'active' AND expires_at BETWEEN %s AND %s",
					$target_start,
					$target_end
				),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();

			foreach ( $rows as $row ) {
				$user = get_userdata( (int) ( $row['user_id'] ?? 0 ) );
				if ( ! $user ) {
					continue;
				}
				$email = (string) ( $user->user_email ?? '' );
				$name  = (string) ( $user->display_name ?: $user->user_login );

				$store->enqueue(
					sprintf( 'Membership renewal reminder for %s (%d days out)', $name, $offset ),
					$email,
					self::subject_for( $offset ),
					self::compose_body( $name, (string) ( $row['plan_name'] ?? '' ), (string) ( $row['expires_at'] ?? '' ), $offset )
				);
				$drafts_created++;
			}
		}

		if ( 0 === $drafts_created ) {
			return 'No renewals due at 30/7/1 day today.';
		}
		return sprintf( 'Drafted %d renewal reminder%s.', $drafts_created, 1 === $drafts_created ? '' : 's' );
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function subject_for( int $days_out ): string {
		if ( 1 === $days_out ) {
			return 'Your Guns2Ammo membership renews tomorrow';
		}
		if ( 7 === $days_out ) {
			return 'A week until your Guns2Ammo renewal';
		}
		return 'Heads up — your Guns2Ammo membership renews in 30 days';
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_body( string $name, string $plan, string $expires_at, int $days_out ): string {
		$plan_line = '' === $plan ? '' : sprintf( "Your current plan: %s.\n", $plan );
		return sprintf(
			"Hi %s,\n\n" .
			"%s" .
			"Your Guns2Ammo membership renews on %s (%d day%s away). If everything looks right, no action needed — we'll process the renewal automatically.\n\n" .
			"Need to change plan or update your card?  Manage it here: https://guns2ammo.com/account/memberships/\n\n" .
			"Questions? Reply to this email and we'll help.\n",
			'' === $name ? 'there' : $name,
			$plan_line,
			'' === $expires_at ? 'your renewal date' : substr( $expires_at, 0, 10 ),
			$days_out,
			1 === $days_out ? '' : 's'
		);
	}
}
