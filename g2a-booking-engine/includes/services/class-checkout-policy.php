<?php
/**
 * Central checkout/eligibility policy.
 *
 * One authority answers, for every public booking request:
 *   - is the authenticated user entitled to a $0 member-included lane?
 *   - what is the server-calculated amount due?
 *   - which gateways may take this payment?
 *
 * Rules enforced here (not scattered across controllers/UI/email code):
 *   - Only a structured entitlement from the membership plugin (Memberistic's
 *     Entitlement_Service via `g2ab_lane_entitlement`) can zero out a lane.
 *     Generic "has any membership" signals cannot.
 *   - A public payable request may ONLY use an online gateway. pay_in_store,
 *     cash, terminal, comp and every offline/manual gateway are reserved for
 *     authenticated staff endpoints — even when the request is crafted
 *     outside the official UI.
 *   - Public deposits and pay-at-store reservations are not allowed: a
 *     non-member pays the complete server-calculated price, every time.
 *
 * @package G2AB\Services
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class G2AB_Checkout_Policy {

	/**
	 * Gateway ids that settle money outside an online, webhook-verifiable
	 * flow. Never legal on public payable endpoints.
	 *
	 * @return string[]
	 */
	public static function offline_gateway_ids() {
		$ids = array( 'pay_in_store', 'cash', 'terminal', 'comp', 'manual', 'offline', 'check', 'invoice' );
		return array_values( array_unique( array_map( 'sanitize_key', (array) apply_filters( 'g2ab_offline_gateway_ids', $ids ) ) ) );
	}

	/**
	 * @param string $gateway_id Gateway id.
	 * @return bool
	 */
	public static function is_offline_gateway( $gateway_id ) {
		return in_array( sanitize_key( (string) $gateway_id ), self::offline_gateway_ids(), true );
	}

	/**
	 * Must a non-member pay online before the reservation is confirmed?
	 *
	 * ON by default and the safe answer. Turning it off is an explicit,
	 * audited business decision (Settings → Payments) that re-opens
	 * pay-at-the-desk reservations for public lane bookings.
	 *
	 * @return bool
	 */
	public static function require_payment_for_non_members() {
		return (bool) apply_filters(
			'g2ab_require_payment_for_non_members',
			1 === (int) get_option( 'g2ab_require_nonmember_payment', 1 )
		);
	}

	/**
	 * May a PUBLIC non-member settle a lane booking at the front desk?
	 *
	 * OFF by default. Only an explicit admin opt-in allows it, and it never
	 * applies to paid events (those always require verified online payment).
	 *
	 * @return bool
	 */
	public static function allow_non_member_front_desk() {
		if ( self::require_payment_for_non_members() ) {
			return false;
		}
		return (bool) apply_filters(
			'g2ab_allow_non_member_front_desk',
			1 === (int) get_option( 'g2ab_allow_nonmember_front_desk', 0 )
		);
	}

	/**
	 * Structured lane entitlement for an AUTHENTICATED user id.
	 *
	 * The membership plugin overrides the seed through `g2ab_lane_entitlement`.
	 * The result shape is normalized here so downstream code can rely on it
	 * whatever a third-party filter returns.
	 *
	 * @param int $user_id Authenticated user id (0 = anonymous).
	 * @return array Structured entitlement snapshot.
	 */
	public static function lane_entitlement( $user_id ) {
		$user_id = absint( $user_id );
		$seed    = array(
			'user_id'           => $user_id,
			'membership_id'     => 0,
			'plan_id'           => 0,
			'plan_slug'         => '',
			'plan_name'         => '',
			'membership_status' => '',
			'eligible'          => false,
			'reason'            => $user_id ? 'no_membership_provider' : 'not_authenticated',
			'pricing_type'      => 'public_full_price',
			'amount_due'        => null,
			'allowed_gateway'   => 'online',
			'checked_at'        => current_time( 'mysql' ),
		);

		$entitlement = apply_filters( 'g2ab_lane_entitlement', $seed, $user_id );
		$entitlement = is_array( $entitlement ) ? array_merge( $seed, $entitlement ) : $seed;

		$entitlement['user_id']           = absint( $entitlement['user_id'] );
		$entitlement['membership_id']     = absint( $entitlement['membership_id'] );
		$entitlement['plan_id']           = absint( $entitlement['plan_id'] );
		$entitlement['plan_slug']         = sanitize_key( (string) $entitlement['plan_slug'] );
		$entitlement['plan_name']         = sanitize_text_field( (string) $entitlement['plan_name'] );
		$entitlement['membership_status'] = sanitize_key( (string) $entitlement['membership_status'] );
		$entitlement['eligible']          = ! empty( $entitlement['eligible'] );
		$entitlement['reason']            = sanitize_key( (string) $entitlement['reason'] );
		$entitlement['pricing_type']      = $entitlement['eligible'] ? 'member_included' : 'public_full_price';

		// An entitlement that claims eligibility must be attributable to the
		// SAME authenticated user this request runs as — a filter bug must
		// never grant one user another user's benefit.
		if ( $entitlement['eligible'] && $entitlement['user_id'] !== $user_id ) {
			$entitlement['eligible'] = false;
			$entitlement['reason']   = 'entitlement_user_mismatch';
			$entitlement['pricing_type'] = 'public_full_price';
		}

		return $entitlement;
	}

	/**
	 * Resolve the checkout decision for a lane booking request.
	 *
	 * @param object $booking_type Booking type row.
	 * @param array  $pricing      Server-calculated pricing (calculate_pricing()).
	 * @param array  $entitlement  Structured entitlement (lane_entitlement()).
	 * @param string $requested_gateway Requested gateway id ('' = none).
	 * @return array|WP_Error {
	 *     amount_due:float, payment_mode:string, gateway_policy:string,
	 *     initial_status:string, entitlement:array
	 * }
	 */
	public static function resolve_lane( $booking_type, array $pricing, array $entitlement, $requested_gateway = '' ) {
		$total             = round( (float) ( $pricing['total'] ?? 0 ), 2 );
		$base_is_free      = (float) $booking_type->base_price <= 0;
		$requested_gateway = sanitize_key( (string) $requested_gateway );

		// ── $0 paths need a valid zero reason ────────────────────────────────
		if ( $total <= 0 ) {
			$member_included = $entitlement['eligible'] && 'memberistic' === sanitize_key( (string) ( $pricing['membership_source'] ?? '' ) );
			if ( ! $member_included && ! $base_is_free ) {
				// A paid booking type resolved to $0 without an eligible
				// membership — a filter bug or tampering. Fail closed.
				return new WP_Error(
					'g2ab_pricing_invalid',
					__( 'We could not verify the price for this reservation. Please try again.', 'g2a-booking' ),
					array( 'status' => 409 )
				);
			}
			return array(
				'amount_due'     => 0.0,
				'payment_mode'   => 'free',
				'gateway_policy' => 'free',
				'initial_status' => 'confirmed',
				'zero_reason'    => $member_included ? 'member_included' : 'free_booking_type',
				'entitlement'    => $entitlement,
			);
		}

		// ── Public payable ────────────────────────────────────────────────────
		// Front-desk settlement is only reachable when an admin has explicitly
		// disabled "require payment for non-members" AND enabled the
		// front-desk option. Both default to the safe answer, so the normal
		// path below is: online gateway only, full amount, checkout hold.
		if ( self::is_offline_gateway( $requested_gateway ) ) {
			if ( ! self::allow_non_member_front_desk() ) {
				return new WP_Error(
					'g2ab_gateway_not_allowed',
					__( 'This reservation requires secure online payment.', 'g2a-booking' ),
					array( 'status' => 400 )
				);
			}
			return array(
				'amount_due'     => 0.0,
				'payment_mode'   => 'in_store',
				'gateway_policy' => 'pay_in_store',
				'initial_status' => 'reserved',
				'zero_reason'    => 'admin_enabled_front_desk',
				'entitlement'    => $entitlement,
			);
		}

		return array(
			'amount_due'     => $total,
			'payment_mode'   => 'full', // Public deposits are not allowed.
			'gateway_policy' => 'online',
			'initial_status' => 'pending', // Checkout hold — never operational until paid.
			'zero_reason'    => '',
			'entitlement'    => $entitlement,
		);
	}

	/**
	 * Pick a usable ONLINE gateway for a public payable booking.
	 *
	 * @param object $booking_type      Booking type row.
	 * @param string $requested_gateway Requested id ('' = pick automatically).
	 * @return object|WP_Error Gateway instance.
	 */
	public static function pick_online_gateway( $booking_type, $requested_gateway = '' ) {
		if ( ! class_exists( 'G2AB_Gateway_Manager' ) ) {
			return new WP_Error( 'g2ab_no_gateway_manager', __( 'Online payment is currently unavailable.', 'g2a-booking' ), array( 'status' => 503 ) );
		}
		$mgr = G2AB_Gateway_Manager::instance();
		$ok  = static function ( $g ) {
			return $g
				&& method_exists( $g, 'create_intent' )
				&& ( ! method_exists( $g, 'is_available' ) || $g->is_available() )
				&& ( ! method_exists( $g, 'id' ) || ! self::is_offline_gateway( $g->id() ) );
		};

		if ( '' !== $requested_gateway ) {
			$gateway = $mgr->get( sanitize_key( $requested_gateway ) );
			if ( $ok( $gateway ) ) {
				return $gateway;
			}
			return new WP_Error( 'g2ab_gateway_unavailable', __( 'Selected payment gateway is not available.', 'g2a-booking' ), array( 'status' => 503 ) );
		}

		foreach ( array( 'stripe', 'paypal' ) as $preferred ) {
			$gateway = $mgr->get( $preferred );
			if ( $ok( $gateway ) ) {
				return $gateway;
			}
		}
		foreach ( $mgr->available() as $gateway ) {
			if ( $ok( $gateway ) ) {
				return $gateway;
			}
		}

		return new WP_Error(
			'g2ab_no_online_gateway',
			__( 'Online payment is currently unavailable. Please try again shortly or call us to reserve.', 'g2a-booking' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Gateways a PUBLIC caller may be offered for a payable request.
	 * Offline/manual gateways are never advertised publicly.
	 *
	 * @return array[] List of ['id' => ..., 'label' => ...].
	 */
	public static function public_payment_methods() {
		$out = array();
		if ( class_exists( 'G2AB_Gateway_Manager' ) ) {
			foreach ( G2AB_Gateway_Manager::instance()->available() as $id => $gw ) {
				if ( self::is_offline_gateway( $id ) || ! method_exists( $gw, 'create_intent' ) ) {
					continue;
				}
				$out[] = array(
					'id'    => $id,
					'label' => method_exists( $gw, 'label' ) ? $gw->label() : $id,
				);
			}
		}
		return $out;
	}

	/**
	 * Minutes a public checkout hold blocks inventory before it expires.
	 *
	 * @return int
	 */
	public static function hold_minutes() {
		if ( class_exists( 'G2AB_Event_Capacity_Service' ) ) {
			return (int) G2AB_Event_Capacity_Service::hold_minutes();
		}
		return max( 1, (int) apply_filters( 'g2ab_event_hold_minutes', get_option( 'g2ab_reservation_hold_minutes', 15 ) ) );
	}
}
