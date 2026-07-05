<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Membership_Provider;

class MembershipProviderMonthlyTest extends TestCase {
	public function test_monthly_cycle_uses_billing_amount_verbatim() {
		$this->assertSame( 2999, Membership_Provider::monthly_cents( 'monthly', '29.99', '35.00', '350.00' ) );
	}

	public function test_annual_cycle_is_normalised_to_twelfths() {
		$this->assertSame( 2500, Membership_Provider::monthly_cents( 'annual', '300.00', null, null ) );
		$this->assertSame( 2500, Membership_Provider::monthly_cents( 'yearly', '300.00', null, null ) );
	}

	public function test_missing_billing_amount_falls_back_to_plan_price() {
		$this->assertSame( 3500, Membership_Provider::monthly_cents( 'monthly', null, '35.00', '350.00' ) );
		$this->assertSame( 2917, Membership_Provider::monthly_cents( 'annual', null, '35.00', '350.00' ) );
	}

	public function test_unknown_cycle_is_treated_as_monthly() {
		$this->assertSame( 4200, Membership_Provider::monthly_cents( '', '42.00', null, null ) );
		$this->assertSame( 4200, Membership_Provider::monthly_cents( 'weird', '42.00', null, null ) );
	}

	public function test_no_price_information_yields_zero() {
		$this->assertSame( 0, Membership_Provider::monthly_cents( 'monthly', null, null, null ) );
	}

	public function test_plan_performance_groups_and_sorts_by_revenue() {
		$rows = array(
			array( 'plan' => 'Defender', 'billing_cycle' => 'monthly', 'billing_amount' => '20.00', 'monthly_price' => null, 'annual_price' => null ),
			array( 'plan' => 'Defender', 'billing_cycle' => 'monthly', 'billing_amount' => '20.00', 'monthly_price' => null, 'annual_price' => null ),
			array( 'plan' => 'Patriot',  'billing_cycle' => 'annual',  'billing_amount' => '600.00', 'monthly_price' => null, 'annual_price' => null ),
		);
		$out = Membership_Provider::plan_performance( $rows );

		$this->assertSame( 'Patriot', $out[0]['plan'] );
		$this->assertSame( 1, $out[0]['active'] );
		$this->assertSame( 5000, $out[0]['revenue'] ); // 600/yr → 50/mo.
		$this->assertSame( 'Defender', $out[1]['plan'] );
		$this->assertSame( 2, $out[1]['active'] );
		$this->assertSame( 4000, $out[1]['revenue'] );
	}
}
