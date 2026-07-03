<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\BridGistic\Action_Queue;

class ActionQueueAttachResultTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_attach_result_updates_field_in_place() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'x', 'action', 1 );

		$after = $q->attach_result( $entry['id'], 'ok' );
		$this->assertSame( 'ok', $after['result'] );

		$stored = $q->find( $entry['id'] );
		$this->assertSame( 'ok', $stored['result'] );
		$this->assertSame( Action_Queue::STATUS_PENDING, $stored['status'], 'attach_result must not change status.' );
	}

	public function test_attach_result_returns_null_for_unknown_id() {
		$this->assertNull( ( new Action_Queue() )->attach_result( 'act_missing', 'nope' ) );
	}
}
