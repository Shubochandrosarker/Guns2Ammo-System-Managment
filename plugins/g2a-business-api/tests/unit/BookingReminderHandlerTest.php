<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Booking_Reminder_Handler;

class BookingReminderHandlerTest extends TestCase {
	public function test_subject_line_includes_start_time_when_present() {
		$this->assertStringContainsString(
			'at 14:30',
			Booking_Reminder_Handler::subject_for( 'CCW Class', '2026-07-04 14:30:00' )
		);
	}

	public function test_subject_line_omits_time_when_absent() {
		$this->assertStringNotContainsString(
			'at ',
			Booking_Reminder_Handler::subject_for( 'Range Lane', '' )
		);
	}

	public function test_body_greets_by_name_when_provided() {
		$body = Booking_Reminder_Handler::compose_body( 'Priya', 'Range Lane', '2026-07-04 14:30:00' );
		$this->assertStringContainsString( 'Hi Priya', $body );
		$this->assertStringContainsString( '2026-07-04 14:30', $body );
	}

	public function test_body_falls_back_when_name_missing() {
		$body = Booking_Reminder_Handler::compose_body( '', 'Range Lane', '' );
		$this->assertStringContainsString( 'Hi there', $body );
		$this->assertStringContainsString( 'tomorrow', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'booking-reminder-24h', Booking_Reminder_Handler::slug() );
	}
}
