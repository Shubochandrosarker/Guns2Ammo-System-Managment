<?php
/**
 * Report_Generator — composes report bodies from live providers.
 *
 * The single entry point is `generate( $handlerSlug )`. It returns an
 * associative payload `{ body, format, range }` that Reports_Store persists
 * as the report's latest delivery.
 *
 * Weekly_Report_Handler delegates here so the cron path and the
 * on-demand "Run now" button produce identical bodies. Every other
 * scheduled report (daily-summary, monthly-growth, seo, booking, member,
 * woo, ai-recs) is composed inline in this file — Providers stay pure,
 * generation stays here.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Reports;

use WordPressistic\G2ABA\Providers\Booking_Provider;
use WordPressistic\G2ABA\Providers\Gaps_Provider;
use WordPressistic\G2ABA\Providers\Insightistic_Provider;
use WordPressistic\G2ABA\Providers\Membership_Provider;
use WordPressistic\G2ABA\Providers\Revenue_Provider;
use WordPressistic\G2ABA\Providers\SEO_Provider;
use WordPressistic\G2ABA\Providers\Store_Provider;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Report_Generator {
	/**
	 * @return array{body:string, format:string, range:array{from:string,to:string}}
	 */
	public function generate( string $handler_slug ): array {
		switch ( $handler_slug ) {
			case 'daily-summary':
				return $this->daily_summary();
			case 'weekly-business':
				return $this->weekly_business();
			case 'monthly-growth':
				return $this->monthly_growth();
			case 'seo':
				return $this->seo_report();
			case 'booking':
				return $this->booking_report();
			case 'member':
				return $this->member_report();
			case 'woo':
				return $this->woo_report();
			case 'ai-recs':
				return $this->ai_recs_report();
			default:
				return $this->stub( 'Report handler not registered.' );
		}
	}

	private function daily_summary(): array {
		$range    = $this->range_yesterday();
		$revenue  = ( new Revenue_Provider() )->overview( $range );
		$bookings = ( new Booking_Provider() )->analytics( $range );
		$members  = ( new Membership_Provider() )->analytics( $range );
		$seo      = ( new SEO_Provider() )->analytics( $range );

		$body = self::compose_daily(
			$range,
			(int) ( $revenue['totalRevenue'] ?? 0 ),
			(int) array_sum( array_column( (array) ( $bookings['bookingsByType'] ?? array() ), 'count' ) ),
			(int) ( $members['renewals'] ?? 0 ),
			(int) ( $members['expired'] ?? 0 ),
			(int) ( $seo['clicks'] ?? 0 )
		);
		return $this->wrap( $body, $range );
	}

	private function weekly_business(): array {
		$range    = new Range( gmdate( 'Y-m-d', strtotime( '-6 days' ) ), gmdate( 'Y-m-d' ) );
		$revenue  = ( new Revenue_Provider() )->overview( $range );
		$bookings = ( new Booking_Provider() )->analytics( $range );
		$members  = ( new Membership_Provider() )->analytics( $range );
		$store    = ( new Store_Provider() )->analytics( $range );
		$seo      = ( new SEO_Provider() )->analytics( $range );

		$body = self::compose_weekly( $range, $revenue, $bookings, $members, $store, $seo );
		return $this->wrap( $body, $range );
	}

	private function monthly_growth(): array {
		$range   = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		$revenue = ( new Revenue_Provider() )->overview( $range );
		$members = ( new Membership_Provider() )->analytics( $range );

		$body = self::compose_monthly(
			$range,
			(int) ( $revenue['totalRevenue'] ?? 0 ),
			(float) ( $revenue['revenueGrowthPct'] ?? 0 ),
			(int) ( $members['active'] ?? 0 ),
			(int) ( $members['mrr'] ?? 0 )
		);
		return $this->wrap( $body, $range );
	}

	private function seo_report(): array {
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		$seo   = ( new SEO_Provider() )->analytics( $range );
		$body  = self::compose_seo( $range, $seo );
		return $this->wrap( $body, $range );
	}

	private function booking_report(): array {
		$range    = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		$bookings = ( new Booking_Provider() )->analytics( $range );
		$body     = self::compose_bookings( $range, $bookings );
		return $this->wrap( $body, $range );
	}

	private function member_report(): array {
		$range   = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		$members = ( new Membership_Provider() )->analytics( $range );
		$body    = self::compose_members( $range, $members );
		return $this->wrap( $body, $range );
	}

	private function woo_report(): array {
		$range = new Range( gmdate( 'Y-m-d', strtotime( '-29 days' ) ), gmdate( 'Y-m-d' ) );
		$store = ( new Store_Provider() )->analytics( $range );
		$body  = self::compose_woo( $range, $store );
		return $this->wrap( $body, $range );
	}

	private function ai_recs_report(): array {
		$range    = new Range( gmdate( 'Y-m-d', strtotime( '-6 days' ) ), gmdate( 'Y-m-d' ) );
		$insights = ( new Insightistic_Provider() )->analytics( $range );
		$gaps     = ( new Gaps_Provider() )->all();
		$body     = self::compose_ai_recs( $range, $insights, $gaps );
		return $this->wrap( $body, $range );
	}

	private function stub( string $why ): array {
		return array(
			'body'   => $why,
			'format' => 'text',
			'range'  => array( 'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d' ) ),
		);
	}

	private function range_yesterday(): Range {
		$y = gmdate( 'Y-m-d', strtotime( '-1 day' ) );
		return new Range( $y, $y );
	}

	private function wrap( string $body, Range $range ): array {
		return array(
			'body'   => $body,
			'format' => 'text',
			'range'  => $range->to_array(),
		);
	}

	// -----------------------------------------------------------------------
	// Body composers — pure functions so unit tests can pin exact wording.
	// -----------------------------------------------------------------------

	public static function compose_daily(
		Range $range,
		int $revenue_cents,
		int $bookings,
		int $renewals,
		int $expired,
		int $clicks
	): string {
		$lines = array(
			sprintf( 'Guns2Ammo — daily summary for %s', $range->from ),
			'',
			sprintf( 'Revenue: $%s', number_format( $revenue_cents / 100 ) ),
			sprintf( 'Bookings: %d', $bookings ),
			sprintf( 'Memberships: %d renewed · %d expired', $renewals, $expired ),
			sprintf( 'SEO clicks: %d', $clicks ),
			'',
			'Full detail: https://app.guns2ammo.com/dashboard',
		);
		return implode( "\n", $lines );
	}

	public static function compose_weekly(
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
		);

		$waivers = self::waiver_digest_line();
		if ( '' !== $waivers ) {
			$lines[] = $waivers;
		}

		$lines[] = '';
		$lines[] = 'Full detail: https://app.guns2ammo.com/business-analysis';
		return implode( "\n", $lines );
	}

	/**
	 * One-line waiver-operations digest for the weekly report: signings this
	 * week (kiosk share), waivers expiring in the next 30 days, and upcoming
	 * bookings with no waiver on file. Empty string when Memberistic's waiver
	 * tables aren't present.
	 */
	private static function waiver_digest_line(): string {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! property_exists( $wpdb, 'prefix' ) ) {
			return '';
		}
		$sigs    = $wpdb->prefix . 'memberistic_waiver_signatures';
		$archive = $wpdb->prefix . 'memberistic_waivers_archive';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sigs ) )
			|| ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $archive ) ) ) {
			return '';
		}

		$week_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$signed_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sigs} WHERE signed_at >= %s", $week_ago ) );
		$kiosk_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sigs} WHERE signed_at >= %s AND source = 'kiosk'", $week_ago ) );

		// Expiring in 30 days: current archive rows whose signed_at falls in
		// the month before the validity cutoff (validity default 365 days).
		$validity = class_exists( '\WordPressistic\Memberistic\Waivers\Waiver_Service' )
			? (int) \WordPressistic\Memberistic\Waivers\Waiver_Service::validity_days()
			: 365;
		$cutoff   = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $validity . ' days' ) );
		$soon     = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $validity - 30 ) . ' days' ) );
		$expiring = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$archive} WHERE is_current = 1 AND signed_at IS NOT NULL AND signed_at >= %s AND signed_at < %s", $cutoff, $soon ) );

		// Bookings in the next 7 days still missing a waiver.
		$missing  = 0;
		$bookings = $wpdb->prefix . 'g2ab_bookings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) ) ) {
			$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM {$bookings}" );
			if ( in_array( 'waiver_signed', $cols, true ) && in_array( 'customer_email', $cols, true ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT customer_email FROM {$bookings} WHERE waiver_signed = 0 AND status IN ('paid','completed','pending','unpaid') AND start_at BETWEEN %s AND %s",
						gmdate( 'Y-m-d H:i:s' ),
						gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) )
					),
					ARRAY_A
				);
				foreach ( (array) $rows as $b ) {
					$email = (string) ( $b['customer_email'] ?? '' );
					if ( '' === $email
						|| ! class_exists( '\WordPressistic\Memberistic\Waivers\Waivers_Archive' )
						|| ! \WordPressistic\Memberistic\Waivers\Waivers_Archive::has_on_file( $email ) ) {
						$missing++;
					}
				}
			}
		}
		// phpcs:enable

		return sprintf(
			'Waivers: %d signed this week (%d at the kiosk) · %d expiring in 30d · %d upcoming bookings missing a waiver',
			$signed_7d,
			$kiosk_7d,
			$expiring,
			$missing
		);
	}

	public static function compose_monthly(
		Range $range,
		int $revenue_cents,
		float $growth_pct,
		int $active_members,
		int $mrr_cents
	): string {
		$lines = array(
			sprintf( 'Guns2Ammo — monthly growth · %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Trailing 30d revenue: $%s (%s%.1f%% vs prior 30d)', number_format( $revenue_cents / 100 ), $growth_pct >= 0 ? '+' : '', $growth_pct ),
			sprintf( 'Active shooters: %d', $active_members ),
			sprintf( 'MRR: $%s', number_format( $mrr_cents / 100 ) ),
		);
		return implode( "\n", $lines );
	}

	public static function compose_seo( Range $range, array $seo ): string {
		$clicks       = (int) ( $seo['clicks'] ?? 0 );
		$impressions  = (int) ( $seo['impressions'] ?? 0 );
		$avg_position = (float) ( $seo['averagePosition'] ?? 0 );

		$lines = array(
			sprintf( 'SEO report · %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Clicks: %d', $clicks ),
			sprintf( 'Impressions: %d', $impressions ),
			sprintf( 'Avg position: %.1f', $avg_position ),
		);

		$top = (array) ( $seo['topQueries'] ?? array() );
		if ( ! empty( $top ) ) {
			$lines[] = '';
			$lines[] = 'Top queries:';
			foreach ( array_slice( $top, 0, 5 ) as $row ) {
				$q = (string) ( $row['query'] ?? '?' );
				$c = (int) ( $row['clicks'] ?? 0 );
				$lines[] = sprintf( '  · %s — %d clicks', $q, $c );
			}
		}
		return implode( "\n", $lines );
	}

	public static function compose_bookings( Range $range, array $bookings ): string {
		$by_type = (array) ( $bookings['bookingsByType'] ?? array() );
		$total   = (int) array_sum( array_column( $by_type, 'count' ) );

		$lines = array(
			sprintf( 'Booking report · %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Total bookings: %d', $total ),
			sprintf( 'Top type: %s', (string) ( $bookings['topBookingType'] ?? '—' ) ),
			sprintf( 'Cancellations: %d', (int) ( $bookings['cancellations'] ?? 0 ) ),
			sprintf( 'No-shows: %d', (int) ( $bookings['noShows'] ?? 0 ) ),
		);
		if ( ! empty( $by_type ) ) {
			$lines[] = '';
			$lines[] = 'By type:';
			foreach ( $by_type as $row ) {
				$lines[] = sprintf(
					'  · %s — %d bookings, $%s',
					(string) ( $row['type'] ?? '?' ),
					(int) ( $row['count'] ?? 0 ),
					number_format( (int) ( $row['revenue'] ?? 0 ) / 100 )
				);
			}
		}
		return implode( "\n", $lines );
	}

	public static function compose_members( Range $range, array $members ): string {
		$lines = array(
			sprintf( 'Membership report · %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Active: %d', (int) ( $members['active'] ?? 0 ) ),
			sprintf( 'Renewals: %d', (int) ( $members['renewals'] ?? 0 ) ),
			sprintf( 'Expired: %d', (int) ( $members['expired'] ?? 0 ) ),
			sprintf( 'MRR: $%s', number_format( (int) ( $members['mrr'] ?? 0 ) / 100 ) ),
			sprintf( 'Churn rate: %.1f%%', (float) ( $members['churnRate'] ?? 0 ) ),
		);
		return implode( "\n", $lines );
	}

	public static function compose_woo( Range $range, array $store ): string {
		$lines = array(
			sprintf( 'WooCommerce report · %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Revenue: $%s', number_format( (int) ( $store['revenue'] ?? 0 ) / 100 ) ),
			sprintf( 'Orders: %d', (int) ( $store['orders'] ?? 0 ) ),
			sprintf( 'Average order: $%s', number_format( (int) ( $store['averageOrderValue'] ?? 0 ) / 100, 2 ) ),
		);
		$top = (array) ( $store['topProducts'] ?? array() );
		if ( ! empty( $top ) ) {
			$lines[] = '';
			$lines[] = 'Top products:';
			foreach ( array_slice( $top, 0, 5 ) as $row ) {
				$lines[] = sprintf(
					'  · %s — %d sold',
					(string) ( $row['name'] ?? '?' ),
					(int) ( $row['unitsSold'] ?? $row['count'] ?? 0 )
				);
			}
		}
		return implode( "\n", $lines );
	}

	public static function compose_ai_recs( Range $range, array $insights, array $gaps ): string {
		$open_insights = (array) ( $insights['open'] ?? $insights ?? array() );
		$lines = array(
			sprintf( 'AI recommendations · %s to %s', $range->from, $range->to ),
			'',
			sprintf( 'Open AI insights: %d', is_countable( $open_insights ) ? count( $open_insights ) : 0 ),
			sprintf( 'Business gaps: %d', count( $gaps ) ),
		);

		if ( ! empty( $gaps ) ) {
			$lines[] = '';
			$lines[] = 'Top gaps:';
			foreach ( array_slice( $gaps, 0, 5 ) as $g ) {
				$lines[] = sprintf(
					'  · [%s] %s',
					(string) ( $g['priority'] ?? 'medium' ),
					(string) ( $g['title'] ?? '—' )
				);
			}
		}
		return implode( "\n", $lines );
	}
}
