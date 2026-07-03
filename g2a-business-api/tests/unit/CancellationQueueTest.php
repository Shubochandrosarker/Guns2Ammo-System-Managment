<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\Ops\Cancellation_Queue;

class CancellationQueueTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_enqueue_persists_and_generates_id() {
		$q = new Cancellation_Queue();
		$e = $q->enqueue( '92841', 'cancel booking 92841' );

		$this->assertStringStartsWith( 'cx_', $e['id'] );
		$this->assertSame( '92841', $e['bookingId'] );
		$this->assertSame( Cancellation_Queue::STATUS_AWAITING, $e['status'] );
	}

	public function test_awaiting_filters_terminal() {
		$q = new Cancellation_Queue();
		$a = $q->enqueue( '1', 'cancel booking 1' );
		$b = $q->enqueue( '2', 'cancel booking 2' );
		$q->mark_completed( $a['id'], 'done' );

		$awaiting = $q->awaiting();
		$this->assertCount( 1, $awaiting );
		$this->assertSame( $b['id'], $awaiting[0]['id'] );
	}

	public function test_mark_completed_stamps_notes_and_resolved_at() {
		$q = new Cancellation_Queue();
		$e = $q->enqueue( '1', 'cancel booking 1' );
		$after = $q->mark_completed( $e['id'], 'refunded via Stripe' );

		$this->assertNotNull( $after );
		$this->assertSame( 'refunded via Stripe', $after['notes'] );
		$this->assertNotEmpty( $after['resolvedAt'] );
	}

	public function test_double_resolve_refused() {
		$q = new Cancellation_Queue();
		$e = $q->enqueue( '1', 'cancel booking 1' );
		$q->mark_completed( $e['id'], 'done' );
		$this->assertNull( $q->mark_completed( $e['id'], 'again' ) );
		$this->assertNull( $q->drop( $e['id'] ) );
	}

	public function test_migrates_legacy_option_shape_once() {
		$GLOBALS['g2aba_test_options']['g2aba_bridgistic_cancel_queue'] = array(
			array( 'bookingId' => '4128', 'query' => 'cancel #4128', 'createdAt' => '2026-06-01T10:00:00Z', 'status' => 'awaiting_manual_action' ),
		);

		$q = new Cancellation_Queue();
		$all = $q->all();

		$this->assertCount( 1, $all );
		$this->assertStringStartsWith( 'cx_', $all[0]['id'] );
		$this->assertSame( '4128', $all[0]['bookingId'] );
		$this->assertArrayNotHasKey( 'g2aba_bridgistic_cancel_queue', $GLOBALS['g2aba_test_options'] );
	}
}
