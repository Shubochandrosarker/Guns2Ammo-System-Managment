<?php
/**
 * Weekly business report handler.
 *
 * Generates a compact summary of the last 7 days (revenue, top movers,
 * membership churn, SEO deltas) and drafts an email to the operator. The
 * draft lands in Email_Draft_Store — an owner reviews + sends from either
 * WP-admin or the dashboard's Email Management page.
 *
 * Body composition delegates to Report_Generator so cron + the dashboard's
 * "Run now" button produce identical output. Reports_Store also gets a
 * copy so the dashboard can render the last delivery.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Automation\Handlers;

use WordPressistic\G2ABA\Ops\Email_Draft_Store;
use WordPressistic\G2ABA\Ops\Opt_Out_Store;
use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Reports\Report_Generator;
use WordPressistic\G2ABA\Reports\Reports_Store;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Weekly_Report_Handler extends Handler_Base {
	public const REPORT_ID = 'weekly-business';

	public static function slug(): string {
		return 'weekly-business-report';
	}

	public static function run(): string {
		$payload = ( new Report_Generator() )->generate( 'weekly-business' );
		$body    = (string) ( $payload['body'] ?? '' );
		$range   = (array) ( $payload['range'] ?? array() );
		$from    = (string) ( $range['from'] ?? gmdate( 'Y-m-d' ) );
		$to      = (string) ( $range['to'] ?? gmdate( 'Y-m-d' ) );

		( new Reports_Store() )->record_delivery( self::REPORT_ID, $payload );

		( new Email_Draft_Store() )->enqueue(
			'Weekly business report',
			'',
			sprintf( 'Weekly business report — %s to %s', $from, $to ),
			$body,
			Opt_Out_Store::CATEGORY_INTERNAL
		);

		return sprintf( 'Weekly report drafted (%s → %s).', $from, $to );
	}

	/**
	 * Kept for backward compatibility with existing tests + any external
	 * caller that constructed the body directly. Delegates to the shared
	 * generator so there's one source of truth.
	 *
	 * @internal
	 */
	public static function compose_body(
		Range $range,
		array $revenue,
		array $bookings,
		array $members,
		array $store,
		array $seo
	): string {
		return Report_Generator::compose_weekly( $range, $revenue, $bookings, $members, $store, $seo );
	}
}
