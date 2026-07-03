<?php
/**
 * IP-keyed sliding-window rate limiter.
 *
 * Implemented on WordPress transients — good enough for a public endpoint
 * that should tolerate ~20 requests/hour/IP without falling over. Real
 * high-volume rate limiting belongs at the CDN / WAF layer.
 *
 * The window is per-IP + per-scope so multiple public endpoints can share
 * the class without stepping on each other.
 *
 * @package G2ABA
 */

namespace WordPressistic\G2ABA\Ops;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rate_Limiter {
	private string $scope;
	private int    $limit;
	private int    $window_seconds;

	public function __construct( string $scope, int $limit = 20, int $window_seconds = 3600 ) {
		$this->scope          = $scope;
		$this->limit          = max( 1, $limit );
		$this->window_seconds = max( 60, $window_seconds );
	}

	/**
	 * Attempt to charge one request against the bucket.
	 *
	 * @return array{allowed:bool,retryAfter:int,remaining:int}
	 */
	public function hit( string $ip ): array {
		$ip = self::normalise_ip( $ip );
		if ( '' === $ip ) {
			// Unknown IP — refuse to increment the shared bucket. Allow the
			// request rather than punish it with a 429 for something the
			// caller has no way to fix.
			return array( 'allowed' => true, 'retryAfter' => 0, 'remaining' => $this->limit );
		}

		$key    = self::transient_key( $this->scope, $ip );
		$bucket = get_transient( $key );
		if ( ! is_array( $bucket ) ) {
			$bucket = array( 'count' => 0, 'reset_at' => time() + $this->window_seconds );
		}

		if ( ( $bucket['reset_at'] ?? 0 ) <= time() ) {
			$bucket = array( 'count' => 0, 'reset_at' => time() + $this->window_seconds );
		}

		$bucket['count']++;
		set_transient( $key, $bucket, max( 60, (int) $bucket['reset_at'] - time() ) );

		if ( $bucket['count'] > $this->limit ) {
			return array(
				'allowed'    => false,
				'retryAfter' => max( 1, (int) $bucket['reset_at'] - time() ),
				'remaining'  => 0,
			);
		}
		return array(
			'allowed'    => true,
			'retryAfter' => 0,
			'remaining'  => max( 0, $this->limit - (int) $bucket['count'] ),
		);
	}

	/**
	 * @internal Exposed for tests. Prefers CF-Connecting-IP → X-Forwarded-For
	 * → REMOTE_ADDR. Refuses obviously private IPs from headers to avoid
	 * spoofing via untrusted proxies.
	 */
	public static function client_ip( ?array $server = null ): string {
		$server = $server ?? $_SERVER; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- read-only sniff.
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $k ) {
			if ( empty( $server[ $k ] ) ) {
				continue;
			}
			$raw   = (string) $server[ $k ];
			$first = trim( strtok( $raw, ',' ) );
			if ( '' !== $first && filter_var( $first, FILTER_VALIDATE_IP ) ) {
				return $first;
			}
		}
		return '';
	}

	public static function normalise_ip( string $ip ): string {
		$ip = trim( $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	private static function transient_key( string $scope, string $ip ): string {
		return 'g2aba_rl_' . substr( hash( 'sha256', $scope . '|' . $ip ), 0, 40 );
	}
}
