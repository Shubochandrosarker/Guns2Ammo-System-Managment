<?php
/**
 * Visitor fingerprinting.
 *
 * A raw IP address is never written to any table in this plugin. Everything
 * that needs to recognise "the same visitor" uses a salted sha256 whose salt
 * rotates daily, so the hashes stop being linkable after 24 hours and cannot
 * be reversed into an address.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Fingerprint {

	public const SALT_OPTION = 'g2ar_daily_salt';

	/**
	 * Today's rotating salt, created on first use.
	 *
	 * @return string
	 */
	public static function daily_salt() {
		$today  = gmdate( 'Y-m-d' );
		$stored = get_option( self::SALT_OPTION, array() );

		if ( is_array( $stored ) && ( $stored['date'] ?? '' ) === $today && ! empty( $stored['salt'] ) ) {
			return (string) $stored['salt'];
		}

		$salt = wp_generate_password( 64, true, true );

		update_option(
			self::SALT_OPTION,
			array(
				'date' => $today,
				'salt' => $salt,
			),
			false
		);

		return $salt;
	}

	/**
	 * Client IP, read defensively from proxy headers.
	 *
	 * Only used as hash input — the value never leaves this class.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

		foreach ( $candidates as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$parts = explode( ',', $raw );
			$ip    = trim( $parts[0] );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '';
	}

	/**
	 * Raw user agent string, used only as hash input.
	 *
	 * @return string
	 */
	private static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
	}

	/**
	 * sha256( ip | user agent | daily salt ).
	 *
	 * @return string 64-char hex digest.
	 */
	public static function visitor_hash() {
		return hash( 'sha256', self::client_ip() . '|' . self::user_agent() . '|' . self::daily_salt() );
	}

	/**
	 * sha256 of the IP alone, for audit rows.
	 *
	 * @return string 64-char hex digest.
	 */
	public static function ip_hash() {
		return hash( 'sha256', self::client_ip() . '|' . self::daily_salt() );
	}

	/**
	 * Coarse device bucket for reporting. No fingerprinting value on its own.
	 *
	 * @return string mobile|tablet|desktop
	 */
	public static function device() {
		$ua = strtolower( self::user_agent() );

		if ( false !== strpos( $ua, 'ipad' ) || false !== strpos( $ua, 'tablet' ) ) {
			return 'tablet';
		}

		if ( false !== strpos( $ua, 'mobi' ) || false !== strpos( $ua, 'android' ) ) {
			return 'mobile';
		}

		return 'desktop';
	}
}
