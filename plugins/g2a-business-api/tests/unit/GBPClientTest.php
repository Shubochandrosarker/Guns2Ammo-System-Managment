<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Integrations\GBP_Client;

class GBPClientTest extends TestCase {
	public function test_shape_sums_daily_series_per_metric() {
		$perf = array(
			'multiDailyMetricTimeSeries' => array(
				array(
					'dailyMetricTimeSeries' => array(
						array(
							'dailyMetric' => 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
							'timeSeries'  => array(
								'datedValues' => array(
									array( 'value' => 40 ),
									array( 'value' => 60 ),
								),
							),
						),
						array(
							'dailyMetric' => 'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
							'timeSeries'  => array(
								'datedValues' => array(
									array( 'value' => 200 ),
									array( 'value' => 300 ),
								),
							),
						),
						array(
							'dailyMetric' => 'CALL_CLICKS',
							'timeSeries'  => array( 'datedValues' => array( array( 'value' => 5 ) ) ),
						),
						array(
							'dailyMetric' => 'BUSINESS_DIRECTION_REQUESTS',
							'timeSeries'  => array( 'datedValues' => array( array( 'value' => 12 ) ) ),
						),
						array(
							'dailyMetric' => 'WEBSITE_CLICKS',
							'timeSeries'  => array( 'datedValues' => array( array( 'value' => 8 ) ) ),
						),
					),
				),
			),
		);

		$out = ( new GBP_Client() )->shape( $perf, 4.75, 132 );

		// Desktop search (40+60) + mobile search (200+300) = 600.
		$this->assertSame( 600, $out['viewsSearch'] );
		// No maps metrics in this fixture.
		$this->assertSame( 0,   $out['viewsMaps'] );
		$this->assertSame( 5,   $out['callsClicked'] );
		$this->assertSame( 12,  $out['directionsRequested'] );
		$this->assertSame( 8,   $out['websiteClicked'] );
		$this->assertSame( 4.75, $out['averageRating'] );
		$this->assertSame( 132, $out['reviewCount'] );
	}

	public function test_shape_zeroes_missing_metrics() {
		$out = ( new GBP_Client() )->shape( array( 'multiDailyMetricTimeSeries' => array() ), 0.0, 0 );
		$this->assertSame( 0,   $out['viewsSearch'] );
		$this->assertSame( 0,   $out['viewsMaps'] );
		$this->assertSame( 0,   $out['callsClicked'] );
		$this->assertSame( 0,   $out['directionsRequested'] );
		$this->assertSame( 0,   $out['websiteClicked'] );
		$this->assertSame( 0.0, $out['averageRating'] );
		$this->assertSame( 0,   $out['reviewCount'] );
	}

	public function test_shape_rounds_rating_to_two_decimals() {
		$out = ( new GBP_Client() )->shape( array(), 4.7654321, 42 );
		$this->assertSame( 4.77, $out['averageRating'] );
		$this->assertSame( 42,   $out['reviewCount'] );
	}
}
