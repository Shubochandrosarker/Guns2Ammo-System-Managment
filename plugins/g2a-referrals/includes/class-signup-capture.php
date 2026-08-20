<?php
/**
 * Capture a referral code typed at signup or checkout.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Signup_Capture {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'user_register', array( self::class, 'on_user_register' ), 10, 1 );
		add_action( 'wp_login', array( self::class, 'on_login' ), 20, 2 );
	}

	/**
	 * Stamp the attributed code onto a brand-new account, so a friend who
	 * clicked a link weeks ago is still credited when they finally pay.
	 *
	 * @param int $user_id New user id.
	 * @return void
	 */
	public static function on_user_register( $user_id ) {
		$code = self::submitted_code();

		if ( '' === $code ) {
			$code = Attribution::current_code();
		}

		if ( '' === $code ) {
			return;
		}

		// Never let a member's own code attach to their own account.
		$referrer = Database\Referrers_Repository::get_by_code( $code );

		if ( $referrer && (int) $referrer['user_id'] === (int) $user_id ) {
			return;
		}

		update_user_meta( (int) $user_id, 'g2ar_signup_code', $code );
		Fraud::remember( (int) $user_id, 'g2ar_visitor_hashes', Fingerprint::visitor_hash() );
	}

	/**
	 * Remember the device a member signs in from.
	 *
	 * @param string   $user_login Login name.
	 * @param \WP_User $user       User.
	 * @return void
	 */
	public static function on_login( $user_login, $user ) {
		unset( $user_login );

		if ( $user instanceof \WP_User ) {
			Fraud::remember( (int) $user->ID, 'g2ar_visitor_hashes', Fingerprint::visitor_hash() );
		}
	}

	/**
	 * A referral code posted with a signup or checkout form.
	 *
	 * @return string Canonical code, or ''.
	 */
	private static function submitted_code() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only; the value is validated against the codes table before it is trusted, and the nonce belongs to the host form.
		$raw = isset( $_REQUEST['g2ar_code'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['g2ar_code'] ) ) : '';

		if ( '' === $raw || ! Fraud::allow_code_lookup( 'signup_code' ) ) {
			return '';
		}

		return Codes::normalize( $raw );
	}
}
