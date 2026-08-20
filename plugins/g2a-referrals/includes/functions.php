<?php
/**
 * Global helper functions.
 *
 * The public read API other plugins and the theme should use. Nothing
 * outside this plugin should touch the g2ar_* tables directly.
 *
 * @package G2AR
 */

use WordPressistic\G2AReferrals\Codes;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'g2ar_get_referral_code' ) ) {
	/**
	 * This user's referral code, creating one if they are eligible and do not
	 * have it yet.
	 *
	 * @param int $user_id WP user id. Defaults to the current user.
	 * @return string Empty when the user has no code.
	 */
	function g2ar_get_referral_code( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();

		if ( $user_id <= 0 ) {
			return '';
		}

		$referrer = Referrers_Repository::ensure_for_user( $user_id );

		return $referrer ? (string) $referrer['code'] : '';
	}
}

if ( ! function_exists( 'g2ar_get_share_url' ) ) {
	/**
	 * Public share link for a code.
	 *
	 * @param string $code Referral code.
	 * @return string
	 */
	function g2ar_get_share_url( $code ) {
		return Codes::share_url( $code );
	}
}

if ( ! function_exists( 'g2ar_get_balance' ) ) {
	/**
	 * A user's live balance of a reward type.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $type    guest_pass|membership_days.
	 * @return float
	 */
	function g2ar_get_balance( $user_id, $type = 'guest_pass' ) {
		return Rewards_Repository::balance( (int) $user_id, (string) $type );
	}
}

if ( ! function_exists( 'g2ar_user_is_member' ) ) {
	/**
	 * Does this user hold a live, benefit-bearing membership?
	 *
	 * Defers to Memberistic, which is the single source of truth for
	 * membership state across this whole system, and applies the same plan
	 * exclusions the booking engine uses.
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	function g2ar_user_is_member( $user_id = 0 ) {
		$user_id = $user_id ?: get_current_user_id();

		if ( $user_id <= 0 || ! function_exists( 'memberistic_get_membership_status' ) ) {
			return false;
		}

		$status = memberistic_get_membership_status( $user_id );

		if ( ! is_array( $status ) || empty( $status['is_live'] ) ) {
			return false;
		}

		return ! in_array( (int) ( $status['plan_id'] ?? 0 ), Settings::non_member_plan_ids(), true );
	}
}

if ( ! function_exists( 'g2ar_setting' ) ) {
	/**
	 * Read a plugin setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function g2ar_setting( $key, $default = null ) {
		return Settings::get( $key, $default );
	}
}
