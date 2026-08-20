<?php
/**
 * Fraud controls.
 *
 * Self-referral is blocked on four independent vectors because any single
 * one is trivially defeated: a second email beats a user_id check, a fresh
 * account beats an email check, a phone hotspot beats a device check.
 *
 * @package G2AR
 */

declare( strict_types=1 );

namespace WordPressistic\G2AReferrals\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2AReferrals\Database\Conversions_Repository;
use WordPressistic\G2AReferrals\Database\Visits_Repository;
use WordPressistic\G2AReferrals\Fraud;
use WordPressistic\G2AReferrals\Settings;

final class FraudTest extends TestCase {

	private array $referrer;

	protected function setUp(): void {
		g2ar_test_reset();

		$this->referrer = array(
			'id'      => 3,
			'user_id' => 7,
			'status'  => 'active',
		);

		$GLOBALS['g2ar_test_users'][7] = (object) array(
			'ID'         => 7,
			'user_email' => 'member@example.test',
		);
	}

	public function test_same_user_is_rejected(): void {
		$this->assertSame( 'self_referral_user', Fraud::self_referral_reason( $this->referrer, 7 ) );
	}

	public function test_same_email_is_rejected_even_from_a_new_account(): void {
		$this->assertSame(
			'self_referral_email',
			Fraud::self_referral_reason( $this->referrer, 99, 'MEMBER@example.test' )
		);
	}

	public function test_same_device_fingerprint_is_rejected(): void {
		$hash = str_repeat( 'b', 64 );
		$GLOBALS['g2ar_test_usermeta'][7]['g2ar_visitor_hashes'] = array( $hash );

		$this->assertSame(
			'self_referral_device',
			Fraud::self_referral_reason( $this->referrer, 99, 'other@example.test', $hash )
		);
	}

	public function test_same_payment_instrument_is_rejected(): void {
		$GLOBALS['g2ar_test_usermeta'][7]['g2ar_payment_fingerprints'] = array( 'cus_abc123' );

		$this->assertSame(
			'self_referral_payment',
			Fraud::self_referral_reason( $this->referrer, 99, 'other@example.test', '', 'cus_abc123' )
		);
	}

	public function test_a_genuine_referral_passes_all_four_vectors(): void {
		$this->assertSame(
			'',
			Fraud::self_referral_reason( $this->referrer, 99, 'friend@example.test', str_repeat( 'c', 64 ), 'cus_zzz' )
		);
	}

	/* ── Monthly cap ───────────────────────────────────────────────── */

	public function test_monthly_cap_blocks_at_the_configured_limit(): void {
		Settings::$values['referral_cap_per_month'] = 5;

		Conversions_Repository::$rewarded_this_month = 4;
		$this->assertFalse( Fraud::monthly_cap_reached( 3 ) );

		Conversions_Repository::$rewarded_this_month = 5;
		$this->assertTrue( Fraud::monthly_cap_reached( 3 ), 'The fifth reward is the last one allowed.' );
	}

	public function test_a_zero_cap_means_no_cap(): void {
		Settings::$values['referral_cap_per_month'] = 0;
		Conversions_Repository::$rewarded_this_month = 500;

		$this->assertFalse( Fraud::monthly_cap_reached( 3 ) );
	}

	/* ── Review flags ──────────────────────────────────────────────── */

	public function test_many_conversions_from_one_device_are_flagged_for_review(): void {
		Visits_Repository::$conversions_for_visitor = 4;

		$this->assertSame(
			'many_conversions_one_device',
			Fraud::review_reason( $this->referrer, str_repeat( 'd', 64 ) )
		);
	}

	public function test_high_daily_volume_is_flagged_for_review(): void {
		Conversions_Repository::$since_count = 11;

		$this->assertSame( 'referrer_daily_volume', Fraud::review_reason( $this->referrer ) );
	}

	public function test_normal_activity_is_not_flagged(): void {
		Visits_Repository::$conversions_for_visitor = 1;
		Conversions_Repository::$since_count        = 2;

		$this->assertSame( '', Fraud::review_reason( $this->referrer, str_repeat( 'e', 64 ) ) );
	}

	public function test_remember_caps_stored_fingerprints_at_ten(): void {
		for ( $i = 0; $i < 15; $i++ ) {
			Fraud::remember( 7, 'g2ar_visitor_hashes', 'hash-' . $i );
		}

		$stored = $GLOBALS['g2ar_test_usermeta'][7]['g2ar_visitor_hashes'];

		$this->assertCount( 10, $stored, 'User meta must not become a tracking log.' );
		$this->assertSame( 'hash-14', end( $stored ) );
	}
}
