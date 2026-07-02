<?php
/**
 * Date-range parser for analytics endpoints.
 *
 * Frontend sends `from`/`to` as ISO YYYY-MM-DD, both optional. When absent we
 * default to the last 30 days ending today (site timezone).
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Range {
	public string $from;
	public string $to;

	public function __construct( string $from, string $to ) {
		$this->from = $from;
		$this->to   = $to;
	}

	public static function from_request( \WP_REST_Request $req ): self {
		$from = self::normalize( (string) $req->get_param( 'from' ) );
		$to   = self::normalize( (string) $req->get_param( 'to' ) );

		if ( '' === $to ) {
			$to = wp_date( 'Y-m-d' );
		}
		if ( '' === $from ) {
			$from_ts = strtotime( $to . ' -29 days' );
			$from    = $from_ts ? gmdate( 'Y-m-d', $from_ts ) : $to;
		}

		return new self( $from, $to );
	}

	public function to_array(): array {
		return array(
			'from' => $this->from,
			'to'   => $this->to,
		);
	}

	public function days(): int {
		$from = strtotime( $this->from . ' 00:00:00' );
		$to   = strtotime( $this->to . ' 00:00:00' );
		if ( ! $from || ! $to ) {
			return 30;
		}
		return max( 1, (int) round( ( $to - $from ) / DAY_IN_SECONDS ) + 1 );
	}

	private static function normalize( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			return '';
		}
		return $raw;
	}
}
