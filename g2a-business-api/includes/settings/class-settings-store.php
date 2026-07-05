<?php
/**
 * Editable dashboard defaults.
 *
 * The frontend Settings page renders these as form controls. Sensitive
 * values (API keys, webhook secrets) do NOT live here — they live in the
 * WP admin Secrets screen. This store is strictly UX defaults so the
 * owner isn't rebuilding filters every time they open the dashboard.
 *
 * The store is careful about sanitisation because it's a public REST
 * surface accepting JSON — every field is normalised through an allow-list
 * before persisting.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Settings;

use WordPressistic\G2ABA\Cors;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings_Store {
	private const OPTION = 'g2aba_dashboard_settings';

	public const RANGES = array(
		'last-7',
		'last-30',
		'last-90',
		'month-to-date',
		'quarter-to-date',
		'year-to-date',
	);

	public const WEEKLY_DAYS = array(
		'monday',
		'tuesday',
		'wednesday',
		'thursday',
		'friday',
		'saturday',
		'sunday',
	);

	public const CURRENCIES = array( 'USD' );

	/**
	 * @return array{
	 *   defaultRange: string,
	 *   currency: string,
	 *   weeklyReportDay: string,
	 *   weeklyReportHour: int,
	 *   dailySummaryHour: int,
	 *   ownerEmail: string,
	 *   timezone: string,
	 *   allowedOrigins: array<int, string>,
	 * }
	 */
	public function all(): array {
		$raw = get_option( self::OPTION, array() );
		$raw = is_array( $raw ) ? $raw : array();
		return $this->with_defaults( $raw );
	}

	/**
	 * Merge a partial patch. Unknown keys are dropped; every known key is
	 * sanitised via the same rules the initial load uses.
	 *
	 * @return array{ok:true, settings:array} The fully-hydrated new state.
	 */
	public function update( array $patch ): array {
		$current = $this->all();
		foreach ( $patch as $k => $v ) {
			if ( array_key_exists( $k, $current ) ) {
				$current[ $k ] = $v;
			}
		}
		$sanitised = $this->sanitise( $current );
		update_option( self::OPTION, $sanitised, false );
		return array( 'ok' => true, 'settings' => $sanitised );
	}

	public static function defaults(): array {
		return array(
			'defaultRange'     => 'last-30',
			'currency'         => 'USD',
			'weeklyReportDay'  => 'monday',
			'weeklyReportHour' => 7,
			'dailySummaryHour' => 7,
			'ownerEmail'       => '',
			'timezone'         => 'America/Phoenix',
			'allowedOrigins'   => Cors::DEFAULT_ORIGINS,
		);
	}

	private function with_defaults( array $raw ): array {
		$out = self::defaults();
		foreach ( array_keys( $out ) as $k ) {
			if ( array_key_exists( $k, $raw ) ) {
				$out[ $k ] = $raw[ $k ];
			}
		}
		return $this->sanitise( $out );
	}

	public function sanitise( array $raw ): array {
		return array(
			'defaultRange'     => in_array( (string) ( $raw['defaultRange'] ?? '' ), self::RANGES, true )
				? (string) $raw['defaultRange']
				: 'last-30',
			'currency'         => in_array( (string) ( $raw['currency'] ?? '' ), self::CURRENCIES, true )
				? (string) $raw['currency']
				: 'USD',
			'weeklyReportDay'  => in_array( strtolower( (string) ( $raw['weeklyReportDay'] ?? '' ) ), self::WEEKLY_DAYS, true )
				? strtolower( (string) $raw['weeklyReportDay'] )
				: 'monday',
			'weeklyReportHour' => self::clamp_hour( $raw['weeklyReportHour'] ?? 7 ),
			'dailySummaryHour' => self::clamp_hour( $raw['dailySummaryHour'] ?? 7 ),
			'ownerEmail'       => self::sanitise_email( (string) ( $raw['ownerEmail'] ?? '' ) ),
			'timezone'         => self::sanitise_tz( (string) ( $raw['timezone'] ?? '' ) ),
			'allowedOrigins'   => self::sanitise_origins( $raw['allowedOrigins'] ?? array() ),
		);
	}

	/**
	 * Normalise the CORS allow-list. Invalid entries (paths, wildcards,
	 * non-http schemes) are dropped; an empty result falls back to the
	 * default so the owner can never lock the hosted dashboard out.
	 *
	 * @param mixed $raw Whatever the REST payload carried.
	 * @return array<int, string>
	 */
	public static function sanitise_origins( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();
		foreach ( $raw as $origin ) {
			if ( ! is_string( $origin ) ) {
				continue;
			}
			$norm = Cors::normalize_origin( $origin );
			if ( null !== $norm && ! in_array( $norm, $out, true ) ) {
				$out[] = $norm;
			}
		}
		if ( empty( $out ) ) {
			return Cors::DEFAULT_ORIGINS;
		}
		return $out;
	}

	public static function clamp_hour( $raw ): int {
		$n = (int) $raw;
		if ( $n < 0 ) {
			return 0;
		}
		if ( $n > 23 ) {
			return 23;
		}
		return $n;
	}

	public static function sanitise_email( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! filter_var( $raw, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}
		return $raw;
	}

	public static function sanitise_tz( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return 'America/Phoenix';
		}
		// Restrict to conservative subset — no arbitrary strings.
		if ( ! preg_match( '#^[A-Za-z_]+/[A-Za-z_]+$#', $raw ) ) {
			return 'America/Phoenix';
		}
		return $raw;
	}
}
