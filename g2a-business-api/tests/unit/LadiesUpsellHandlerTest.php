<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Ladies_Upsell_Handler;

class LadiesUpsellHandlerTest extends TestCase {
	public function test_bring_friend_body_names_the_offer() {
		$body = Ladies_Upsell_Handler::body_bring_friend( 'Priya' );
		$this->assertStringContainsString( 'Hi Priya', $body );
		$this->assertStringContainsString( '$15/hr', $body );
	}

	public function test_ccw_body_mentions_next_step_positioning() {
		$body = Ladies_Upsell_Handler::body_ccw( '' );
		$this->assertStringContainsString( 'Hi there', $body );
		$this->assertStringContainsString( 'CCW class', $body );
		$this->assertStringContainsString( 'AZ permit', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'post-booking-upsell-ladies', Ladies_Upsell_Handler::slug() );
	}
}
