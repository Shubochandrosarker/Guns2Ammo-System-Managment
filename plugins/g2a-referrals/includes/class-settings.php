<?php
/**
 * Plugin settings.
 *
 * Every reward type, value, window and piece of customer-facing copy lives
 * here and is editable in admin. Nothing about the reward model is
 * hard-coded: "1 month" and "1 pass" are defaults, not constants.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPTION = 'g2ar_settings';

	/**
	 * Cached settings for the current request.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Shipping defaults.
	 *
	 * Two of these carry money and were decided explicitly:
	 *
	 *  - guest_pass_expiry_days = 90. Guest Passes DO expire. An unexpiring
	 *    pass is an unbounded liability sitting on the books forever; 90 days
	 *    caps it and gives the reward urgency. Set to 0 for never-expires.
	 *  - referral_cap_per_month = 5. Bounds both lane capacity (every pass is
	 *    a free lane hour someone has to staff) and abuse. Set to 0 to remove
	 *    the cap.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// ---- Reward model -------------------------------------------------
			'friend_reward_type'        => 'membership_days',
			'friend_reward_periods'     => 1,
			'referrer_reward_type'      => 'guest_pass',
			'referrer_reward_amount'    => 1,

			// ---- Money-bearing policy ----------------------------------------
			'guest_pass_expiry_days'    => 90,
			'referral_cap_per_month'    => 5,
			'hold_window_days'          => 14,

			// ---- Liability accounting ----------------------------------------
			// Used only for the admin "outstanding liability" figure. A Guest
			// Pass is one free lane hour; lane time lists at $20/hour.
			'guest_pass_value'          => 20.00,
			'membership_month_value'    => 29.99,

			// ---- Attribution --------------------------------------------------
			'cookie_days'               => 90,
			'attribution_model'         => 'first_touch',

			// ---- Stacking -----------------------------------------------------
			// Best single offer wins, never combine. The floor is the lowest a
			// membership may ever resolve to after any one offer is applied.
			'stacking_mode'             => 'best_single',
			'membership_price_floor'    => 0.00,

			// ---- Fraud --------------------------------------------------------
			'max_conversions_per_hash'  => 3,
			'max_conversions_per_day'   => 10,
			'rate_limit_attempts'       => 20,
			'rate_limit_window_minutes' => 15,

			// ---- Plan eligibility ---------------------------------------------
			// Plan 5 "Guest Pass" is not a paying member (see the
			// g2a-guest-pass-not-a-member mu-plugin). Guest Pass holders may
			// still refer, but they earn a free month rather than a pass:
			// they have no membership to bring a guest onto.
			'non_member_plan_ids'       => array( 5 ),
			'non_member_plans_may_refer' => 'yes',
			'non_member_plan_reward'    => 'membership_days',

			// ---- Redemption ----------------------------------------------------
			'redemption_booking_type_ids' => array( 1 ),
			'redemption_enabled'        => 'yes',

			// ---- Product page copy ---------------------------------------------
			// The "lane fee toward the purchase" line is a COMMERCIAL PROMISE.
			// It ships OFF until Nicholas says yes; until then the safe line
			// runs instead. Flip try_at_range_fee_credit to 'yes' in
			// Referrals → Settings once he has confirmed.
			'try_at_range_enabled'      => 'yes',
			'try_at_range_fee_credit'   => 'no',
			'try_at_range_heading'      => 'Shoot it before you buy it',
			'try_at_range_body_safe'    => 'Rent this exact model on our indoor range. See how it shoots before you commit.',
			'try_at_range_body_credit'  => 'Rent this exact model on our indoor range. If you love it, we\'ll put your lane fee toward the purchase.',
			'try_at_range_cta'          => 'Book a Lane',
			'try_at_range_meta'         => 'From $20/hour · Mesa, AZ',

			// The 10% first-order offer must be confirmed live and redeemable
			// before it is advertised — the stacking rule needs something real
			// to enforce against. Until then the non-member banner runs the
			// membership pitch without a discount claim.
			'first_order_offer_enabled' => 'no',
			'first_order_offer_percent' => 10,
			'banner_enabled'            => 'yes',
			'banner_dismiss_days'       => 7,
			'banner_guest_lead'         => 'New here?',
			'banner_guest_body_offer'   => 'Join any membership and take %1$s%% off your first order',
			'banner_guest_body_plain'   => 'Join any membership and shoot free on our indoor range',
			'banner_guest_cta'          => 'Become a Member',
			'banner_member_lead'        => 'Refer a friend.',
			'banner_member_body'        => 'They get a free month, you get a Guest Pass.',
			'banner_member_cta'         => 'Get my link',

			// ---- Dashboard copy -------------------------------------------------
			'terms_text'                => 'Rewards are granted when your friend\'s first membership payment confirms, and may be reversed if that payment is refunded or cancelled within the hold window. Guest Passes are valid for one free lane hour for one guest. Offers never combine — the best single offer applies.',
		);
	}

	/**
	 * All settings, defaults merged under stored values.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		self::$cache = array_merge( self::defaults(), $stored );

		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		if ( array_key_exists( $key, $all ) ) {
			return $all[ $key ];
		}

		return $default;
	}

	/**
	 * Read an integer setting.
	 *
	 * @param string $key Setting key.
	 * @return int
	 */
	public static function get_int( $key ) {
		return (int) self::get( $key, 0 );
	}

	/**
	 * Read a float setting.
	 *
	 * @param string $key Setting key.
	 * @return float
	 */
	public static function get_float( $key ) {
		return (float) self::get( $key, 0 );
	}

	/**
	 * True when a yes/no setting is on.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key, 'no' );
	}

	/**
	 * Plan ids that do not count as paying members.
	 *
	 * Seeded from the shared g2a_non_benefit_plan_ids option when present so
	 * this plugin and the booking-engine mu-plugin cannot drift apart.
	 *
	 * @return int[]
	 */
	public static function non_member_plan_ids() {
		$shared = get_option( 'g2a_non_benefit_plan_ids', null );
		$ids    = is_array( $shared ) && $shared ? $shared : self::get( 'non_member_plan_ids', array( 5 ) );

		return array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
	}

	/**
	 * Persist settings, keeping unknown keys out of the option.
	 *
	 * @param array $values Raw values to merge over what is stored.
	 * @return array The saved settings.
	 */
	public static function update( array $values ) {
		$current  = self::all();
		$defaults = self::defaults();
		$clean    = array();

		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$clean[ $key ] = self::sanitize( $key, $value, $defaults[ $key ] );
		}

		$merged = array_merge( $current, $clean );
		update_option( self::OPTION, $merged, false );
		self::$cache = $merged;

		return $merged;
	}

	/**
	 * Type-aware sanitisation keyed off the default's type.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Incoming value.
	 * @param mixed  $default Default for this key, used to infer the type.
	 * @return mixed
	 */
	private static function sanitize( $key, $value, $default ) {
		if ( is_array( $default ) ) {
			return array_values( array_unique( array_map( 'absint', (array) $value ) ) );
		}

		if ( is_int( $default ) ) {
			return max( 0, absint( $value ) );
		}

		if ( is_float( $default ) ) {
			return max( 0, round( (float) $value, 2 ) );
		}

		$value = is_scalar( $value ) ? (string) $value : '';

		// yes/no flags.
		if ( in_array( $default, array( 'yes', 'no' ), true ) ) {
			return in_array( $value, array( 'yes', '1', 'on', 'true' ), true ) ? 'yes' : 'no';
		}

		// Copy fields keep punctuation but no markup.
		return sanitize_textarea_field( $value );
	}

	/**
	 * Drop the request cache. Used by tests and after a direct option write.
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}
}
