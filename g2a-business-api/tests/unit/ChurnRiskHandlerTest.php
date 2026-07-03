<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Churn_Risk_Handler;

class ChurnRiskHandlerTest extends TestCase {
	public function test_body_names_the_plan_and_expiry() {
		$body = Churn_Risk_Handler::compose_body( 'Priya', 'Defender', '2026-07-10 00:00:00' );
		$this->assertStringContainsString( 'Hi Priya', $body );
		$this->assertStringContainsString( 'Defender', $body );
		$this->assertStringContainsString( '2026-07-10', $body );
	}

	public function test_body_uses_generic_fallbacks_when_data_missing() {
		$body = Churn_Risk_Handler::compose_body( '', '', '' );
		$this->assertStringContainsString( 'Hi there', $body );
		$this->assertStringContainsString( 'Guns2Ammo membership', $body );
		$this->assertStringContainsString( 'your renewal date', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'agent-churn-risk-outreach', Churn_Risk_Handler::slug() );
	}
}
