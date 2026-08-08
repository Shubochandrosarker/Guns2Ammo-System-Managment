<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Tasks\Tasks_Store;

class TasksStoreTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_create_persists_and_generates_id() {
		$s = new Tasks_Store();
		$t = $s->create( 'Improve CCW page', 'Body', Tasks_Store::SOURCE_AI_INSIGHT, 'ins-1', 'Marketing' );

		$this->assertStringStartsWith( 'task_', $t['id'] );
		$this->assertSame( Tasks_Store::STATUS_OPEN,       $t['status'] );
		$this->assertSame( Tasks_Store::SOURCE_AI_INSIGHT, $t['source'] );
		$this->assertSame( 'ins-1',   $t['sourceId'] );
		$this->assertSame( 'Marketing', $t['owner'] );
	}

	public function test_open_filters_terminal_states() {
		$s = new Tasks_Store();
		$a = $s->create( 'a', '', Tasks_Store::SOURCE_MANUAL );
		$b = $s->create( 'b', '', Tasks_Store::SOURCE_MANUAL );
		$s->resolve( $a['id'] );

		$open = $s->open();
		$this->assertCount( 1, $open );
		$this->assertSame( $b['id'], $open[0]['id'] );
	}

	public function test_resolve_stamps_and_refuses_double_resolve() {
		$s = new Tasks_Store();
		$t = $s->create( 't', '', Tasks_Store::SOURCE_MANUAL );

		$after = $s->resolve( $t['id'] );
		$this->assertSame( Tasks_Store::STATUS_DONE, $after['status'] );
		$this->assertNotEmpty( $after['resolvedAt'] );

		$this->assertNull( $s->resolve( $t['id'] ) );
		$this->assertNull( $s->dismiss( $t['id'] ) );
	}

	public function test_dismiss_is_terminal() {
		$s = new Tasks_Store();
		$t = $s->create( 't', '', Tasks_Store::SOURCE_MANUAL );
		$after = $s->dismiss( $t['id'] );
		$this->assertSame( Tasks_Store::STATUS_DISMISSED, $after['status'] );
	}

	public function test_normalize_source_accepts_known_only() {
		$this->assertSame( Tasks_Store::SOURCE_AI_INSIGHT,   Tasks_Store::normalize_source( ' AI_INSIGHT ' ) );
		$this->assertSame( Tasks_Store::SOURCE_BUSINESS_GAP, Tasks_Store::normalize_source( 'business_gap' ) );
		$this->assertSame( Tasks_Store::SOURCE_MANUAL,       Tasks_Store::normalize_source( 'garbage' ) );
	}

	public function test_counts_by_source() {
		$s = new Tasks_Store();
		$s->create( 'a', '', Tasks_Store::SOURCE_AI_INSIGHT );
		$s->create( 'b', '', Tasks_Store::SOURCE_AI_INSIGHT );
		$s->create( 'c', '', Tasks_Store::SOURCE_BUSINESS_GAP );

		$counts = $s->counts_by_source();
		$this->assertSame( 2, $counts[ Tasks_Store::SOURCE_AI_INSIGHT ] );
		$this->assertSame( 1, $counts[ Tasks_Store::SOURCE_BUSINESS_GAP ] );
	}

	public function test_migrates_legacy_option_shape_once() {
		$GLOBALS['g2aba_test_options']['g2aba_bridgistic_tasks'] = array(
			array( 'title' => 'BridGistic: something', 'body' => 'x', 'createdAt' => '2026-06-01T10:00:00Z', 'status' => 'open' ),
		);
		$s   = new Tasks_Store();
		$all = $s->all();

		$this->assertCount( 1, $all );
		$this->assertSame( Tasks_Store::SOURCE_BRIDGISTIC, $all[0]['source'] );
		$this->assertStringStartsWith( 'task_', $all[0]['id'] );
		$this->assertArrayNotHasKey( 'g2aba_bridgistic_tasks', $GLOBALS['g2aba_test_options'] );
	}
}
