<?php
/**
 * GET /dashboard/overview — the Phase-C aggregated dashboard endpoint.
 *
 * Canonical envelope (Response_Envelope), integer USD cents, ISO-8601 UTC
 * datetimes, honest per-module unavailable states, derived alerts.
 * Read-capability gated like every other read route; GET, so the central
 * CSRF gate (Session_Auth::requires_csrf) exempts it by method.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\REST;

use WordPressistic\G2ABA\Providers\Analytics\Booking_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Membership_Analytics_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Waiver_Summary_Provider;
use WordPressistic\G2ABA\Providers\Analytics\Woo_Analytics_Provider;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Response_Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dashboard_Controller extends REST_Controller {
	/** Inclusive upper bound on the requested window. */
	public const MAX_RANGE_DAYS = 366;

	/** Expiring-waiver alert trips when expiring30d exceeds this floor AND 20% of current. */
	public const WAIVER_SPIKE_FLOOR = 10;

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/dashboard/overview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'overview' ),
				'permission_callback' => array( $this, 'read_permissions_check' ),
				'args'                => array(
					'from' => array( 'type' => 'string' ),
					'to'   => array( 'type' => 'string' ),
				),
			)
		);
	}

	public function overview( \WP_REST_Request $req ) {
		$parsed = self::parse_range(
			(string) ( $req->get_param( 'from' ) ?? '' ),
			(string) ( $req->get_param( 'to' ) ?? '' )
		);
		if ( ! $parsed['ok'] ) {
			return Response_Envelope::error_response( $parsed['code'], $parsed['message'], 400 );
		}
		$range = $parsed['range'];

		$bookings_provider    = new Booking_Analytics_Provider();
		$memberships_provider = new Membership_Analytics_Provider();
		$woo_provider         = new Woo_Analytics_Provider();
		$waivers_provider     = new Waiver_Summary_Provider();

		$modules = array(
			'bookings'    => array( $bookings_provider, $bookings_provider->cached_summary( $range ) ),
			'memberships' => array( $memberships_provider, $memberships_provider->cached_summary( $range ) ),
			'woocommerce' => array( $woo_provider, $woo_provider->cached_summary( $range ) ),
			'waivers'     => array( $waivers_provider, $waivers_provider->cached_summary( $range ) ),
		);

		$bookings    = $modules['bookings'][1];
		$memberships = $modules['memberships'][1];
		$woo         = $modules['woocommerce'][1];
		$waivers     = $modules['waivers'][1];

		$revenue = array(
			'bookingsCents'    => (int) ( $bookings['revenue']['netCents'] ?? 0 ),
			'membershipsCents' => (int) ( $memberships['revenueCents'] ?? 0 ),
			'wooCents'         => (int) ( $woo['revenue']['netCents'] ?? 0 ),
		);
		$revenue['totalCents'] = $revenue['bookingsCents'] + $revenue['membershipsCents'] + $revenue['wooCents'];

		// Daily revenue series (0.4.0) — one GROUP-BY-date pass per provider,
		// cached under distinct `-series` keys; unavailable providers simply
		// contribute zeros (their module block already says so).
		$series = self::build_series(
			$range,
			$bookings_provider->cached_daily_revenue( $range )['days'],
			$memberships_provider->cached_daily_revenue( $range )['days'],
			$woo_provider->cached_daily_revenue( $range )['days']
		);

		$stripe_cancel_failures = self::stripe_cancel_failures_count();

		$modules_block = array();
		$sources       = array();
		$freshness_set = array();
		foreach ( $modules as $name => list( $provider, $summary ) ) {
			$available = (bool) ( $summary['available'] ?? false );
			$freshness = $provider->freshness();

			// Canonical plugin slugs only — the UI maps slug -> display name.
			// Store-level detail (e.g. which waiver store contributed) lives
			// inside the module payload itself (data.waivers.stores).
			$modules_block[ $name ] = array(
				'available' => $available,
				'source'    => $available ? array( $provider->source_slug() ) : array(),
				'freshness' => $freshness,
			);
			$freshness_set[]        = $freshness;
			if ( $available ) {
				$sources[] = $provider->source_slug();
			}
		}

		$data = array(
			'range'       => $range->to_array(),
			'revenue'     => $revenue,
			'series'      => $series,
			'bookings'    => $bookings,
			'memberships' => $memberships,
			'woocommerce' => $woo,
			'waivers'     => $waivers,
			'alerts'      => self::derive_alerts( $modules_block, $bookings, $waivers, $stripe_cancel_failures ),
			'modules'     => $modules_block,
		);

		return $this->ok(
			Response_Envelope::success(
				$data,
				array_values( array_unique( $sources ) ),
				Response_Envelope::combine_freshness( $freshness_set )
			)
		);
	}

	/**
	 * Merge the per-provider daily revenue maps into one dense, zero-filled
	 * series covering every day of the range. Pure given its inputs, so the
	 * "series sums == module revenue totals" invariant is unit-testable.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<string, int> $bookings_days    'YYYY-MM-DD' => cents.
	 * @param array<string, int> $memberships_days 'YYYY-MM-DD' => cents.
	 * @param array<string, int> $woo_days         'YYYY-MM-DD' => cents.
	 * @return array<int, array{date:string, bookingsCents:int, membershipsCents:int, wooCents:int, totalCents:int}>
	 */
	public static function build_series( Range $range, array $bookings_days, array $memberships_days, array $woo_days ): array {
		$by_date = array();
		foreach (
			array(
				'bookingsCents'    => $bookings_days,
				'membershipsCents' => $memberships_days,
				'wooCents'         => $woo_days,
			) as $key => $days
		) {
			foreach ( $days as $date => $cents ) {
				$date = (string) $date;
				if ( ! isset( $by_date[ $date ] ) ) {
					$by_date[ $date ] = array(
						'bookingsCents'    => 0,
						'membershipsCents' => 0,
						'wooCents'         => 0,
					);
				}
				$by_date[ $date ][ $key ] = (int) $cents;
			}
		}

		$series = \WordPressistic\G2ABA\Providers\Analytics\Analytics_Provider_Base::fill_daily_series(
			$range,
			$by_date,
			array(
				'bookingsCents'    => 0,
				'membershipsCents' => 0,
				'wooCents'         => 0,
			)
		);

		foreach ( $series as &$row ) {
			$row['totalCents'] = $row['bookingsCents'] + $row['membershipsCents'] + $row['wooCents'];
		}
		unset( $row );

		return $series;
	}

	/**
	 * Validate + bound the requested window.
	 *
	 * Defaults: last 30 days ending today. Rejects malformed dates,
	 * from > to, and windows longer than MAX_RANGE_DAYS.
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @return array{ok:bool, range?:Range, code?:string, message?:string}
	 */
	public static function parse_range( string $from, string $to ): array {
		$from = trim( $from );
		$to   = trim( $to );

		foreach ( array( 'from' => $from, 'to' => $to ) as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m )
				|| ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				return array(
					'ok'      => false,
					'code'    => 'invalid_date_format',
					'message' => sprintf( 'Parameter "%s" must be a valid YYYY-MM-DD date.', $label ),
				);
			}
		}

		if ( '' === $to ) {
			$to = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		}
		if ( '' === $from ) {
			$from_ts = strtotime( $to . ' -29 days' );
			$from    = $from_ts ? gmdate( 'Y-m-d', $from_ts ) : $to;
		}

		if ( strcmp( $from, $to ) > 0 ) {
			return array(
				'ok'      => false,
				'code'    => 'invalid_date_range',
				'message' => '"from" must be on or before "to".',
			);
		}

		$range = new Range( $from, $to );
		if ( $range->days() > self::MAX_RANGE_DAYS ) {
			return array(
				'ok'      => false,
				'code'    => 'date_range_too_long',
				'message' => sprintf( 'Date range must be %d days or fewer.', self::MAX_RANGE_DAYS ),
			);
		}

		return array(
			'ok'    => true,
			'range' => $range,
		);
	}

	/**
	 * Derive the alerts array. Pure given its inputs.
	 *
	 * @internal Exposed for tests.
	 *
	 * @param array<string, array{available:bool}> $modules_block Per-module availability.
	 * @param array<string, mixed>                 $bookings      Booking summary.
	 * @param array<string, mixed>                 $waivers       Waiver summary.
	 * @param int                                  $stripe_cancel_failures Count only.
	 * @return array<int, array{code:string, severity:string, message:string}>
	 */
	public static function derive_alerts( array $modules_block, array $bookings, array $waivers, int $stripe_cancel_failures ): array {
		$alerts = array();

		foreach ( $modules_block as $name => $module ) {
			if ( empty( $module['available'] ) ) {
				$alerts[] = array(
					'code'     => $name . '_unavailable',
					'severity' => 'warning',
					'message'  => sprintf( 'The %s data source is unavailable; its figures are omitted from this overview.', $name ),
				);
			}
		}

		$expiring = (int) ( $waivers['counts']['expiring30d'] ?? 0 );
		$current  = (int) ( $waivers['counts']['current'] ?? 0 );
		if ( $expiring >= self::WAIVER_SPIKE_FLOOR && $expiring >= (int) ceil( $current * 0.2 ) ) {
			$alerts[] = array(
				'code'     => 'waivers_expiring_spike',
				'severity' => 'warning',
				'message'  => sprintf( '%d waivers expire within 30 days.', $expiring ),
			);
		}

		if ( $stripe_cancel_failures > 0 ) {
			$alerts[] = array(
				'code'     => 'stripe_cancel_failures',
				'severity' => 'critical',
				'message'  => sprintf( '%d membership cancellation(s) failed to sync to Stripe and need manual review.', $stripe_cancel_failures ),
			);
		}

		$recon = (int) ( $bookings['reconciliation']['warnings'] ?? 0 );
		if ( $recon > 0 ) {
			$alerts[] = array(
				'code'     => 'booking_reconciliation_warnings',
				'severity' => 'warning',
				'message'  => sprintf( '%d booking(s) have missing or unrecognised payment records in this range.', $recon ),
			);
		}

		return $alerts;
	}

	/**
	 * Count-only read of Memberistic's failed-Stripe-cancel notice option.
	 */
	private static function stripe_cancel_failures_count(): int {
		$failures = get_option( 'memberistic_stripe_cancel_failures', array() );
		return is_array( $failures ) ? count( $failures ) : 0;
	}
}
