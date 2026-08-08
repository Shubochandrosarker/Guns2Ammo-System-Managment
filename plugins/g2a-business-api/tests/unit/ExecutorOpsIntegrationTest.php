<?php
namespace WordPressistic\G2ABA\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WordPressistic\G2ABA\BridGistic\Action_Queue;
use WordPressistic\G2ABA\BridGistic\Executor;
use WordPressistic\G2ABA\Ops\Cancellation_Queue;
use WordPressistic\G2ABA\Ops\Email_Draft_Store;

class ExecutorOpsIntegrationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['g2aba_test_options'] = array();
	}

	public function test_email_intent_writes_into_new_draft_store() {
		$aq    = new Action_Queue();
		$entry = $aq->enqueue( 'send follow-up email to expired members', 'action', 1 );

		Executor::on_approved( $entry );

		$drafts = ( new Email_Draft_Store() )->all();
		$this->assertCount( 1, $drafts );
		$this->assertSame( Email_Draft_Store::STATUS_PENDING, $drafts[0]['status'] );

		$after = $aq->find( $entry['id'] );
		$this->assertStringContainsString( 'Drafted email em_', $after['result'] );
		$this->assertStringContainsString( 'Email Drafts', $after['result'] );
	}

	public function test_cancel_intent_writes_into_new_cancellation_queue() {
		$aq    = new Action_Queue();
		$entry = $aq->enqueue( 'cancel booking 92841', 'action', 1 );

		Executor::on_approved( $entry );

		$queue = ( new Cancellation_Queue() )->all();
		$this->assertCount( 1, $queue );
		$this->assertSame( '92841', $queue[0]['bookingId'] );

		$after = $aq->find( $entry['id'] );
		$this->assertStringContainsString( 'Cancellation request cx_', $after['result'] );
	}
}
