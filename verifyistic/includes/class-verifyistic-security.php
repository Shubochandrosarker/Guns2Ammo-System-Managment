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
	 */
	public static function register_attempt( $ip ) {
		$key   = self::rl_key( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, self::RL_WINDOW );
	}

	private static function rl_key( $ip ) {
		return 'vfy_rl_' . md5( (string) $ip );
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
		$list = array();
		if ( defined( 'VERIFYISTIC_TRUSTED_PROXIES' ) && is_string( VERIFYISTIC_TRUSTED_PROXIES ) ) {
			$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', VERIFYISTIC_TRUSTED_PROXIES ) ) ) );
		}
		$opt = (string) get_option( 'verifyistic_trusted_proxies', '' );
		if ( '' !== $opt ) {
			$list = array_merge( $list, array_filter( array_map( 'trim', explode( ',', $opt ) ) ) );
		}
		if ( empty( $list ) ) return false;
		foreach ( $list as $range ) {
			if ( self::ip_in_cidr( $remote, $range ) ) return true;
		}
		return false;
	}

	private static function ip_in_cidr( $ip, $range ) {
		if ( false === strpos( $range, '/' ) ) return $ip === $range;
		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$bits = (int) $bits;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) && filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			if ( $bits < 0 || $bits > 32 ) return false;
			$mask = $bits === 0 ? 0 : ( ~0 << ( 32 - $bits ) );
			return ( ip2long( $ip ) & $mask ) === ( ip2long( $subnet ) & $mask );
		}
		return false;
	}
}
