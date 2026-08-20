<?php
/**
 * Granting rewards.
 *
 * @package G2AR
 */

declare( strict_types=1 );

namespace WordPressistic\G2AReferrals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Rewards_Service;
use WordPressistic\G2AReferrals\Settings;

final class RewardsServiceTest extends TestCase {

	private array $conversion;
	private array $referrer;

	protected function setUp(): void {
		g2ar_test_reset();

		$this->conversion = array(
			'id'                   => 11,
			'referrer_id'          => 3,
			'friend_user_id'       => 42,
			'friend_membership_id' => 0,
			'status'               => 'qualified',
		);

		$this->referrer = array(
			'id'            => 3,
			'user_id'       => 7,
			'membership_id' => 0,
			'status'        => 'active',
		);
	}

	/** Pretend this user holds the given plan. */
	private function givePlan( int $user_id, int $plan_id ): void {
		$GLOBALS['g2ar_test_plan'][ $user_id ] = $plan_id;
	}

	public function test_a_normal_referral_grants_a_pass_and_a_free_month(): void {
		Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer );
		Rewards_Service::grant_friend_reward( $this->conversion );

		$rows = Rewards_Repository::$written;

		$this->assertCount( 2, $rows );

		$this->assertSame( 7, $rows[0]['user_id'] );
		$this->assertSame( 'guest_pass', $rows[0]['reward_type'] );
		$this->assertSame( 'grant', $rows[0]['direction'] );

		$this->assertSame( 42, $rows[1]['user_id'] );
		$this->assertSame( 'membership_days', $rows[1]['reward_type'] );
		$this->assertSame( 'grant', $rows[1]['direction'] );
	}

	/**
	 * Regression: when the referrer is on a non-member plan they earn a free
	 * month too, so BOTH sides of one conversion write a membership_days
	 * grant. A duplicate check that matched on (source, source_id, type)
	 * alone treated the friend's row as a repeat of the referrer's and
	 * dropped it — the friend silently lost their reward.
	 */
	public function test_both_sides_are_granted_when_referrer_and_friend_earn_the_same_reward_type(): void {
		$this->givePlan( 7, 5 );

		Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer );
		Rewards_Service::grant_friend_reward( $this->conversion );

		$rows = Rewards_Repository::$written;

		$this->assertCount( 2, $rows, "The friend's free month must not be mistaken for the referrer's." );
		$this->assertSame( 'membership_days', $rows[0]['reward_type'] );
		$this->assertSame( 7, $rows[0]['user_id'] );
		$this->assertSame( 'membership_days', $rows[1]['reward_type'] );
		$this->assertSame( 42, $rows[1]['user_id'] );
	}

	public function test_a_repeat_grant_for_the_same_conversion_is_refused(): void {
		Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer );
		Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer );

		$this->assertCount( 1, Rewards_Repository::$written, 'A retry must never double-reward.' );
	}

	public function test_a_repeat_friend_grant_for_the_same_conversion_is_refused(): void {
		Rewards_Service::grant_friend_reward( $this->conversion );
		Rewards_Service::grant_friend_reward( $this->conversion );

		$this->assertCount( 1, Rewards_Repository::$written );
	}

	public function test_guest_pass_grants_carry_the_configured_expiry(): void {
		Settings::$values['guest_pass_expiry_days'] = 90;

		Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer );

		$expiry = Rewards_Repository::$written[0]['expires_at'];

		$this->assertNotNull( $expiry );
		$this->assertEqualsWithDelta(
			time() + ( 90 * DAY_IN_SECONDS ),
			strtotime( $expiry . ' UTC' ),
			120,
			'Guest Passes must expire 90 days out by default — an unexpiring pass is unbounded liability.'
		);
	}

	public function test_a_zero_expiry_setting_means_the_pass_never_expires(): void {
		Settings::$values['guest_pass_expiry_days'] = 0;

		Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer );

		$this->assertNull( Rewards_Repository::$written[0]['expires_at'] );
	}

	public function test_free_months_never_carry_an_expiry(): void {
		Settings::$values['guest_pass_expiry_days'] = 90;

		$this->assertNull( Rewards_Service::expiry_for( 'membership_days' ) );
	}

	public function test_a_manual_grant_requires_a_reason(): void {
		$this->assertSame( 0, Rewards_Service::manual_grant( 7, 'guest_pass', 1, '   ' ) );
		$this->assertSame( array(), Rewards_Repository::$written );
	}

	public function test_a_manual_grant_records_the_reason_and_the_actor(): void {
		$GLOBALS['g2ar_test_current_user'] = 99;

		Rewards_Service::manual_grant( 7, 'guest_pass', 2, 'Goodwill after a lane outage' );

		$row = Rewards_Repository::$written[0];

		$this->assertSame( 'manual', $row['source'] );
		$this->assertSame( 'Goodwill after a lane outage', $row['note'] );
		$this->assertSame( 99, $row['actor_id'] );
		$this->assertSame( 2, $row['amount'] );
	}

	public function test_a_non_member_plan_holder_earns_a_free_month_not_a_pass(): void {
		$this->givePlan( 7, 5 );

		$this->assertSame( 'membership_days', Rewards_Service::referrer_reward_type( 7 ) );
	}

	public function test_a_normal_member_earns_a_guest_pass(): void {
		$this->givePlan( 7, 2 );

		$this->assertSame( 'guest_pass', Rewards_Service::referrer_reward_type( 7 ) );
	}

	public function test_non_member_plans_can_be_barred_from_referring_entirely(): void {
		Settings::$values['non_member_plans_may_refer'] = 'no';
		$this->givePlan( 7, 5 );

		$this->assertSame( '', Rewards_Service::referrer_reward_type( 7 ) );
		$this->assertSame( 0, Rewards_Service::grant_referrer_reward( $this->conversion, $this->referrer ) );
	}
}
