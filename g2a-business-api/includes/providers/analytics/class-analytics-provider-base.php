<?php
/**
 * Shared plumbing for the Phase-C analytics providers.
 *
 * Rules enforced here (so no concrete provider can forget them):
 *  - every raw $wpdb read is preceded by a table-existence check;
 *  - summary() wraps the concrete build in try/catch and degrades to an
 *    explicit unavailable payload — a broken upstream schema can 500 its
 *    own plugin, never this API;
 *  - cached_summary() memoises through a transient for 120s (filter
 *    `g2aba_analytics_cache_ttl`), keyed by provider + range + the
 *    caller's capability tier so read/admin results can never bleed
 *    into each other.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Providers\Analytics;

use WordPressistic\G2ABA\Cache;
use WordPressistic\G2ABA\Capabilities;
use WordPressistic\G2ABA\Range;
use WordPressistic\G2ABA\Response_Envelope;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Analytics_Provider_Base implements Analytics_Provider_Interface {
	public const CACHE_TTL = 120;

	/**
	 * Freshness computed by the most recent summary() call, if any.
	 *
	 * @var array{status:string, lastUpdatedAt:?string}|null
	 */
	protected ?array $last_freshness = null;

	abstract public function is_available(): bool;

	abstract public function source_slug(): string;

	/**
	 * Concrete aggregation. Only called when is_available() is true; may
	 * throw — summary() owns the catch.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function build_summary( Range $range ): array;

	/**
	 * Keys every payload carries beyond `available` + range; used to build
	 * the honest unavailable skeleton.
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function empty_summary(): array;

	public function freshness(): array {
		if ( is_array( $this->last_freshness ) ) {
			return $this->last_freshness;
		}
		return $this->availability_freshness( $this->safe_is_available() );
	}

	/**
	 * Aggregate for the range. Never throws, never fatals: schema missing,
	 * plugin inactive, or an upstream exception all resolve to the same
	 * explicit `available: false` payload.
	 *
	 * @return array<string, mixed>
	 */
	public function summary( Range $range ): array {
		try {
			if ( ! $this->is_available() ) {
				return $this->unavailable_summary( $range );
			}

			$out              = $this->build_summary( $range );
			$out['available'] = true;
			$out['range']     = $range->to_array();

			$this->last_freshness = array(
				'status'        => Response_Envelope::FRESH,
				'lastUpdatedAt' => $out['lastUpdatedAt'] ?? Response_Envelope::now_iso(),
			);

			return $out;
		} catch ( \Throwable $e ) {
			// Deliberately swallowed: no SQL/paths/traces may reach a response.
			return $this->unavailable_summary( $range );
		}
	}

	/**
	 * summary() behind a short transient. Key = provider + range +
	 * capability tier; TTL 120s via the `g2aba_analytics_cache_ttl` filter.
	 *
	 * @return array<string, mixed>
	 */
	public function cached_summary( Range $range ): array {
		$ttl = (int) apply_filters( 'g2aba_analytics_cache_ttl', self::CACHE_TTL, $this->provider_key() );
		$key = self::cache_key( $this->provider_key(), $range, self::capability_tier() );

		$summary = Cache::remember(
			$key,
			$ttl,
			function () use ( $range ) {
				return $this->summary( $range );
			}
		);

		// A cache hit skips summary(), so rebuild the freshness block from
		// the stored payload to keep freshness() truthful.
		$available            = (bool) ( $summary['available'] ?? false );
		$this->last_freshness = $available
			? array(
				'status'        => Response_Envelope::FRESH,
				'lastUpdatedAt' => $summary['lastUpdatedAt'] ?? null,
			)
			: $this->availability_freshness( false );

		return is_array( $summary ) ? $summary : $this->unavailable_summary( $range );
	}

	// ---- Phase-D detail payloads (additive; overview summaries unchanged) ----

	/**
	 * Concrete detail aggregation for the /analytics/<module> endpoints.
	 * Defaults to the overview summary; providers with richer detail
	 * (byType, series, trends, ...) override this. Only called when
	 * is_available() is true; may throw — detail() owns the catch.
	 *
	 * @return array<string, mixed>
	 */
	protected function build_detail( Range $range ): array {
		return $this->build_summary( $range );
	}

	/**
	 * Keys the detail payload carries beyond `available` + range; used to
	 * build the honest unavailable skeleton for the detail endpoints.
	 *
	 * @return array<string, mixed>
	 */
	protected function empty_detail(): array {
		return $this->empty_summary();
	}

	/**
	 * Detail payload for the range. Same never-throws contract as summary().
	 *
	 * @return array<string, mixed>
	 */
	public function detail( Range $range ): array {
		try {
			if ( ! $this->is_available() ) {
				return $this->unavailable_detail( $range );
			}

			$out              = $this->build_detail( $range );
			$out['available'] = true;
			$out['range']     = $range->to_array();

			$this->last_freshness = array(
				'status'        => Response_Envelope::FRESH,
				'lastUpdatedAt' => $out['lastUpdatedAt'] ?? Response_Envelope::now_iso(),
			);

			return $out;
		} catch ( \Throwable $e ) {
			// Deliberately swallowed: no SQL/paths/traces may reach a response.
			return $this->unavailable_detail( $range );
		}
	}

	/**
	 * detail() behind the same short transient as cached_summary(), under a
	 * DISTINCT key (`<provider>-detail`) so detail payloads never collide
	 * with overview summaries.
	 *
	 * @return array<string, mixed>
	 */
	public function cached_detail( Range $range ): array {
		return $this->remember_payload(
			$this->provider_key() . '-detail',
			$range,
			function () use ( $range ) {
				return $this->detail( $range );
			},
			function () use ( $range ) {
				return $this->unavailable_detail( $range );
			}
		);
	}

	/**
	 * Honest degraded detail payload — stable shape, zeroed metrics.
	 *
	 * @return array<string, mixed>
	 */
	public function unavailable_detail( Range $range ): array {
		$this->last_freshness = $this->availability_freshness( false );

		return array_merge(
			$this->empty_detail(),
			array(
				'available'     => false,
				'range'         => $range->to_array(),
				'lastUpdatedAt' => null,
			)
		);
	}

	/**
	 * Per-day recognised net revenue for the range, keyed 'YYYY-MM-DD' =>
	 * int cents (days with no revenue may be absent — callers zero-fill).
	 * Providers that feed the dashboard revenue series override this.
	 * Only called when is_available() is true; may throw.
	 *
	 * @return array<string, int>
	 */
	protected function build_daily_revenue( Range $range ): array {
		return array();
	}

	/**
	 * Daily revenue behind the never-throws availability contract.
	 *
	 * @return array{available:bool, days:array<string, int>}
	 */
	public function daily_revenue( Range $range ): array {
		try {
			if ( ! $this->is_available() ) {
				return array(
					'available' => false,
					'days'      => array(),
				);
			}
			return array(
				'available' => true,
				'days'      => $this->build_daily_revenue( $range ),
			);
		} catch ( \Throwable $e ) {
			return array(
				'available' => false,
				'days'      => array(),
			);
		}
	}

	/**
	 * daily_revenue() behind a transient under a DISTINCT key
	 * (`<provider>-series`).
	 *
	 * @return array{available:bool, days:array<string, int>}
	 */
	public function cached_daily_revenue( Range $range ): array {
		return $this->remember_payload(
			$this->provider_key() . '-series',
			$range,
			function () use ( $range ) {
				return $this->daily_revenue( $range );
			},
			static function () {
				return array(
					'available' => false,
					'days'      => array(),
				);
			},
			false
		);
	}

	/**
	 * Shared transient plumbing for the Phase-D payloads: same TTL/filter and
	 * capability-tier keying as cached_summary(), parameterised by cache
	 * identity so every payload kind gets its own key.
	 *
	 * @param callable():array $producer          Builds the payload on a miss.
	 * @param callable():array $degraded          Fallback when the cache holds garbage.
	 * @param bool             $track_freshness   Rebuild last_freshness from cache hits.
	 * @return array<string, mixed>
	 */
	protected function remember_payload( string $cache_identity, Range $range, callable $producer, callable $degraded, bool $track_freshness = true ): array {
		$ttl = (int) apply_filters( 'g2aba_analytics_cache_ttl', self::CACHE_TTL, $this->provider_key() );
		$key = self::cache_key( $cache_identity, $range, self::capability_tier() );

		$payload = Cache::remember( $key, $ttl, $producer );

		if ( $track_freshness && is_array( $payload ) ) {
			$available            = (bool) ( $payload['available'] ?? false );
			$this->last_freshness = $available
				? array(
					'status'        => Response_Envelope::FRESH,
					'lastUpdatedAt' => $payload['lastUpdatedAt'] ?? null,
				)
				: $this->availability_freshness( false );
		}

		return is_array( $payload ) ? $payload : $degraded();
	}

	/**
	 * Percentage delta between periods; null when the previous value is zero
	 * (an all-zero previous period must yield null deltas, never INF/100%).
	 *
	 * @internal Exposed (pure) for tests.
	 */
	public static function pct_delta( int $current, int $previous ): ?float {
		if ( 0 === $previous ) {
			return null;
		}
		return round( ( ( $current - $previous ) / $previous ) * 100, 2 );
	}

	/**
	 * Canonical trends block: the previous period's raw figures + deltas.
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @param int $current_count    Current-period count.
	 * @param int $current_revenue  Current-period revenue cents.
	 * @param array{count:int, revenueCents:int, netCents:int} $previous
	 * @return array{previous:array, deltas:array{countPct:?float, revenuePct:?float}}
	 */
	public static function build_trends( int $current_count, int $current_revenue, array $previous ): array {
		return array(
			'previous' => array(
				'count'        => (int) ( $previous['count'] ?? 0 ),
				'revenueCents' => (int) ( $previous['revenueCents'] ?? 0 ),
				'netCents'     => (int) ( $previous['netCents'] ?? 0 ),
			),
			'deltas'   => array(
				'countPct'   => self::pct_delta( $current_count, (int) ( $previous['count'] ?? 0 ) ),
				'revenuePct' => self::pct_delta( $current_revenue, (int) ( $previous['revenueCents'] ?? 0 ) ),
			),
		);
	}

	/**
	 * Expand sparse per-day rows into a dense series covering every day of
	 * the range (missing days filled with the zero template), oldest first.
	 *
	 * @internal Exposed (pure) for tests.
	 *
	 * @param array<string, array<string, int>> $by_date 'YYYY-MM-DD' => row.
	 * @param array<string, int>                $zero    Template for missing days.
	 * @return array<int, array<string, mixed>> Each row: ['date' => ...] + values.
	 */
	public static function fill_daily_series( Range $range, array $by_date, array $zero ): array {
		$out    = array();
		$cursor = strtotime( $range->from . ' 00:00:00 UTC' );
		$end    = strtotime( $range->to . ' 00:00:00 UTC' );
		if ( false === $cursor || false === $end ) {
			return $out;
		}
		// Hard bound: never emit more than the API's max window.
		$guard = 0;
		while ( $cursor <= $end && $guard < 400 ) {
			$date  = gmdate( 'Y-m-d', $cursor );
			$row   = isset( $by_date[ $date ] ) && is_array( $by_date[ $date ] ) ? $by_date[ $date ] : $zero;
			$out[] = array_merge( array( 'date' => $date ), $row );

			$cursor = strtotime( '+1 day', $cursor );
			$guard++;
		}
		return $out;
	}

	/**
	 * Deterministic cache key. Public+static so tests can assert isolation.
	 *
	 * Keyed by PROVIDER (not source plugin): two providers can read the
	 * same plugin (memberships + waivers both read Memberistic) and must
	 * never share a cache entry.
	 */
	public static function cache_key( string $provider_key, Range $range, string $tier ): string {
		return 'analytics_' . $provider_key . '_' . $range->from . '_' . $range->to . '_' . $tier;
	}

	/**
	 * Stable per-provider cache identity. Defaults to the class basename in
	 * kebab-case (booking-analytics-provider, waiver-summary-provider, ...).
	 */
	public function provider_key(): string {
		$parts = explode( '\\', static::class );
		return strtolower( str_replace( '_', '-', (string) end( $parts ) ) );
	}

	/**
	 * "admin" when the caller holds the admin capability, else "read".
	 */
	public static function capability_tier(): string {
		return Capabilities::current_user_can_admin() ? 'admin' : 'read';
	}

	/**
	 * Honest degraded payload — stable shape, zeroed metrics, explicit flag.
	 *
	 * @return array<string, mixed>
	 */
	public function unavailable_summary( Range $range ): array {
		$this->last_freshness = $this->availability_freshness( false );

		return array_merge(
			$this->empty_summary(),
			array(
				'available'     => false,
				'range'         => $range->to_array(),
				'lastUpdatedAt' => null,
			)
		);
	}

	protected function availability_freshness( bool $available ): array {
		return array(
			'status'        => $available ? Response_Envelope::FRESH : Response_Envelope::UNAVAILABLE,
			'lastUpdatedAt' => null,
		);
	}

	/**
	 * is_available() that can never throw (used by freshness()).
	 */
	protected function safe_is_available(): bool {
		try {
			return $this->is_available();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * SHOW TABLES check — run before ANY raw query against a sibling
	 * plugin's schema.
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- schema-existence probe only.
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Source rows store site-local datetimes (`current_time('mysql')`);
	 * convert to ISO-8601 UTC for the wire. Null-safe.
	 */
	protected static function iso_from_db( $mysql_datetime ): ?string {
		$raw = trim( (string) $mysql_datetime );
		if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return null;
		}
		try {
			$tz  = new \DateTimeZone( Response_Envelope::timezone() );
			$dt  = new \DateTimeImmutable( $raw, $tz );
			$utc = $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
			return $utc->format( 'Y-m-d\TH:i:s\Z' );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Site-local datetime bounds for a Range (source tables store
	 * `current_time('mysql')` values, so the WHERE clause must match).
	 *
	 * @return array{0:string, 1:string} [from, to]
	 */
	protected static function range_bounds( Range $range ): array {
		return array( $range->from . ' 00:00:00', $range->to . ' 23:59:59' );
	}
}
