<?php
/**
 * Verifyistic_Security — verification hardening helpers.
 *
 * Adds three independent layers on top of the existing nonce check:
 *   1. Per-IP rate limiting (transient counter) to blunt brute-force /
 *      automated age-guessing.
 *   2. A signed, time-stamped form token so submissions that arrive
 *      impossibly fast (bots) or are replayed long after render are rejected
 *      — no database row required.
 *   3. A honeypot field check (handled at the caller; this class exposes the
 *      timing + rate-limit primitives).
 *
 * @package Verifyistic
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Verifyistic_Security {

	const RL_WINDOW   = 900; // 15 minutes
	const RL_MAX      = 15;  // max failed attempts per IP per window
	const MIN_SECONDS = 0.8; // a human can't fill the popup faster than this
	const MAX_SECONDS = 7200; // token valid for 2 hours

	/* ------------------------------------------------------------------ */
	/* Form token (anti-bot timing + replay)                              */
	/* ------------------------------------------------------------------ */

	public static function issue_form_token() {
		$t   = time();
		$jti = wp_generate_password( 12, false, false );
		return $t . '.' . $jti . '.' . hash_hmac( 'sha256', $t . '|' . $jti, self::salt() );
	}

	/**
	 * Validate the token: signature must match and elapsed time must sit in a
	 * human-plausible window. Replay protection (jti burn) is handled
	 * separately by burn_form_token(), called only AFTER full verification
	 * has succeeded — so a user who corrects a DOB typo can resubmit.
	 *
	 * @return string|false  The jti on success (empty string for legacy
	 *                       2-part tokens), or false on failure.
	 */
	public static function verify_form_token( $token ) {
		$token = (string) $token;
		$parts = explode( '.', $token );
		// Back-compat: also accept the 2-part legacy form for one release.
		if ( 3 === count( $parts ) ) {
			list( $t, $jti, $sig ) = $parts;
			$expected = hash_hmac( 'sha256', $t . '|' . $jti, self::salt() );
		} elseif ( 2 === count( $parts ) ) {
			list( $t, $sig ) = $parts;
			$jti = '';
			$expected = hash_hmac( 'sha256', (string) $t, self::salt() );
		} else {
			return false;
		}
		if ( ! ctype_digit( (string) $t ) ) return false;
		if ( ! hash_equals( $expected, (string) $sig ) ) return false;
		$elapsed = time() - (int) $t;
		if ( $elapsed < self::MIN_SECONDS || $elapsed > self::MAX_SECONDS ) return false;
		// Replay check: refuse if this jti was already burned by a previous
		// SUCCESSFUL verification. Tokens that failed downstream checks (bad
		// DOB, under age, etc.) are NOT burned, so retries work.
		if ( $jti ) {
			$burn_key = 'vfy_jti_' . md5( $jti );
			if ( get_transient( $burn_key ) ) return false;
		}
		return $jti;
	}

	/**
	 * Mark a token's jti as consumed. Call this only AFTER the user has
	 * passed every verification step — never before, or a single typo will
	 * permanently lock the user out of retrying.
	 */
	public static function burn_form_token( $jti ) {
		if ( ! $jti ) return;
		set_transient( 'vfy_jti_' . md5( $jti ), 1, self::MAX_SECONDS );
	}

	/**
	 * Distinguish a genuinely automated (bot) submission from a benign
	 * cache artifact, used only when verify_form_token() has already failed.
	 *
	 * A correctly-signed token that arrives faster than a human could
	 * plausibly complete the form (< MIN_SECONDS) is the one signature that
	 * unambiguously identifies a bot, so it stays a hard block. Every other
	 * failure mode verify_form_token() rejects — an expired token (page cached
	 * for >2h), an already-burned jti (token shared across visitors by a
	 * full-page cache), a missing token, or a bad/forged signature — is NOT
	 * treated as bot-fast here, so the caller can fail open and let the nonce,
	 * honeypot, rate limit, and DOB age check decide. This prevents the false
	 * "please complete the form" wall that locked of-age customers out on
	 * cached sites.
	 *
	 * @return bool True only when the token is correctly signed AND submitted
	 *              impossibly fast for a human.
	 */
	public static function is_token_bot_fast( $token ) {
		$parts = explode( '.', (string) $token );
		if ( 3 === count( $parts ) ) {
			list( $t, $jti, $sig ) = $parts;
			$expected = hash_hmac( 'sha256', $t . '|' . $jti, self::salt() );
		} elseif ( 2 === count( $parts ) ) {
			list( $t, $sig ) = $parts;
			$expected = hash_hmac( 'sha256', (string) $t, self::salt() );
		} else {
			return false;
		}
		if ( ! ctype_digit( (string) $t ) ) return false;
		// A forged / mismatched signature is not "our" token: let the other
		// hard gates handle it rather than hard-blocking on timing.
		if ( ! hash_equals( $expected, (string) $sig ) ) return false;
		return ( time() - (int) $t ) < self::MIN_SECONDS;
	}

	private static function salt() {
		// wp_salt() is unique per-site and never exposed to the client.
		return wp_salt( 'auth' ) . '|verifyistic-form';
	}

	/* ------------------------------------------------------------------ */
	/* Rate limiting                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * @return bool True when the IP is still under the limit.
	 */
	public static function check_rate_limit( $ip ) {
		$key   = self::rl_key( $ip );
		$count = (int) get_transient( $key );
		return $count < self::RL_MAX;
	}

	/**
	 * Record a failed/declined attempt against an IP.
	 *
	 * Wrapped in a MySQL advisory lock so two concurrent failed attempts
	 * (a scripted age-guessing burst) can't both read the same stale count
	 * and both write back the same incremented value — a lost update here
	 * would silently make the effective cap weaker than `RL_MAX` under
	 * concurrency, on an endpoint that exists specifically to blunt that
	 * kind of automated abuse.
	 */
	public static function register_attempt( $ip ) {
		self::atomic_increment( self::rl_key( $ip ), self::RL_WINDOW );
	}

	private static function rl_key( $ip ) {
		return 'vfy_rl_' . md5( (string) $ip );
	}

	/**
	 * Atomically read-increment-write a transient counter.
	 */
	public static function atomic_increment( $key, $ttl ) {
		if ( ! self::acquire_lock( $key ) ) {
			return;
		}
		try {
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, $ttl );
		} finally {
			self::release_lock( $key );
		}
	}

	/**
	 * Atomic "still under cap? then charge one" — for counters where the
	 * check and the increment must happen as a single step (no work runs
	 * between them, unlike the failed-attempt counter above).
	 *
	 * @return bool True when the counter was under `$max` and got charged.
	 */
	public static function atomic_check_and_increment( $key, $max, $ttl ) {
		if ( ! self::acquire_lock( $key ) ) {
			error_log( '[Verifyistic] Rate-limit lock timeout; allowing request to avoid false shared-user 429.' );
			return true;
		}
		try {
			$count = (int) get_transient( $key );
			if ( $count >= $max ) {
				return false;
			}
			set_transient( $key, $count + 1, $ttl );
			return true;
		} finally {
			self::release_lock( $key );
		}
	}

	/**
	 * MySQL advisory lock. Duck-types `$wpdb` (rather than requiring the
	 * real `wpdb` class) so it silently no-ops in the plugin's lightweight
	 * PHP-only test bootstrap; every real WordPress request has `$wpdb`.
	 */
	private static function acquire_lock( $key, $timeout = 3 ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return true;
		}
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', 'vfy_lock_' . $key, max( 0, $timeout ) ) );
	}

	private static function release_lock( $key ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'vfy_lock_' . $key ) );
	}

	/* ------------------------------------------------------------------ */
	/* Client IP                                                          */
	/* ------------------------------------------------------------------ */

	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}
		// SECURITY: trust forwarded headers only when REMOTE_ADDR is itself a
		// known proxy. Configured via constant VERIFYISTIC_TRUSTED_PROXIES
		// (comma-separated IPs / CIDRs) or option verifyistic_trusted_proxies.
		if ( ! self::is_trusted_proxy( $remote ) ) {
			return $remote;
		}
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) continue;
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			if ( strpos( $ip, ',' ) !== false ) $ip = trim( explode( ',', $ip )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
		}
		return $remote;
	}

	private static function is_trusted_proxy( $remote ) {
		$list = self::default_trusted_proxy_ranges();
		if ( defined( 'VERIFYISTIC_TRUSTED_PROXIES' ) && is_string( VERIFYISTIC_TRUSTED_PROXIES ) ) {
			$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', VERIFYISTIC_TRUSTED_PROXIES ) ) ) );
		}
		$opt = (string) get_option( 'verifyistic_trusted_proxies', '' );
		if ( '' !== $opt ) {
			$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', $opt ) ) ) );
		}
		$list = apply_filters( 'verifyistic_trusted_proxies', array_values( array_unique( $list ) ) );
		if ( empty( $list ) ) return false;
		foreach ( $list as $range ) {
			if ( self::ip_in_cidr( $remote, $range ) ) return true;
		}
		return false;
	}

	private static function default_trusted_proxy_ranges() {
		return array(
			'173.245.48.0/20',
			'103.21.244.0/22',
			'103.22.200.0/22',
			'103.31.4.0/22',
			'141.101.64.0/18',
			'108.162.192.0/18',
			'190.93.240.0/20',
			'188.114.96.0/20',
			'197.234.240.0/22',
			'198.41.128.0/17',
			'162.158.0.0/15',
			'104.16.0.0/13',
			'104.24.0.0/14',
			'172.64.0.0/13',
			'131.0.72.0/22',
			'2400:cb00::/32',
			'2606:4700::/32',
			'2803:f800::/32',
			'2405:b500::/32',
			'2405:8100::/32',
			'2a06:98c0::/29',
			'2c0f:f248::/32',
		);
	}

	private static function ip_in_cidr( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) return $ip === $range;
		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$ip_bin     = @inet_pton( $ip );
		$subnet_bin = @inet_pton( $subnet );
		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) return false;
		$bits      = max( 0, min( strlen( $ip_bin ) * 8, (int) $bits ) );
		$full      = intdiv( $bits, 8 );
		$remainder = $bits % 8;
		if ( $full && substr( $ip_bin, 0, $full ) !== substr( $subnet_bin, 0, $full ) ) return false;
		if ( 0 === $remainder ) return true;
		$mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;
		return ( ord( $ip_bin[ $full ] ) & $mask ) === ( ord( $subnet_bin[ $full ] ) & $mask );
	}
}
