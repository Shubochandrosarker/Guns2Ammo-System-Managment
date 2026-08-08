<?php
/**
 * Unit tests for WordPressistic\Memberistic\Integrations\Entitlement_Service.
 *
 * Repository classes are stubbed in tests/bootstrap.php with configurable
 * static fixtures; no database or live WordPress is involved.
 */

use PHPUnit\Framework\TestCase;
use WordPressistic\Memberistic\Database\Memberships_Repository;
use WordPressistic\Memberistic\Database\People_Repository;
use WordPressistic\Memberistic\Database\Plans_Repository;
use WordPressistic\Memberistic\Integrations\Entitlement_Service;

final class EntitlementServiceTest extends TestCase {

	protected function setUp(): void {
		memberistic_tests_reset_state();
	}

	private function seedPlan( int $id, string $slug, string $name = '' ): void {
		Plans_Repository::$by_id[ $id ] = array(
			'id'   => $id,
			'slug' => $slug,
			'name' => $name ?: ucfirst( $slug ),
		);
	}

	private function seedPrimaryMembership( int $user_id, array $overrides = array() ): array {
		$membership = array_merge( array(
			'id'           => 11,
			'user_id'      => $user_id,
			'plan_id'      => 3,
			'status'       => 'active',
			'renewal_date' => '2030-01-01',
		), $overrides );
		Memberships_Repository::$by_user_id[ $user_id ]        = $membership;
		Memberships_Repository::$by_id[ $membership['id'] ]    = $membership;
		return $membership;
	}

	/* ── included_plan_slugs() ── */

	public function testIncludedPlanSlugsDefaults(): void {
		$this->assertSame( array( 'defender', 'patriot', 'guardian' ), Entitlement_Service::included_plan_slugs() );
	}

	public function testIncludedPlanSlugsStripsGuestPassInjectedViaOption(): void {
		update_option( 'memberistic_lane_included_plan_slugs', array( 'defender', 'guest-pass', 'range-guest', 'patriot' ) );
		$slugs = Entitlement_Service::included_plan_slugs();

		$this->assertNotContains( 'guest-pass', $slugs );
		$this->assertNotContains( 'range-guest', $slugs );
		$this->assertSame( array( 'defender', 'patriot' ), $slugs );
	}

	public function testIncludedPlanSlugsStripsGuestPassInjectedViaFilter(): void {
		add_filter( 'memberistic_lane_included_plan_slugs', static function ( $slugs ) {
			$slugs[] = 'guest-pass';
			$slugs[] = 'Guest-Pass'; // case games don't help either
			return $slugs;
		} );

		$slugs = Entitlement_Service::included_plan_slugs();
		$this->assertNotContains( 'guest-pass', $slugs );
		$this->assertContains( 'defender', $slugs );
	}

	public function testIncludedPlanSlugsEmptyOptionFallsBackToDefaults(): void {
		update_option( 'memberistic_lane_included_plan_slugs', array() );
		$this->assertSame( array( 'defender', 'patriot', 'guardian' ), Entitlement_Service::included_plan_slugs() );
	}

	/* ── eligible_statuses() ── */

	public function testEligibleStatusesDefaults(): void {
		$this->assertSame( array( 'active', 'comped' ), Entitlement_Service::eligible_statuses() );
	}

	/* ── resolve_for_user() ── */

	public function testAnonymousUserIsNotAuthenticatedAndIneligible(): void {
		$result = Entitlement_Service::resolve_for_user( 0 );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_NOT_AUTHENTICATED, $result['reason'] );
		$this->assertSame( 'public_full_price', $result['pricing_type'] );
		$this->assertSame( 0, $result['membership_id'] );
	}

	public function testUserWithoutMembershipIsIneligible(): void {
		$result = Entitlement_Service::resolve_for_user( 42 );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_NO_MEMBERSHIP, $result['reason'] );
	}

	public function testPrimaryMemberOnActiveDefenderIsEligible(): void {
		$this->seedPlan( 3, 'defender' );
		$this->seedPrimaryMembership( 7 );

		$result = Entitlement_Service::resolve_for_user( 7 );

		$this->assertTrue( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_INCLUDED, $result['reason'] );
		$this->assertSame( 'member_included', $result['pricing_type'] );
		$this->assertSame( 0.0, $result['amount_due'] );
		$this->assertSame( 'defender', $result['plan_slug'] );
		$this->assertSame( 11, $result['membership_id'] );
		$this->assertSame( 'member_included', $result['allowed_gateway'] );
	}

	public function testGuestPassPlanIsNotEligible(): void {
		$this->seedPlan( 5, 'guest-pass', 'Guest Pass' );
		$this->seedPrimaryMembership( 7, array( 'plan_id' => 5 ) );

		$result = Entitlement_Service::resolve_for_user( 7 );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_PLAN_INELIGIBLE, $result['reason'] );
		$this->assertNotSame( 0.0, $result['amount_due'] );
	}

	/**
	 * @dataProvider ineligibleStatusProvider
	 */
	public function testIneligibleStatusesAreRejected( string $status ): void {
		$this->seedPlan( 3, 'defender' );
		$this->seedPrimaryMembership( 7, array( 'status' => $status ) );

		$result = Entitlement_Service::resolve_for_user( 7 );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_STATUS_INELIGIBLE, $result['reason'] );
	}

	public static function ineligibleStatusProvider(): array {
		return array(
			'trial'    => array( 'trial' ),
			'past_due' => array( 'past_due' ),
			'expired'  => array( 'expired' ),
		);
	}

	public function testExpiredRenewalDateIsRejected(): void {
		$this->seedPlan( 3, 'defender' );
		$this->seedPrimaryMembership( 7, array( 'status' => 'active', 'renewal_date' => '2020-01-01' ) );

		$result = Entitlement_Service::resolve_for_user( 7 );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_EXPIRED, $result['reason'] );
	}

	public function testEmptyRenewalDateMeansNonExpiring(): void {
		$this->seedPlan( 3, 'defender' );
		$this->seedPrimaryMembership( 7, array( 'renewal_date' => '' ) );

		$result = Entitlement_Service::resolve_for_user( 7 );
		$this->assertTrue( $result['eligible'] );
	}

	public function testLinkedActivePersonQualifiesThroughOwnAccount(): void {
		$this->seedPlan( 3, 'defender' );
		// Membership belongs to primary user 7; user 9 is a linked person.
		$membership = $this->seedPrimaryMembership( 7 );
		People_Repository::$by_wp_user_id[9] = array(
			'id'            => 21,
			'wp_user_id'    => 9,
			'membership_id' => $membership['id'],
			'status'        => 'active',
		);

		$result = Entitlement_Service::resolve_for_user( 9 );

		$this->assertTrue( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_INCLUDED, $result['reason'] );
		$this->assertSame( $membership['id'], $result['membership_id'] );
	}

	public function testLinkedInactivePersonIsRejected(): void {
		$this->seedPlan( 3, 'defender' );
		$membership = $this->seedPrimaryMembership( 7 );
		People_Repository::$by_wp_user_id[9] = array(
			'id'            => 21,
			'wp_user_id'    => 9,
			'membership_id' => $membership['id'],
			'status'        => 'inactive',
		);

		$result = Entitlement_Service::resolve_for_user( 9 );

		$this->assertFalse( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_PERSON_INACTIVE, $result['reason'] );
	}

	/* ── filter_lane_entitlement() bridge ── */

	public function testFilterBridgePreservesEarlierEligibleResult(): void {
		// Another integration already granted a structured eligible result and
		// Memberistic finds nothing — it must not downgrade it.
		$earlier = array( 'eligible' => true, 'user_id' => 7, 'reason' => 'member_included' );
		$result  = Entitlement_Service::filter_lane_entitlement( $earlier, 7 );
		$this->assertSame( $earlier, $result );
	}

	public function testFilterBridgeAnswersAuthoritativelyWhenEligible(): void {
		$this->seedPlan( 3, 'defender' );
		$this->seedPrimaryMembership( 7 );

		$result = Entitlement_Service::filter_lane_entitlement( array( 'eligible' => false ), 7 );
		$this->assertTrue( $result['eligible'] );
		$this->assertSame( Entitlement_Service::REASON_INCLUDED, $result['reason'] );
	}
}
