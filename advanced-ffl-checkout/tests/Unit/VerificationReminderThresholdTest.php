<?php

namespace WpisticFFL\Tests\Unit;

use WpisticFFL\G2A_Verification_Phase_B;

require_once dirname( __DIR__, 2 ) . '/includes/class-wpistic-ffl-g2a-verification-phase-b.php';

final class VerificationReminderThresholdTest extends TestCase {

	public function test_picks_60_day_threshold_when_nothing_sent_yet(): void {
		$this->assertSame( 60, G2A_Verification_Phase_B::pick_threshold( 45, [] ) );
	}

	public function test_picks_the_tightest_matching_threshold_not_the_first(): void {
		// 5 days left matches the 60-, 30-, and 7-day buckets -- the tightest
		// (7) must win so the email doesn't understate the urgency.
		$this->assertSame( 7, G2A_Verification_Phase_B::pick_threshold( 5, [] ) );
	}

	public function test_skips_thresholds_already_sent(): void {
		$this->assertSame( 30, G2A_Verification_Phase_B::pick_threshold( 5, [ 7 => true ] ) );
	}

	public function test_returns_null_when_far_from_expiring(): void {
		$this->assertNull( G2A_Verification_Phase_B::pick_threshold( 90, [] ) );
	}

	public function test_returns_null_once_every_threshold_already_sent(): void {
		$this->assertNull( G2A_Verification_Phase_B::pick_threshold( -5, [ 0 => true, 7 => true, 30 => true, 60 => true ] ) );
	}

	public function test_zero_threshold_covers_an_already_expired_document(): void {
		$this->assertSame( 0, G2A_Verification_Phase_B::pick_threshold( -10, [] ) );
	}
}
