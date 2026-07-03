<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Integrations\GA4_Client;

class GA4ClientTest extends TestCase {
	public function test_shape_reads_metric_order_from_request() {
		$aggregate = array(
			'rows' => array(
				array(
					'metricValues' => array(
						array( 'value' => '18412' ),  // sessions
						array( 'value' => '11208' ),  // engagedSessions
						array( 'value' => '48124.00' ), // totalRevenue (USD dollars)
						array( 'value' => '0.382' ),  // bounceRate (0..1)
					),
				),
			),
		);
		$series = array(
			'rows' => array(
				array(
					'dimensionValues' => array( array( 'value' => '20260701' ) ),
					'metricValues'    => array( array( 'value' => '612' ) ),
				),
				array(
					'dimensionValues' => array( array( 'value' => '20260702' ) ),
					'metricValues'    => array( array( 'value' => '588' ) ),
				),
			),
		);

		$out = ( new GA4_Client() )->shape( $aggregate, $series );

		$this->assertSame( 18412, $out['sessions'] );
		$this->assertSame( 11208, $out['engagedSessions'] );
		// USD dollars → cents.
		$this->assertSame( 4_812_400, $out['revenueCents'] );
		// GA4 fractional bounce rate → percent, 1 decimal.
		$this->assertSame( 38.2, $out['bounceRate'] );

		$this->assertCount( 2, $out['sessionsSeries'] );
		$this->assertSame( '2026-07-01', $out['sessionsSeries'][0]['date'] );
		$this->assertSame( 612,          $out['sessionsSeries'][0]['value'] );
	}

	public function test_shape_tolerates_empty_response() {
		$out = ( new GA4_Client() )->shape( array(), array() );

		$this->assertSame( 0,   $out['sessions'] );
		$this->assertSame( 0,   $out['engagedSessions'] );
		$this->assertSame( 0,   $out['revenueCents'] );
		$this->assertSame( 0.0, $out['bounceRate'] );
		$this->assertSame( array(), $out['sessionsSeries'] );
	}

	public function test_shape_skips_missing_date_gracefully() {
		$out = ( new GA4_Client() )->shape(
			array(),
			array(
				'rows' => array(
					array( 'dimensionValues' => array(), 'metricValues' => array( array( 'value' => '10' ) ) ),
				),
			)
		);
		$this->assertSame( '', $out['sessionsSeries'][0]['date'] );
		$this->assertSame( 10, $out['sessionsSeries'][0]['value'] );
	}
}
