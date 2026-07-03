<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Abandoned_Inquiry_Handler;

class AbandonedInquiryHandlerTest extends TestCase {
	public function test_body_lists_each_inquiry_with_excerpt() {
		$body = Abandoned_Inquiry_Handler::compose_body(
			array(
				array( 'name' => 'Priya', 'email' => 'priya@example.com', 'subject' => 'Booking help', 'created_at' => '2026-07-01 09:00:00' ),
				array( 'name' => '',      'email' => 'anon@example.com',  'subject' => '',            'message' => 'Just testing, please ignore', 'created_at' => '2026-07-01 10:00:00' ),
			)
		);
		$this->assertStringContainsString( '2 inquiries', $body );
		$this->assertStringContainsString( 'Priya <priya@example.com>', $body );
		$this->assertStringContainsString( 'Booking help', $body );
		// Empty-subject fallback should pull from the message excerpt.
		$this->assertStringContainsString( 'Just testing, please ignore', $body );
	}

	public function test_body_truncates_long_message_excerpts() {
		$long = str_repeat( 'x', 200 );
		$body = Abandoned_Inquiry_Handler::compose_body(
			array( array( 'name' => 'x', 'email' => 'x@example.com', 'subject' => '', 'message' => $long, 'created_at' => '2026-07-01 10:00:00' ) )
		);
		$this->assertStringContainsString( '…', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'abandoned-inquiry-alert', Abandoned_Inquiry_Handler::slug() );
	}
}
