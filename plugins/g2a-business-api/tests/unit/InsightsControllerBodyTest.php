<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Insights_Controller;

class InsightsControllerBodyTest extends TestCase {
	public function test_body_includes_every_present_field() {
		$body = Insights_Controller::compose_task_body( array(
			'summary'        => 'CCW page leaking',
			'reason'         => 'CTA below fold',
			'action'         => 'Move CTA above fold',
			'expectedImpact' => '+12 bookings/mo',
		) );
		$this->assertStringContainsString( 'Summary: CCW page leaking', $body );
		$this->assertStringContainsString( 'Reason: CTA below fold', $body );
		$this->assertStringContainsString( 'Action: Move CTA above fold', $body );
		$this->assertStringContainsString( 'ExpectedImpact: +12 bookings/mo', $body );
	}

	public function test_body_skips_missing_fields() {
		$body = Insights_Controller::compose_task_body( array( 'summary' => 'x' ) );
		$this->assertStringContainsString( 'Summary: x', $body );
		$this->assertStringNotContainsString( 'Reason:', $body );
	}
}
