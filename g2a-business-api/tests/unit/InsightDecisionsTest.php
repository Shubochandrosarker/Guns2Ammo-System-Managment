<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Tasks\Insight_Decisions;

class InsightDecisionsTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_apply_hides_dismissed() {
		$d = new Insight_Decisions();
		$d->dismiss( 'ins-a' );

		$out = $d->apply( array(
			array( 'id' => 'ins-a', 'title' => 'A' ),
			array( 'id' => 'ins-b', 'title' => 'B' ),
		) );
		$ids = array_column( $out, 'id' );
		$this->assertNotContains( 'ins-a', $ids );
		$this->assertContains( 'ins-b', $ids );
	}

	public function test_apply_marks_approved_with_task_id() {
		$d = new Insight_Decisions();
		$d->approve( 'ins-a', 'task_xyz' );

		$out = $d->apply( array( array( 'id' => 'ins-a', 'title' => 'A' ) ) );
		$this->assertTrue( $out[0]['converted'] );
		$this->assertSame( 'task_xyz', $out[0]['taskId'] );
	}

	public function test_apply_leaves_undecided_untouched() {
		$out = ( new Insight_Decisions() )->apply( array( array( 'id' => 'ins-c', 'title' => 'C' ) ) );
		$this->assertArrayNotHasKey( 'converted', $out[0] );
		$this->assertSame( 'C', $out[0]['title'] );
	}

	public function test_reset_clears_decision() {
		$d = new Insight_Decisions();
		$d->dismiss( 'ins-x' );
		$this->assertTrue( $d->is_dismissed( 'ins-x' ) );

		$d->reset( 'ins-x' );
		$this->assertFalse( $d->is_dismissed( 'ins-x' ) );
	}

	public function test_apply_skips_entries_without_id() {
		$out = ( new Insight_Decisions() )->apply( array(
			array( 'title' => 'no id' ),
			array( 'id' => 'ins-x', 'title' => 'has id' ),
		) );
		$this->assertCount( 2, $out );
	}
}
