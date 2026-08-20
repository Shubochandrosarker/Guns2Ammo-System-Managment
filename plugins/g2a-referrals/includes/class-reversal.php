<?php
/**
 * Reward reversal on refund or cancellation.
 *
 * Mandatory, not optional: without it a refunded membership leaves a real
 * Guest Pass and a real free month on the books that nobody paid for.
 *
 * @package G2AR
 */

namespace WordPressistic\G2AReferrals;

use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Events_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Reversal {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_woo_refund' ), 10, 2 );

		// Memberistic already owns a verified Stripe webhook endpoint on
		// account acct_1QAfjyEbqMF6KO5k and re-broadcasts every event as
		// memberistic_stripe_webhook_event. We listen to that rather than
		// registering a second endpoint, which would mean two signature
		// checks and two chances to disagree.
		add_action( 'memberistic_stripe_webhook_event', array( self::class, 'on_stripe_event' ), 10, 3 );

		add_action( 'memberistic_membership_cancelled', array( self::class, 'on_membership_cancelled' ), 10, 1 );
	}

	/**
	 * WooCommerce refund.
	 *
	 * @param int $order_id  Order id.
	 * @param int $refund_id Refund id.
	 * @return void
	 */
	public static function on_woo_refund( $order_id, $refund_id = 0 ) {
		unset( $refund_id );

		$membership_id = self::membership_for_woo_order( (int) $order_id );

		if ( $membership_id > 0 ) {
			self::reverse_for_membership( $membership_id, __( 'WooCommerce order refunded', 'g2a-referrals' ) );
		}
	}

	/**
	 * Stripe webhook, relayed by Memberistic.
	 *
	 * @param string $type  Event type.
	 * @param array  $obj   Event data object.
	 * @param array  $event Full event.
	 * @return void
	 */
	public static function on_stripe_event( $type, $obj, $event = array() ) {
		unset( $event );

		$obj = is_array( $obj ) ? $obj : (array) $obj;

		$reversing = array(
			'charge.refunded',
			'charge.dispute.created',
			'customer.subscription.deleted',
			'invoice.payment_failed',
		);

		if ( ! in_array( (string) $type, $reversing, true ) ) {
			return;
		}

		// invoice.payment_failed only reverses when it is the FIRST invoice:
		// a lapsed renewal months later is churn, not a bad referral.
		if ( 'invoice.payment_failed' === (string) $type
			&& 'subscription_create' !== (string) ( $obj['billing_reason'] ?? '' ) ) {
			return;
		}

		$membership_id = self::membership_for_stripe( $obj );

		if ( $membership_id > 0 ) {
			self::reverse_for_membership(
				$membership_id,
				sprintf(
					/* translators: %s: Stripe event type. */
					__( 'Stripe %s', 'g2a-referrals' ),
					(string) $type
				)
			);
		}
	}

	/**
	 * Membership cancelled inside the hold window.
	 *
	 * @param int $membership_id Membership row id.
	 * @return void
	 */
	public static function on_membership_cancelled( $membership_id ) {
		self::reverse_for_membership( (int) $membership_id, __( 'Membership cancelled', 'g2a-referrals' ) );
	}

	/**
	 * Reverse the referral attached to a membership, if it is still inside
	 * the hold window.
	 *
	 * @param int    $membership_id Membership row id.
	 * @param string $reason        Human reason.
	 * @return bool
	 */
	public static function reverse_for_membership( $membership_id, $reason ) {
		$conversion = Conversions_Repository::get_by_membership( (int) $membership_id );

		if ( ! $conversion || 'rewarded' !== (string) $conversion['status'] ) {
			return false;
		}

		$hold = Settings::get_int( 'hold_window_days' );

		if ( $hold > 0 && ! empty( $conversion['rewarded_at'] ) ) {
			$deadline = strtotime( (string) $conversion['rewarded_at'] ) + ( $hold * DAY_IN_SECONDS );

			if ( time() > $deadline ) {
				// Past the hold window the reward is the member's to keep.
				// Record the decision so an auditor can see it was considered.
				Events_Repository::log(
					'reversal_skipped_outside_hold',
					array(
						'referrer_id' => (int) $conversion['referrer_id'],
						'object_type' => 'conversion',
						'object_id'   => (int) $conversion['id'],
						'actor_id'    => 0,
						'payload'     => array(
							'reason'    => $reason,
							'hold_days' => $hold,
						),
					)
				);

				return false;
			}
		}

		return Rewards_Service::reverse_conversion( (int) $conversion['id'], $reason );
	}

	/**
	 * Find the membership behind a WooCommerce order.
	 *
	 * @param int $order_id Order id.
	 * @return int
	 */
	private static function membership_for_woo_order( $order_id ) {
		global $wpdb;

		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return 0;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return 0;
		}

		$membership_id = (int) $order->get_meta( '_memberistic_membership_id' );

		if ( $membership_id > 0 ) {
			return $membership_id;
		}

		$table = $wpdb->prefix . 'memberistic_memberships';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE woo_subscription_id = %d LIMIT 1", $order_id )
		);
	}

	/**
	 * Find the membership behind a Stripe object.
	 *
	 * @param array $obj Stripe data object.
	 * @return int
	 */
	private static function membership_for_stripe( array $obj ) {
		global $wpdb;

		$table = $wpdb->prefix . 'memberistic_memberships';

		$subscription = (string) ( $obj['subscription'] ?? ( $obj['id'] ?? '' ) );
		$customer     = (string) ( $obj['customer'] ?? '' );

		if ( $subscription && 0 === strpos( $subscription, 'sub_' ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE stripe_subscription_id = %s LIMIT 1", $subscription )
			);

			if ( $id > 0 ) {
				return $id;
			}
		}

		if ( $customer ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE stripe_customer_id = %s ORDER BY id DESC LIMIT 1",
					$customer
				)
			);
		}

		return 0;
	}
}
