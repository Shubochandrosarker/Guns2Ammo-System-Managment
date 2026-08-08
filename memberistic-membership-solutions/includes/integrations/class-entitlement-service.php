<?php
/**
 * Lane-booking entitlement policy — the single authority that decides whether
 * an authenticated user's membership includes free range-lane reservations.
 *
 * Business rules (see docs/entitlements.md):
 *  - Only the configured "included" plan slugs qualify (default: defender,
 *    patriot, guardian). Guest Pass, Range Guest, WooCommerce customers,
 *    subscribers and every other classification pay the public price.
 *  - Only the configured statuses qualify (default: active, comped). Trial,
 *    past_due, suspended, expired, cancelled and needs_review never do unless
 *    a site explicitly widens the documented setting.
 *  - Entitlement is resolved from an AUTHENTICATED user id only. A typed email
 *    address never grants (or reveals) anything.
 *  - Linked/family members qualify through their own associated account: the
 *    people row must reference their wp_user_id and be active.
 *
 * The booking engine consumes this through the `g2ab_lane_entitlement` filter
 * and receives a structured snapshot, never a bare boolean.
 *
 * @package Memberistic
 */

namespace WordPressistic\Memberistic\Integrations;

use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Entitlement_Service {

	/* Eligibility reason codes — stable strings, safe to log and assert on. */
	const REASON_INCLUDED          = 'member_included';
	const REASON_NOT_AUTHENTICATED = 'not_authenticated';
	const REASON_NO_MEMBERSHIP     = 'no_membership';
	const REASON_STATUS_INELIGIBLE = 'status_not_eligible';
	const REASON_PLAN_INELIGIBLE   = 'plan_not_eligible';
	const REASON_EXPIRED           = 'membership_expired';
	const REASON_PERSON_INACTIVE   = 'linked_person_inactive';

	public static function register() {
		add_filter( 'g2ab_lane_entitlement', array( self::class, 'filter_lane_entitlement' ), 10, 2 );
	}

	/**
	 * Plan slugs whose membership INCLUDES lane time.
	 *
	 * Documented business setting: `memberistic_lane_included_plan_slugs`
	 * (array of stable plan slugs). Default is the three real membership
	 * tiers. `guest-pass` is rejected even if a site adds it here by mistake.
	 *
	 * @return string[]
	 */
	public static function included_plan_slugs() {
		$slugs = get_option( 'memberistic_lane_included_plan_slugs', array( 'defender', 'patriot', 'guardian' ) );
		$slugs = is_array( $slugs ) ? $slugs : array();
		$slugs = array_values( array_filter( array_map( 'sanitize_key', $slugs ) ) );
		if ( ! $slugs ) {
			$slugs = array( 'defender', 'patriot', 'guardian' );
		}
		$slugs = (array) apply_filters( 'memberistic_lane_included_plan_slugs', $slugs );

		// Guest Pass may exist as an intentionally sold product, but it never
		// includes free lane time — enforced here so no setting typo can leak it.
		return array_values( array_diff( array_map( 'sanitize_key', $slugs ), array( 'guest-pass', 'range-guest' ) ) );
	}

	/**
	 * Membership statuses that keep the benefit usable.
	 *
	 * Documented business setting: `memberistic_lane_eligible_statuses`.
	 * Default: active, comped. Everything else is excluded by policy.
	 *
	 * @return string[]
	 */
	public static function eligible_statuses() {
		$statuses = get_option( 'memberistic_lane_eligible_statuses', array( 'active', 'comped' ) );
		$statuses = is_array( $statuses ) ? $statuses : array();
		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );
		if ( ! $statuses ) {
			$statuses = array( 'active', 'comped' );
		}
		return (array) apply_filters( 'memberistic_lane_eligible_statuses', $statuses );
	}

	/**
	 * Filter bridge for the booking engine.
	 *
	 * @param array $entitlement Seed entitlement array from the booking engine.
	 * @param int   $user_id     AUTHENTICATED user id (0 for guests).
	 * @return array
	 */
	public static function filter_lane_entitlement( $entitlement, $user_id ) {
		$resolved = self::resolve_for_user( (int) $user_id );

		// Never downgrade a structured result the site may have layered earlier;
		// Memberistic answers authoritatively only when it finds an eligible
		// membership, otherwise it annotates the reason and leaves eligible=false.
		if ( is_array( $entitlement ) && ! empty( $entitlement['eligible'] ) && empty( $resolved['eligible'] ) ) {
			return $entitlement;
		}

		return $resolved;
	}

	/**
	 * Resolve the structured lane entitlement for an authenticated user.
	 *
	 * @param int $user_id Authenticated WP user id. 0 = anonymous.
	 * @return array{
	 *     user_id:int, membership_id:int, plan_id:int, plan_slug:string,
	 *     membership_status:string, eligible:bool, reason:string,
	 *     pricing_type:string, amount_due:float, allowed_gateway:string,
	 *     plan_name:string, checked_at:string
	 * }
	 */
	public static function resolve_for_user( $user_id ) {
		$user_id = absint( $user_id );
		$result  = array(
			'user_id'           => $user_id,
			'membership_id'     => 0,
			'plan_id'           => 0,
			'plan_slug'         => '',
			'plan_name'         => '',
			'membership_status' => '',
			'eligible'          => false,
			'reason'            => self::REASON_NOT_AUTHENTICATED,
			'pricing_type'      => 'public_full_price',
			'amount_due'        => null, // booking engine fills in the server-calculated price.
			'allowed_gateway'   => 'online',
			'checked_at'        => current_time( 'mysql' ),
		);

		if ( ! $user_id ) {
			return $result;
		}

		$candidate = self::membership_for_authenticated_user( $user_id );

		if ( is_string( $candidate ) ) { // A reason code — the lookup failed in a specific way.
			$result['reason'] = $candidate;
			return $result;
		}

		if ( ! is_array( $candidate ) || empty( $candidate['membership'] ) ) {
			$result['reason'] = self::REASON_NO_MEMBERSHIP;
			return $result;
		}

		$membership = $candidate['membership'];

		$result['membership_id']     = (int) ( $membership['id'] ?? 0 );
		$result['plan_id']           = (int) ( $membership['plan_id'] ?? 0 );
		$result['membership_status'] = sanitize_key( (string) ( $membership['status'] ?? '' ) );

		$plan = $result['plan_id'] ? Plans_Repository::get( $result['plan_id'] ) : null;
		if ( is_array( $plan ) ) {
			$result['plan_slug'] = sanitize_key( (string) ( $plan['slug'] ?? '' ) );
			$result['plan_name'] = (string) ( $plan['name'] ?? '' );
		}

		if ( ! in_array( $result['membership_status'], self::eligible_statuses(), true ) ) {
			$result['reason'] = self::REASON_STATUS_INELIGIBLE;
			return $result;
		}

		if ( self::membership_expired( $membership ) ) {
			$result['reason'] = self::REASON_EXPIRED;
			return $result;
		}

		if ( ! in_array( $result['plan_slug'], self::included_plan_slugs(), true ) ) {
			$result['reason'] = self::REASON_PLAN_INELIGIBLE;
			return $result;
		}

		$result['eligible']        = true;
		$result['reason']          = self::REASON_INCLUDED;
		$result['pricing_type']    = 'member_included';
		$result['amount_due']      = 0.0;
		$result['allowed_gateway'] = 'member_included';

		return $result;
	}

	/**
	 * Find the membership an AUTHENTICATED user may exercise:
	 *  1. a membership where they are the primary user; else
	 *  2. a membership where a people row references their wp_user_id
	 *     (linked/family member through their own account) and is active.
	 *
	 * No email matching — an unauthenticated visitor typing a member's email
	 * must never reach this code path with that member's user id.
	 *
	 * @param int $user_id Authenticated user id.
	 * @return array{membership:array}|string|null Candidate, reason code, or null.
	 */
	private static function membership_for_authenticated_user( $user_id ) {
		$membership = Memberships_Repository::get_by_user_id( $user_id );
		if ( is_array( $membership ) && ! empty( $membership['id'] ) ) {
			return array( 'membership' => $membership );
		}

		global $wpdb;
		$people = People_Repository::table();
		$person = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$people} WHERE wp_user_id = %d ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		if ( ! is_array( $person ) || empty( $person['membership_id'] ) ) {
			return null;
		}

		if ( 'active' !== sanitize_key( (string) ( $person['status'] ?? '' ) ) ) {
			return self::REASON_PERSON_INACTIVE;
		}

		$membership = Memberships_Repository::get( (int) $person['membership_id'] );
		if ( is_array( $membership ) && ! empty( $membership['id'] ) ) {
			return array( 'membership' => $membership );
		}

		return null;
	}

	/**
	 * True when the membership's renewal date has passed (end of that day,
	 * site timezone). Empty/zero renewal dates mean non-expiring.
	 *
	 * @param array $membership Membership row.
	 * @return bool
	 */
	private static function membership_expired( $membership ) {
		$renewal_raw = (string) ( $membership['renewal_date'] ?? '' );
		if ( '' === $renewal_raw || 0 === strpos( $renewal_raw, '0000-00-00' ) ) {
			return false;
		}

		$date_part = substr( $renewal_raw, 0, 10 );
		$renewal   = \DateTime::createFromFormat( 'Y-m-d H:i:s', $date_part . ' 23:59:59', wp_timezone() );
		if ( ! $renewal ) {
			return false;
		}

		return $renewal->getTimestamp() < time();
	}
}
