<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\BridGistic\Action_Queue;
use WordPressistic\G2ABA\BridGistic\Executor;

class ExecutorTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_email_intent_writes_a_draft_and_stamps_result() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'send follow-up email to expired members', 'action', 1 );

		Executor::on_approved( $entry );

		// Phase 5 moved drafts into Email_Draft_Store (option = g2aba_email_drafts).
		$drafts = get_option( 'g2aba_email_drafts', array() );
		$this->assertCount( 1, $drafts );
		$this->assertSame( 'pending_send', $drafts[0]['status'] );

		$after = $q->find( $entry['id'] );
		$this->assertStringContainsString( 'Drafted email', $after['result'] );
	}

	public function test_task_intent_appends_to_task_store() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'create a task to improve the CCW page', 'action', 1 );

		Executor::on_approved( $entry );

		// Phase 11: BridGistic tasks are now first-class Tasks_Store entries.
		$tasks = get_option( 'g2aba_tasks', array() );
		$this->assertCount( 1, $tasks );
		$first = array_values( $tasks )[0];
		$this->assertStringStartsWith( 'BridGistic:', $first['title'] );
		$this->assertSame( 'open', $first['status'] );
		$this->assertSame( 'bridgistic', $first['source'] );
	}

	public function test_cancel_booking_captures_id_when_present() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'cancel booking 92841 for Priya', 'action', 1 );

		Executor::on_approved( $entry );

		// Phase 5 moved cancellations into Cancellation_Queue (option = g2aba_cancellations).
		$queue = get_option( 'g2aba_cancellations', array() );
		$this->assertCount( 1, $queue );
		$this->assertSame( '92841', $queue[0]['bookingId'] );

		$after = $q->find( $entry['id'] );
		$this->assertStringContainsString( 'Cancellation request', $after['result'] );
	}

	public function test_unknown_intent_still_stamps_result_and_no_side_effects() {
		$q     = new Action_Queue();
		$entry = $q->enqueue( 'do the funky chicken', 'action', 1 );

		Executor::on_approved( $entry );

		$this->assertSame( array(), get_option( 'g2aba_bridgistic_email_drafts', array() ) );
		$this->assertSame( array(), get_option( 'g2aba_bridgistic_tasks', array() ) );

		$after = $q->find( $entry['id'] );
		$this->assertStringContainsString( 'no specific handler', $after['result'] );
	}

	public function test_extract_booking_id_prefers_hash_id() {
		$this->assertSame( '4128', Executor::extract_booking_id( 'refund #4128 to Priya' ) );
	}

	public function test_extract_booking_id_falls_back_to_verb_pair() {
		$this->assertSame( '92841', Executor::extract_booking_id( 'cancel booking 92841' ) );
		$this->assertNull( Executor::extract_booking_id( 'cancel Priya\'s booking' ) );
	}

	public function test_missing_entry_is_a_noop() {
		// A malformed hook payload must not throw.
		Executor::on_approved( null );
		Executor::on_approved( array() );
		Executor::on_approved( array( 'id' => 'act_none', 'query' => 'hello' ) );
		$this->assertTrue( true );
	}
}
