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
		$t = time();
		return $t . '.' . hash_hmac( 'sha256', (string) $t, self::salt() );
	}

	/**
	 * Validate the token: signature must match and elapsed time must sit in a
	 * human-plausible window.
	 */
	public static function verify_form_token( $token ) {
		$token = (string) $token;
		if ( false === strpos( $token, '.' ) ) {
			return false;
		}
		list( $t, $sig ) = explode( '.', $token, 2 );
		if ( ! ctype_digit( (string) $t ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', (string) $t, self::salt() );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return false;
		}
		$elapsed = time() - (int) $t;
		return ( $elapsed >= self::MIN_SECONDS && $elapsed <= self::MAX_SECONDS );
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
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
