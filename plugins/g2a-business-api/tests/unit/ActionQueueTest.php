<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\BridGistic\Action_Queue;

class ActionQueueTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_enqueue_persists_entry() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'send a refund', 'action', 42 );

		$this->assertStringStartsWith( 'act_', $entry['id'] );
		$this->assertSame( Action_Queue::STATUS_PENDING, $entry['status'] );
		$this->assertSame( 'send a refund', $entry['query'] );
		$this->assertSame( 42, $entry['requesterId'] );
		$this->assertSame( 1, count( $q->all() ) );
	}

	public function test_pending_filters_out_resolved() {
		$q = new Action_Queue();
		$a = $q->enqueue( 'x', 'action', 1 );
		$b = $q->enqueue( 'y', 'action', 1 );
		$q->resolve( $a['id'], Action_Queue::STATUS_APPROVED, 'ok' );

		$pending = $q->pending();
		$this->assertCount( 1, $pending );
		$this->assertSame( $b['id'], $pending[0]['id'] );
	}

	public function test_resolve_flips_status_and_stamps_result() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'x', 'action', 1 );
		$out   = $q->resolve( $entry['id'], Action_Queue::STATUS_APPROVED, 'approved by owner' );

		$this->assertNotNull( $out );
		$this->assertSame( Action_Queue::STATUS_APPROVED, $out['status'] );
		$this->assertSame( 'approved by owner', $out['result'] );
		$this->assertNotEmpty( $out['resolvedAt'] );
	}

	public function test_resolve_refuses_to_resolve_twice() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'x', 'action', 1 );
		$q->resolve( $entry['id'], Action_Queue::STATUS_APPROVED, 'first' );

		$again = $q->resolve( $entry['id'], Action_Queue::STATUS_REJECTED, 'second' );
		$this->assertNull( $again, 'A resolved action must not be re-resolvable.' );

		$stored = $q->find( $entry['id'] );
		$this->assertSame( Action_Queue::STATUS_APPROVED, $stored['status'] );
	}

	public function test_resolve_rejects_bogus_status() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'x', 'action', 1 );
		$this->assertNull( $q->resolve( $entry['id'], 'exploded', 'no' ) );
	}

	public function test_find_returns_null_for_unknown_id() {
		$this->assertNull( ( new Action_Queue() )->find( 'act_missing' ) );
	}

	public function test_generate_id_is_unique_prefix() {
		$id1 = Action_Queue::generate_id();
		$id2 = Action_Queue::generate_id();
		$this->assertNotSame( $id1, $id2 );
		$this->assertStringStartsWith( 'act_', $id1 );
	}
}
