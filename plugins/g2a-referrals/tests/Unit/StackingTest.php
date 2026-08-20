<?php
/**
 * Offer stacking.
 *
 * A referred friend could otherwise combine the referral reward, the "join
 * and save" banner offer and a member discount on one membership. Best
 * single offer wins, never combine, with a floor underneath.
 *
 * @package G2AR
 */

declare( strict_types=1 );

namespace WordPressistic\G2AReferrals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2AReferrals\Settings;
use WordPressistic\G2AReferrals\Stacking;

final class StackingTest extends TestCase {

	protected function setUp(): void {
		g2ar_test_reset();
	}

	public function test_the_largest_single_offer_wins_and_the_rest_are_dropped(): void {
		$offers = array(
			array( 'id' => 'banner_10pc', 'label' => '10% off', 'amount' => 30.00 ),
			array( 'id' => 'referral', 'label' => 'Referral', 'amount' => 59.99 ),
			array( 'id' => 'member', 'label' => 'Member', 'amount' => 15.00 ),
		);

		$result = Stacking::resolve( $offers, 299.99 );

		$this->assertSame( 'referral', $result['offer']['id'] );
		$this->assertSame( 240.00, $result['price'] );
		$this->assertCount( 2, $result['rejected'], 'The other two offers must not also apply.' );
	}

	public function test_offers_are_never_summed(): void {
		$offers = array(
			array( 'id' => 'a', 'amount' => 20.00 ),
			array( 'id' => 'b', 'amount' => 20.00 ),
			array( 'id' => 'c', 'amount' => 20.00 ),
		);

		$result = Stacking::resolve( $offers, 100.00 );

		$this->assertSame( 80.00, $result['price'], 'Three $20 offers must take $20 off, not $60.' );
	}

	public function test_the_floor_is_absolute(): void {
		Settings::$values['membership_price_floor'] = 25.00;

		$offers = array( array( 'id' => 'huge', 'amount' => 290.00 ) );
		$result = Stacking::resolve( $offers, 299.99 );

		$this->assertSame( 25.00, $result['price'] );
		$this->assertTrue( $result['offer']['capped'] );
		$this->assertSame( 274.99, $result['offer']['amount'], 'The applied amount is trimmed to the floor, not the price.' );
	}

	public function test_no_offers_leaves_the_list_price_alone(): void {
		$result = Stacking::resolve( array(), 299.99 );

		$this->assertNull( $result['offer'] );
		$this->assertSame( 299.99, $result['price'] );
	}

	public function test_zero_and_negative_offers_are_ignored(): void {
		$offers = array(
			array( 'id' => 'zero', 'amount' => 0 ),
			array( 'id' => 'negative', 'amount' => -50 ),
		);

		$result = Stacking::resolve( $offers, 100.00 );

		$this->assertNull( $result['offer'] );
		$this->assertSame( 100.00, $result['price'] );
	}

	public function test_price_never_goes_below_zero_without_a_floor(): void {
		$offers = array( array( 'id' => 'over', 'amount' => 500.00 ) );
		$result = Stacking::resolve( $offers, 299.99 );

		$this->assertGreaterThanOrEqual( 0.0, $result['price'] );
	}

	public function test_enforce_collapses_a_checkout_pricing_array(): void {
		$pricing = array(
			'list_price' => 299.99,
			'offers'     => array(
				array( 'id' => 'banner', 'amount' => 30.00 ),
				array( 'id' => 'referral', 'amount' => 59.99 ),
			),
		);

		$result = Stacking::enforce( $pricing, array( 'membership_id' => 5 ) );

		$this->assertCount( 1, $result['offers'] );
		$this->assertSame( 'referral', $result['offers'][0]['id'] );
		$this->assertSame( 59.99, $result['discount_amount'] );
		$this->assertSame( 240.00, $result['total'] );
		$this->assertCount( 1, $result['rejected_offers'] );
	}
}
