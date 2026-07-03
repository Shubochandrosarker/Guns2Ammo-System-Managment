<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\Weekly_Report_Handler;
use WordPressistic\G2ABA\Range;

class WeeklyReportHandlerTest extends TestCase {
	public function test_compose_body_covers_all_channels() {
		$range = new Range( '2026-06-27', '2026-07-03' );
		$body  = Weekly_Report_Handler::compose_body(
			$range,
			array( 'totalRevenue' => 4_812_400, 'revenueGrowthPct' => 12.4 ),
			array( 'bookingsByType' => array(
				array( 'type' => 'Range Lane', 'count' => 412 ),
				array( 'type' => 'CCW Class',  'count' =>  74 ),
			), 'topBookingType' => 'Range Lane' ),
			array( 'active' => 612, 'renewals' => 39, 'expired' => 27 ),
			array( 'revenue' => 2_106_800, 'orders' => 236 ),
			array( 'clicks' => 5_412 )
		);

		$this->assertStringContainsString( '2026-06-27 to 2026-07-03', $body );
		$this->assertStringContainsString( '$48,124', $body );
		$this->assertStringContainsString( '+12.4%', $body );
		$this->assertStringContainsString( 'Range Lane leading', $body );
		$this->assertStringContainsString( '486 (Range Lane leading)', $body );
		$this->assertStringContainsString( '612 active', $body );
		$this->assertStringContainsString( '39 renewed', $body );
		$this->assertStringContainsString( '27 expired', $body );
		$this->assertStringContainsString( '5412', $body );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'weekly-business-report', Weekly_Report_Handler::slug() );
	}
}
