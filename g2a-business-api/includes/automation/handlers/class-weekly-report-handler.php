<?php
/**
 * Weekly business report handler.
 *
 * Generates a compact summary of the last 7 days (revenue, top movers,
 * membership churn, SEO deltas) and drafts an email to the operator. The
 * draft lands in Email_Draft_Store — an owner reviews + sends from either
 * WP-admin or the dashboard's Email Management page.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Weekly_Report_Handler extends Handler_Base {
	public static function slug(): string {
		return 'weekly-business-report';
	}

	public static function run(): string {
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-6 days' ) ), gmdate( 'Y-m-d' ) );

		$revenue    = ( new Revenue_Provider() )->overview( $range );
		$bookings   = ( new Booking_Provider() )->analytics( $range );
		$members    = ( new Membership_Provider() )->analytics( $range );
		$store      = ( new Store_Provider() )->analytics( $range );
		$seo        = ( new SEO_Provider() )->analytics( $range );

		$body = self::compose_body( $range, $revenue, $bookings, $members, $store, $seo );

		( new Email_Draft_Store() )->enqueue(
			'Weekly business report',
			'',
			sprintf( 'Weekly business report — %s to %s', $range->from, $range->to ),
			$body,
			\WordPressistic\G2ABA\Ops\Opt_Out_Store::CATEGORY_INTERNAL
		);

		return sprintf( 'Weekly report drafted (%s → %s).', $range->from, $range->to );
	}

	/**
	 * @internal Exposed for tests.
	 */
	public static function compose_body(
		Range $range,
		array $revenue,
		array $bookings,
		array $members,
		array $store,
		array $seo
	): string {
		$rev_total = (int) ( $revenue['totalRevenue'] ?? 0 );
		$growth    = (float) ( $revenue['revenueGrowthPct'] ?? 0 );
		$booked    = (int) array_sum( array_column( (array) ( $bookings['bookingsByType'] ?? array() ), 'count' ) );
		$top_type  = (string) ( $bookings['topBookingType'] ?? '—' );
		$active    = (int) ( $members['active'] ?? 0 );
		$renewals  = (int) ( $members['renewals'] ?? 0 );
		$expired   = (int) ( $members['expired'] ?? 0 );
		$store_rev = (int) ( $store['revenue'] ?? 0 );
		$orders    = (int) ( $store['orders'] ?? 0 );
		$clicks    = (int) ( $seo['clicks'] ?? 0 );

		$lines = array(
			sprintf( 'Guns2Ammo — week of %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Revenue: $%s (%s%.1f%% vs previous week)', number_format( $rev_total / 100 ), $growth >= 0 ? '+' : '', $growth ),
			sprintf( 'Bookings: %d (%s leading)', $booked, $top_type ),
			sprintf( 'Store: $%s from %d orders', number_format( $store_rev / 100 ), $orders ),
			sprintf( 'Memberships: %d active · %d renewed · %d expired', $active, $renewals, $expired ),
			sprintf( 'SEO clicks: %d', $clicks ),
			'',
			'Full detail: https://app.guns2ammo.com/business-analysis',
		);
		return implode( "\n", $lines );
	}
}
