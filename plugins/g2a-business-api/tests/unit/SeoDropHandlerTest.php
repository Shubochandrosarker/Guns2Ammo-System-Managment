<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Automation\Handlers\SEO_Drop_Handler;

class SeoDropHandlerTest extends TestCase {
	public function test_flags_only_pages_below_threshold() {
		$now  = array(
			array( 'url' => '/ccw-class/',   'clicks' =>  60 ),  // dropped
			array( 'url' => '/rentals/',     'clicks' =>  80 ),  // stable
			array( 'url' => '/newsletter/',  'clicks' =>  20 ),  // dropped
		);
		$prev = array(
			array( 'url' => '/ccw-class/',   'clicks' => 120 ),  // -50%
			array( 'url' => '/rentals/',     'clicks' =>  95 ),  // -16%
			array( 'url' => '/newsletter/',  'clicks' =>  50 ),  // -60%
		);
		$drops = SEO_Drop_Handler::compute_drops( $now, $prev, 25.0 );

		$urls = array_column( $drops, 'url' );
		$this->assertContains( '/ccw-class/',  $urls );
		$this->assertContains( '/newsletter/', $urls );
		$this->assertNotContains( '/rentals/', $urls );
	}

	public function test_pages_without_previous_data_are_ignored() {
		$drops = SEO_Drop_Handler::compute_drops(
			array( array( 'url' => '/brand-new/', 'clicks' => 12 ) ),
			array(),
			25.0
		);
		$this->assertSame( array(), $drops );
	}

	public function test_delta_pct_is_rounded_to_one_decimal() {
		$drops = SEO_Drop_Handler::compute_drops(
			array( array( 'url' => '/x/', 'clicks' => 33 ) ),
			array( array( 'url' => '/x/', 'clicks' => 100 ) ),
			25.0
		);
		$this->assertSame( -67.0, $drops[0]['deltaPct'] );
	}

	public function test_slug_is_stable() {
		$this->assertSame( 'seo-click-drop-alert', SEO_Drop_Handler::slug() );
	}
}
