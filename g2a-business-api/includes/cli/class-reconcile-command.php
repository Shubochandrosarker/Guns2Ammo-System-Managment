<?php
/**
 * WP-CLI: `wp g2a-business reconcile <bookings|memberships|woo> --from= --to=`
 *
 * Independently recomputes the revenue a dashboard provider reports for a
 * window, straight off the source tables/service, and diffs the two. For
 * each source it prints: the source table/service, source row count,
 * included count, excluded count with per-status reasons (the mapping
 * table), gross/refund/net cents, the dashboard provider's own result for
 * the identical range, the difference, and PASS/FAIL (PASS = zero
 * unexplained difference).
 *
 * The provider side calls `summary()` directly (never `cached_summary()`),
 * so a stale transient can't mask a drift.
 *
 * Registered only when WP_CLI is defined (see Plugin::run()).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\CLI;

use WordPressistic\G2ABA\Money;
use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Woo_Analytics_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\REST\Dashboard_Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reconcile_Command {
	/**
	 * Hooked from Plugin::run(); no-op outside WP-CLI.
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'g2a-business reconcile', new self() );
	}

	/**
	 * Reconcile a dashboard provider against its source of truth.
	 *
	 * ## OPTIONS
	 *
	 * <source>
	 * : Which source to reconcile.
	 * ---
	 * options:
	 *   - bookings
	 *   - memberships
	 *   - woo
	 * ---
	 *
	 * [--from=<YYYY-MM-DD>]
	 * : Window start (default: 29 days before --to).
	 *
	 * [--to=<YYYY-MM-DD>]
	 * : Window end (default: today, site timezone).
	 *
	 * [--verbose]
	 * : Additionally print the provider's daily net-revenue series (the same
	 *   figures the /analytics/<source> and /dashboard/overview `series`
	 *   blocks report) plus its sum, so per-day numbers can be cross-checked
	 *   against the endpoint.
	 *
	 * ## EXAMPLES
	 *
	 *     wp g2a-business reconcile bookings --from=2026-06-01 --to=2026-06-30 --verbose
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args --from/--to/--verbose.
	 */
	public function __invoke( $args, $assoc_args ) {
		$source  = (string) ( $args[0] ?? '' );
		$verbose = ! empty( $assoc_args['verbose'] );

		$parsed = Dashboard_Controller::parse_range(
			(string) ( $assoc_args['from'] ?? '' ),
			(string) ( $assoc_args['to'] ?? '' )
		);
		if ( ! $parsed['ok'] ) {
			\WP_CLI::error( $parsed['message'] );
			return;
		}
		$range = $parsed['range'];

		switch ( $source ) {
			case 'bookings':
				$result   = $this->reconcile_bookings( $range );
				$provider = new Booking_Analytics_Provider();
				break;
			case 'memberships':
				$result   = $this->reconcile_memberships( $range );
				$provider = new Membership_Analytics_Provider();
				break;
			case 'woo':
				$result   = $this->reconcile_woo( $range );
				$provider = new Woo_Analytics_Provider();
				break;
			default:
				\WP_CLI::error( 'Unknown source. Use one of: bookings, memberships, woo.' );
				return;
		}

		if ( $verbose && ! isset( $result['unavailable'] ) ) {
			// Uncached, like the provider summary above — a stale transient
			// must never mask a per-day drift.
			$this->render_daily_series( $range, $provider->daily_revenue( $range ) );
		}

		$this->render( $source, $range, $result );
	}

	/**
	 * --verbose output: one line per day with recognised net cents, then the
	 * sum — the exact figures the endpoint `series` blocks report, so
	 * `sum(series) == provider net` can be eyeballed (or grepped) directly.
	 *
	 * @param array{available:bool, days:array<string, int>} $daily
	 */
	private function render_daily_series( Range $range, array $daily ): void {
		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( 'Daily net revenue series (%s .. %s):', $range->from, $range->to ) );

		if ( empty( $daily['available'] ) ) {
			\WP_CLI::warning( 'Provider unavailable — no daily series.' );
			return;
		}

		$days = $daily['days'];
		$sum  = 0;
		$rows = \WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base::fill_daily_series(
			$range,
			array_map(
				static fn( $cents ) => array( 'revenueCents' => (int) $cents ),
				$days
			),
			array( 'revenueCents' => 0 )
		);
		foreach ( $rows as $row ) {
			\WP_CLI::line( sprintf( '  %s  %12d cents', $row['date'], $row['revenueCents'] ) );
			$sum += (int) $row['revenueCents'];
		}
		\WP_CLI::line( sprintf( '  %-10s  %12d cents', 'SUM', $sum ) );
	}

	// ---- bookings ---------------------------------------------------------

	private function reconcile_bookings( Range $range ): array {
		global $wpdb;

		$provider = new Booking_Analytics_Provider();
		if ( ! $provider->is_available() ) {
			return array( 'unavailable' => 'g2ab_bookings / g2ab_payments tables not found (booking engine inactive?)' );
		}

		$payments = $wpdb->prefix . 'g2ab_payments';
		$from     = $range->from . ' 00:00:00';
		$to       = $range->to . ' 23:59:59';

		// Same grouped aggregate the provider runs, recomputed here.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
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
		$rows   = is_array( $rows ) ? $rows : array();
		$ledger = Booking_Analytics_Provider::aggregate_payment_rows( $rows );

		$reasons = array();
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			$class  = Booking_Analytics_Provider::classify_payment_status( $status );
			if ( 'revenue' !== $class ) {
				$reasons[ $status ] = array(
					'count'  => (int) ( $row['c'] ?? 0 ),
					'reason' => 'excluded' === $class ? 'known non-revenue status' : 'UNKNOWN status (reconciliation warning)',
				);
			}
		}

		$summary = $provider->summary( $range );

		return array(
			'source_label'   => "{$payments} (payment ledger, recognition date = COALESCE(processed_at, created_at))",
			'source_rows'    => array_sum( array_map( static fn( $r ) => (int) ( $r['c'] ?? 0 ), $rows ) ),
			'included'       => $ledger['includedCount'],
			'excluded'       => $ledger['excludedCount'] + $ledger['unknownCount'],
			'reasons'        => $reasons,
			'source_gross'   => $ledger['grossCents'],
			'source_refunds' => $ledger['refundCents'],
			'source_net'     => $ledger['netCents'],
			'provider_gross'   => (int) ( $summary['revenue']['grossCents'] ?? 0 ),
			'provider_refunds' => (int) ( $summary['revenue']['refundCents'] ?? 0 ),
			'provider_net'     => (int) ( $summary['revenue']['netCents'] ?? 0 ),
			'notes'          => sprintf(
				'Revenue statuses: %s. Provider reconciliation warnings in range: %d.',
				implode( ', ', Booking_Analytics_Provider::REVENUE_STATUSES ),
				(int) ( $summary['reconciliation']['warnings'] ?? 0 )
			),
		);
	}

	// ---- memberships ------------------------------------------------------

	private function reconcile_memberships( Range $range ): array {
		global $wpdb;

		$provider = new Membership_Analytics_Provider();
		if ( ! $provider->is_available() ) {
			return array( 'unavailable' => 'memberistic_memberships table not found (Memberistic inactive?)' );
		}

		$payments = $wpdb->prefix . 'memberistic_payments';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$has_payments = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payments ) );
		if ( ! $has_payments ) {
			return array( 'unavailable' => 'memberistic_payments table not found — no revenue ledger to reconcile.' );
		}

		$from = $range->from . ' 00:00:00';
		$to   = $range->to . ' 23:59:59';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS c, COALESCE(SUM(amount), 0) AS amount_sum
				 FROM {$payments}
				 WHERE COALESCE(paid_at, created_at) BETWEEN %s AND %s
				 GROUP BY status",
				$from,
				$to
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : array();

		$included = 0;
		$excluded = 0;
		$gross    = 0;
		$refunds  = 0;
		$reasons  = array();
		foreach ( $rows as $row ) {
			$status = strtolower( (string) ( $row['status'] ?? '' ) );
			$count  = (int) ( $row['c'] ?? 0 );
			$cents  = Money::to_cents_from_string( (string) ( $row['amount_sum'] ?? '0' ) );
			if ( Membership_Analytics_Provider::REVENUE_PAYMENT_STATUS === $status ) {
				$included += $count;
				$gross    += $cents;
			} else {
				$excluded          += $count;
				$reason             = 'refunded' === $status
					? 'refund — Memberistic flips row status instead of tracking partials'
					: 'non-revenue status';
				$reasons[ $status ] = array(
					'count'  => $count,
					'reason' => $reason,
				);
				if ( 'refunded' === $status ) {
					$refunds += $cents;
				}
			}
		}

		$summary = $provider->summary( $range );

		return array(
			'source_label'   => "{$payments} (recognition: status=completed on COALESCE(paid_at, created_at))",
			'source_rows'    => array_sum( array_map( static fn( $r ) => (int) ( $r['c'] ?? 0 ), $rows ) ),
			'included'       => $included,
			'excluded'       => $excluded,
			'reasons'        => $reasons,
			'source_gross'   => $gross,
			'source_refunds' => $refunds,
			'source_net'     => $gross - $refunds,
			'provider_gross'   => (int) ( $summary['revenueCents'] ?? 0 ),
			'provider_refunds' => 0,
			'provider_net'     => (int) ( $summary['revenueCents'] ?? 0 ),
			'compare'        => 'gross', // Provider reports completed-gross only; refunds shown informationally.
			'notes'          => 'Provider revenueCents counts completed payments only; refunded rows shown as excluded.',
		);
	}

	// ---- woo ----------------------------------------------------------------

	private function reconcile_woo( Range $range ): array {
		$provider = new Woo_Analytics_Provider();
		if ( ! $provider->is_available() ) {
			return array( 'unavailable' => 'WooCommerce not active — nothing to reconcile.' );
		}

		// Independent recompute via wc_get_orders (ids of every status in
		// range for the excluded tally, objects for the included math).
		$included_ids = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => Woo_Analytics_Provider::INCLUDED_STATUSES,
				'date_created' => $range->from . '...' . $range->to,
				'return'       => 'ids',
			)
		);
		$all_ids      = wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => 'any',
				'date_created' => $range->from . '...' . $range->to,
				'return'       => 'ids',
			)
		);
		$included_ids = is_array( $included_ids ) ? $included_ids : array();
		$all_ids      = is_array( $all_ids ) ? $all_ids : array();

		$gross   = 0;
		$refunds = 0;
		foreach ( $included_ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order ) {
				continue;
			}
			$gross   += Money::to_cents_from_string( (string) $order->get_total() );
			$refunds += Money::to_cents_from_string( (string) $order->get_total_refunded() );
		}

		$summary = $provider->summary( $range );

		return array(
			'source_label'   => 'WooCommerce orders via wc_get_orders() (statuses: completed, processing)',
			'source_rows'    => count( $all_ids ),
			'included'       => count( $included_ids ),
			'excluded'       => count( $all_ids ) - count( $included_ids ),
			'reasons'        => array(
				'other-statuses' => array(
					'count'  => count( $all_ids ) - count( $included_ids ),
					'reason' => 'status not in (completed, processing): pending/on-hold/cancelled/refunded/failed/draft',
				),
			),
			'source_gross'   => $gross,
			'source_refunds' => $refunds,
			'source_net'     => $gross - $refunds,
			'provider_gross'   => (int) ( $summary['revenue']['grossCents'] ?? 0 ),
			'provider_refunds' => (int) ( $summary['revenue']['refundCents'] ?? 0 ),
			'provider_net'     => (int) ( $summary['revenue']['netCents'] ?? 0 ),
			'notes'          => ( $summary['truncated'] ?? false )
				? 'WARNING: provider hit its order cap and truncated — expect a difference.'
				: '',
		);
	}

	// ---- output -------------------------------------------------------------

	private function render( string $source, Range $range, array $result ): void {
		if ( isset( $result['unavailable'] ) ) {
			\WP_CLI::warning( $result['unavailable'] );
			\WP_CLI::error( 'FAIL — source unavailable.' );
			return;
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( 'Reconciliation: %s  (%s .. %s)', $source, $range->from, $range->to ) );
		\WP_CLI::line( str_repeat( '-', 72 ) );
		\WP_CLI::line( 'Source:            ' . $result['source_label'] );
		\WP_CLI::line( 'Source rows:       ' . $result['source_rows'] );
		\WP_CLI::line( 'Included:          ' . $result['included'] );
		\WP_CLI::line( 'Excluded:          ' . $result['excluded'] );
		if ( ! empty( $result['reasons'] ) ) {
			\WP_CLI::line( 'Exclusion reasons:' );
			foreach ( $result['reasons'] as $status => $info ) {
				\WP_CLI::line( sprintf( '  %-18s %5d  %s', $status, $info['count'], $info['reason'] ) );
			}
		}
		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( '%-22s %14s %14s', '', 'source', 'provider' ) );
		\WP_CLI::line( sprintf( '%-22s %14s %14s', 'gross (cents)', $result['source_gross'], $result['provider_gross'] ) );
		\WP_CLI::line( sprintf( '%-22s %14s %14s', 'refunds (cents)', $result['source_refunds'], $result['provider_refunds'] ) );
		\WP_CLI::line( sprintf( '%-22s %14s %14s', 'net (cents)', $result['source_net'], $result['provider_net'] ) );

		$compare = $result['compare'] ?? 'net';
		$diff    = 'gross' === $compare
			? $result['provider_gross'] - $result['source_gross']
			: $result['provider_net'] - $result['source_net'];

		\WP_CLI::line( sprintf( 'Difference (%s):   %d cents', $compare, $diff ) );
		if ( ! empty( $result['notes'] ) ) {
			\WP_CLI::line( 'Notes: ' . $result['notes'] );
		}
		\WP_CLI::line( '' );

		if ( 0 === $diff ) {
			\WP_CLI::success( 'PASS — zero unexplained difference.' );
		} else {
			\WP_CLI::error( sprintf( 'FAIL — %d cents unexplained difference.', $diff ) );
		}
	}
}
