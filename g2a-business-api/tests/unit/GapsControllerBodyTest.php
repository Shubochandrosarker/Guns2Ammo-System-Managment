<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\REST\Gaps_Controller;

class GapsControllerBodyTest extends TestCase {
	public function test_body_uses_field_labels_and_ordering() {
		$body = Gaps_Controller::compose_body( array(
			'evidence' => 'E',
			'impact'   => 'I',
			'fix'      => 'F',
			'priority' => 'high',
			'owner'    => 'Marketing',
		) );
		$this->assertSame(
			"Evidence: E\nImpact: I\nFix: F\nPriority: high\nOwner: Marketing",
			$body
		);
	}

	public function test_body_skips_empty_fields() {
		$body = Gaps_Controller::compose_body( array( 'evidence' => 'E' ) );
		$this->assertSame( 'Evidence: E', $body );
	}
}
