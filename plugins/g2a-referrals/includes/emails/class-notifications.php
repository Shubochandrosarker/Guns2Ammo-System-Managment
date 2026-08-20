<?php
/**
 * Referral emails.
 *
 * Deliberately not a mailer. Memberistic already owns transactional email
 * on this site — templates, merge tags, the HTML wrapper, the send log and
 * the kill-switch — so this registers two templates into that system and
 * asks it to send. A second mailer would mean a second set of
 * deliverability problems and a second place the kill-switch has to be
 * remembered.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals\Emails;

use WordPressistic\G2AReferrals\Codes;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notifications {

	public const TEMPLATE_REFERRER = 'referral_reward_earned';
	public const TEMPLATE_FRIEND   = 'referral_friend_welcome';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'memberistic_email_templates', array( self::class, 'add_templates' ) );
		add_filter( 'memberistic_email_template_subject', array( self::class, 'subject' ), 10, 3 );
		add_filter( 'memberistic_email_template_body', array( self::class, 'body' ), 10, 3 );
		add_filter( 'memberistic_email_merge_tags', array( self::class, 'merge_tags' ), 10, 5 );

		add_action( 'g2ar_referral_rewarded', array( self::class, 'on_rewarded' ), 10, 2 );
	}

	/**
	 * Expose the two referral templates in Memberistic's admin UI, so staff
	 * can edit and resend them alongside every other transactional email.
	 *
	 * @param array $templates Registered templates.
	 * @return array
	 */
	public static function add_templates( $templates ) {
		$templates = is_array( $templates ) ? $templates : array();

		$templates[] = array(
			'id'          => self::TEMPLATE_REFERRER,
			'label'       => __( 'Referral Reward Earned', 'g2a-referrals' ),
			'description' => __( 'Tells a member their referred friend joined and their reward is waiting.', 'g2a-referrals' ),
		);

		$templates[] = array(
			'id'          => self::TEMPLATE_FRIEND,
			'label'       => __( 'Referral Free Month', 'g2a-referrals' ),
			'description' => __( 'Confirms to a referred friend that a free month was added to their first term.', 'g2a-referrals' ),
		);

		return $templates;
	}

	/**
	 * Default subjects. Memberistic's own admin overrides still win.
	 *
	 * @param string $subject  Resolved subject.
	 * @param string $template Template id.
	 * @param array  $context  Merge tags.
	 * @return string
	 */
	public static function subject( $subject, $template, $context ) {
		unset( $context );

		if ( self::TEMPLATE_REFERRER === $template ) {
			return __( 'Your referral reward is ready at {brand_label}', 'g2a-referrals' );
		}

		if ( self::TEMPLATE_FRIEND === $template ) {
			return __( 'A free month has been added to your {brand_label} membership', 'g2a-referrals' );
		}

		return $subject;
	}

	/**
	 * Default bodies.
	 *
	 * @param string $body     Resolved body.
	 * @param string $template Template id.
	 * @param array  $context  Merge tags.
	 * @return string
	 */
	public static function body( $body, $template, $context ) {
		unset( $context );

		if ( self::TEMPLATE_REFERRER === $template ) {
			return __(
				"Hi {member_name},\n\n{referral_friend_name} joined {brand_label} through your link — thank you.\n\nYour reward: {referral_reward}\nAvailable now: {referral_balance}\n{referral_expiry_line}\nYour referral code: {referral_code}\nShare your link: {referral_link}\n\nBring a friend on your next lane booking and their hour is on us. Tick \"Use 1 Guest Pass\" when you book, or just show this code at the counter.\n\nView your rewards: {account_url}\n\n{brand_label}\n{business_phone}",
				'g2a-referrals'
			);
		}

		if ( self::TEMPLATE_FRIEND === $template ) {
			return __(
				"Hi {member_name},\n\nWelcome to {brand_label}. Because you joined through a friend's referral, we've added a free month to your first term — no action needed.\n\nPlan: {plan_name}\nNext renewal: {renewal_date}\n\nYou can refer friends too: they get a free month, you get a free lane hour for a guest.\nYour referral code: {referral_code}\nShare your link: {referral_link}\n\nManage your membership: {account_url}\n\nSee you at the range.\n\n{brand_label}\n{business_phone}",
				'g2a-referrals'
			);
		}

		return $body;
	}

	/**
	 * Add referral merge tags to Memberistic's context.
	 *
	 * @param array $context       Merge tags.
	 * @param array $membership    Membership row.
	 * @param array $person        Primary person row.
	 * @param array $extra_context Context passed by the caller.
	 * @return array
	 */
	public static function merge_tags( $context, $membership = array(), $person = array(), $extra_context = array() ) {
		unset( $person );

		$context = is_array( $context ) ? $context : array();

		$user_id = (int) ( $membership['primary_user_id'] ?? 0 );
		$code    = '';

		if ( $user_id > 0 ) {
			$referrer = Referrers_Repository::get_by_user( $user_id );
			$code     = $referrer ? (string) $referrer['code'] : '';
		}

		$balance = $user_id > 0
			? Rewards_Repository::balance( $user_id, Rewards_Repository::TYPE_GUEST_PASS )
			: 0.0;

		$expiry = $user_id > 0
			? Rewards_Repository::next_expiry( $user_id, Rewards_Repository::TYPE_GUEST_PASS )
			: null;

		$context['{referral_code}'] = $code;
		$context['{referral_link}'] = $code ? Codes::share_url( $code ) : '';

		$context['{referral_balance}'] = sprintf(
			/* translators: %s: number of Guest Passes. */
			_n( '%s Guest Pass', '%s Guest Passes', (int) round( $balance ), 'g2a-referrals' ),
			number_format_i18n( $balance )
		);

		$context['{referral_reward}'] = (string) ( $extra_context['reward_label'] ?? $context['{referral_balance}'] );

		// Only mention expiry when there is one — "expires never" is not a
		// sentence anyone wants in a reward email.
		$context['{referral_expiry_line}'] = $expiry
			? sprintf(
				/* translators: %s: expiry date. */
				__( 'Use it by: %s', 'g2a-referrals' ),
				wp_date( (string) get_option( 'date_format', 'j M Y' ), strtotime( $expiry . ' UTC' ) )
			) . "\n"
			: '';

		$context['{referral_friend_name}'] = (string) ( $extra_context['friend_name'] ?? __( 'A friend', 'g2a-referrals' ) );

		return $context;
	}

	/**
	 * Send both sides once a referral is rewarded.
	 *
	 * @param array $conversion Conversion row.
	 * @param array $referrer   Referrer row.
	 * @return void
	 */
	public static function on_rewarded( $conversion, $referrer ) {
		if ( ! class_exists( '\WordPressistic\Memberistic\Emails\Email_Service' ) ) {
			return;
		}

		$conversion = (array) $conversion;
		$referrer   = (array) $referrer;

		$friend_membership = (int) ( $conversion['friend_membership_id'] ?? 0 );
		$referrer_membership = (int) ( $referrer['membership_id'] ?? 0 );

		if ( $friend_membership > 0 ) {
			\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
				$friend_membership,
				self::TEMPLATE_FRIEND,
				array( 'source' => 'g2a-referrals' )
			);
		}

		if ( $referrer_membership > 0 ) {
			\WordPressistic\Memberistic\Emails\Email_Service::send_membership_email(
				$referrer_membership,
				self::TEMPLATE_REFERRER,
				array(
					'source'       => 'g2a-referrals',
					'friend_name'  => self::short_name( (int) ( $conversion['friend_user_id'] ?? 0 ) ),
					'reward_label' => self::reward_label( (int) ( $referrer['user_id'] ?? 0 ) ),
				)
			);
		}
	}

	/**
	 * "Sarah M." — the referrer never learns more than that about a friend,
	 * in email any more than on the dashboard.
	 *
	 * @param int $user_id Friend's WP user id.
	 * @return string
	 */
	private static function short_name( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;

		if ( ! $user ) {
			return __( 'A friend', 'g2a-referrals' );
		}

		$first = trim( (string) $user->first_name );
		$last  = trim( (string) $user->last_name );

		if ( '' === $first ) {
			$parts = preg_split( '/\s+/', trim( (string) $user->display_name ) );
			$first = (string) ( $parts[0] ?? '' );
			$last  = (string) ( $parts[1] ?? '' );
		}

		if ( '' === $first ) {
			return __( 'A friend', 'g2a-referrals' );
		}

		return $last ? $first . ' ' . strtoupper( substr( $last, 0, 1 ) ) . '.' : $first;
	}

	/**
	 * What this referrer earned, in words.
	 *
	 * @param int $user_id Referrer's WP user id.
	 * @return string
	 */
	private static function reward_label( $user_id ) {
		$type = \WordPressistic\G2AReferrals\Rewards_Service::referrer_reward_type( $user_id );

		if ( Rewards_Repository::TYPE_MEMBERSHIP_DAYS === $type ) {
			$periods = max( 1, Settings::get_int( 'friend_reward_periods' ) );

			return sprintf(
				/* translators: %d: number of free months. */
				_n( '%d free month', '%d free months', $periods, 'g2a-referrals' ),
				$periods
			);
		}

		$amount = max( 1, Settings::get_int( 'referrer_reward_amount' ) );

		return sprintf(
			/* translators: %d: number of Guest Passes. */
			_n( '%d Guest Pass', '%d Guest Passes', $amount, 'g2a-referrals' ),
			$amount
		);
	}
}
