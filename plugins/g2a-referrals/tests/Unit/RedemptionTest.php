<?php
/**
 * Guest Pass redemption.
 *
 * The first test in this file is the one that matters most in the whole
 * plugin: members hold member_discount = 100.00 on lane bookings, so their
 * lane time is already free. If redemption ran blind, a member would burn a
 * hard-earned reward on a booking that cost nothing — the angriest
 * front-desk conversation this feature can generate.
 *
 * @package G2AR
 */

declare( strict_types=1 );

namespace WordPressistic\G2AReferrals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2AReferrals\Database\Rewards_Repository;
use WordPressistic\G2AReferrals\Redemption;

final class RedemptionTest extends TestCase {

	protected function setUp(): void {
		g2ar_test_reset();

		// A member with one pass, opted in, on an eligible lane booking.
		Rewards_Repository::$balances['7|guest_pass'] = 1.0;
		Redemption::set_opt_in( true );
	}

	/**
	 * A lane booking type.
	 *
	 * @param int $id Booking type id.
	 * @return object
	 */
	private function bookingType( int $id = 1 ): object {
		return (object) array(
			'id'              => $id,
			'base_price'      => 20.00,
			'member_discount' => 100.00,
		);
	}

	/**
	 * Pricing as the booking engine hands it to the filter.
	 *
	 * @param float $subtotal Subtotal.
	 * @param float $discount Discount already applied.
	 * @return array
	 */
	private function pricing( float $subtotal, float $discount ): array {
		return array(
			'subtotal'        => $subtotal,
			'discount_amount' => $discount,
			'total'           => round( $subtotal - $discount, 2 ),
			'discount_label'  => '',
		);
	}

	/* ── THE RULE ──────────────────────────────────────────────────── */

	public function test_pass_is_not_consumed_when_total_is_already_zero(): void {
		// Memberistic has already taken the lane to $0 at priority 11.
		$pricing = $this->pricing( 20.00, 20.00 );

		$result = Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 1, 7 );

		$this->assertSame( 0.0, $result['total'], 'A free booking must stay free.' );
		$this->assertSame( 20.00, $result['discount_amount'], 'The discount must not be inflated.' );
		$this->assertArrayNotHasKey( 'guest_pass_applied', $result, 'No pass may be applied to a $0 booking.' );
		$this->assertSame( 'already_free', $result['guest_pass_skipped'] );
	}

	public function test_zero_total_booking_writes_no_ledger_row_on_commit(): void {
		$pricing = $this->pricing( 20.00, 20.00 );
		Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 1, 7 );

		Redemption::on_booking_created( 4242, array() );

		$this->assertSame( array(), Rewards_Repository::$written, 'A $0 booking must never spend a pass.' );
	}

	public function test_pass_applies_one_seat_when_the_booking_actually_costs_money(): void {
		// Two seats at $20, member covers only their own: $20 left to pay.
		$pricing = $this->pricing( 40.00, 20.00 );

		$result = Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 2, 7 );

		$this->assertSame( 1, $result['guest_pass_applied'] );
		$this->assertSame( 20.00, $result['guest_pass_credit'], 'The credit is one seat, not the whole booking.' );
		$this->assertSame( 40.00, $result['discount_amount'] );
		$this->assertSame( 0.0, $result['total'] );
	}

	public function test_credit_never_exceeds_what_is_still_owed(): void {
		// One seat at $20 with a $15 discount already applied: $5 owed.
		$pricing = $this->pricing( 20.00, 15.00 );

		$result = Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 1, 7 );

		$this->assertSame( 5.00, $result['guest_pass_credit'] );
		$this->assertSame( 0.0, $result['total'] );
		$this->assertSame( 20.00, $result['discount_amount'] );
	}

	/* ── Opt-in ────────────────────────────────────────────────────── */

	public function test_nothing_happens_without_the_opt_in(): void {
		Redemption::set_opt_in( false );

		$pricing = $this->pricing( 40.00, 0.00 );
		$result  = Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 2, 7 );

		$this->assertSame( $pricing, $result, 'Redemption is opt-in per booking, never automatic.' );
	}

	public function test_nothing_happens_for_a_logged_out_visitor(): void {
		$pricing = $this->pricing( 40.00, 0.00 );
		$result  = Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 2, 0 );

		$this->assertSame( $pricing, $result );
	}

	public function test_nothing_happens_without_a_balance(): void {
		Rewards_Repository::$balances['7|guest_pass'] = 0.0;

		$pricing = $this->pricing( 40.00, 0.00 );
		$result  = Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 2, 7 );

		$this->assertArrayNotHasKey( 'guest_pass_applied', $result );
	}

	public function test_ineligible_booking_types_are_skipped(): void {
		// Booking type 3 is not in redemption_booking_type_ids.
		$pricing = $this->pricing( 40.00, 0.00 );
		$result  = Redemption::filter_booking_pricing( $pricing, $this->bookingType( 3 ), 2, 7 );

		$this->assertArrayNotHasKey( 'guest_pass_applied', $result );
	}

	/* ── Commit ────────────────────────────────────────────────────── */

	public function test_commit_writes_exactly_one_redeem_row(): void {
		$pricing = $this->pricing( 40.00, 20.00 );
		Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 2, 7 );

		Redemption::on_booking_created( 900, array() );

		$this->assertCount( 1, Rewards_Repository::$written );

		$row = Rewards_Repository::$written[0];
		$this->assertSame( 7, $row['user_id'] );
		$this->assertSame( 'redeem', $row['direction'] );
		$this->assertSame( 'guest_pass', $row['reward_type'] );
		$this->assertSame( 1, $row['amount'] );
		$this->assertSame( 900, $row['booking_id'] );
	}

	public function test_commit_is_declined_when_the_balance_vanished_mid_request(): void {
		$pricing = $this->pricing( 40.00, 20.00 );
		Redemption::filter_booking_pricing( $pricing, $this->bookingType(), 2, 7 );

		// Two tabs submitted together: the other one spent the pass first.
		Rewards_Repository::$balances['7|guest_pass'] = 0.0;

		Redemption::on_booking_created( 901, array() );

		$this->assertSame( array(), Rewards_Repository::$written );
	}

	public function test_a_display_preview_never_arms_a_consumption(): void {
		$pricing = $this->pricing( 40.00, 20.00 );

		// Only the display filter runs — this is the booking form preview.
		Redemption::filter_display_pricing( $pricing, $this->bookingType(), 2, 7 );
		Redemption::on_booking_created( 902, array() );

		$this->assertSame( array(), Rewards_Repository::$written, 'Previewing a price must not spend anything.' );
	}

	/* ── Hook priority ─────────────────────────────────────────────── */

	public function test_redemption_hooks_at_priority_12(): void {
		Redemption::register();

		$hooks = $GLOBALS['g2ar_test_filters'];

		$this->assertArrayHasKey( 'g2ab_booking_pricing', $hooks );
		$this->assertArrayHasKey( 'g2ab_booking_display_pricing', $hooks );

		// Memberistic sits at 11 on both filters and only overwrites when
		// its own discount is larger, so redemption must run after it.
		$this->assertSame( 12, $hooks['g2ab_booking_pricing'][0]['priority'] );
		$this->assertSame( 12, $hooks['g2ab_booking_display_pricing'][0]['priority'] );
	}
}
