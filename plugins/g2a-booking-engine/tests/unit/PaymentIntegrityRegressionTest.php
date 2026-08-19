<?php
/**
 * Payment-integrity regression matrix — 2026-08-19 Stripe non-charging incident.
 *
 * Every test here exists because a specific defect shipped to production and
 * cost real money. See
 * docs/incident-2026-08-19-stripe-not-charging/INCIDENT-AUDIT-2026-08-19-STRIPE-NOT-CHARGING.md
 *
 * The five business invariants under test:
 *
 *   1. payment_required && !verified_payment  →  status MUST NOT be `paid`
 *   2. status = `paid`                        →  authoritative payment evidence MUST exist
 *   3. gateway unavailable                    →  MUST fail closed, never convert to free/pay-at-store
 *   4. event seat confirmation                →  payment evidence required
 *   5. no surface may report PAID unless the authoritative state is `paid`
 *
 * Scenarios that need a live gateway (declined card, abandoned checkout,
 * expired session, duplicate/delayed webhook, live capture) are the staging
 * checklist in FIX-PLAN.md §P4 — they cannot be asserted without Stripe.
 */

use PHPUnit\Framework\TestCase;

/**
 * Configurable stand-in for the gateway registry.
 *
 * The real G2AB_Gateway_Manager is not loaded by the unit bootstrap (it builds
 * live gateway objects), so this double provides the same surface the checkout
 * policy consumes: get(), available().
 */
if ( ! class_exists( 'G2AB_Gateway_Manager', false ) ) {
	// phpcs:disable
	final class G2AB_Gateway_Manager {

	private static $instance = null;

	/** @var array<string,object> */
	public $gateways = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Test helper — reset the registry between scenarios. */
	public static function tests_set( array $gateways ) {
		$mgr           = self::instance();
		$mgr->gateways = $gateways;
	}

	public function get( $id ) {
		return $this->gateways[ $id ] ?? null;
	}

	public function available() {
		$out = array();
		foreach ( $this->gateways as $id => $gw ) {
			if ( $gw->is_available() ) {
				$out[ $id ] = $gw;
			}
		}
		return $out;
	}
	}
	// phpcs:enable
}

/** Minimal gateway double. */
final class G2AB_Test_Gateway {

	private $id;
	private $available;
	private $has_intent;

	public function __construct( $id, $available = true, $has_intent = true ) {
		$this->id         = $id;
		$this->available  = $available;
		$this->has_intent = $has_intent;
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return ucfirst( $this->id );
	}

	public function is_available() {
		return (bool) $this->available;
	}

	public function create_intent( $booking, $amount, $context = array() ) {
		if ( ! $this->has_intent ) {
			throw new RuntimeException( 'no intent support' );
		}
		return array( 'redirect_url' => 'https://checkout.stripe.com/c/pay/cs_test_x' );
	}
}

final class PaymentIntegrityRegressionTest extends TestCase {

	protected function setUp(): void {
		g2ab_tests_reset_state();
		$this->withGateways( array( 'stripe' => new G2AB_Test_Gateway( 'stripe' ) ) );
	}

	/* ────────────────────────── helpers ────────────────────────── */

	private function withGateways( array $gateways ): void {
		G2AB_Gateway_Manager::tests_set( $gateways );
	}

	private function laneType( float $base_price = 25.0, int $members_only = 0 ): object {
		return (object) array(
			'id'             => 4,
			'name'           => 'Basic Lane',
			'slug'           => 'lane-booking',
			'base_price'     => $base_price,
			'members_only'   => $members_only,
			'deposit_amount' => 0.0,
			// The production defect: this column was empty, and an empty column
			// defaulted to `full,in_store`. The policy must not read it at all.
			'payment_modes'  => '',
		);
	}

	private function pricing( float $total, string $membership_source = '' ): array {
		return array(
			'subtotal'                => 25.0,
			'discount_amount'         => round( 25.0 - $total, 2 ),
			'total'                   => $total,
			'member_discount_percent' => $total > 0 ? 0.0 : 100.0,
			'membership_level_id'     => 0,
			'membership_source'       => $membership_source,
			'discount_label'          => '',
		);
	}

	/** Simulate the membership plugin answering for an authenticated member. */
	private function entitleUser( int $user_id, string $plan_slug ): void {
		add_filter( 'g2ab_lane_entitlement', static function ( $seed, $uid ) use ( $user_id, $plan_slug ) {
			if ( (int) $uid !== $user_id ) {
				return $seed;
			}
			return array_merge( $seed, array(
				'user_id'           => $user_id,
				'membership_id'     => 900 + $user_id,
				'plan_id'           => 3,
				'plan_slug'         => $plan_slug,
				'plan_name'         => ucfirst( $plan_slug ),
				'membership_status' => 'active',
				'eligible'          => true,
				'reason'            => 'lane_included',
			) );
		}, 10, 2 );
	}

	private function stripeSession( array $overrides = array() ): array {
		return array_merge( array(
			'id'                  => 'cs_live_A1b2C3d4E5',
			'object'              => 'checkout.session',
			'mode'                => 'payment',
			'status'              => 'complete',
			'payment_status'      => 'paid',
			'client_reference_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'metadata'            => array(
				'booking_id'   => '77',
				'booking_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			),
			'currency'            => 'usd',
			'amount_total'        => 2500,
			'payment_intent'      => 'pi_live_ZZZ',
			'livemode'            => true,
		), $overrides );
	}

	private function paidBooking(): object {
		return (object) array(
			'id'           => 77,
			'uuid'         => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
			'status'       => 'pending',
			'currency'     => 'USD',
			'total_amount' => 25.0,
			'paid_amount'  => 0.0,
			'metadata'     => wp_json_encode( array( 'due_now' => 25.0 ) ),
		);
	}

	private function paymentAttempt(): object {
		return (object) array(
			'id'                  => 501,
			'booking_id'          => 77,
			'gateway'             => 'stripe',
			'checkout_session_id' => 'cs_live_A1b2C3d4E5',
			'transaction_id'      => 'cs_live_A1b2C3d4E5',
			'status'              => 'open',
		);
	}

	/* ═══════════════ 1 · Non-member lane — Stripe mandatory ═══════════════ */

	public function testNonMemberLaneRequiresOnlinePayment(): void {
		$decision = G2AB_Checkout_Policy::resolve_lane(
			$this->laneType(),
			$this->pricing( 25.0 ),
			G2AB_Checkout_Policy::lane_entitlement( 0 )
		);

		$this->assertIsArray( $decision, 'A payable non-member lane must resolve, not error.' );
		$this->assertSame( 25.0, $decision['amount_due'] );
		$this->assertSame( 'full', $decision['payment_mode'] );
		$this->assertSame( 'online', $decision['gateway_policy'] );
		$this->assertSame( 'pending', $decision['initial_status'] );
	}

	/**
	 * RC-1 regression guard. The July-2026 builds resolved this exact input to
	 * payment_mode='in_store', due_now=0, status='reserved' and never called
	 * Stripe. That shape must be unreachable from the public lane path for ANY
	 * combination of inputs.
	 */
	public function testPublicLaneCanNeverResolveToPayAtStore(): void {
		$cases = array(
			'no requested gateway'        => '',
			'explicit pay_in_store'       => 'pay_in_store',
			'explicit cash'               => 'cash',
			'explicit terminal'           => 'terminal',
			'explicit comp'               => 'comp',
			'explicit manual'             => 'manual',
		);

		foreach ( $cases as $label => $requested ) {
			$decision = G2AB_Checkout_Policy::resolve_lane(
				$this->laneType(),
				$this->pricing( 25.0 ),
				G2AB_Checkout_Policy::lane_entitlement( 0 ),
				$requested
			);

			if ( is_wp_error( $decision ) ) {
				$this->assertSame(
					'g2ab_gateway_not_allowed',
					$decision->get_error_code(),
					"[{$label}] offline gateways must be refused outright."
				);
				continue;
			}

			$this->assertNotSame( 'in_store', $decision['payment_mode'], "[{$label}] Stripe bypass reintroduced." );
			$this->assertNotSame( 'reserved', $decision['initial_status'], "[{$label}] unpaid booking made operational." );
			$this->assertGreaterThan( 0, $decision['amount_due'], "[{$label}] payable booking zeroed out." );
		}
	}

	/* ═════════ 2-4 · Defender / Patriot / Guardian — $0 when entitled ═════════ */

	/**
	 * @dataProvider includedPlanProvider
	 */
	public function testEntitledMemberLaneIsFreeAndConfirmed( string $plan ): void {
		$GLOBALS['g2ab_test_user_id'] = 42;
		$this->entitleUser( 42, $plan );

		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 42 );
		$this->assertTrue( $entitlement['eligible'], "{$plan} must be entitled." );
		$this->assertSame( 'member_included', $entitlement['pricing_type'] );

		$decision = G2AB_Checkout_Policy::resolve_lane(
			$this->laneType(),
			$this->pricing( 0.0, 'memberistic' ),
			$entitlement
		);

		$this->assertIsArray( $decision );
		$this->assertSame( 0.0, $decision['amount_due'] );
		$this->assertSame( 'free', $decision['payment_mode'] );
		$this->assertSame( 'confirmed', $decision['initial_status'] );
		$this->assertSame( 'member_included', $decision['zero_reason'] );
	}

	public static function includedPlanProvider(): array {
		return array(
			'defender' => array( 'defender' ),
			'patriot'  => array( 'patriot' ),
			'guardian' => array( 'guardian' ),
		);
	}

	/* ══════════════════ 5 · Expired member — must pay ══════════════════ */

	public function testExpiredMemberPaysFullPrice(): void {
		$GLOBALS['g2ab_test_user_id'] = 43;
		add_filter( 'g2ab_lane_entitlement', static function ( $seed, $uid ) {
			return array_merge( $seed, array(
				'user_id'           => (int) $uid,
				'membership_status' => 'expired',
				'eligible'          => false,
				'reason'            => 'membership_expired',
			) );
		}, 10, 2 );

		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 43 );
		$this->assertFalse( $entitlement['eligible'] );
		$this->assertSame( 'public_full_price', $entitlement['pricing_type'] );

		$decision = G2AB_Checkout_Policy::resolve_lane( $this->laneType(), $this->pricing( 25.0 ), $entitlement );
		$this->assertSame( 'full', $decision['payment_mode'] );
		$this->assertSame( 'pending', $decision['initial_status'] );
	}

	/**
	 * An expired/absent membership that somehow produces a $0 total on a PAID
	 * booking type must fail closed rather than hand out a free lane.
	 * (Root cause RC-4 — the guest priced as a member.)
	 */
	public function testZeroTotalWithoutEntitlementIsRejected(): void {
		$decision = G2AB_Checkout_Policy::resolve_lane(
			$this->laneType( 25.0 ),
			$this->pricing( 0.0 ),                       // no membership_source
			G2AB_Checkout_Policy::lane_entitlement( 0 )
		);

		$this->assertTrue( is_wp_error( $decision ), 'A $0 price on a paid lane type must be refused.' );
		$this->assertSame( 'g2ab_pricing_invalid', $decision->get_error_code() );
	}

	/** A genuinely free booking type is still allowed to be free. */
	public function testGenuinelyFreeBookingTypeIsAllowed(): void {
		$decision = G2AB_Checkout_Policy::resolve_lane(
			$this->laneType( 0.0 ),
			$this->pricing( 0.0 ),
			G2AB_Checkout_Policy::lane_entitlement( 0 )
		);

		$this->assertIsArray( $decision );
		$this->assertSame( 'free_booking_type', $decision['zero_reason'] );
		$this->assertSame( 'confirmed', $decision['initial_status'] );
	}

	/* ═══════════════ 6 · Logged-out guest — must pay ═══════════════ */

	public function testLoggedOutGuestIsNeverEntitled(): void {
		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 0 );
		$this->assertFalse( $entitlement['eligible'] );
		$this->assertSame( 'not_authenticated', $entitlement['reason'] );
		$this->assertTrue( G2AB_Checkout_Policy::require_payment_for_non_members() );
	}

	/**
	 * RC-4 regression guard: an entitlement attributed to a DIFFERENT user id
	 * than the one the request runs as must be discarded. This is the
	 * cross-plugin defect where a logged-out guest typing a member's email was
	 * priced as that member.
	 */
	public function testEntitlementForAnotherUserIsDiscarded(): void {
		add_filter( 'g2ab_lane_entitlement', static function ( $seed ) {
			return array_merge( $seed, array(
				'user_id'  => 999,            // some other member
				'eligible' => true,
				'reason'   => 'lane_included',
			) );
		}, 10, 2 );

		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 0 );

		$this->assertFalse( $entitlement['eligible'], 'One user must never inherit another user\'s benefit.' );
		$this->assertSame( 'entitlement_user_mismatch', $entitlement['reason'] );
		$this->assertSame( 'public_full_price', $entitlement['pricing_type'] );
	}

	/* ══════ 7-8 · Paid CCW / event — online payment mandatory ══════ */

	public function testPaidEventRejectsOfflineGateway(): void {
		foreach ( G2AB_Checkout_Policy::offline_gateway_ids() as $offline ) {
			$this->assertTrue(
				G2AB_Checkout_Policy::is_offline_gateway( $offline ),
				"'{$offline}' must be refused for a paid event seat."
			);
		}
		$this->assertFalse( G2AB_Checkout_Policy::is_offline_gateway( 'stripe' ) );
	}

	public function testPaidEventPicksAnOnlineGatewayWhenAvailable(): void {
		$gateway = G2AB_Checkout_Policy::pick_online_gateway( $this->laneType() );
		$this->assertFalse( is_wp_error( $gateway ) );
		$this->assertSame( 'stripe', $gateway->id() );
	}

	/* ═════════ 19 · Stripe unavailable — MUST fail closed (Invariant 3) ═════════ */

	public function testNoOnlineGatewayFailsClosedAndNeverFallsBackToPayInStore(): void {
		// The exact production state: Stripe disabled/unregistered, pay_in_store
		// still active and "always available".
		$this->withGateways( array(
			'pay_in_store' => new G2AB_Test_Gateway( 'pay_in_store' ),
			'stripe'       => new G2AB_Test_Gateway( 'stripe', false ),
		) );

		$gateway = G2AB_Checkout_Policy::pick_online_gateway( $this->laneType() );

		$this->assertTrue( is_wp_error( $gateway ), 'With no usable online gateway the request MUST fail.' );
		$this->assertSame( 'g2ab_no_online_gateway', $gateway->get_error_code() );
		$this->assertSame( 503, $gateway->get_error_data()['status'] );
	}

	public function testUnavailableRequestedGatewayIsRefusedNotSubstituted(): void {
		$this->withGateways( array(
			'pay_in_store' => new G2AB_Test_Gateway( 'pay_in_store' ),
			'stripe'       => new G2AB_Test_Gateway( 'stripe', false ),
		) );

		$gateway = G2AB_Checkout_Policy::pick_online_gateway( $this->laneType(), 'stripe' );

		$this->assertTrue( is_wp_error( $gateway ) );
		$this->assertSame( 'g2ab_gateway_unavailable', $gateway->get_error_code() );
	}

	/* ═════════ 20 · Offline gateways are never advertised publicly ═════════ */

	public function testPublicPaymentMethodsExcludeOfflineGateways(): void {
		$this->withGateways( array(
			'pay_in_store' => new G2AB_Test_Gateway( 'pay_in_store' ),
			'cash'         => new G2AB_Test_Gateway( 'cash' ),
			'stripe'       => new G2AB_Test_Gateway( 'stripe' ),
		) );

		$ids = array_column( G2AB_Checkout_Policy::public_payment_methods(), 'id' );

		$this->assertContains( 'stripe', $ids );
		$this->assertNotContains( 'pay_in_store', $ids );
		$this->assertNotContains( 'cash', $ids );
	}

	public function testPickOnlineGatewayNeverReturnsAnOfflineGateway(): void {
		$this->withGateways( array( 'pay_in_store' => new G2AB_Test_Gateway( 'pay_in_store' ) ) );

		$gateway = G2AB_Checkout_Policy::pick_online_gateway( $this->laneType() );

		$this->assertTrue( is_wp_error( $gateway ), 'pay_in_store must never satisfy an online-gateway request.' );
	}

	/* ═════════ 12 · A genuine, matching Stripe session validates ═════════ */

	public function testGenuineLiveSessionValidates(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession(),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertIsArray( $result, 'A correct live session must validate.' );
		$this->assertSame( 2500, $result['amount_minor'] );
		$this->assertSame( 25.0, $result['amount'] );
		$this->assertSame( 'USD', $result['currency'] );
	}

	/* ═════════ 15 · Crafted success URL cannot mark a booking paid ═════════ */

	public function testSessionForAnotherBookingIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'client_reference_id' => '11111111-2222-3333-4444-555555555555' ) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_reference_mismatch', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['permanent'] );
	}

	public function testUnpaidSessionIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'payment_status' => 'unpaid', 'status' => 'open' ) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_not_complete', $result->get_error_code() );
	}

	public function testForgedSessionIdShapeIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'id' => 'cs_live_../../etc/passwd' ) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_bad_session', $result->get_error_code() );
	}

	public function testSubscriptionModeSessionCannotPayForABooking(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'mode' => 'subscription' ) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_bad_mode', $result->get_error_code() );
	}

	/* ═════════════════ 16 · Wrong amount ═════════════════ */

	public function testUnderpaymentIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'amount_total' => 100 ) ),   // $1.00 for a $25 lane
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_amount_mismatch', $result->get_error_code() );
	}

	public function testOverpaymentIsAlsoRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'amount_total' => 9900 ) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_amount_mismatch', $result->get_error_code() );
	}

	/* ═════════════════ 17 · Wrong currency ═════════════════ */

	public function testCurrencyMismatchIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );
		add_filter( 'g2ab_allowed_currencies', static fn() => array( 'USD', 'CAD' ) );

		$booking           = $this->paidBooking();
		$booking->currency = 'USD';

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'currency' => 'cad' ) ),
			$booking,
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_currency_mismatch', $result->get_error_code() );
	}

	public function testDisallowedCurrencyIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'currency' => 'eur' ) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_currency_mismatch', $result->get_error_code() );
	}

	/* ═════════════════ 18 · Wrong booking metadata ═════════════════ */

	public function testMetadataMismatchIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array(
				'metadata' => array( 'booking_id' => '9999', 'booking_uuid' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee' ),
			) ),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_metadata_mismatch', $result->get_error_code() );
	}

	public function testSessionFromADifferentAttemptIsRejected(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		$attempt                      = $this->paymentAttempt();
		$attempt->checkout_session_id = 'cs_live_SOMETHINGELSE';
		$attempt->transaction_id      = 'cs_live_SOMETHINGELSE';

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession(),
			$this->paidBooking(),
			$attempt
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_attempt_mismatch', $result->get_error_code() );
	}

	/* ═════════ H10 / CF-5 · Test-vs-live environment mismatch ═════════ */

	public function testTestModeSessionCannotPayALiveBooking(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );  // site is LIVE

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession( array( 'id' => 'cs_test_A1b2C3d4E5', 'livemode' => false ) ),
			$this->paidBooking(),
			(object) array( 'id' => 501, 'checkout_session_id' => 'cs_test_A1b2C3d4E5', 'transaction_id' => 'cs_test_A1b2C3d4E5' )
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_mode_mismatch', $result->get_error_code() );
	}

	/**
	 * CF-5, fixed in 1.12.1. `g2ab_stripe_test_mode` defaults to 1, so a site
	 * that never explicitly saved the payments screen used to reject every
	 * genuine LIVE payment as an environment mismatch — money taken, booking
	 * left unpaid. The configured key's prefix is now authoritative.
	 */
	public function testLivePaymentIsAcceptedWhenTheKeyIsLiveAndTheOptionWasNeverSaved(): void {
		// Deliberately do NOT set g2ab_stripe_test_mode — reproduce a fresh site.
		update_option( 'g2ab_stripe_secret_key', 'sk_live_ExampleKeyValue' );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession(),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertIsArray(
			$result,
			'A live session paid with a live key must validate even when the test-mode option was never saved.'
		);
	}

	/**
	 * When the key cannot answer (no key, or a restricted `rk_` key) we fall
	 * back to the option — which defaults to TEST. Refusing is the correct
	 * outcome there: an undeterminable environment must fail closed.
	 */
	public function testUndeterminableEnvironmentStillFailsClosed(): void {
		update_option( 'g2ab_stripe_secret_key', '' );

		$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
			$this->stripeSession(),
			$this->paidBooking(),
			$this->paymentAttempt()
		);

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'g2ab_payment_mode_mismatch', $result->get_error_code() );
	}

	/* ═════════ P1-4 · Stripe environment is read from the key, not an option ═════════ */

	public function testKeyModeIsDerivedFromTheSecretKeyPrefix(): void {
		update_option( 'g2ab_stripe_secret_key', 'sk_live_ExampleKeyValue' );
		$this->assertSame( 'live', G2AB_Gateway_Stripe::key_mode() );
		$this->assertFalse( G2AB_Gateway_Stripe::is_test_mode() );

		update_option( 'g2ab_stripe_secret_key', 'sk_test_ExampleKeyValue' );
		$this->assertSame( 'test', G2AB_Gateway_Stripe::key_mode() );
		$this->assertTrue( G2AB_Gateway_Stripe::is_test_mode() );
	}

	public function testKeyModeFallsBackToTheOptionWhenThePrefixIsUnrecognised(): void {
		update_option( 'g2ab_stripe_secret_key', 'rk_live_RestrictedKey' );

		$this->assertSame( '', G2AB_Gateway_Stripe::key_mode() );

		update_option( 'g2ab_stripe_test_mode', 0 );
		$this->assertFalse( G2AB_Gateway_Stripe::is_test_mode() );

		update_option( 'g2ab_stripe_test_mode', 1 );
		$this->assertTrue( G2AB_Gateway_Stripe::is_test_mode() );
	}

	public function testTheKeyPrefixOverridesAContradictoryOption(): void {
		update_option( 'g2ab_stripe_secret_key', 'sk_live_ExampleKeyValue' );
		update_option( 'g2ab_stripe_test_mode', 1 );   // stale/never-saved default

		$this->assertFalse(
			G2AB_Gateway_Stripe::is_test_mode(),
			'A live key means live mode however the option is set.'
		);
		$this->assertTrue( G2AB_Gateway_Stripe::mode_option_disagrees_with_key() );
	}

	public function testNoDisagreementIsReportedWhenTheOptionMatchesTheKey(): void {
		update_option( 'g2ab_stripe_secret_key', 'sk_live_ExampleKeyValue' );
		update_option( 'g2ab_stripe_test_mode', 0 );
		$this->assertFalse( G2AB_Gateway_Stripe::mode_option_disagrees_with_key() );

		update_option( 'g2ab_stripe_secret_key', 'sk_test_ExampleKeyValue' );
		update_option( 'g2ab_stripe_test_mode', 1 );
		$this->assertFalse( G2AB_Gateway_Stripe::mode_option_disagrees_with_key() );
	}

	public function testNoDisagreementIsReportedWhenTheKeyCannotAnswer(): void {
		update_option( 'g2ab_stripe_secret_key', '' );
		update_option( 'g2ab_stripe_test_mode', 1 );

		$this->assertFalse(
			G2AB_Gateway_Stripe::mode_option_disagrees_with_key(),
			'An unreadable key is not a disagreement — do not nag with an unactionable notice.'
		);
	}

	/* ═════════ P1-6 · every switch that can stop the money is audited ═════════ */

	public function testEveryPaymentCapabilitySwitchIsWatched(): void {
		$watched = g2ab_payment_capability_options();

		foreach ( array(
			'g2ab_addons_active',          // removing 'stripe' unregisters the gateway
			'g2ab_stripe_enabled',         // is_available() reads this
			'g2ab_stripe_secret_key',      // is_available() reads this
			'g2ab_stripe_test_mode',       // decides which Stripe account transacts
			'g2ab_payment_gateway_default',
		) as $option ) {
			$this->assertContains(
				$option,
				$watched,
				"Changing '{$option}' can stop the site collecting money and must leave an audit record."
			);
		}
	}

	/* ═════════ Invariant 2 · booking state eligibility ═════════ */

	public function testAlreadyTerminalBookingCannotBeReactivatedByAPayment(): void {
		update_option( 'g2ab_stripe_test_mode', 0 );

		foreach ( array( 'cancelled', 'refunded', 'partially_refunded', 'completed', 'no_show' ) as $status ) {
			$booking         = $this->paidBooking();
			$booking->status = $status;

			$result = G2AB_Payment_Validator::validate_stripe_checkout_session(
				$this->stripeSession(),
				$booking,
				$this->paymentAttempt()
			);

			$this->assertTrue( is_wp_error( $result ), "A {$status} booking must not be payment-activated." );
			$this->assertSame( 'g2ab_payment_state_ineligible', $result->get_error_code() );
		}
	}

	public function testExpectedDueNowUsesTheStoredDueNowNotTheTotal(): void {
		$booking           = $this->paidBooking();
		$booking->metadata = wp_json_encode( array( 'due_now' => 10.0 ) );

		$this->assertSame( 1000, G2AB_Payment_Validator::expected_due_now_minor( $booking ) );
	}

	/* ═════════ Invariant 1 & 4 · allowed state transitions ═════════ */

	public function testUnpaidStatesCannotJumpStraightToCompleted(): void {
		$this->assertFalse( G2AB_Booking_Transitions::is_allowed( 'pending', 'completed' ) );
		$this->assertFalse( G2AB_Booking_Transitions::is_allowed( 'expired', 'completed' ) );
		$this->assertFalse( G2AB_Booking_Transitions::is_allowed( 'cancelled', 'paid' ) );
	}

	public function testPaidIsReachableOnlyFromPrePaymentStates(): void {
		foreach ( array( 'pending', 'reserved', 'confirmed', 'expired', 'payment_failed' ) as $from ) {
			$this->assertTrue(
				G2AB_Booking_Transitions::is_allowed( $from, 'paid' ),
				"{$from} → paid must remain reachable (webhook / desk collection)."
			);
		}
	}

	/* ═════════ Invariant 5 · no surface may claim PAID without evidence ═════════ */

	public function testPublicPayAtStoreRowIsNeverOperational(): void {
		$row = array( 'status' => 'reserved', 'payment_mode' => 'in_store', 'source' => 'web' );

		$this->assertFalse(
			G2AB_Booking_Visibility::is_operational( (object) $row ),
			'A public web pay-at-store row is an artefact of the incident, never operational revenue.'
		);
	}

	public function testStaffRecordedPayAtStoreRowRemainsOperational(): void {
		foreach ( array( 'admin', 'frontdesk', 'pos', 'phone', 'walk_in' ) as $source ) {
			$this->assertTrue(
				G2AB_Booking_Visibility::is_operational(
					(object) array( 'status' => 'reserved', 'payment_mode' => 'in_store', 'source' => $source )
				),
				"A {$source} walk-in is a legitimate pay-at-desk booking."
			);
		}
	}

	public function testOperationalSqlExcludesWebPayAtStore(): void {
		$sql = G2AB_Booking_Visibility::operational_sql( 'b' );

		$this->assertStringContainsString( "b.source IN (", $sql, 'The staff-source restriction is the fix for RC-5.' );
		$this->assertStringNotContainsString( "b.status = 'pending'", $sql, 'Checkout holds must never be operational.' );
	}

	public function testStatusLabelNeverSaysPaidForAnUnpaidRow(): void {
		$label = G2AB_Booking_Visibility::status_label(
			(object) array( 'status' => 'reserved', 'payment_mode' => 'in_store', 'source' => 'web' )
		);
		$this->assertSame( 'Pay at Store', $label );
		$this->assertStringNotContainsStringIgnoringCase( 'paid', $label );

		$hold = G2AB_Booking_Visibility::status_label( (object) array( 'status' => 'pending' ) );
		$this->assertSame( 'Checkout Hold', $hold );
		$this->assertStringNotContainsStringIgnoringCase( 'confirm', $hold );
	}

	public function testOnlyAPaidRowIsLabelledPaymentConfirmed(): void {
		$this->assertSame(
			'Payment Confirmed',
			G2AB_Booking_Visibility::status_label( (object) array( 'status' => 'paid' ) )
		);
	}

	/* ═════════ Diagnostic statuses stay off rosters ═════════ */

	public function testCheckoutHoldsAreDiagnosticNotOperational(): void {
		foreach ( G2AB_Booking_Visibility::diagnostic_statuses() as $status ) {
			$this->assertNotContains(
				$status,
				G2AB_Booking_Visibility::operational_statuses(),
				"'{$status}' must not be both diagnostic and operational."
			);
			$this->assertFalse(
				G2AB_Booking_Visibility::is_operational( (object) array( 'status' => $status, 'source' => 'web' ) )
			);
		}
	}
}
