<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Providers\Segments_Provider;

class SegmentsProviderOpportunitiesTest extends TestCase {
	public function test_compose_opportunities_singularises_at_one_ccw() {
		$out = Segments_Provider::compose_opportunities( 1, 0, 0, 0 );
		$this->assertSame( array( 'Send CCW alumni a rifle-class invite (1 shooter)' ), $out );
	}

	public function test_compose_opportunities_plurals_correctly() {
		$out = Segments_Provider::compose_opportunities( 5, 0, 0, 0 );
		$this->assertStringContainsString( '5 shooters', $out[0] );
	}

	public function test_compose_opportunities_omits_zero_segments() {
		$out = Segments_Provider::compose_opportunities( 0, 0, 0, 0 );
		$this->assertSame( array(), $out );
	}

	public function test_compose_opportunities_lists_every_nonzero_segment() {
		$out = Segments_Provider::compose_opportunities( 10, 5, 3, 2 );
		$this->assertCount( 4, $out );
		$this->assertStringContainsString( 'CCW alumni',        $out[0] );
		$this->assertStringContainsString( 'Ammo bulk',         $out[1] );
		$this->assertStringContainsString( 'Expired members',   $out[2] );
		$this->assertStringContainsString( 'first-time',        $out[3] );
	}
}
