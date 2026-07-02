<?php
/**
 * Bookings analytics — reads from the G2A Booking Engine (`g2ab_*` tables).
 *
 * We deliberately talk to the plugin via its public helper functions where
 * possible; fall back to $wpdb queries against `{$wpdb->prefix}g2ab_bookings`.
 * If neither is available, return a zeroed skeleton.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Provider {
	public function analytics( Range $range ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'g2ab_bookings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-check only, table name is trusted.
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return $this->empty_payload( $range );
		}

		$from = $range->from . ' 00:00:00';
		$to   = $range->to . ' 23:59:59';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE created_at BETWEEN %s AND %s",
				$from,
				$to
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$by_type          = array();
		$paid             = 0;
		$unpaid           = 0;
		$cancellations    = 0;
		$no_shows         = 0;
		$series_by_date   = array();
		$total_conversion = 0;
		$total_landing    = 0;

		foreach ( $rows as $row ) {
			$type    = (string) ( $row['type'] ?? 'Range Lane' );
			$revenue = Money::to_cents_from_string( (string) ( $row['amount'] ?? '0' ) );
			$status  = (string) ( $row['status'] ?? '' );

			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array(
					'type'    => $type,
					'count'   => 0,
					'revenue' => 0,
				);
			}
			$by_type[ $type ]['count']++;
			$by_type[ $type ]['revenue'] += $revenue;

			if ( 'paid' === $status || 'completed' === $status ) {
				$paid++;
			} elseif ( 'cancelled' === $status ) {
				$cancellations++;
			} elseif ( 'no_show' === $status ) {
				$no_shows++;
			} else {
				$unpaid++;
			}

			$day                    = substr( (string) ( $row['created_at'] ?? $range->from ), 0, 10 );
			$series_by_date[ $day ] = ( $series_by_date[ $day ] ?? 0 ) + $revenue;
		}

		ksort( $series_by_date );
		$revenue_series = array();
		foreach ( $series_by_date as $day => $val ) {
			$revenue_series[] = array( 'date' => $day, 'value' => (int) $val );
		}

		$by_type = array_values( $by_type );
		usort( $by_type, static fn( $a, $b ) => $b['revenue'] <=> $a['revenue'] );
		$top_type = $by_type[0]['type'] ?? '—';

		$total_bookings = $paid + $unpaid + $cancellations + $no_shows;
		return array(
			'range'            => $range->to_array(),
			'bookingsByType'   => $by_type,
			'paidVsUnpaid'     => array( 'paid' => $paid, 'unpaid' => $unpaid ),
			'cancellationRate' => $total_bookings > 0 ? round( ( $cancellations / $total_bookings ) * 100, 1 ) : 0.0,
			'noShowRate'       => $total_bookings > 0 ? round( ( $no_shows / $total_bookings ) * 100, 1 ) : 0.0,
			'conversionRate'   => $total_landing > 0
				? round( ( $total_conversion / $total_landing ) * 100, 1 )
				: 0.0,
			'topBookingType'   => $top_type,
			'revenueSeries'    => $revenue_series,
		);
	}

	private function empty_payload( Range $range ): array {
		return array(
			'range'            => $range->to_array(),
			'bookingsByType'   => array(),
			'paidVsUnpaid'     => array( 'paid' => 0, 'unpaid' => 0 ),
			'cancellationRate' => 0.0,
			'noShowRate'       => 0.0,
			'conversionRate'   => 0.0,
			'topBookingType'   => '—',
			'revenueSeries'    => array(),
		);
	}
}
