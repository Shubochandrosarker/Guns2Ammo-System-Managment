<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Membership_Renewal_Handler;

class MembershipRenewalHandlerTest extends TestCase {
	public function test_subject_line_shifts_with_urgency() {
		$this->assertSame(
			'Your Guns2Ammo membership renews tomorrow',
			Membership_Renewal_Handler::subject_for( 1 )
		);
		$this->assertSame(
			'A week until your Guns2Ammo renewal',
			Membership_Renewal_Handler::subject_for( 7 )
		);
		$this->assertSame(
			'Heads up — your Guns2Ammo membership renews in 30 days',
			Membership_Renewal_Handler::subject_for( 30 )
		);
	}

	public function test_body_singularises_at_one_day_out() {
		$body = Membership_Renewal_Handler::compose_body( 'Priya', 'Defender', '2026-07-10 00:00:00', 1 );
		$this->assertStringContainsString( '1 day away', $body );
		$this->assertStringNotContainsString( '1 days', $body );
	}

	public function test_body_plurals_for_larger_offsets() {
		$body = Membership_Renewal_Handler::compose_body( 'Priya', 'Defender', '2026-07-10 00:00:00', 7 );
		$this->assertStringContainsString( '7 days away', $body );
	}

	public function test_body_handles_empty_name_and_plan() {
		$body = Membership_Renewal_Handler::compose_body( '', '', '2026-07-10 00:00:00', 30 );
		$this->assertStringContainsString( 'Hi there', $body );
		$this->assertStringNotContainsString( 'Your current plan:', $body );
	}

	public function test_body_falls_back_when_expires_at_missing() {
		$body = Membership_Renewal_Handler::compose_body( 'Priya', 'Defender', '', 7 );
		$this->assertStringContainsString( 'your renewal date', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'membership-renewal-reminders', Membership_Renewal_Handler::slug() );
	}
}
