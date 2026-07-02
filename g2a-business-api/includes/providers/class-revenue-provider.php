<?php
/**
 * Composite revenue overview — combines Store + Booking + Membership.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Revenue_Provider {
	public function overview( Range $range ): array {
		$store       = ( new Store_Provider() )->analytics( $range );
		$bookings    = ( new Booking_Provider() )->analytics( $range );
		$memberships = ( new Membership_Provider() )->analytics( $range );

		$store_rev   = (int) ( $store['revenue'] ?? 0 );
		$booking_rev = array_sum( array_column( $bookings['bookingsByType'] ?? array(), 'revenue' ) );
		$member_rev  = (int) ( $memberships['mrr'] ?? 0 );
		$total       = $store_rev + $booking_rev + $member_rev;

		$best = 'woocommerce';
		if ( $booking_rev >= $store_rev && $booking_rev >= $member_rev ) {
			$best = 'booking';
		} elseif ( $member_rev >= $store_rev && $member_rev >= $booking_rev ) {
			$best = 'membership';
		}

		$series = $this->merge_series(
			array( $bookings['revenueSeries'] ?? array() ),
			$range
		);

		return array(
			'range'                => $range->to_array(),
			'totalRevenue'         => $total,
			'woocommerceRevenue'   => $store_rev,
			'bookingRevenue'       => (int) $booking_rev,
			'membershipRevenue'    => $member_rev,
			'averageOrderValue'    => (int) ( $store['averageOrderValue'] ?? 0 ),
			'revenueGrowthPct'     => $this->growth_pct( $range, $total ),
			'bestRevenueSource'    => $best,
			'series'               => $series,
		);
	}

	/**
	 * @param array<int,array<int,array{date:string,value:int}>> $series_sets
	 */
	private function merge_series( array $series_sets, Range $range ): array {
		$by_date = array();
		foreach ( $series_sets as $set ) {
			foreach ( $set as $point ) {
				$day             = (string) ( $point['date'] ?? '' );
				$val             = (int) ( $point['value'] ?? 0 );
				$by_date[ $day ] = ( $by_date[ $day ] ?? 0 ) + $val;
			}
		}
		ksort( $by_date );

		// Fill missing days with 0 so charts don't gap.
		$out = array();
		$cur = strtotime( $range->from );
		$end = strtotime( $range->to );
		if ( ! $cur || ! $end ) {
			return $out;
		}
		while ( $cur <= $end ) {
			$day  = gmdate( 'Y-m-d', $cur );
			$out[] = array(
				'date'  => $day,
				'value' => (int) ( $by_date[ $day ] ?? 0 ),
			);
			$cur = strtotime( '+1 day', $cur );
		}
		return $out;
	}

	private function growth_pct( Range $range, int $current_total ): float {
		// Compare against the previous window of equal length.
		$len         = $range->days();
		$prev_from   = gmdate( 'Y-m-d', strtotime( $range->from . ' -' . $len . ' days' ) );
		$prev_to     = gmdate( 'Y-m-d', strtotime( $range->from . ' -1 day' ) );
		$prev_range  = new Range( $prev_from, $prev_to );

		$prev_store       = ( new Store_Provider() )->analytics( $prev_range );
		$prev_bookings    = ( new Booking_Provider() )->analytics( $prev_range );
		$prev_memberships = ( new Membership_Provider() )->analytics( $prev_range );

		$prev_total = (int) ( $prev_store['revenue'] ?? 0 )
			+ (int) array_sum( array_column( $prev_bookings['bookingsByType'] ?? array(), 'revenue' ) )
			+ (int) ( $prev_memberships['mrr'] ?? 0 );

		if ( $prev_total <= 0 ) {
			return $current_total > 0 ? 100.0 : 0.0;
		}
		return round( ( ( $current_total - $prev_total ) / $prev_total ) * 100, 1 );
	}
}
