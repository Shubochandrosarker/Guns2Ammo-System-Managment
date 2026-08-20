<?php
/**
 * Granting, redeeming, expiring and reversing rewards.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Events_Repository;
use WordPressistic\G2AReferrals\Database\Referrers_Repository;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rewards_Service {

	/**
	 * Which reward a given referrer earns.
	 *
	 * Plan 5 "Guest Pass" holders are not paying members and have no
	 * membership for a guest to be brought onto, so a Guest Pass would be
	 * worthless to them. They earn membership days instead — the reward has
	 * to be something the holder can actually use.
	 *
	 * @param int $user_id Referrer's WP user id.
	 * @return string Reward type.
	 */
	public static function referrer_reward_type( $user_id ) {
		$default = (string) Settings::get( 'referrer_reward_type' );

		if ( ! self::is_non_member_plan_holder( $user_id ) ) {
			return $default;
		}

		if ( ! Settings::is_on( 'non_member_plans_may_refer' ) ) {
			return '';
		}

		return (string) Settings::get( 'non_member_plan_reward' );
	}

	/**
	 * Is this user on a plan that does not count as a paying member?
	 *
	 * @param int $user_id WP user id.
	 * @return bool
	 */
	public static function is_non_member_plan_holder( $user_id ) {
		if ( ! function_exists( 'memberistic_get_membership_status' ) ) {
			return false;
		}

		$status = memberistic_get_membership_status( (int) $user_id );

		if ( ! is_array( $status ) || empty( $status['plan_id'] ) ) {
			return false;
		}

		return in_array( (int) $status['plan_id'], Settings::non_member_plan_ids(), true );
	}

	/**
	 * Grant the referrer's reward for a conversion.
	 *
	 * @param array $conversion Conversion row.
	 * @param array $referrer   Referrer row.
	 * @return int Ledger row id, 0 when nothing was granted.
	 */
	public static function grant_referrer_reward( array $conversion, array $referrer ) {
		$user_id = (int) ( $referrer['user_id'] ?? 0 );
		$type    = self::referrer_reward_type( $user_id );

		if ( $user_id <= 0 || '' === $type ) {
			return 0;
		}

		// Belt and braces behind UNIQUE(friend_membership_id): even a manual
		// re-run of qualification cannot grant twice for one conversion.
		if ( Rewards_Repository::exists_for_source( 'referral', (int) $conversion['id'], 'grant', $type, $user_id ) ) {
			return 0;
		}

		$amount = Rewards_Repository::TYPE_GUEST_PASS === $type
			? max( 1, Settings::get_int( 'referrer_reward_amount' ) )
			: max( 1, Settings::get_int( 'friend_reward_periods' ) );

		return Rewards_Repository::add(
			array(
				'user_id'       => $user_id,
				'reward_type'   => $type,
				'amount'        => $amount,
				'direction'     => 'grant',
				'source'        => 'referral',
				'source_id'     => (int) $conversion['id'],
				'membership_id' => (int) ( $referrer['membership_id'] ?? 0 ),
				'expires_at'    => self::expiry_for( $type ),
				'note'          => __( 'Referral reward', 'g2a-referrals' ),
				'actor_id'      => 0,
			)
		);
	}

	/**
	 * Grant the friend's free month and extend their membership.
	 *
	 * The ledger row is written first and the membership extended second: if
	 * the extension fails we want a visible record that the reward was owed,
	 * not a silent loss.
	 *
	 * @param array $conversion Conversion row.
	 * @return int Ledger row id, 0 when nothing was granted.
	 */
	public static function grant_friend_reward( array $conversion ) {
		$user_id       = (int) ( $conversion['friend_user_id'] ?? 0 );
		$membership_id = (int) ( $conversion['friend_membership_id'] ?? 0 );
		$type          = (string) Settings::get( 'friend_reward_type' );
		$periods       = max( 1, Settings::get_int( 'friend_reward_periods' ) );

		if ( $user_id <= 0 || '' === $type ) {
			return 0;
		}

		if ( Rewards_Repository::exists_for_source( 'referral', (int) $conversion['id'], 'grant', $type, $user_id ) ) {
			return 0;
		}

		$ledger_id = Rewards_Repository::add(
			array(
				'user_id'       => $user_id,
				'reward_type'   => $type,
				'amount'        => $periods,
				'direction'     => 'grant',
				'source'        => 'referral',
				'source_id'     => (int) $conversion['id'],
				'membership_id' => $membership_id,
				'note'          => __( 'Free month for joining through a referral', 'g2a-referrals' ),
				'actor_id'      => 0,
			)
		);

		if ( $ledger_id && Rewards_Repository::TYPE_MEMBERSHIP_DAYS === $type ) {
			self::extend_membership( $membership_id, $periods, (int) $conversion['id'] );
		}

		return $ledger_id;
	}

	/**
	 * Push a membership's renewal date out by whole billing periods.
	 *
	 * When the subscription lives in Stripe the extension is also queued for
	 * Stripe as a trial extension / next-invoice credit, so the site and
	 * Stripe never disagree about when the friend is next charged. The queue
	 * is drained by the g2ar_sync_stripe_extension action, which the Stripe
	 * integration owns — this class never calls the Stripe API directly.
	 *
	 * @param int $membership_id Membership row id.
	 * @param int $periods       Billing periods to add.
	 * @param int $conversion_id Conversion that earned it.
	 * @return bool
	 */
	public static function extend_membership( $membership_id, $periods, $conversion_id = 0 ) {
		global $wpdb;

		$membership_id = (int) $membership_id;
		$periods       = max( 1, (int) $periods );

		if ( $membership_id <= 0 ) {
			return false;
		}

		$table = $wpdb->prefix . 'memberistic_memberships';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$membership = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $membership_id ),
			ARRAY_A
		);

		if ( ! is_array( $membership ) ) {
			return false;
		}

		$cycle    = strtolower( (string) ( $membership['billing_cycle'] ?? 'monthly' ) );
		$interval = ( false !== strpos( $cycle, 'ann' ) || false !== strpos( $cycle, 'year' ) ) ? 'year' : 'month';

		$base = ! empty( $membership['renewal_date'] ) ? (string) $membership['renewal_date'] : current_time( 'mysql', true );
		$from = strtotime( $base );

		// A renewal date already in the past would silently shorten the
		// reward — extend from today instead so the friend always gets a
		// full extra period from now.
		if ( ! $from || $from < time() ) {
			$from = time();
		}

		$new_renewal = gmdate( 'Y-m-d H:i:s', strtotime( '+' . $periods . ' ' . $interval, $from ) );

		$updated = $wpdb->update(
			$table,
			array(
				'renewal_date' => $new_renewal,
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => $membership_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		Events_Repository::log(
			'membership_extended',
			array(
				'object_type' => 'membership',
				'object_id'   => $membership_id,
				'actor_id'    => 0,
				'payload'     => array(
					'periods'       => $periods,
					'interval'      => $interval,
					'new_renewal'   => $new_renewal,
					'conversion_id' => (int) $conversion_id,
				),
			)
		);

		if ( ! empty( $membership['stripe_subscription_id'] ) ) {
			/**
			 * Mirror the extension into Stripe.
			 *
			 * @param string $subscription_id Stripe subscription id.
			 * @param int    $periods         Billing periods added.
			 * @param string $interval        month|year.
			 * @param int    $membership_id   Membership row id.
			 */
			do_action(
				'g2ar_sync_stripe_extension',
				(string) $membership['stripe_subscription_id'],
				$periods,
				$interval,
				$membership_id
			);
		}

		return (bool) $updated;
	}

	/**
	 * Expiry timestamp for a newly granted reward, or null when the type
	 * never expires.
	 *
	 * @param string $type Reward type.
	 * @return string|null
	 */
	public static function expiry_for( $type ) {
		if ( Rewards_Repository::TYPE_GUEST_PASS !== $type ) {
			return null;
		}

		$days = Settings::get_int( 'guest_pass_expiry_days' );

		if ( $days <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Manual grant from admin. A reason is mandatory — an unexplained
	 * adjustment to a money ledger is indistinguishable from theft.
	 *
	 * @param int    $user_id WP user id.
	 * @param string $type    Reward type.
	 * @param float  $amount  Positive magnitude.
	 * @param string $reason  Why.
	 * @return int Ledger row id.
	 */
	public static function manual_grant( $user_id, $type, $amount, $reason ) {
		$reason = trim( (string) $reason );

		if ( '' === $reason ) {
			return 0;
		}

		$ledger_id = Rewards_Repository::add(
			array(
				'user_id'     => (int) $user_id,
				'reward_type' => (string) $type,
				'amount'      => $amount,
				'direction'   => 'grant',
				'source'      => 'manual',
				'expires_at'  => self::expiry_for( $type ),
				'note'        => $reason,
				'actor_id'    => get_current_user_id(),
			)
		);

		if ( $ledger_id ) {
			Events_Repository::log(
				'manual_grant',
				array(
					'object_type' => 'reward',
					'object_id'   => $ledger_id,
					'payload'     => array(
						'user_id' => (int) $user_id,
						'type'    => (string) $type,
						'amount'  => (float) $amount,
						'reason'  => $reason,
					),
				)
			);
		}

		return $ledger_id;
	}

	/**
	 * Sweep grants past their expiry, writing one negative row per grant.
	 *
	 * Never deletes. An expired pass has to stay visible in the member's
	 * history so "where did my pass go?" has an answer at the counter.
	 *
	 * @param int $limit Batch size.
	 * @return int Rows expired.
	 */
	public static function run_expiry( $limit = 500 ) {
		$grants = Rewards_Repository::expired_grants( $limit );
		$count  = 0;

		foreach ( $grants as $grant ) {
			$user_id = (int) $grant['user_id'];
			$type    = (string) $grant['reward_type'];

			// Only expire what is still unspent: a pass already redeemed must
			// not be subtracted a second time.
			$balance = Rewards_Repository::balance( $user_id, $type );
			$amount  = min( abs( (float) $grant['amount'] ), max( 0, $balance ) );

			if ( $amount <= 0 ) {
				// Already redeemed before it expired. Write a zero-amount
				// marker so the balance is untouched and the nightly sweep
				// stops rescanning this grant forever.
				Rewards_Repository::add(
					array(
						'user_id'     => $user_id,
						'reward_type' => $type,
						'amount'      => 0,
						'direction'   => 'expire',
						'source'      => 'expiry',
						'source_id'   => (int) $grant['id'],
						'note'        => __( 'Expired after redemption — no balance to remove', 'g2a-referrals' ),
						'actor_id'    => 0,
					)
				);
				continue;
			}

			Rewards_Repository::add(
				array(
					'user_id'     => $user_id,
					'reward_type' => $type,
					'amount'      => $amount,
					'direction'   => 'expire',
					'source'      => 'expiry',
					'source_id'   => (int) $grant['id'],
					'note'        => __( 'Expired', 'g2a-referrals' ),
					'actor_id'    => 0,
				)
			);

			Events_Repository::log(
				'reward_expired',
				array(
					'object_type' => 'reward',
					'object_id'   => (int) $grant['id'],
					'actor_id'    => 0,
					'payload'     => array(
						'user_id' => $user_id,
						'type'    => $type,
						'amount'  => $amount,
					),
				)
			);

			++$count;
		}

		return $count;
	}

	/**
	 * Reverse both sides of a conversion after a refund or cancellation.
	 *
	 * Writes negative rows; never deletes. If the friend's free month has
	 * already been consumed the reversal is still recorded and flagged, but
	 * the time is not clawed back retroactively — taking back range time
	 * someone has already used is not a thing a business can do.
	 *
	 * @param int    $conversion_id Conversion row id.
	 * @param string $reason        Why.
	 * @return bool
	 */
	public static function reverse_conversion( $conversion_id, $reason = '' ) {
		$conversion = Conversions_Repository::get( (int) $conversion_id );

		if ( ! $conversion || 'rewarded' !== (string) $conversion['status'] ) {
			return false;
		}

		$referrer = Referrers_Repository::get( (int) $conversion['referrer_id'] );
		$reason   = $reason ?: __( 'Referred membership refunded or cancelled', 'g2a-referrals' );

		// Referrer side.
		if ( $referrer ) {
			$referrer_user = (int) $referrer['user_id'];
			$referrer_type = self::referrer_reward_type( $referrer_user );

			if ( $referrer_type ) {
				$balance = Rewards_Repository::balance( $referrer_user, $referrer_type );
				$granted = Rewards_Repository::TYPE_GUEST_PASS === $referrer_type
					? max( 1, Settings::get_int( 'referrer_reward_amount' ) )
					: max( 1, Settings::get_int( 'friend_reward_periods' ) );
				$take    = min( $granted, max( 0, $balance ) );

				if ( $take > 0 ) {
					Rewards_Repository::add(
						array(
							'user_id'     => $referrer_user,
							'reward_type' => $referrer_type,
							'amount'      => $take,
							'direction'   => 'reverse',
							'source'      => 'referral',
							'source_id'   => (int) $conversion['id'],
							'note'        => $reason,
							'actor_id'    => get_current_user_id(),
						)
					);
				}

				if ( $take < $granted ) {
					Events_Repository::log(
						'reversal_incomplete',
						array(
							'referrer_id' => (int) $conversion['referrer_id'],
							'object_type' => 'conversion',
							'object_id'   => (int) $conversion['id'],
							'payload'     => array(
								'side'      => 'referrer',
								'granted'   => $granted,
								'recovered' => $take,
								'note'      => 'reward already consumed',
							),
						)
					);
				}
			}
		}

		// Friend side.
		$friend_user = (int) $conversion['friend_user_id'];
		$friend_type = (string) Settings::get( 'friend_reward_type' );

		if ( $friend_user > 0 && $friend_type ) {
			$granted = max( 1, Settings::get_int( 'friend_reward_periods' ) );

			Rewards_Repository::add(
				array(
					'user_id'       => $friend_user,
					'reward_type'   => $friend_type,
					'amount'        => $granted,
					'direction'     => 'reverse',
					'source'        => 'referral',
					'source_id'     => (int) $conversion['id'],
					'membership_id' => (int) $conversion['friend_membership_id'],
					'note'          => $reason,
					'actor_id'      => get_current_user_id(),
				)
			);
		}

		Conversions_Repository::set_status( (int) $conversion['id'], 'reversed', array( 'reject_reason' => $reason ) );

		if ( $referrer ) {
			Referrers_Repository::refresh_counters( (int) $referrer['id'] );
		}

		Events_Repository::log(
			'conversion_reversed',
			array(
				'referrer_id' => (int) $conversion['referrer_id'],
				'object_type' => 'conversion',
				'object_id'   => (int) $conversion['id'],
				'payload'     => array( 'reason' => $reason ),
			)
		);

		return true;
	}
}
