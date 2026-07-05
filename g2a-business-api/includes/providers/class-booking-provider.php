<?php
/**
 * Bookings analytics — reads from the G2A Booking Engine (`g2ab_*` tables).
 *
 * The real `g2ab_bookings` schema (g2a-booking-engine class-installer.php)
 * keys the booking type via `booking_type_id` → `g2ab_booking_types.name`,
 * carries money in `total_amount` / `paid_amount`, and treats
 * paid|confirmed|completed as revenue statuses (see G2AB_Analytics). When
 * the booking engine's G2AB_Analytics class is loaded we reuse its
 * PAID_STATUSES constant; otherwise we fall back to the same literal set.
 *
 * If the tables aren't installed, return a zeroed skeleton so the dashboard
 * reads a stable payload either way.
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
	/**
	 * Fallback when the booking engine isn't loaded — mirrors
	 * G2AB_Analytics::PAID_STATUSES.
	 */
	public const PAID_STATUSES = array( 'paid', 'confirmed', 'completed' );

	/**
	 * Statuses counted as revenue. Delegates to the booking engine's own
	 * constant when its class is loaded so the two plugins can never drift.
	 *
	 * @return array<int, string>
	 */
	public static function paid_statuses(): array {
		if ( class_exists( '\G2AB_Analytics' ) ) {
			return (array) \G2AB_Analytics::PAID_STATUSES;
		}
		return self::PAID_STATUSES;
	}

	public function analytics( Range $range ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'g2ab_bookings';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-check only, table name is trusted.
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return self::empty_payload( $range );
		}

		$types = $wpdb->prefix . 'g2ab_booking_types';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_types = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $types ) );

		$from = $range->from . ' 00:00:00';
		$to   = $range->to . ' 23:59:59';

		$type_select = $has_types
			? "COALESCE(NULLIF(t.name, ''), 'Range Lane')"
			: "'Range Lane'";
		$type_join   = $has_types
			? "LEFT JOIN {$types} t ON t.id = b.booking_type_id"
			: '';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.status, b.total_amount, b.paid_amount, b.created_at,
					{$type_select} AS type_name
				 FROM {$table} b
				 {$type_join}
				 WHERE b.created_at BETWEEN %s AND %s",
				$from,
				$to
			),
			ARRAY_A
		);

		return self::aggregate( is_array( $rows ) ? $rows : array(), $range );
	}

	/**
	 * Pure aggregation over booking rows.
	 *
	 * Each row: status, paid_amount, created_at, type_name. Revenue counts
	 * `paid_amount` on paid-status rows only — the same definition the
	 * booking engine's own dashboard uses.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<int, array<string, mixed>> $rows Booking rows.
	 */
	public static function aggregate( array $rows, Range $range ): array {
		$paid_statuses = self::paid_statuses();

		$by_type        = array();
		$paid           = 0;
		$unpaid         = 0;
		$cancellations  = 0;
		$no_shows       = 0;
		$series_by_date = array();

		foreach ( $rows as $row ) {
			$type    = (string) ( $row['type_name'] ?? 'Range Lane' );
			$status  = (string) ( $row['status'] ?? '' );
			$is_paid = in_array( $status, $paid_statuses, true );
			$revenue = $is_paid
				? Money::to_cents_from_string( (string) ( $row['paid_amount'] ?? '0' ) )
				: 0;

			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array(
					'type'    => $type,
					'count'   => 0,
					'revenue' => 0,
				);
			}
			$by_type[ $type ]['count']++;
			$by_type[ $type ]['revenue'] += $revenue;

			if ( $is_paid ) {
				$paid++;
			} elseif ( 'cancelled' === $status ) {
				$cancellations++;
			} elseif ( 'no_show' === $status ) {
				$no_shows++;
			} else {
				// pending / reserved / refunded / expired — booked but no
				// recognised revenue.
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
			'conversionRate'   => 0.0, // No landing-page funnel source yet.
			'topBookingType'   => $top_type,
			'revenueSeries'    => $revenue_series,
		);
	}

	public static function empty_payload( Range $range ): array {
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
