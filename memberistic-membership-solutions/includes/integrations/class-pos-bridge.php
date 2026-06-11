<?php
/**
 * G2A POS Core bridge.
 *
 * Plugs Memberistic into the POS RangeMembership resolution chain:
 *
 *  - `g2a_pos_membership_lookup`   → live member status (plan, expiry,
 *    discounts) for the customer the cashier has on screen. The POS treats
 *    a non-null array from this filter as authoritative, so Memberistic
 *    becomes the membership provider of record at the counter.
 *  - `g2a_pos_membership_bookings` → the customer's upcoming range
 *    bookings (when the G2A Booking Engine is installed) for the POS
 *    dashboard / check-in flow.
 *  - Membership lifecycle hooks    → keep `pos_customer_id` on the
 *    membership row stamped with the WP user id the POS CRM keys on.
 *
 * Gated by the "POS Bridge" toggle on Memberistic → Integrations.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Memberships_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class POS_Bridge {

	public static function register() {
		if ( ! Integrations_Registry::is_enabled( 'pos_bridge' ) ) {
			return;
		}

		add_filter( 'g2a_pos_membership_lookup', array( self::class, 'membership_lookup' ), 10, 2 );
		add_filter( 'g2a_pos_membership_bookings', array( self::class, 'membership_bookings' ), 10, 3 );
		add_action( 'memberistic_membership_created', array( self::class, 'stamp_pos_customer' ), 10, 1 );
		add_action( 'memberistic_membership_activated', array( self::class, 'stamp_pos_customer' ), 10, 1 );
	}

	/**
	 * Resolve a POS customer (WP user id) to their Memberistic membership.
	 *
	 * @param array|null $result      Earlier filter result.
	 * @param int        $customer_id WP user id from the POS CRM.
	 * @return array|null Shape consumed by RangeMembership::normalize().
	 */
	public static function membership_lookup( $result, $customer_id ) {
		if ( null !== $result ) {
			return $result; // Another provider already answered.
		}

		$membership = self::find_membership_for_user( (int) $customer_id );
		if ( ! $membership ) {
			return null;
		}

		$plan      = self::plan_row( (int) $membership['plan_id'] );
		$is_active = in_array( (string) $membership['status'], array( 'active', 'past_due' ), true );
		$benefits  = array();
		if ( $plan && ! empty( $plan['benefits'] ) ) {
			$decoded  = json_decode( (string) $plan['benefits'], true );
			$benefits = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'active'     => $is_active,
			'plan'       => $plan ? (string) $plan['name'] : '',
			'plan_slug'  => $plan ? (string) $plan['slug'] : '',
			'expires_at' => $membership['end_date'] ?: $membership['renewal_date'],
			'member_id'  => (int) $membership['id'],
			'discounts'  => $benefits,
			'source'     => 'memberistic',
		);
	}

	/**
	 * Upcoming bookings for the POS dashboard, sourced from the booking engine.
	 *
	 * @param array|null $result      Earlier filter result.
	 * @param int        $customer_id WP user id.
	 * @param int        $days_ahead  Lookahead window.
	 * @return array|null
	 */
	public static function membership_bookings( $result, $customer_id, $days_ahead = 30 ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! defined( 'G2AB_VERSION' ) && ! class_exists( '\\G2AB_Plugin' ) ) {
			return null; // Booking engine absent — let the POS fall back.
		}

		global $wpdb;
		$customer_id = (int) $customer_id;
		$days_ahead  = max( 1, min( 365, (int) $days_ahead ) );
		$user        = get_user_by( 'id', $customer_id );
		$email       = $user ? (string) $user->user_email : '';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.uuid, b.start_at, b.end_at, b.status, b.party_size, r.name AS resource_name
			 FROM {$wpdb->prefix}g2ab_bookings b
			 LEFT JOIN {$wpdb->prefix}g2ab_resources r ON r.id = b.resource_id
			 WHERE ( b.user_id = %d OR ( %s <> '' AND b.customer_email = %s ) )
			   AND b.status NOT IN ( 'cancelled', 'no_show', 'expired' )
			   AND b.start_at BETWEEN %s AND %s
			 ORDER BY b.start_at ASC
			 LIMIT 25",
			$customer_id,
			$email,
			$email,
			current_time( 'mysql' ),
			wp_date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $days_ahead * DAY_IN_SECONDS )
		), ARRAY_A );

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'       => (string) $row['uuid'],
				'title'    => (string) ( $row['resource_name'] ?: __( 'Range booking', 'memberistic' ) ),
				'start'    => (string) $row['start_at'],
				'end'      => (string) $row['end_at'],
				'status'   => (string) $row['status'],
				'party'    => (int) $row['party_size'],
				'source'   => 'g2a-booking-engine',
			);
		}
		return $out;
	}

	/**
	 * Mirror the POS customer key (WP user id) onto the membership row so
	 * staff tools can join both systems without per-request lookups.
	 *
	 * @param int $membership_id Membership row id.
	 */
	public static function stamp_pos_customer( $membership_id ) {
		global $wpdb;
		$membership_id = (int) $membership_id;
		if ( $membership_id <= 0 ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT primary_user_id, pos_customer_id FROM {$wpdb->prefix}memberistic_memberships WHERE id = %d",
			$membership_id
		), ARRAY_A );
		if ( ! $row || empty( $row['primary_user_id'] ) || ! empty( $row['pos_customer_id'] ) ) {
			return;
		}
		$wpdb->update(
			$wpdb->prefix . 'memberistic_memberships',
			array( 'pos_customer_id' => (string) (int) $row['primary_user_id'] ),
			array( 'id' => $membership_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Best membership for a WP user: prefer the one they own, fall back to
	 * any membership they're linked to as a person. Active rows win.
	 *
	 * @return array|null
	 */
	private static function find_membership_for_user( $user_id ) {
		if ( $user_id <= 0 ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT m.id, m.plan_id, m.status, m.renewal_date, m.end_date
			 FROM {$wpdb->prefix}memberistic_memberships m
			 LEFT JOIN {$wpdb->prefix}memberistic_people p ON p.membership_id = m.id
			 WHERE m.primary_user_id = %d OR p.wp_user_id = %d
			 ORDER BY ( m.status = 'active' ) DESC, m.id DESC
			 LIMIT 1",
			$user_id,
			$user_id
		), ARRAY_A );
	}

	/** @return array|null */
	private static function plan_row( $plan_id ) {
		if ( $plan_id <= 0 ) {
			return null;
		}
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT id, name, slug, benefits FROM {$wpdb->prefix}memberistic_plans WHERE id = %d",
			$plan_id
		), ARRAY_A );
	}
}
