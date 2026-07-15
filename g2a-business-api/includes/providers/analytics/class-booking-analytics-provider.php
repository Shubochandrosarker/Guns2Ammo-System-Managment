<?php
/**
 * Booking revenue aggregates from the G2A Booking Engine payments ledger.
 *
 * REVENUE RECOGNITION — payment-status mapping (source of truth:
 * g2a-booking-engine `{prefix}g2ab_payments.status`, values observed in
 * includes/payments/*.php and admin/class-payments-list.php):
 *
 *   status            | counted as                        | why
 *   ------------------+-----------------------------------+---------------------------------------------
 *   succeeded         | revenue (gross)                   | Stripe/PayPal webhook capture
 *   captured          | revenue (gross)                   | Authorize.net / Fortis capture
 *   paid, completed   | revenue (gross)                   | legacy/defensive: pre-1.3 rows used booking
 *                     |                                   | vocabulary in the payments column
 *   refunded          | revenue (gross) + refund_amount   | funds WERE captured, then returned;
 *   partial_refund    |   subtracted into net             | gross keeps `amount`, net subtracts refunds
 *   pending           | excluded (never captured)         | intent created / pay-in-store reservation
 *   failed            | excluded (never captured)         | gateway decline
 *   anything else     | excluded + reconciliation warning | unknown state — surfaced, never guessed
 *
 * gross = SUM(amount) over included statuses;
 * refunds = SUM(refund_amount) over included statuses;
 * net = gross - refunds. All integer USD cents.
 *
 * Reconciliation warnings additionally count bookings in paid-ish booking
 * states (paid/confirmed/completed — G2AB_Analytics::PAID_STATUSES) that
 * have NO revenue-status payment row: money the booking claims but the
 * ledger cannot substantiate.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Range;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Booking_Analytics_Provider extends Analytics_Provider_Base {
	/** Payment statuses whose `amount` is recognised as gross revenue. */
	public const REVENUE_STATUSES = array( 'succeeded', 'captured', 'paid', 'completed', 'refunded', 'partial_refund' );

	/** Payment statuses that are known-but-not-revenue (no warning). */
	public const NON_REVENUE_STATUSES = array( 'pending', 'failed', 'cancelled', 'voided' );

	/** Booking (not payment) statuses that claim revenue — mirrors G2AB_Analytics::PAID_STATUSES. */
	public const PAID_BOOKING_STATUSES = array( 'paid', 'confirmed', 'completed' );

	public function source_slug(): string {
		return 'g2a-booking-engine';
	}

	public function is_available(): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		return $this->table_exists( $wpdb->prefix . 'g2ab_bookings' )
			&& $this->table_exists( $wpdb->prefix . 'g2ab_payments' );
	}

	protected function empty_summary(): array {
		return array(
			// Frontend contract keys (dashboard-app types/api.ts).
			'count'                => 0,
			'paid'                 => 0,
			'unpaid'               => 0,
			'revenueCents'         => 0,
			// Richer detail.
			'revenue'              => array(
				'grossCents'  => 0,
				'refundCents' => 0,
				'netCents'    => 0,
			),
			'bookingCount'         => 0,
			'avgBookingValueCents' => 0,
			'byStatus'             => array(),
			'reconciliation'       => array(
				'warnings'                   => 0,
				'paidBookingsWithoutPayment' => 0,
				'unknownStatusPayments'      => 0,
			),
		);
	}

	protected function build_summary( Range $range ): array {
		global $wpdb;

		$bookings = $wpdb->prefix . 'g2ab_bookings';
		$payments = $wpdb->prefix . 'g2ab_payments';
		list( $from, $to ) = self::range_bounds( $range );

		// 1. Booking counts by status (bounded aggregate).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$status_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS c FROM {$bookings} WHERE created_at BETWEEN %s AND %s GROUP BY status",
				$from,
				$to
			),
			ARRAY_A
		);
		$by_status     = array();
		$booking_count = 0;
		foreach ( (array) $status_rows as $row ) {
			$count                                        = (int) ( $row['c'] ?? 0 );
			$by_status[ (string) ( $row['status'] ?? '' ) ] = $count;
			$booking_count                               += $count;
		}

		// 2. Payment ledger grouped by status (bounded aggregate; recognition
		//    date = processed_at, falling back to created_at for rows the
		//    gateway never stamped).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$payment_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status,
					COUNT(*) AS c,
					COUNT(DISTINCT booking_id) AS booking_c,
					COALESCE(SUM(amount), 0) AS amount_sum,
					COALESCE(SUM(refund_amount), 0) AS refund_sum
				 FROM {$payments}
				 WHERE COALESCE(processed_at, created_at) BETWEEN %s AND %s
				 GROUP BY status",
				$from,
				$to
			),
			ARRAY_A
		);
		$ledger = self::aggregate_payment_rows( (array) $payment_rows );

		// 3. Paid-ish bookings without any revenue-status payment row.
		$revenue_placeholders = implode( ',', array_fill( 0, count( self::REVENUE_STATUSES ), '%s' ) );
		$paid_placeholders    = implode( ',', array_fill( 0, count( self::PAID_BOOKING_STATUSES ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orphaned = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$bookings} b
				 WHERE b.created_at BETWEEN %s AND %s
				   AND b.status IN ({$paid_placeholders})
				   AND NOT EXISTS (
					 SELECT 1 FROM {$payments} p
					 WHERE p.booking_id = b.id AND p.status IN ({$revenue_placeholders})
				   )",
				array_merge( array( $from, $to ), self::PAID_BOOKING_STATUSES, self::REVENUE_STATUSES )
			)
		);

		// 4. Freshness marker (bounded MAX aggregate).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last_updated = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(updated_at) FROM {$bookings} WHERE created_at BETWEEN %s AND %s",
				$from,
				$to
			)
		);

		$warnings = $orphaned + $ledger['unknownCount'];

		// Frontend contract: paid = bookings in a revenue-claiming booking
		// state, unpaid = everything else (pending/reserved/cancelled/...).
		$paid_bookings = 0;
		foreach ( self::PAID_BOOKING_STATUSES as $status ) {
			$paid_bookings += (int) ( $by_status[ $status ] ?? 0 );
		}

		return array(
			'count'                => $booking_count,
			'paid'                 => $paid_bookings,
			'unpaid'               => max( 0, $booking_count - $paid_bookings ),
			'revenueCents'         => $ledger['netCents'],
			'revenue'              => array(
				'grossCents'  => $ledger['grossCents'],
				'refundCents' => $ledger['refundCents'],
				'netCents'    => $ledger['netCents'],
			),
			'bookingCount'         => $booking_count,
			'avgBookingValueCents' => $ledger['includedBookingCount'] > 0
				? (int) round( $ledger['netCents'] / $ledger['includedBookingCount'] )
				: 0,
			'byStatus'             => $by_status,
			'reconciliation'       => array(
				'warnings'                   => $warnings,
				'paidBookingsWithoutPayment' => $orphaned,
				'unknownStatusPayments'      => $ledger['unknownCount'],
			),
			'lastUpdatedAt'        => self::iso_from_db( $last_updated ),
		);
	}

	/**
	 * Classify one payment status per the mapping in the file header.
	 *
	 * @internal Exposed (pure) for tests + the reconciliation CLI.
	 * @return string 'revenue' | 'excluded' | 'unknown'
	 */
	public static function classify_payment_status( string $status ): string {
		$status = strtolower( trim( $status ) );
		if ( in_array( $status, self::REVENUE_STATUSES, true ) ) {
			return 'revenue';
		}
		if ( in_array( $status, self::NON_REVENUE_STATUSES, true ) ) {
			return 'excluded';
		}
		return 'unknown';
	}

	/**
	 * Pure money math over grouped payment rows.
	 *
	 * @internal Exposed for tests + the reconciliation CLI.
	 *
	 * @param array<int, array<string, mixed>> $rows Each: status, c,
	 *        booking_c, amount_sum, refund_sum (decimal strings).
	 * @return array{grossCents:int, refundCents:int, netCents:int,
	 *         includedCount:int, includedBookingCount:int,
	 *         excludedCount:int, unknownCount:int,
	 *         excludedByStatus:array<string,int>}
	 */
	public static function aggregate_payment_rows( array $rows ): array {
		$gross              = 0;
		$refunds            = 0;
		$included           = 0;
		$included_bookings  = 0;
		$excluded           = 0;
		$unknown            = 0;
		$excluded_by_status = array();

		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			$count  = (int) ( $row['c'] ?? 0 );

			switch ( self::classify_payment_status( $status ) ) {
				case 'revenue':
					$gross            += Money::to_cents_from_string( (string) ( $row['amount_sum'] ?? '0' ) );
					$refunds          += Money::to_cents_from_string( (string) ( $row['refund_sum'] ?? '0' ) );
					$included         += $count;
					$included_bookings += (int) ( $row['booking_c'] ?? 0 );
					break;
				case 'excluded':
					$excluded                       += $count;
					$excluded_by_status[ $status ]   = ( $excluded_by_status[ $status ] ?? 0 ) + $count;
					break;
				default:
					$unknown                        += $count;
					$excluded_by_status[ $status ]   = ( $excluded_by_status[ $status ] ?? 0 ) + $count;
					break;
			}
		}

		return array(
			'grossCents'           => $gross,
			'refundCents'          => $refunds,
			'netCents'             => $gross - $refunds,
			'includedCount'        => $included,
			'includedBookingCount' => $included_bookings,
			'excludedCount'        => $excluded,
			'unknownCount'         => $unknown,
			'excludedByStatus'     => $excluded_by_status,
		);
	}
}
