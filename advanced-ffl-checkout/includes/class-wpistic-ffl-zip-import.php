<?php
/**
 * ZIP code coordinate service.
 *
 * Instead of downloading and importing the full GeoNames dataset (which
 * requires WP Cron and external HTTP access), this class looks up ZIP
 * coordinates on demand from the free zippopotam.us API and caches
 * each result in the zip_coords table.
 *
 * First time a ZIP is searched:  1 API call (~100ms), result cached.
 * Every subsequent search:        instant lookup from local DB, zero API calls.
 *
 * The free API has no rate limits for reasonable usage and requires no key.
 * Endpoint: https://api.zippopotam.us/us/{zip}
 *
 * @package WpisticFFL
 */

namespace WpisticFFL;

defined( 'ABSPATH' ) || exit;

class Zip_Import {

	const API_URL = 'https://api.zippopotam.us/us/';

	/**
	 * Get lat/lng for a ZIP code.
	 * Checks local DB first; calls API if not cached.
	 *
	 * @param  string $zip  5-digit US ZIP code.
	 * @return array{lat:float,lng:float,city:string,state:string}|null
	 */
	public static function get_coords( string $zip ): ?array {
		global $wpdb;

		$zip = preg_replace( '/\D/', '', $zip );
		$zip = str_pad( substr( $zip, 0, 5 ), 5, '0', STR_PAD_LEFT );

		if ( strlen( $zip ) !== 5 ) {
			return null;
		}

		// Try local cache first
		$cached = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT lat, lng, city, state FROM ' . DB::table( 'zip_coords' ) . ' WHERE zip = %s',
				$zip
			)
		);

		if ( $cached ) {
			return [
				'lat'   => (float) $cached->lat,
				'lng'   => (float) $cached->lng,
				'city'  => $cached->city,
				'state' => $cached->state,
			];
		}

		// v1.1.2 — short-circuit if recent failure cached (avoids retry storms)
		$failure_key = 'wpistic_ffl_zip_fail_' . $zip;
		if ( get_transient( $failure_key ) ) {
			return null;
		}

		// Not cached — call the free zippopotam.us API (3-second timeout to avoid
		// blocking checkout when API is rate-limited/down)
		$response = wp_remote_get( self::API_URL . $zip, [
			'timeout' => 3,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache failure for 1 hour — prevents hammering the API on every search
			set_transient( $failure_key, 1, HOUR_IN_SECONDS );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['places'][0] ) ) {
			return null;
		}

		$place = $body['places'][0];
		$lat   = (float) $place['latitude'];
		$lng   = (float) $place['longitude'];
		$city  = sanitize_text_field( $place['place name'] ?? '' );
		$state = sanitize_text_field( $place['state abbreviation'] ?? '' );

		// Cache for all future lookups
		$wpdb->replace( DB::table( 'zip_coords' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			compact( 'zip', 'lat', 'lng', 'city', 'state' ),
			[ '%s', '%f', '%f', '%s', '%s' ]
		);

		return compact( 'lat', 'lng', 'city', 'state' );
	}

	/**
	 * Batch-populate ZIP coordinates for a list of ZIP codes.
	 * Skips ZIPs already in the DB.
	 * Called after CSV dealer import to pre-warm the cache.
	 *
	 * @param  string[] $zips         List of ZIP codes to look up.
	 * @param  int      $max_per_call Maximum ZIPs to process in one call (AJAX chunking).
	 * @return array{done:int,remaining:int}
	 */
	public static function batch_populate( array $zips, int $max_per_call = 20 ): array {
		global $wpdb;

		// Filter out ZIPs already cached
		$table       = DB::table( 'zip_coords' );
		$done        = 0;
		$to_process  = [];

		foreach ( $zips as $zip ) {
			$zip = preg_replace( '/\D/', '', $zip );
			if ( strlen( $zip ) < 5 ) {
				continue;
			}
			$zip    = str_pad( substr( $zip, 0, 5 ), 5, '0', STR_PAD_LEFT );
			$exists = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT 1 FROM {$table} WHERE zip = %s LIMIT 1",
				$zip
			) );
			if ( ! $exists ) {
				$to_process[] = $zip;
			}
		}

		$remaining  = count( $to_process );
		$chunk      = array_slice( $to_process, 0, $max_per_call );

		foreach ( $chunk as $zip ) {
			self::get_coords( $zip ); // This handles caching internally
			++$done;
		}

		return [
			'done'      => $done,
			'remaining' => max( 0, $remaining - $done ),
		];
	}

	/**
	 * Get status (for admin display).
	 */
	public static function get_status(): array {
		global $wpdb;
		$db_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DB::table( 'zip_coords' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return [
			'status'     => $db_count > 0 ? 'ready' : 'idle',
			'processed'  => $db_count,
			'total'      => 43000,
			'db_count'   => $db_count,
			'percentage' => min( 100, round( ( $db_count / 43000 ) * 100 ) ),
			'completed'  => $db_count > 0 ? 'On-demand (cached)' : '',
		];
	}

	/**
	 * Legacy method — kept for hook compatibility. Immediately returns since
	 * we no longer use cron-based importing.
	 */
	public static function process_chunk(): void {
		wp_clear_scheduled_hook( 'wpistic_ffl_process_zip_import' );
	}

	/**
	 * Legacy method stub.
	 */
	public static function start(): array {
		return [ 'success' => true, 'message' => 'On-demand ZIP lookup enabled — no bulk import needed.' ];
	}
}
