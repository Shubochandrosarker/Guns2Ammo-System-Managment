<?php
/**
 * Canonical response envelope for the Phase-C aggregation endpoints.
 *
 * Success:
 * {
 *   "success": true,
 *   "data":    <payload>,
 *   "meta": {
 *     "requestId":   "<uuid4>",
 *     "generatedAt": "<ISO-8601 UTC>",
 *     "timezone":    "<wp_timezone_string()>",
 *     "currency":    "USD",
 *     "source":      ["plugin-slug", ...],
 *     "freshness":   {"status": "fresh|stale|unavailable", "lastUpdatedAt": "<ISO UTC|null>"}
 *   }
 * }
 *
 * Error:
 * {
 *   "success": false,
 *   "error": {"code": "<stable_snake_case>", "message": "<safe>", "requestId": "<uuid4>"}
 * }
 *
 * All money in `data` is integer USD cents; all datetimes are ISO-8601 UTC.
 * Error messages must be operator-safe: never SQL, paths, or traces.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Response_Envelope {
	public const CURRENCY = 'USD';

	public const FRESH       = 'fresh';
	public const STALE       = 'stale';
	public const UNAVAILABLE = 'unavailable';

	/**
	 * UUIDv4 request id. Uses core's generator when loaded so log
	 * correlation matches WP conventions; falls back to a local RFC-4122
	 * implementation in stripped-down contexts (tests, CLI).
	 */
	public static function request_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $bytes ), 4 ) );
	}

	/**
	 * Current instant as ISO-8601 UTC.
	 */
	public static function now_iso(): string {
		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Site timezone string; safe fallback when WP isn't fully loaded.
	 */
	public static function timezone(): string {
		return function_exists( 'wp_timezone_string' ) ? (string) wp_timezone_string() : 'UTC';
	}

	/**
	 * Build the success envelope (as an array — controllers wrap it in a
	 * WP_REST_Response themselves so they keep their cache headers).
	 *
	 * @param mixed                                            $data      Payload.
	 * @param array<int, string>                               $sources   Contributing plugin slugs.
	 * @param array{status:string, lastUpdatedAt:?string}|null $freshness Freshness block; defaults to fresh/now.
	 */
	public static function success( $data, array $sources = array(), ?array $freshness = null ): array {
		if ( null === $freshness ) {
			$freshness = array(
				'status'        => self::FRESH,
				'lastUpdatedAt' => self::now_iso(),
			);
		}

		return array(
			'success' => true,
			'data'    => $data,
			'meta'    => array(
				'requestId'   => self::request_id(),
				'generatedAt' => self::now_iso(),
				'timezone'    => self::timezone(),
				'currency'    => self::CURRENCY,
				'source'      => array_values( $sources ),
				'freshness'   => array(
					'status'        => (string) ( $freshness['status'] ?? self::FRESH ),
					'lastUpdatedAt' => $freshness['lastUpdatedAt'] ?? null,
				),
			),
		);
	}

	/**
	 * Build the error envelope body. `$message` must already be a safe,
	 * human-readable sentence — callers never pass exception text through.
	 */
	public static function error( string $code, string $message ): array {
		return array(
			'success' => false,
			'error'   => array(
				'code'      => $code,
				'message'   => $message,
				'requestId' => self::request_id(),
			),
		);
	}

	/**
	 * Error envelope as a ready WP_REST_Response with the right HTTP status.
	 */
	public static function error_response( string $code, string $message, int $status = 400 ): \WP_REST_Response {
		return new \WP_REST_Response( self::error( $code, $message ), $status );
	}

	/**
	 * Combine per-module freshness blocks into one roll-up:
	 *  - every module unavailable  -> unavailable
	 *  - every module fresh        -> fresh
	 *  - anything else (mixed)     -> stale
	 * lastUpdatedAt is the max of the contributing modules' timestamps.
	 *
	 * @param array<int, array{status:string, lastUpdatedAt:?string}> $blocks
	 */
	public static function combine_freshness( array $blocks ): array {
		if ( empty( $blocks ) ) {
			return array(
				'status'        => self::UNAVAILABLE,
				'lastUpdatedAt' => null,
			);
		}

		$statuses = array();
		$latest   = null;
		foreach ( $blocks as $block ) {
			$statuses[] = (string) ( $block['status'] ?? self::UNAVAILABLE );
			$ts         = $block['lastUpdatedAt'] ?? null;
			if ( is_string( $ts ) && ( null === $latest || strcmp( $ts, $latest ) > 0 ) ) {
				$latest = $ts;
			}
		}

		$unique = array_unique( $statuses );
		if ( array( self::UNAVAILABLE ) === $unique ) {
			$status = self::UNAVAILABLE;
		} elseif ( array( self::FRESH ) === $unique ) {
			$status = self::FRESH;
		} else {
			$status = self::STALE;
		}

		return array(
			'status'        => $status,
			'lastUpdatedAt' => $latest,
		);
	}
}
