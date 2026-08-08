<?php
/**
 * Unit tests for G2AB_Checkout_Policy (includes/services/class-checkout-policy.php).
 */

use PHPUnit\Framework\TestCase;

final class CheckoutPolicyTest extends TestCase {

	protected function setUp(): void {
		g2ab_tests_reset_state();
	}

	private function bookingType( float $base_price ): object {
		return (object) array(
			'id'         => 12,
			'base_price' => $base_price,
		);
	}

	/* ── Offline gateway detection ── */

	public function testOfflineGatewaysAreDetected(): void {
		foreach ( array( 'pay_in_store', 'cash', 'terminal', 'comp', 'manual' ) as $gateway ) {
			$this->assertTrue(
				G2AB_Checkout_Policy::is_offline_gateway( $gateway ),
				"Gateway '{$gateway}' must be classified offline."
			);
		}
	}

	public function testOnlineGatewaysAreNotOffline(): void {
		$this->assertFalse( G2AB_Checkout_Policy::is_offline_gateway( 'stripe' ) );
		$this->assertFalse( G2AB_Checkout_Policy::is_offline_gateway( 'paypal' ) );
		$this->assertFalse( G2AB_Checkout_Policy::is_offline_gateway( '' ) );
	}

	/* ── lane_entitlement() normalization ── */

	public function testLaneEntitlementNormalizesFilterOutput(): void {
		add_filter( 'g2ab_lane_entitlement', static function ( $seed, $user_id ) {
			return array_merge( $seed, array(
				'user_id'           => '7',           // string → int
				'membership_id'     => '11',
				'plan_id'           => '3',
				'plan_slug'         => 'Defender!',   // mixed case + punctuation → sanitize_key
				'plan_name'         => "  Defender \n",
				'membership_status' => 'ACTIVE',
				'eligible'          => 1,             // truthy → bool
				'reason'            => 'Member Included',
			) );
		}, 10, 2 );

		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 7 );

		$this->assertSame( 7, $entitlement['user_id'] );
		$this->assertSame( 11, $entitlement['membership_id'] );
		$this->assertSame( 3, $entitlement['plan_id'] );
		$this->assertSame( 'defender', $entitlement['plan_slug'] );
		$this->assertSame( 'Defender', $entitlement['plan_name'] );
		$this->assertSame( 'active', $entitlement['membership_status'] );
		$this->assertTrue( $entitlement['eligible'] );
		$this->assertSame( 'member_included', $entitlement['pricing_type'] );
	}

	public function testLaneEntitlementNonArrayFilterResultFallsBackToSeed(): void {
		add_filter( 'g2ab_lane_entitlement', static fn() => 'garbage' );

		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 5 );

		$this->assertFalse( $entitlement['eligible'] );
		$this->assertSame( 'public_full_price', $entitlement['pricing_type'] );
		$this->assertSame( 'no_membership_provider', $entitlement['reason'] );
	}

	public function testLaneEntitlementRejectsEligibleForMismatchedUserId(): void {
		add_filter( 'g2ab_lane_entitlement', static function ( $seed ) {
			// A buggy filter grants eligibility attributed to ANOTHER user.
			return array_merge( $seed, array(
				'eligible' => true,
				'user_id'  => 99,
				'reason'   => 'member_included',
			) );
		} );

		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 7 );

		$this->assertFalse( $entitlement['eligible'], 'Eligibility must never transfer between user ids.' );
		$this->assertSame( 'entitlement_user_mismatch', $entitlement['reason'] );
		$this->assertSame( 'public_full_price', $entitlement['pricing_type'] );
	}

	/* ── resolve_lane() ── */

	public function testResolveLaneRejectsPayInStoreOnPayableTotal(): void {
		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 0 );
		$result      = G2AB_Checkout_Policy::resolve_lane(
			$this->bookingType( 40.0 ),
			array( 'total' => 40.0 ),
			$entitlement,
			'pay_in_store'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_gateway_not_allowed', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
	}

	public function testResolveLaneZeroTotalWithPaidBaseAndNoEntitlementFailsClosed(): void {
		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 0 ); // not eligible
		$result      = G2AB_Checkout_Policy::resolve_lane(
			$this->bookingType( 40.0 ),          // paid booking type…
			array( 'total' => 0.0 ),             // …that somehow resolved to $0
			$entitlement,
			''
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_pricing_invalid', $result->get_error_code() );
	}

	public function testResolveLaneEligibleMemberIsFreeAndConfirmed(): void {
		add_filter( 'g2ab_lane_entitlement', static function ( $seed, $user_id ) {
			return array_merge( $seed, array(
				'eligible' => true,
				'user_id'  => $user_id,
				'reason'   => 'member_included',
			) );
		}, 10, 2 );
		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 7 );

		$result = G2AB_Checkout_Policy::resolve_lane(
			$this->bookingType( 40.0 ),
			array( 'total' => 0.0, 'membership_source' => 'memberistic' ),
			$entitlement,
			''
		);

		$this->assertIsArray( $result );
		$this->assertSame( 0.0, $result['amount_due'] );
		$this->assertSame( 'free', $result['payment_mode'] );
		$this->assertSame( 'free', $result['gateway_policy'] );
		$this->assertSame( 'confirmed', $result['initial_status'] );
		$this->assertSame( 'member_included', $result['zero_reason'] );
	}

	public function testResolveLaneZeroTotalWithoutMemberisticSourceFailsClosed(): void {
		// Eligible entitlement but the pricing engine did NOT attribute the $0
		// to memberistic — a paid base price must then fail closed.
		add_filter( 'g2ab_lane_entitlement', static function ( $seed, $user_id ) {
			return array_merge( $seed, array( 'eligible' => true, 'user_id' => $user_id ) );
		}, 10, 2 );
		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 7 );

		$result = G2AB_Checkout_Policy::resolve_lane(
			$this->bookingType( 40.0 ),
			array( 'total' => 0.0, 'membership_source' => 'coupon_hack' ),
			$entitlement,
			''
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'g2ab_pricing_invalid', $result->get_error_code() );
	}

	public function testResolveLanePayableIsPendingFullOnlineNeverDeposit(): void {
		$entitlement = G2AB_Checkout_Policy::lane_entitlement( 0 );

		$result = G2AB_Checkout_Policy::resolve_lane(
			$this->bookingType( 40.0 ),
			array( 'total' => 80.0 ),
			$entitlement,
			'stripe'
		);

		$this->assertIsArray( $result );
		$this->assertSame( 80.0, $result['amount_due'] );
		$this->assertSame( 'full', $result['payment_mode'], 'Public deposits are not allowed — always full.' );
		$this->assertSame( 'online', $result['gateway_policy'] );
		$this->assertSame( 'pending', $result['initial_status'], 'Payable bookings start as checkout holds.' );
		$this->assertNotSame( 'deposit', $result['payment_mode'] );
	}
}
