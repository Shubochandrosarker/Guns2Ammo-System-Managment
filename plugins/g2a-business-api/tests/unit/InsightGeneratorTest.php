<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\AI\Insight_Generator;

class InsightGeneratorTest extends TestCase {
	public function test_extract_json_array_bare_array() {
		$out = Insight_Generator::extract_json_array( '[{"id":"a","title":"t"}]' );
		$this->assertSame( 'a', $out[0]['id'] );
	}

	public function test_extract_json_array_strips_code_fence() {
		$out = Insight_Generator::extract_json_array( "```json\n[{\"id\":\"a\"}]\n```" );
		$this->assertSame( 'a', $out[0]['id'] );
	}

	public function test_extract_json_array_finds_embedded_array() {
		$raw = "Sure, here are the insights:\n[{\"id\":\"seo\"}, {\"id\":\"ops\"}]\nHope that helps.";
		$out = Insight_Generator::extract_json_array( $raw );
		$this->assertCount( 2, $out );
		$this->assertSame( 'ops', $out[1]['id'] );
	}

	public function test_extract_json_array_throws_when_nothing_matches() {
		$this->expectException( \RuntimeException::class );
		Insight_Generator::extract_json_array( "I couldn't come up with anything" );
	}

	public function test_normalize_fills_defaults_and_slugs_id() {
		$now  = gmdate( 'Y-m-d' );
		$out  = Insight_Generator::normalize(
			array(
				array(
					'title'          => 'Fix CCW booking flow',
					'category'       => 'BOOKING',
					'summary'        => 'CCW booking conversion is 5.2%',
					'reason'         => 'CTA below the fold',
					'action'         => 'Promote CTA above the fold',
					'priority'       => 'HIGH',
					'expectedImpact' => '+12 bookings/mo',
				),
			)
		);
		$this->assertCount( 1, $out );
		$this->assertSame( 'booking', $out[0]['category'] );
		$this->assertSame( 'high', $out[0]['priority'] );
		$this->assertSame( $now, $out[0]['createdAt'] );
		// A missing/blank id should be auto-generated.
		$this->assertNotEmpty( $out[0]['id'] );
	}

	public function test_normalize_falls_back_on_unknown_priority_and_category() {
		$out = Insight_Generator::normalize(
			array(
				array(
					'id'       => 'x',
					'title'    => 't',
					'category' => 'not-a-real-category',
					'priority' => 'urgent',
				),
			)
		);
		$this->assertSame( 'operations', $out[0]['category'] );
		$this->assertSame( 'medium', $out[0]['priority'] );
	}
}
